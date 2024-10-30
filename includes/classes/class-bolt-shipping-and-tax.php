<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bolt_Shipping_and_Tax {

	const SHIPPING_TYPE_REGULAR = 'regular';
	const SHIPPING_TYPE_LOCAL_PICKUP = 'local_pickup';
	const SHIPPING_COUPON_ERR_MAPPING = array(
		'default' => E_BOLT_SHIPPING_GENERAL_ERROR,
	);

	/**
	 * Tax calculation cache
	 *
	 * @since 2.0.11
	 * @var array
	 */
	private $tax_calculation;

	/**
	 * Error information.
	 *
	 * @since 2.3.0
	 * @var Bolt_Error_Handler
	 */
	private $error_handler;

	public function __construct() {
		$this->error_handler   = new Bolt_Error_Handler( BOLT_ENDPOINT_ID_SHIPPING_TAX );
		$this->tax_calculation = array();

		if ( WC_BOLT_WP_REST_API_ADDON ) {
			add_filter( 'json_endpoints', array( $this, 'register_wp_rest_api_route' ) );
		} else {
			add_action( 'rest_api_init', array( $this, 'register_endpoint' ) );
		}
	}

	/**
	 * Register WP REST API route
	 */
	public function register_wp_rest_api_route( $routes ) {
		$routes['/bolt/shippingtax'] = array(
			array(
				array( $this, 'handle_shipping_tax_endpoint' ),
				\WP_JSON_Server::CREATABLE | \WP_JSON_Server::ACCEPT_JSON
			),
		);

		return $routes;
	}

	/**
	 * Register wordpress endpoints
	 */
	public function register_endpoint() {
		register_rest_route( 'bolt', '/shippingtax', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_shipping_tax_endpoint' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 *  Function for bolt shipping and tax method endpoint.
	 *
	 * @return WP_REST_Response array   Well-formed response sent to the Bolt Server
	 *
	 * TODO: Add fees to this calculation
	 */
	public function handle_shipping_tax_endpoint() {
		wc_bolt()->get_metrics_client()->save_start_time();
		BugsnagHelper::initBugsnag();

		try {
			/////////////////////////////////////////////////////////////////
			// In the case of long processing, we ignore Bolt's aborts
			// And give the calculation 40 seconds to complete
			// If it takes longer, then custom merchant-side optimization is needed
			/////////////////////////////////////////////////////////////////
			ignore_user_abort( true );
			set_time_limit( 40 );
			/////////////////////////////////////////////////////////////////

			$GLOBALS['is_webhook_request'] = true;

			# Get request body that Bolt has sent us
			$bolt_order_json = file_get_contents( 'php://input' );
			BugsnagHelper::addBreadCrumbs( array( 'BOLT Shipping&Tax API REQUEST' => $bolt_order_json ) );
			$bolt_order = json_decode( $bolt_order_json );

			# Get the authentication header that Bolt has sent us
			$hmac_header = @$_SERVER[ BOLT_HEADER_HMAC ] ?: '';

			//to prevent third party plugin write their errors to output
			ob_start();

			if ( ! verify_signature( $bolt_order_json, $hmac_header ) ) {
				//////////////////////////////////////////////////////////
				// Request can not be verified as originating from Bolt
				// So we return an error
				//////////////////////////////////////////////////////////
				wc_bolt()->get_metrics_client()->process_metric( BOLT_METRIC_NAME_SHIP_TAX, BOLT_STATUS_FAILURE );
				$this->error_handler->handle_error( E_BOLT_SHIPPING_GENERAL_ERROR, (object) array( BOLT_ERR_REASON => 'Invalid HMAC header' ) );

				return $this->error_handler->build_error();
			}

			$_POST[ BOLT_PREFIX_SHIPPING_AND_TAX . $bolt_order->cart->order_reference ] = $bolt_order;

			$this->retrive_active_cart_session_by_order_reference( $bolt_order->cart->order_reference );
			if ( $this->error_handler->has_error() ) {
				wc_bolt()->get_metrics_client()->process_metric( BOLT_METRIC_NAME_SHIP_TAX, BOLT_STATUS_FAILURE );

				return $this->error_handler->build_error();
			}

			// Always check if cart is empty or not after retrieve the active cart session by order reference.
			if ( WC()->cart->is_empty() ) {
				throw new \Exception( 'Empty cart' );
			}

			// Check if md5 return empty, and md5 always expect string as parameter
			if ( ! ( $bolt_cart_md5 = md5( $bolt_order_json ) ) ) {
				throw new \Exception( 'Failed to encrypt api request data :' . $bolt_order_json );
			}

			//////////////////////////////////////////////////////////
			// Check first for a cached estimate from a previous
			// aborted attempt.  If found, return it and exit
			//////////////////////////////////////////////////////////
			if ( ( $cached_estimate = wc_bolt_data()->get_session( BOLT_PREFIX_SHIPPING_AND_TAX . $bolt_order->cart->order_reference . "_" . $bolt_cart_md5 ) ) ) {
				wc_bolt_data()->delete_session( BOLT_PREFIX_SHIPPING_AND_TAX . $bolt_order->cart->order_reference . "_" . $bolt_cart_md5 );

				return Bolt_HTTP_Handler::prepare_http_response(
					json_decode( $cached_estimate ),
					HTTP_STATUS_OK,
					array( BOLT_HEADER_CACHED_VALUE => true )
				);
			}

			$result = $this->evaluate_shipping_tax( $bolt_order );

			if ( $this->error_handler->has_error() ) {
				wc_bolt()->get_metrics_client()->process_metric( BOLT_METRIC_NAME_SHIP_TAX, BOLT_STATUS_FAILURE );

				return $this->error_handler->build_error();
			}

			wc_bolt()->get_metrics_client()->process_metric( BOLT_METRIC_NAME_SHIP_TAX, BOLT_STATUS_SUCCESS );
			# Cache the shipping and tax response in case of a timed out request
			if ( apply_filters( 'wc_bolt_if_enable_shipping_tax_cache', true ) ) {
				wc_bolt_data()->update_session( BOLT_PREFIX_SHIPPING_AND_TAX . $bolt_order->cart->order_reference . "_" . $bolt_cart_md5, json_encode( $result[ BOLT_FIELD_NAME_BODY ] ) );
			}

			Bolt_HTTP_Handler::clean_buffers( true );

			return Bolt_HTTP_Handler::prepare_http_response(
				$result[ BOLT_FIELD_NAME_BODY ],
				$result[ BOLT_FIELD_NAME_HTTP_CODE ],
				array( BOLT_HEADER_CACHED_VALUE => false )
			);

		} catch ( \Exception $e ) {
			wc_bolt()->get_metrics_client()->process_metric( BOLT_METRIC_NAME_SHIP_TAX, BOLT_STATUS_FAILURE );
			$this->error_handler->handle_error( E_BOLT_SHIPPING_GENERAL_ERROR, (object) array( BOLT_ERR_REASON => $e->getMessage() ) );

			return $this->error_handler->build_error();

		} finally {
			# If the user isn't logged in then we created a new session, that will no longer be used
			# Need to destroy it not to add an extra entry to the session table
			if ( ! Bolt_Feature_Switch::instance()->is_native_cart_session_enabled() && ! is_user_logged_in() ) {
				@ WC()->session->destroy_session();
			}
		}
	}

	public function retrive_active_cart_session_by_order_reference( $order_reference ) {
		try {
			set_cart_by_bolt_reference( $order_reference );
		} catch ( \Exception $e ) {
			$this->error_handler->handle_error( E_BOLT_SHIPPING_CUSTOM_ERROR, (object) array( BOLT_ERR_REASON => $e->getMessage() ) );

			return false;
		}
		reset_wc_notices();

		return true;
	}


	/**
	 * Evaluate shipping and tax
	 *
	 * @param $bolt_order Bolt order data
	 *
	 * @return array including body of response and http code
	 */
	public function evaluate_shipping_tax( $bolt_order ) {
		$shipping_validation = apply_filters( 'wc_bolt_shipping_validation', $bolt_order );

		if ( is_wp_error( $shipping_validation ) ) {
			$this->error_handler->handle_error( $shipping_validation->get_error_code(), (object) array( BOLT_ERR_REASON => $shipping_validation->get_error_message() ) );

			return false;
		}

		//////////////////////////////////////////////////////////////////////////
		// Define the customer shipping location
		//////////////////////////////////////////////////////////////////////////
		$country_code = bolt_addr_helper()->verify_country_code( $bolt_order->shipping_address->country_code, $bolt_order->shipping_address->region ) ?: '';
		$post_code    = $bolt_order->shipping_address->postal_code ?: '';
		$region       = bolt_addr_helper()->get_region_code_without_encoding( $country_code, $bolt_order->shipping_address->region ?: ( bolt_addr_helper()->check_if_address_field_required( WC_SHIPPING_STATE, $country_code, WC_SHIPPING_PREFIX ) ? $bolt_order->shipping_address->locality : '' ) );
		$city         = $bolt_order->shipping_address->locality ?: '';

		//if the config "Allow shipping to PO Box" is set to false
		//check if address contains text like "PO box", and if so return error code 6101 (integer)
		$settings = wc_bolt()->get_settings();
		if ( 'no' === $settings[ Bolt_Settings::SETTING_NAME_ALLOW_SHIPPING_POBOX ] && bolt_addr_helper()->check_if_address_contain_pobox( $bolt_order->shipping_address->street_address1, $bolt_order->shipping_address->street_address2 ) ) {
			$this->error_handler->handle_error( E_BOLT_SHIPPING_PO_BOX_SHIPPING_DISALLOWED, (object) array( BOLT_ERR_REASON => __( 'Address with P.O. Box is not allowed.', 'bolt-checkout-woocommerce' ) ) );

			return false;
		}

		// Related to apple pay request contains dummy data instead full shipping address, email and phone
		// Also depending from country request can contains only few first symbols from postcode
		$is_apple_pay = isset( $bolt_order->request_source ) && ( $bolt_order->request_source == 'applePay' );
		if ( $is_apple_pay ) {
			$original_session_data                   = wc_bolt_data()->get_session( BOLT_PREFIX_SESSION_DATA . $bolt_order->cart->order_reference );
			$original_session_data['bolt_apply_pay'] = $bolt_order->order_token;
			wc_bolt_data()->update_session( BOLT_PREFIX_SESSION_DATA . $bolt_order->cart->order_reference, $original_session_data );
		}

		$shipping_address_data = array(
			WC_SHIPPING_EMAIL      => $bolt_order->shipping_address->email ?: $bolt_order->cart->billing_address->email,
			WC_SHIPPING_FIRST_NAME => $bolt_order->shipping_address->first_name ?: '',
			WC_SHIPPING_LAST_NAME  => $bolt_order->shipping_address->last_name ?: '',
			WC_SHIPPING_ADDRESS_1  => $bolt_order->shipping_address->street_address1,
			WC_SHIPPING_CITY       => $city,
			WC_SHIPPING_STATE      => bolt_addr_helper()->get_region_code( $country_code, $region, true ),
			WC_SHIPPING_POSTCODE   => $post_code,
			WC_SHIPPING_COUNTRY    => $country_code,
		);
		if ( $error_msg = bolt_addr_helper()->validate_address( $shipping_address_data, WC_SHIPPING_PREFIX, $is_apple_pay ) ) {
			$this->error_handler->handle_error( E_BOLT_SHIPPING_CUSTOM_ERROR, (object) array( BOLT_ERR_REASON => $error_msg ) );

			return false;
		}

		if ( isset( $bolt_order->cart->billing_address ) ) {
			$billing_country_code = bolt_addr_helper()->verify_country_code( $bolt_order->cart->billing_address->country_code, $bolt_order->cart->billing_address->region ?: '' ) ?: '';
			$billing_region       = bolt_addr_helper()->get_region_code_without_encoding( $billing_country_code, $bolt_order->cart->billing_address->region ?: ( bolt_addr_helper()->check_if_address_field_required( WC_BILLING_STATE, $billing_country_code, WC_BILLING_PREFIX ) ? $bolt_order->cart->billing_address->locality : '' ) );
			$billing_address_data = array(
				WC_BILLING_FIRST_NAME => $bolt_order->cart->billing_address->first_name ?: '',
				WC_BILLING_LAST_NAME  => $bolt_order->cart->billing_address->last_name ?: '',
				WC_BILLING_EMAIL      => $bolt_order->cart->billing_address->email,
				WC_BILLING_PHONE      => $bolt_order->cart->billing_address->phone ?: '',
				WC_BILLING_ADDRESS_1  => $bolt_order->cart->billing_address->street_address1,
				WC_BILLING_CITY       => $bolt_order->cart->billing_address->locality ?: '',
				WC_BILLING_COUNTRY    => $billing_country_code,
				WC_BILLING_STATE      => bolt_addr_helper()->get_region_code( $billing_country_code, $billing_region, true ),
				WC_BILLING_POSTCODE   => $bolt_order->cart->billing_address->postal_code ?: '',
			);
			if ( $error_msg = bolt_addr_helper()->validate_address( $billing_address_data, WC_BILLING_PREFIX, $is_apple_pay ) ) {
				$this->error_handler->handle_error( E_BOLT_SHIPPING_CUSTOM_ERROR, (object) array( BOLT_ERR_REASON => $error_msg ) );

				return false;
			}
		}

		WC()->customer->set_location( $country_code, $region, $post_code, $city );
		WC()->customer->set_shipping_address( $shipping_address_data[ WC_SHIPPING_ADDRESS_1 ] );
		WC()->customer->set_shipping_address_2( $bolt_order->shipping_address->street_address2 ?: '' );
		WC()->customer->save();
		do_action( 'wc_bolt_shippingtax_after_set_customer_shipping_address', $shipping_address_data, $bolt_order->cart->order_reference );

		$taxjar_enabled = TaxjarHelper::is_enabled();
		if ( $taxjar_enabled ) {
			new TaxjarHelper();
		}

		$shipping_options = apply_filters( 'wc_bolt_before_load_shipping_options', array(), $bolt_order, $this->error_handler );
		if ( $this->error_handler->has_error() ) {
			return false;
		}

		if ( WC()->cart->needs_shipping() ) {

			$shipping_methods = wc_bolt_get_shipping_methods_for_first_package();

			/**
			 * @var WC_Shipping_Rate $shipping_method
			 */
			if ( ! empty( $shipping_methods ) ) {
				$this->calculate_tax( $shipping_methods );
				foreach ( $shipping_methods["rates"] as $method_key => $shipping_method ) {
					$shipping_options[] = apply_filters( 'wc_bolt_loading_shipping_option', (object) array(
						BOLT_CART_SHIPMENT_SERVICE    => html_entity_decode( $shipping_method->get_label() ),
						BOLT_CART_SHIPMENT_COST       => convert_monetary_value_to_bolt_format( $shipping_method->get_cost() ),
						BOLT_CART_SHIPMENT_REFERENCE  => (string) $method_key,
						BOLT_CART_SHIPMENT_TAX_AMOUNT => $this->tax_calculation[ $method_key ]
					), $method_key, $shipping_method, $bolt_order );
				}
			}
		} else {
			Bolt_woocommerce_cart_calculation::calculate();
			$shipping_options[] = (object) array(
				BOLT_CART_SHIPMENT_SERVICE    => 'No shipping required',
				BOLT_CART_SHIPMENT_REFERENCE  => 'no_shipping_required',
				BOLT_CART_SHIPMENT_COST       => 0,
				BOLT_CART_SHIPMENT_TAX_AMOUNT => convert_monetary_value_to_bolt_format( WC()->cart->get_total_tax() )
			);
		}

		// extensions can do customization per merchant in this filter hook
		$shipping_options = apply_filters( 'wc_bolt_after_load_shipping_options', $shipping_options, $bolt_order );

		###################################
		# There were no shipping options
		# found for the provided location.
		# The Bolt modal will reflect this,
		# but still alert Bugsnag
		###################################
		if ( empty( $shipping_options ) ) {
			BugsnagHelper::notifyException( new \Exception( "No shipping options were found." ), array(), 'info' );
		}
		///////////////////////////////////////////////////////////

		############################################################
		# The coupon validation result is based on error notice, so 
		# before validating the applied coupons, first check whether
		# there is already any error notice in case of other errors.
		# In that way the response would be more appropriate.
		############################################################
		if ( $notices = wc_get_notices( WC_NOTICE_TYPE_ERROR ) ) {
			$error_msg = '';
			foreach ( $notices as $notice ) {
				// WooCommerce notice has different structures in different versions.
				$error_msg .= wc_kses_notice( get_wc_notice_message( $notice ) );
			}

			$this->error_handler->handle_error( E_BOLT_SHIPPING_CUSTOM_ERROR, (object) array( BOLT_ERR_REASON => $error_msg ) );

			return false;
		}

		// Validate the applied coupons
		$bolt_discounts = new Bolt_Discounts_Helper( WC()->cart );
		$bolt_discounts->validate_applied_coupons( array( WC_BILLING_EMAIL => isset( $bolt_order->shipping_address->email ) ? $bolt_order->shipping_address->email : '' ), $this->error_handler, SELF::SHIPPING_COUPON_ERR_MAPPING );
		if ( $this->error_handler->has_error() ) {
			return false;
		}

		$shipping_and_tax_payload = (object) array(
			BOLT_FIELD_NAME_TAX_RESULT       => (object) array(
				BOLT_FIELD_NAME_AMOUNT => 0
			),
			BOLT_FIELD_NAME_SHIPPING_OPTIONS => $shipping_options,
		);

		return array(
			BOLT_FIELD_NAME_BODY      => $shipping_and_tax_payload,
			BOLT_FIELD_NAME_HTTP_CODE => HTTP_STATUS_OK,
		);
	}

	/**
	 * Calculate full tax (cart tax + shipping tax) per shipping method
	 *
	 * @param array $shipping_methods
	 *
	 * @since 2.8.0
	 */
	private function calculate_tax( $shipping_methods ) {
		/*
		 * We are currently only supporting shipping to a single address due to multi-address support being in progress
		 * TODO: When Bolt finishes multi-address shipping support, this approach must be revised
		*/

		/*
		 * Calculate tas per each option
		 * If true we should calculate cart to know shipping delivery tax
		 * If false we can get shipping delivery tax from cache created for the same delivery type
		 * regular / local pickup
		*/
		$calculate_tax_per_each_option = apply_filters( 'wc_bolt_calculate_tax_per_each_option', false );

		$shipping_groups = array();

		foreach ( $shipping_methods["rates"] as $shipping_method_key => $shipping_method ) {
			$shipping_method_type                                             = $this->get_shipping_method_type( $shipping_method );
			$shipping_groups[ $shipping_method_type ][ $shipping_method_key ] = $shipping_method;
		}

		do_action( 'wc_bolt_before_calculate_shipping_tax', $shipping_methods );

		foreach ( $shipping_groups as $shipping_method_type => $shipping_methods ) {
			$shipping_methods_tax_statuses = array();
			$cart_contents_tax             = null;
			$cart_fee_tax                  = null;
			foreach ( $shipping_methods as $shipping_method_key => $shipping_method ) {
				if ( $calculate_tax_per_each_option || is_null( $cart_contents_tax ) ) {
					wc_bolt_set_chosen_shipping_method_for_first_package( $shipping_method_key );
					Bolt_woocommerce_cart_calculation::calculate();
					$this->tax_calculation[ $shipping_method_key ] = convert_monetary_value_to_bolt_format( WC()->cart->get_total_tax() );

					if ( ! $calculate_tax_per_each_option ) {
						// In WooCommerce, the cart taxes consist of cart_contents_taxes, fee_taxes and shipping taxes.
						// Here we store cart_contents_taxes and fee_taxes for shipping tax calculation furtherly.
						$cart_contents_tax = wc_add_number_precision_deep( WC()->cart->get_cart_contents_taxes(), false );
						$cart_fee_tax      = wc_add_number_precision_deep( WC()->cart->get_fee_taxes(), false );

						// After calculating cart totals, all the available shipping methods are saved in the WC sessions,
						// and these data won't change until the shipping package or shipping address changes,
						// so we can check whether the shipping method is taxable by retrieving tax information from the loaded shipping methods in the session.
						$shipping_methods_tax_statuses = wc_bolt_get_shipping_methods_tax_status();
					}
				} else {
					// We calculate shipping tax only if it is taxable.
					if ( array_key_exists( $shipping_method->get_instance_id() . '_' . $shipping_method->get_method_id(), $shipping_methods_tax_statuses ) ) {
						$shipping_tax = wc_bolt_calculate_shipping_method_tax( $shipping_method_key );
					} else {
						$shipping_tax = array();
					}

					// Calulate the cart tax total in the same way as WooCommerce.
					$this->tax_calculation[ $shipping_method_key ] = convert_monetary_value_to_bolt_format( wc_bolt_get_merged_tax( array(
							$cart_contents_tax,
							$cart_fee_tax,
							$shipping_tax
						)
					) );
				}
			}
		}

		do_action( 'wc_bolt_after_calculate_shipping_tax', $this->tax_calculation, $shipping_methods );

		$this->tax_calculation = apply_filters( 'wc_bolt_tax_calculation', $this->tax_calculation, $shipping_methods );
	}

	/**
	 * Identify is shipping method is local pickup or not
	 *
	 * @param $shipping_method
	 *
	 * @return string "local_pickup" for local pickup, "regular" another.
	 * @since 2.0.11
	 */
	private function get_shipping_method_type( $shipping_method ) {
		$local_pickup_method_labels = apply_filters( 'woocommerce_local_pickup_methods', array(
			'legacy_local_pickup',
			'local_pickup'
		) );
		if ( in_array( $shipping_method->get_method_id(), $local_pickup_method_labels ) ) {
			$shipping_method_type = SELF::SHIPPING_TYPE_LOCAL_PICKUP;
		} else {
			$shipping_method_type = SELF::SHIPPING_TYPE_REGULAR;
		}

		return $shipping_method_type;
	}
}

new Bolt_Shipping_and_Tax();