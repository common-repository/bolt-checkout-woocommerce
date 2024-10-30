<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bolt_Checkout
 *
 * Provides the Bolt to WooCommerce checkout functionality mappings
 */
class Bolt_Checkout extends \WC_Checkout {

	// The maximum time that we wait while another process creates an order
	const MAX_LOCK_SECONDS_DEFAULT = 120;

	/**
	 * The single instance of the class.
	 *
	 * @since 1.3.2
	 * @var Bolt_Checkout|null
	 */
	protected static $instance = null;

	/**
	 * @var string  A JSON string of the transaction processed by Bolt
	 */
	protected static $bolt_transaction;

	/**
	 * @var integer  created order id
	 */
	protected static $order_id;

	/**
	 * @var integer  posted data
	 */
	protected static $posted_data;

	/**
	 * @since 1.3.5
	 * @var array Bolt gateway settings.
	 */
	private $gateway_settings;

	/**
	 * @since 1.3.5
	 * @var boolean True if we in pre_auth order creation process
	 */
	private $is_in_pre_auth;

	/**
	 * @since 2.2.0
	 * @var WC_Order the newly created order instance.
	 */
	private $order_created;

	/**
	 * Error information.
	 *
	 * @since 2.2.0
	 * @var Bolt_Error_Handler
	 */
	private $error_handler;

	/**
	 * Set error_handler
	 *
	 * @param $error_handler Bolt_Error_Handler
	 *
	 * @since 2.2.0
	 */
	public function set_error_handler( $error_handler ) {
		$this->error_handler = $error_handler;
	}

	/**
	 * Gets the main Bolt_Checkout Instance.
	 *
	 * @return Bolt_Checkout Main instance
	 * @since 1.3.2
	 * @static
	 *
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
			self::$instance->init_hooks();
		}

		return self::$instance;
	}

	/**
	 * Bolt_Checkout_Request constructor.
	 *
	 * @since 1.0
	 */
	public function __construct() {
		$this->gateway_settings = wc_bolt()->get_settings();
		$this->is_in_pre_auth   = false;
	}

	/**
	 * Reset the instance of the class
	 *
	 * @since  1.0.0
	 * @access public
	 */
	public static function reset() {
		self::$instance         = null;
		self::$bolt_transaction = null;
		self::$order_id         = null;
		self::$posted_data      = null;
	}

	/**
	 * Hook into actions and filters.
	 *
	 * @since 1.3.2
	 */
	private function init_hooks() {
		# Enqueue required JS and pass other js variables.
		add_action( 'wp_enqueue_scripts', array( self::$instance, 'enqueue_bolt_scripts' ) );

		add_action( 'wc_ajax_wc_bolt_create_order', array( self::$instance, 'save_order' ) );
		add_action( 'wc_ajax_wc_bolt_save_email', array( self::$instance, 'save_email' ) );
		add_action( 'wc_ajax_wc_bolt_checkout_validation', array( self::$instance, 'do_checkout_validation' ) );
		add_action( 'wc_ajax_wc_bolt_record_frontend_error', array( self::$instance, 'record_frontend_error' ) );
		add_action( 'wc_ajax_wc_bolt_generate_checkout_btn', array( self::$instance, 'generate_checkout_btn' ) );

		add_action( 'woocommerce_checkout_order_processed', array( self::$instance, 'save_transaction' ), 10, 3 );
		add_action( 'woocommerce_checkout_order_processed', array( self::$instance, 'save_non_bolt_order' ), 10, 3 );

		# let other hooks go first by setting priority to 200, then we can see if there are any other errors
		add_filter( 'woocommerce_payment_successful_result', array(
			self::$instance,
			'create_redirect_url'
		), 200, 2 );

		# to prevent duplicated orders created via orphaned transactions
		add_action( 'woocommerce_before_checkout_process', array(
			self::$instance,
			'check_if_duplicate_order'
		), 1 );

		//for Orphaned Transaction, we should update display_id with order id in response
		add_filter( 'woocommerce_payment_successful_result', array(
			self::$instance,
			'add_order_number_to_display_id'
		), 200, 2 );

		// Remove `cancel` action from the list of account orders actions
		add_filter( 'woocommerce_my_account_my_orders_actions', array(
			self::$instance,
			'remove_cancel_action'
		), 200, 2 );

		//prevent canceling order if order on review
		add_filter( 'woocommerce_cancel_unpaid_order', array( self::$instance, 'cancel_unpaid_order' ), 9999, 2 );

		// Update BOLT_ORDER_META_TRANSACTION_ORDER right after saving order to the DB
		add_action( 'woocommerce_after_order_object_save', array(
			self::$instance,
			'save_bolt_transaction_order'
		), 1, 2 );
		add_action( 'woocommerce_after_shop_order_object_save', array(
			self::$instance,
			'save_bolt_transaction_order'
		), 1, 2 );
	}

	/**
	 * Add script to front end.
	 *
	 * @since 1.0
	 */
	public function enqueue_bolt_scripts() {
		if ( wc_bolt_is_eliminated_from_current_page() ) {
			return;
		}
		global $wp;
		// Add the public resources.
		wp_enqueue_script( 'wc-bolt-checkout-process', WC_BOLT_CHECKOUT_PLUGIN_FILE . 'assets/js/bolt-payment-review.js', array( 'jquery' ), WC_BOLT_CHECKOUT_VERSION . '.9' );

		// Send variables to JS file.
		wp_localize_script( 'wc-bolt-checkout-process', 'wc_bolt_checkout_config', array(
			'display_notices_selector' => $this->gateway_settings[ Bolt_Settings::SETTING_NAME_DISPLAY_NOTICES_SELECTOR ],
			'ajax_url'                 => \WC_AJAX::get_endpoint( '%%wc_endpoint%%' ),
			'nonce'                    => array(
				'payment'             => wp_create_nonce( 'wc-bolt-payment-request' ),
				'shipping'            => wp_create_nonce( 'wc-bolt-payment-request-shipping' ),
				'update_shipping'     => wp_create_nonce( 'wc-bolt-update-shipping-method' ),
				'create_checkout_btn' => wp_create_nonce( 'wc-bolt-create_checkout_btn' ),
				'save_details'        => wp_create_nonce( 'wc-bolt-save_transaction_details' ),
			),
			// Make sure there is always an alternative for redirect url after successfully save woocommerce order
			'default_redirect_url'     => wc_get_endpoint_url( 'order-received', '', wc_get_page_permalink( 'checkout' ) ),
			'is_order_pay_page'        => ( wc_bolt_is_checkout_page() && ! empty( $wp->query_vars['order-pay'] ) ) ? '1' : '0',
		) );
	}


	/**
	 * Ajax function for Quick Buy to assure that if registration is required, the user
	 * is logged in.
	 */
	public function do_checkout_validation() {
		BugsnagHelper::initBugsnag();

		if ( isset( $_POST['bolt_order_invoice'] ) ) {
			// User on 'pay for order' page
			$error_message = wc_bolt_check_error_for_pay_exist_order( absint( $_POST['bolt_order_reference'] ) );
			if ( $error_message ) {
				wc_add_notice( $error_message, WC_NOTICE_TYPE_ERROR );
				$this->send_ajax_failure_response();
			} else {
				wp_send_json( array(
					'result'         => 'success',
					'refresh_button' => 'true',
				) );
			}
		}

		$errors = new \WP_Error();
		try {
			// Check if cart is empty or not.
			if ( WC()->cart->is_empty() ) {
				wc_add_notice( 'Empty cart', WC_NOTICE_TYPE_ERROR );
			} else {
				if ( ! defined( 'WOOCOMMERCE_CHECKOUT' ) ) {
					define( 'WOOCOMMERCE_CHECKOUT', true );
				}

				$posted_data = WC()->checkout()->get_posted_data();

				// Update session for customer and totals.
				$this->update_session( $posted_data );

				// Check if the customer needs to be registered and is unique
				if ( ! is_user_logged_in() && ( $this->is_registration_required() || ! empty( $posted_data['createaccount'] ) ) ) {

					$email = $posted_data[ WC_BILLING_EMAIL ];

					if ( email_exists( $email ) ) {
						$message = __( 'An account is already registered with your email address. Please <a href="' . get_permalink( get_option( 'woocommerce_myaccount_page_id' ) ) . '"><u><b>log in</b></u></a>.', 'woocommerce' );
						wc_add_notice( $message, WC_NOTICE_TYPE_ERROR );
					}
				}

				// Using a one-time item in post data to establish this is the appropriate entry to validate checkout with Bolt.
				// And it only take effect in Bolt checkout process.
				$_POST['in_bolt_checkout'] = true;

				// Validate posted data and cart items before proceeding.
				$this->validate_checkout( $posted_data, $errors );
			}
		} catch ( \Exception $e ) {
			if ( method_exists( $e, 'getErrorCode' ) && ( $e->getErrorCode() == 'customer_invalid_billing_email' ) ) {
				wc_add_notice( $e->getMessage(), WC_NOTICE_TYPE_ERROR );
				BugsnagHelper::getBugsnag()->notifyException( $e, array(), 'info' );
			} else {
				wc_add_notice( 'Validation fails', WC_NOTICE_TYPE_ERROR );
				BugsnagHelper::getBugsnag()->notifyException( $e );
			}
		} finally {
			if ( $errors->get_error_messages() ) {
				foreach ( $errors->get_error_messages() as $message ) {
					wc_add_notice( $message, WC_NOTICE_TYPE_ERROR );
				}
			}
			if ( 0 === wc_notice_count( WC_NOTICE_TYPE_ERROR ) ) {
				$bolt_order_reference = $_POST['bolt_order_reference'];
				if ( ! empty( $bolt_order_reference ) ) {
					if ( isset( $posted_data['woocommerce_checkout_update_totals'] ) && ! $posted_data['woocommerce_checkout_update_totals'] ) {
						//if woocommerce_checkout_update_totals exist and equal false, it means the customer try to place an order now,
						//and we need to unset it to process checkout
						unset( $posted_data['woocommerce_checkout_update_totals'] );
					}
					wc_bolt_data()->update_session( BOLT_PREFIX_SESSION_POSTED_DATA . $bolt_order_reference, $_POST );
				}
				wp_send_json( array(
					'result'         => 'success',
					'refresh_button' => 'true',
				) );
			} else {
				$this->send_ajax_failure_response();
			}
		}
	}


	/**
	 * Saves order in woocommerce backend after the transaction has been initiated by Bolt.
	 *
	 * @throws Exception    thrown if the order reference and the client transaction does not
	 *                      match Bolt's records
	 * @var boolean $is_in_pre_auth true if we are in pre auth order creation process
	 *
	 * @var array $api_response A transaction object retrieved from the Bolt API fetch endpoint
	 */
	public function save_order( $api_response = null, $is_in_pre_auth = false ) {
		$this->is_in_pre_auth = $is_in_pre_auth;
		BugsnagHelper::initBugsnag();
		if ( $this->is_in_pre_auth ) {
			return $this->save_order_pre_auth( $api_response );
		} else {
			return $this->save_order_non_pre_auth( $api_response );
		}
	}

	/**
	 * Save order for Bolt non-pre-auth
	 *
	 * @since 2.2.0
	 */
	private function save_order_non_pre_auth( $api_response = null ) {
		try {
			###############################################
			# Authenticate the request
			###############################################
			if ( @ $_POST['orphaned_transaction'] ) {
				# Webhook HMAC Header check
				$request_body     = file_get_contents( 'php://input' );
				$is_valid_request = verify_signature( $request_body, @$_SERVER[ BOLT_HEADER_HMAC ] );

				if ( ! $is_valid_request ) {
					throw new \Exception( 'Invalid HMAC provided.' );
				}
			} else {
				///////////////////////////////////////////////////////////////////////////
				// Verify that the front-end provided transaction is valid by looking up
				// the transaction on Bolt by Bolt Transaction reference and cross-referencing
				// the order references of each
				///////////////////////////////////////////////////////////////////////////

				// TODO : we can remove this condition when namespace is enabled.
				if ( empty( $api_response ) ) {
					if ( isset( $_POST['transaction_details'] ) && ! empty( $_POST['transaction_details'] ) ) {
						$front_end_transaction = json_decode( stripslashes( $_POST['transaction_details'] ) );
						$api_response          = wc_bolt()->get_bolt_data_collector()->handle_api_request( 'transactions/' . $front_end_transaction->reference );
						//If order is created via pre-auth checkout process then
						//in frontend call we only empty cart and delete session
						$order_id = wc_bolt_get_order_id_by_order_reference( $front_end_transaction->cart->order_reference );
						if ( $order_id && ! empty( $existing_order = wc_get_order( $order_id ) ) && 'checkout' === $existing_order->get_created_via() ) {
							WC()->cart->empty_cart();
							//clear cache to prevent using it if user creates new cart with the same inventory
							$bolt_data = WC()->session->get( 'bolt_data', array() );
							unset( $bolt_data[ BOLT_CART_ORDER_REFERENCE ] );
							WC()->session->set( 'bolt_data', $bolt_data );

							// Add the customer note to admin
							if ( isset( $api_response->order->user_note ) ) {
								$existing_order->set_customer_note( $api_response->order->user_note );
								$existing_order->save();
							}

							$result = array(
								'order_id'     => $order_id,
								'result'       => 'success',
								'redirect_url' => $existing_order->get_checkout_order_received_url()
							);
							wp_send_json( $result );
						}


						if ( $api_response->order->cart->order_reference != $front_end_transaction->cart->order_reference ) {
							throw new \Exception( "Mismatched order references.\n\nBolt transaction:\n" . json_encode( $api_response, JSON_PRETTY_PRINT ) . "\n\nWeb Client transaction:\n" . json_encode( $front_end_transaction ) );
						}
					} else {
						throw new \Exception( __( 'We were unable to process your order, please try again.', 'woocommerce' ) );
					}
				}
			}
			###############################################

			$this->setup_common_data( $api_response );

			///////////////////////////////////////////////////////
			// Retrieve the immutable cart data by order reference
			// and reset the cart to match Bolt order creation time
			///////////////////////////////////////////////////////
			$set_current_user = isset( $_POST['orphaned_transaction'] );
			$set_cart_result  = set_cart_by_bolt_reference( static::$bolt_transaction->order->cart->order_reference, $set_current_user );

			///////////////////////////////////////////////////////
			// If this is an order payment from "invoice for order" email,
			// we would bypass all the process related to wc cart and order creation
			// then directly go to complete the payment
			///////////////////////////////////////////////////////
			if ( $set_cart_result === 'orderinvoice' ) {
				return $this->process_pay_invoiceemail( intval( static::$bolt_transaction->order->cart->order_reference ) );
			}

			// Check if cart is empty or not.
			if ( WC()->cart->is_empty() ) {
				throw new \Exception( 'Empty cart' );
			}

			$shipping_method = $this->handle_shipping_method();
			$this->simulate_native_wc_post_data( $shipping_method );

		} catch ( \Exception $e ) {
			///////////////////////////////////////////////////////////////////////////
			// we call save_order both for ajax and api, so we have to determines
			// whether the current request is a wordpress Ajax request to properly
			// format the error response
			///////////////////////////////////////////////////////////////////////////
			if ( wp_doing_ajax() ) { // now we are in ajax request
				BugsnagHelper::notifyException( $e );
				wc_add_notice( $e->getMessage(), WC_NOTICE_TYPE_ERROR );
				$this->send_ajax_failure_response();
			} else { // now we are in a api request
				throw $e;
			}
		}
		///////////////////////////////////////////////////////////////////////////
		// Attempt to process the checkout via WooCommerce framework.
		return $this->process_checkout( array( $shipping_method ) );
	}

	/**
	 * Save order for Bolt pre-auth
	 *
	 * @since 2.2.0
	 */
	private function save_order_pre_auth( $api_response = null ) {
		try {
			$this->setup_common_data( $api_response );
			// Check if cart is empty or not.
			if ( WC()->cart->is_empty() ) {
				throw new \Exception( 'Empty cart' );
			}
			$shipping_method = $this->handle_shipping_method();
			$this->simulate_native_wc_post_data( $shipping_method );
		} catch ( \Exception $e ) {
			throw $e;
		}

		///////////////////////////////////////////////////////////////////////////
		// Attempt to process the checkout via WooCommerce framework.
		return $this->process_checkout( array( $shipping_method ) );
	}

	/**
	 * Setup some common data for saving order
	 *
	 * @since 2.2.0
	 */
	private function setup_common_data( $api_response ) {
		static::$bolt_transaction = apply_filters( 'wc_bolt_update_checkout_api_response', $api_response );

		// For both ajax call and orphaned_transaction, use a one-time item in post data to establish
		// the function save_order is the appropriate entry to process checkout with Bolt.
		// And it only take effect in Bolt checkout process.
		$_POST['in_bolt_checkout'] = true;

		if ( ! defined( 'WOOCOMMERCE_CHECKOUT' ) ) {
			define( 'WOOCOMMERCE_CHECKOUT', true );
		}
	}

	/**
	 * Handle shipping method for saving order
	 *
	 * @since 2.2.0
	 */
	private function handle_shipping_method() {
		# TODO: Must verify what this data looks like when there is no shipping method applied
		$shipping_method = ! empty( static::$bolt_transaction->order->cart->shipments ) ? static::$bolt_transaction->order->cart->shipments[0]->reference : false;

		# We'll also calculate real-time shipping quotes and store them for future reference, if needed
		#
		# Bolt server doesn't support multiple packages yet, bolt order contains only one shipping method
		# However woocommerce session in this case contains all packages
		# So we shouldn't override chosen_shipping_methods if there are multiple packages.

		if ( ! wc_bolt_if_cart_has_multiple_packages()
		     && $shipping_method && ( $shipping_method !== 'no_shipping_required' ) ) {
			wc_bolt_set_chosen_shipping_method_for_first_package( $shipping_method );
			$method_counts = WC()->session->get( 'shipping_method_counts' );

			if ( $method_counts ) {
				$method_counts = maybe_unserialize( $method_counts );
				WC()->session->set( 'shipping_method_counts', $method_counts );
			}
		}

		return $shipping_method;
	}

	/**
	 * Mimic the expected checkout page POST data state so the we can use the native
	 * WooCommerce order creation methods for cart and product page checkout
	 *
	 * @since 2.2.0
	 */
	private function simulate_native_wc_post_data( $shipping_method ) {
		// If a user isn't on checkout page (on cart page, or on product page checkout)
		if ( ! @$_POST['checkout'] ) {
			$this->set_post_array_from_bolt_transaction( $shipping_method );
		}
	}

	/**
	 * Filter: woocommerce_payment_successful_result
	 *
	 * Sets the redirect url for forwarding after order save.
	 *
	 * @param array $result An array that will be the json result returned on an ajax call to WC order save
	 * @param int $order_id The id of the recently created order
	 *
	 * @return array An array that will be the json result returned on an ajax call to WC order save
	 */
	public function create_redirect_url( $result, $order_id ) {
		#If the customer choose other payment method to checkout, then we should not do our process
		$order                = wc_get_order( $order_id );
		$order_payment_method = $order->get_payment_method();
		if ( empty( $order_payment_method ) || $order_payment_method !== BOLT_GATEWAY_NAME ) {
			return $result;
		}
		BugsnagHelper::initBugsnag();
		#############################
		#Due to some bad coding in 3rd party plugin (it directly echo error message
		#in the catch block), when wp_send_json output the result, some non-json formatted messages
		#would be added into the output and send to client, make our ajax fail
		#then we add code here to force to flush the output buffer before wp_send_json.
		#log error in backend with bugsnag
		#############################

		Bolt_HTTP_Handler::clean_buffers( true );

		return $result;
	}


	/**
	 * Saves information associated with a Bolt transaction to the order
	 *
	 * Filter: woocommerce_checkout_order_processed
	 *
	 * @param int $order_id The id of the newly created order
	 * @param array $posted_data Cart data that was used to create the order
	 * @param WC_Order $order Order object of the newly created order
	 *
	 * @throws Exception      thrown when the price returned from Bolt differs by more
	 *                        then $0.10 from the expected price
	 */
	public function save_transaction( $order_id, $posted_data, $order = null ) {
		if ( empty( $order ) ) {
			$order = wc_get_order( $order_id );
		}
		#If the customer choose other payment method to checkout, then we should not do our process
		$order_payment_method = $order->get_payment_method();
		if ( empty( $order_payment_method ) || $order_payment_method !== BOLT_GATEWAY_NAME ) {
			return;
		}
		BugsnagHelper::initBugsnag();
		$this->order_created = $order;
		if ( $this->is_in_pre_auth ) {
			$this->save_transaction_pre_auth( $order_id );
		} else {
			$this->save_transaction_non_pre_auth( $order_id );
		}
	}

	/**
	 * Since Bolt does not support multiple shipping packages, only get the first available shipping method of order.
	 *
	 * @since 2.2.0
	 */
	private function get_order_shipping_method() {
		$shipping_methods = $this->order_created->get_shipping_methods();
		if ( $shipping_methods ) {
			/* @var WC_Order_Item_Shipping $shipping_method */
			$shipping_method = current( $shipping_methods );
		} else {
			$shipping_method = new \WC_Order_Item_Shipping();
		}

		return $shipping_method;
	}

	/**
	 * Re-calculate order shipping cost
	 *
	 * @since 2.2.0
	 */
	private function recalculate_order_shipping() {
		$bolt_transaction = static::$bolt_transaction;

		$shipping_method = $this->get_order_shipping_method();

		// override shipping method only if it necessary, if cost or tax differ
		//
		// Bolt server doesn't support multiple packages yet, bolt order contains only one shipping method
		// So we shouldn't override shipping method if there are multiple packages.
		if ( ! wc_bolt_if_order_has_multiple_packages( $this->order_created )
		     && $this->check_if_need_override_shipping( $bolt_transaction, $this->order_created, $shipping_method ) ) {
			BugsnagHelper::notifyException(
				new \Exception( "Need to override shipping" ),
				array(
					'woocommerce' => print_r( $shipping_method, true ),
					'bolt'        => $bolt_transaction->order->cart->shipments[0]
				), 'info' );
			$shipping_method->set_method_id( $bolt_transaction->order->cart->shipments[0]->reference );
			$shipping_method->set_name( $bolt_transaction->order->cart->shipments[0]->service );
			$currency_divider = get_currency_divider();
			$shipping_method->set_total( $bolt_transaction->order->cart->shipments[0]->cost->amount / $currency_divider );
			if ( isset( $bolt_transaction->order->cart->shipments[0]->tax_amount ) && ! empty( $bolt_transaction->order->cart->shipments[0]->tax_amount ) ) {
				$bolt_shipping_tax = $bolt_transaction->order->cart->shipments[0]->tax_amount;
			}
			if ( isset( $bolt_shipping_tax ) && isset( $bolt_shipping_tax->amount ) && $bolt_shipping_tax->amount > 0 ) {
				$shipping_method->set_taxes( array( "total" => $bolt_shipping_tax->amount / $currency_divider - $this->order_created->get_cart_tax() ) );
			}
			$shipping_method->save();
			if ( ! $shipping_method->get_order_id() ) {
				$this->order_created->add_item( $shipping_method );
			}
			$this->order_created->calculate_shipping();
			$this->order_created->calculate_totals();

			//Support third party discounts module if we needed to calculate_totals

			//Due to the special logic of yith gift card, we have to eliminate the applied gift card amount
			apply_yith_ywgc_premium_discount( $this->order_created );

			//for compatibility with module PW WooCommerce Gift Cards
			//If calculate_totals doesn't take in account gift cart and set wrong total_amount
			//then module PW WooCommerce Gift Cards in the hook woocommerce_update_order
			//and now object $order refer to obsolete data
			$this->order_created = wc_get_order( $this->order_created->get_id() );
		}
	}

	/**
	 * Re-calculate order tax
	 *
	 * @since 2.2.0
	 */
	private function recalculate_order_tax() {
		$bolt_taxes = $this->get_tax_total_by_bolt_transaction( static::$bolt_transaction );

		//////////////////////////////////////////////////////////////////////////////////////
		// When calculating shipping tax for cart, woocommerce would consider the tax status,
		// and ignore tax calculation if the tax status is none. But when re-calculating the
		// order total after order created, it would calculate the tax of order shipping item,
		// in that way tax status would not take effect, and if the merchant enables shipping
		// in the rule of tax rate, the shipping tax amount of order can be different from the
		// cart, so we have to reset the tax amount as the cart
		//////////////////////////////////////////////////////////////////////////////////////
		if ( convert_monetary_value_to_bolt_format( wc_bolt_get_order_tax_total( $this->order_created ) ) != $bolt_taxes ) {
			$pre_shipping_tax = $this->order_created->get_shipping_tax();
			$shipping_tax     = ( $bolt_taxes - convert_monetary_value_to_bolt_format( $this->order_created->get_cart_tax() ) ) / get_currency_divider();
			$shipping_method  = $this->get_order_shipping_method();
			$shipping_method->set_taxes( array( "total" => array( 0 => $shipping_tax ) ) );
			$shipping_method->save();

			$this->order_created->update_taxes();
			$this->order_created->set_total( $this->order_created->get_total() - $pre_shipping_tax + $shipping_tax );
		}
	}

	/**
	 * Set order customer note by the user note from Bolt transaction
	 *
	 * @since 2.2.0
	 */
	private function set_order_customer_note() {
		// Add the customer note to admin
		if ( @static::$bolt_transaction->order->user_note ) {
			$this->order_created->set_customer_note( static::$bolt_transaction->order->user_note );
		}
	}

	/**
	 * Update order data from Bolt transaction
	 *
	 * @since 2.2.0
	 */
	private function update_order_for_saving_transaction() {
		###########################################################################
		# Here we add re-calculated shipping and tax cost
		# Though it is not known to exist, there is potential that discounts
		# and other cart price variations may occur due to adding shipping cost.
		#
		# Here we would have a problem.  It is no longer possible to simply fail the order
		# because Bolt has already collected the customer's money.
		#
		#  Failing for rounding is handled in **Bolt_Data_Collector::buildCart**
		#  before the order is paid.
		#
		# At this point, if there is a price problem, we still must create the order
		# and inform the merchant that there is a price difference.  Because we are using
		# the same snapshot of the cart that existed at the time of Bolt order creation,
		# the only possible problem can be after new shipping and tax calculation.
		#
		# TODO: consider adding this before the initial order save to improve efficiency
		#       If allowed by WooCommmerce validation, this also would eliminate any chance
		#       of mismatched order data being saved to WooCommerce that could happen if any
		#       of these steps failed.
		###########################################################################

		$this->recalculate_order_shipping();
		$this->recalculate_order_tax();
		$this->set_order_customer_note();

		$this->order_created->save();
	}

	/**
	 * Get order total from Bolt transaction
	 *
	 * @since 2.2.0
	 */
	private function get_bolt_order_total() {
		$amount = isset( static::$bolt_transaction->amount->amount ) ? static::$bolt_transaction->amount->amount : static::$bolt_transaction->order->cart->total_amount->amount;

		return $amount / get_currency_divider();
	}

	/**
	 * Calculate price difference of order total between WC and Bolt transaction
	 *
	 * @since 2.2.0
	 */
	private function calculate_order_price_difference() {
		// TODO: move the support for yith gift card into the hook of this filter.
		$price_difference = apply_filters( 'wc_bolt_price_difference_recalculation_in_order', ( $this->order_created->get_total() - $this->get_bolt_order_total() ), $this->order_created, static::$bolt_transaction );

		return $price_difference;
	}

	/**
	 * Log on bugsnag if there is price difference
	 *
	 * @since 2.2.0
	 */
	private function send_order_price_difference_to_bugsnag( $order_id, $price_difference ) {
		$ex_msg = sprintf( __( 'Order ID : %s. Bolt Total and WooCommerce total difference (%s) resolved in tax.  Bolt total: [%s], WooCommerce total: [%s]', 'woocommerce-bolt-payment-gateway' ), $order_id, $price_difference, $this->get_bolt_order_total(), $this->order_created->get_total() );
		BugsnagHelper::notifyException( new \Exception( $ex_msg ) );
	}

	/**
	 * Saves information associated with a Bolt transaction to the order for non-pre-auth
	 *
	 * @since 2.2.0
	 */
	private function save_transaction_non_pre_auth( $order_id ) {
		# add bolt transaction data to the order
		$this->order_created->set_transaction_id( static::$bolt_transaction->reference );
		wc_bolt_update_order_meta( $this->order_created, BOLT_ORDER_META_TRANSACTION_REFERENCE_ID, static::$bolt_transaction->reference );
		wc_bolt_update_order_meta( $this->order_created, BOLT_ORDER_META_TRANSACTION_ID, static::$bolt_transaction->id );
		$this->order_created->set_payment_method( BOLT_GATEWAY_NAME );
		$settings = wc_bolt()->get_settings();
		$this->order_created->set_payment_method_title( $settings[ Bolt_Settings::SETTING_NAME_PAYMENT_METHOD_TITLE ] );
		$this->update_order_for_saving_transaction();

		$price_difference = $this->calculate_order_price_difference();

		if ( ( (int) abs( $price_difference ) ) > 0 ) {
			$this->send_order_price_difference_to_bugsnag( $order_id, $price_difference );
			// Ignore price difference if Bolt has already marked status cancelled, failed or rejected_irreversible.
			$ignore_price_difference_statuses = array(
				BOLT_TRANSACTION_STATUS_CANCELLED,
				BOLT_TRANSACTION_STATUS_FAILED,
				BOLT_TRANSACTION_STATUS_IRREVERSIBLE
			);
			if ( ! in_array( static::$bolt_transaction->status, $ignore_price_difference_statuses ) ) {
				$dashboard_url = wc_bolt()->get_bolt_settings()->get_merchant_dashboard_host() . '/transaction/' . static::$bolt_transaction->reference;
				$note          = 'THERE IS A DIFFERENCE IN THE BOLT PAID TOTAL OF ' . $this->get_bolt_order_total()
				                 . ' AND THE WOOCOMMERCE EXPECTED TOTAL OF ' . $this->order_created->get_total() . ', PLEASE REVIEW ORDER DETAILS
                         AT <a href="' . $dashboard_url . '">' . $dashboard_url . '</a> BEFORE PROCESSING THIS ORDER.';
				wc_reduce_stock_levels( $order_id );
				$this->order_created->update_status( WC_ORDER_STATUS_ON_HOLD, $note );
			}
		} else {
			// By default the woocommerce order status is set to pending when created initially,
			// so we have to invokes all functions attached to action hook `woocommerce_order_status_pending` explicitly.
			// This is useful to lock the one-time usage coupon and etc.
			if ( static::$bolt_transaction->status == BOLT_TRANSACTION_STATUS_PENDING && $this->order_created->get_status() == WC_ORDER_STATUS_PENDING ) {
				$order = wc_get_order( $order_id );
				do_action( 'woocommerce_order_status_pending', $order_id, $order );
			} else {
				update_transaction_status( $this->order_created, static::$bolt_transaction->status );
			}
		}
	}

	/**
	 * Saves information associated with a Bolt transaction to the order for pre-auth
	 *
	 * @since 2.2.0
	 */
	private function save_transaction_pre_auth( $order_id ) {
		# add bolt transaction data to the order
		$this->order_created->set_payment_method( BOLT_GATEWAY_NAME );
		$settings = wc_bolt()->get_settings();
		$this->order_created->set_payment_method_title( $settings[ Bolt_Settings::SETTING_NAME_PAYMENT_METHOD_TITLE ] );
		$this->update_order_for_saving_transaction();

		$price_difference = $this->calculate_order_price_difference();

		if ( ( (int) abs( $price_difference ) ) > 0 ) {
			$this->send_order_price_difference_to_bugsnag( $order_id, $price_difference );
			$note = 'THERE IS A DIFFERENCE IN THE BOLT PAID TOTAL OF ' . $this->get_bolt_order_total()
			        . ' AND THE WOOCOMMERCE EXPECTED TOTAL OF ' . $this->order_created->get_total() . ', PLEASE REVIEW ORDER DETAILS BEFORE PROCESSING THIS ORDER.';

			wc_reduce_stock_levels( $order_id );
			$this->order_created->update_status( WC_ORDER_STATUS_ON_HOLD, $note );
		}
	}

	/**
	 * Process the payment if pay via invoice email.
	 *
	 * @inheritdoc
	 * @throws Exception
	 * @var string $order_id
	 */
	public function process_pay_invoiceemail( $order_id ) {
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				throw new \Exception( 'Order is invalid' );
			}
			#######################################################################
			# By default, woocommerce would list all the available payment gateways
			# on the order pay page even the shop manager manually choose a payment
			# gateway for this order. Then If the customer chooses the Bolt to complete
			# the order, we should update the payment method for the order to Bolt.
			# If not, the save_transaction method does nothing and directly returns.
			###########################################################
			if ( $order->get_payment_method() !== BOLT_GATEWAY_NAME ) {
				$order->set_payment_method( BOLT_GATEWAY_NAME );
				$settings = wc_bolt()->get_settings();
				$order->set_payment_method_title( $settings[ Bolt_Settings::SETTING_NAME_PAYMENT_METHOD_TITLE ] );
				$order->save();
			}

			$this->save_transaction( $order_id, array(), $order );

			return $this->process_order_payment( $order_id, BOLT_GATEWAY_NAME );
		} catch ( \Exception $e ) {
			throw $e;
		}
	}

	/**
	 * Process the checkout.
	 *
	 * @inheritdoc
	 * @var array $shipping_methods The shipping methods chosen for this order
	 */
	public function process_checkout( $shipping_methods = null ) {
		if ( $this->is_in_pre_auth ) {
			return $this->process_checkout_pre_auth( $shipping_methods );
		} else {
			return $this->process_checkout_non_pre_auth( $shipping_methods );
		}
	}

	/**
	 * Get posted data from POST data.
	 *
	 * @return array of data.
	 * @since  2.3.0
	 */
	private function prepare_posted_data() {
		try {
			wc_maybe_define_constant( 'WOOCOMMERCE_CHECKOUT', true );
			wc_set_time_limit( 0 );

			do_action( 'woocommerce_before_checkout_process' );

			if ( WC()->cart->is_empty() ) {
				throw new \Exception( "The cart is empty." );
			}

			do_action( 'woocommerce_checkout_process' );

			#############################################################
			# Code below is a workaround for known woocommerce issue:
			# When error message is added for the first time, in some php environment
			# it would result in the error 'cannot use string offset as an array'.
			# To avoid this, we create an empty array here and update wc_set_notices
			##############################################################
			$notices = wc_get_notices();
			$notices = (array) $notices;
			if ( ! isset( $notices[ WC_NOTICE_TYPE_ERROR ] ) ) {
				$notices[ WC_NOTICE_TYPE_ERROR ] = array();
				wc_set_notices( $notices );
			}

			$posted_data = $this->get_posted_data();

			return $posted_data;

		} catch ( \Exception $e ) {
			throw $e;
		}
	}

	/**
	 * This function is for some common process of Bolt pre-auth&non-pre-auth checkout.
	 *
	 * @since 2.2.0
	 */
	private function before_validate_checkout( $posted_data, $shipping_methods = null ) {
		try {
			// Update session for customer and totals.
			// update_session method includes WC cart calculation,
			// so we need add filter to calculate in safe way
			do_action( 'wc_bolt_before_update_session_in_checkout', $posted_data, $shipping_methods );
			Bolt_woocommerce_cart_calculation::add_filter();
			$this->update_session( $posted_data );
			Bolt_woocommerce_cart_calculation::remove_filter();

			# Bolt server doesn't support multiple packages yet, bolt order contains only one shipping method
			# However woocommerce session in this case contains all packages
			# So we shouldn't override chosen_shipping_methods if there are multiple packages.
			if ( $shipping_methods && ! wc_bolt_if_cart_has_multiple_packages() ) {
				wc_bolt_set_chosen_shipping_method_for_first_package( $shipping_methods[0] );
			}
		} catch ( \Exception $e ) {
			throw $e;
		}
	}

	/**
	 * Validate the shipping&billing address in posted data.
	 *
	 * @return boolean True if validation succeeds, otherwise false.
	 * @since 2.3.0
	 */
	private function validate_address_in_posted_data( $posted_data ) {
		// We can have error in address data, because for apple pay we didn't have
		// opportunity to check adddress on shipping&tax step (Canada, UK, etc)

		// TODO change validate_address to return all errors
		$original_session_data = wc_bolt_data()->get_session( BOLT_PREFIX_SESSION_DATA . static::$bolt_transaction->order->cart->order_reference );
		$is_apple_pay          = isset( $original_session_data['bolt_apply_pay'] ) && ( $original_session_data['bolt_apply_pay'] == static::$bolt_transaction->order->token );
		if ( $error_shipping_msg = bolt_addr_helper()->validate_address( $posted_data, WC_SHIPPING_PREFIX, $is_apple_pay ) ) {
			$this->error_handler->handle_error( E_BOLT_WRONG_ADDRESS, (object) array(
				BOLT_ERR_REASON            => $error_shipping_msg,
				BOLT_CART_SHIPPING_ADDRESS => bolt_addr_helper()->get_address( $posted_data, WC_SHIPPING_PREFIX )
			) );
		}
		if ( $error_billing_msg = bolt_addr_helper()->validate_address( $posted_data, WC_BILLING_PREFIX, $is_apple_pay ) ) {
			if ( $error_shipping_msg != $error_billing_msg ) {
				$this->error_handler->handle_error( E_BOLT_WRONG_ADDRESS, (object) array(
					BOLT_ERR_REASON           => $error_billing_msg,
					BOLT_CART_BILLING_ADDRESS => bolt_addr_helper()->get_address( $posted_data, WC_BILLING_PREFIX )
				) );
			}
		}

		return ! ( $error_shipping_msg || $error_billing_msg );
	}

	/**
	 * WP rocket plugin clears the cache when we update any user.
	 * They do it in case user privileges are changed.
	 * We change only user address so we can prevent cache clearing.
	 * Otherwise Woocommerce can do the same calculation when we call WC()->cart->calculate_totals()
	 *
	 * @since 2.2.0
	 */
	private function empty_wp_rocket_cache() {
		if ( WP_Rocket_Helper::is_enabled() ) {
			WP_Rocket_Helper::remove_empty_cache_on_user_change();
		}
	}

	/**
	 * Process the order for Bolt pre-auth.
	 *
	 * @since 2.2.0
	 */
	private function process_checkout_pre_auth( $shipping_methods = null ) {
		try {
			$posted_data = $this->prepare_posted_data();
			if ( ! $this->validate_address_in_posted_data( $posted_data ) ) {
				return false;
			}
			$this->before_validate_checkout( $posted_data, $shipping_methods );

			// WP_Error object to catch any errors during checkout
			$errors = new \WP_Error();

			// Validate posted data and cart items before proceeding.
			$this->validate_checkout( $posted_data, $errors );
			if ( $errors->get_error_messages() ) {
				foreach ( $errors->get_error_messages() as $message ) {
					$this->error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array( BOLT_ERR_REASON => $message ) );
				}

				return false;
			}

			if ( empty( $posted_data['woocommerce_checkout_update_totals'] ) ) {
				$this->empty_wp_rocket_cache();

				$this->check_if_user_just_created( $posted_data, static::$bolt_transaction->order->cart->order_reference );

				$this->process_customer( $posted_data );

				$order_reference = static::$bolt_transaction->order->cart->order_reference;
				if ( ! $this->compare_with_bolt_data_before_order_creation( $shipping_methods ) ) {
					return false;
				}

				if ( ! $this->acquire_order_creation_lock( $order_reference ) ) {
					$this->error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array( BOLT_ERR_REASON => 'Can not acquire lock before order creation' ) );

					return false;
				}
				$result = $this->check_if_order_exists_by_order_reference_and_shipping( $order_reference, static::$bolt_transaction->order->cart->shipments[0] );
				if ( $this->error_handler->has_error() ) {
					$this->release_order_creation_lock( $order_reference );

					return $this->error_handler->build_error();
				}
				if ( $result instanceof \WP_REST_Response ) {
					$this->release_order_creation_lock( $order_reference );

					return $result;
				}

				WC_Bolt_Hook_Manager::postpone_order_creation_hooks();
				$order_id = $this->create_order( $posted_data );
				if ( ! $this->release_order_creation_lock( $order_reference ) ) {
					BugsnagHelper::notifyException(
						new \Exception( 'Can not release resource after order creation' ),
						array(
							'order_id'                => $order_id,
							BOLT_CART_ORDER_REFERENCE => $order_reference
						),
						'warning'
					);
				}

				if ( is_wp_error( $order_id ) ) {
					throw new \Exception( $order_id->get_error_message() );
				}

				$order = wc_get_order( $order_id );

				$formatted_order = wc_bolt()->get_bolt_data_collector()->format_order_as_bolt_cart( $order_id, static::$bolt_transaction->order->cart->order_reference );

				static::$order_id    = $order_id;
				static::$posted_data = $posted_data;

				// In some cases we can finish work around order creation asynchronously
				if ( apply_filters( 'wc_bolt_should_postpone_order_creation_hooks', false ) ) {
					// order is created, we are ready to return answer and do rest of work asynchronously
					add_action( 'shutdown', array( $this, 'finish_order_creation_asynchronously' ), 0 );
				} else {
					$this->process_hooks_after_order_creation();
				}

				return Bolt_HTTP_Handler::prepare_http_response(
					array(
						BOLT_FIELD_NAME_STATUS    => BOLT_STATUS_SUCCESS,
						BOLT_CART_DISPLAY_ID      => $order->get_order_number(),
						'total'                   => convert_monetary_value_to_bolt_format( $order->get_total() ),
						BOLT_CART_ORDER_REFERENCE => static::$bolt_transaction->order->cart->order_reference,
						'order_received_url'      => $order->get_checkout_order_received_url(),
						'order'                   => $formatted_order
					),
					200
				);
			}

		} catch ( \Exception $e ) {
			$this->error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array( BOLT_ERR_REASON => $e->getMessage() ) );

			return Bolt_HTTP_Handler::prepare_http_response(
				array(
					BOLT_FIELD_NAME_STATUS => BOLT_STATUS_FAILURE,
					BOLT_FIELD_NAME_ERROR  => array( BOLT_FIELD_NAME_ERROR_MESSAGE => $e->getMessage() )
				),
				HTTP_STATUS_UNPROCESSABLE
			);
		}
	}

	/**
	 * Process the order for Bolt non-pre-auth.
	 *
	 * @since 2.2.0
	 */
	private function process_checkout_non_pre_auth( $shipping_methods = null ) {
		try {
			$posted_data = $this->prepare_posted_data();
			$this->before_validate_checkout( $posted_data, $shipping_methods );

			//WP_Error object to catch any errors during checkout
			$errors = new \WP_Error();
			// Validate posted data and cart items before proceeding.
			$this->validate_checkout( $posted_data, $errors );
			if ( $errors->get_error_messages() ) {
				foreach ( $errors->get_error_messages() as $message ) {
					BugsnagHelper::notifyException( new \Exception( 'Validate checkout error:' . $message ) );
					wc_add_notice( $message, WC_NOTICE_TYPE_ERROR );
				}
			}

			if ( empty( $posted_data['woocommerce_checkout_update_totals'] ) && 0 === wc_notice_count( WC_NOTICE_TYPE_ERROR ) ) {
				$this->empty_wp_rocket_cache();

				$this->process_customer( $posted_data );

				$order_reference = static::$bolt_transaction->order->cart->order_reference;

				if ( ! $this->acquire_order_creation_lock( $order_reference ) ) {
					throw new \Exception( 'Can not acquire lock before order creation' );
				}

				$order_id = $this->create_order( $posted_data );
				if ( ! $this->release_order_creation_lock( $order_reference ) ) {
					BugsnagHelper::notifyException(
						new \Exception( 'Can not release resource after order creation' ),
						array(
							'order_id'                => $order_id,
							BOLT_CART_ORDER_REFERENCE => $order_reference
						),
						'warning'
					);
				}

				$this->destroy_bolt_order_details_unique_data( $order_reference );

				if ( is_wp_error( $order_id ) ) {
					throw new \Exception( $order_id->get_error_message() );
				}

				$order = wc_get_order( $order_id );

				do_action( 'woocommerce_checkout_order_processed', $order_id, $posted_data, $order );
				if ( WC()->cart->needs_payment() ) {
					return $this->process_order_payment( $order_id, $posted_data[ WC_PAYMENT_METHOD ] );
				} else {
					return $this->process_order_without_payment( $order_id );
				}
			}

		} catch ( \Exception $e ) {
			wc_add_notice( $e->getMessage(), WC_NOTICE_TYPE_ERROR );
		}

		#############################################################
		#  If we reached here, it means that we have failed to create
		#  an order and we will respect WooCommerce's decision.
		#  We must now inform the user of the problems and Bugsnag
		##############################################################
		$error_msg = 'Fail to create order';
		if ( $notices = wc_get_notices( WC_NOTICE_TYPE_ERROR ) ) {
			$error_msg = '';
			foreach ( $notices as $notice ) {
				// WooCommerce notice has different structures in different versions.
				$error_msg .= wp_kses_post( get_wc_notice_message( $notice ) );
			}
		}

		if ( wp_doing_ajax() ) { // now we are in ajax request
			BugsnagHelper::notifyException( new \Exception( $error_msg ) );
			$this->send_ajax_failure_response();
		} else { // now we are in a api request
			throw new \Exception( $error_msg );
		}
	}

	/**
	 * Close HTTP connection and do rest of work around order creation process after we sent answer to server
	 *
	 * @since 2.0.11
	 */
	public function finish_order_creation_asynchronously() {
		Bolt_HTTP_Handler::close_connection();
		$this->process_hooks_after_order_creation();
	}

	/**
	 * Do some hooks after we created order
	 *
	 * @since 2.0.11
	 */
	private function process_hooks_after_order_creation() {
		$order = wc_get_order( static::$order_id );
		do_action( 'woocommerce_checkout_order_processed', static::$order_id, static::$posted_data, $order );
		// Invokes all functions attached to action hook `woocommerce_order_status_pending` explicitly.
		// Lock coupon usage and etc.
		do_action( 'woocommerce_order_status_pending', static::$order_id, $order );
		WC_Bolt_Hook_Manager::execute_order_creation_hooks( static::$order_id );
		if ( convert_monetary_value_to_bolt_format( $order->get_total() ) <> static::$bolt_transaction->order->cart->total_amount->amount ) {
			$formatted_order = wc_bolt()->get_bolt_data_collector()->format_order_as_bolt_cart( static::$order_id, static::$bolt_transaction->order->cart->order_reference );
			BugsnagHelper::notifyException(
				new \Exception( 'Difference between woocommerce order total and bolt order total' ),
				array(
					'order_id'                => static::$order_id,
					BOLT_CART_ORDER_REFERENCE => static::$bolt_transaction->order->cart->order_reference,
					'woocommerce_order_total' => $order->get_total(),
					'bolt_total'              => static::$bolt_transaction->order->cart->total_amount->amount,
					'woocommerce_order'       => $formatted_order
				)
			);
		}
	}

	/**
	 * Process before create the order to see if this is a duplicate order
	 *
	 * When the customer finish payment, if api handler (especially an 'auth' call)
	 * and ajax call from save_checkout function are invoked at the same time, the two
	 * entries both try to create order, and would result duplicated orders
	 * so we check if the bolt_transaction_reference_id already belong to an order before
	 * creating real order in action woocommerce_checkout_create_order.
	 * If this exist, we respond with a json success and order success page url and exit.
	 * If this does not exist, we continue normal order creation flow.
	 *
	 * @see handle_bolt_endpoint
	 *
	 * Action: woocommerce_checkout_create_order
	 *
	 */
	public function check_if_duplicate_order() {
		if ( ! $this->is_in_pre_auth && static::$bolt_transaction ) {
			$order_id = wc_bolt_data()->get_order_id_by_reference( static::$bolt_transaction->reference );
			if ( ! is_wp_error( $order_id ) && $order_id ) {
				// The checkout_order_received_url should be get from the created order, not the order in the arguments
				$existing_order = wc_get_order( $order_id );
				$result         = array(
					'order_id'     => $order_id,
					'result'       => 'success',
					'redirect_url' => $existing_order->get_checkout_order_received_url()
				);
				wp_send_json( $result );
			}
		}
	}

	/**
	 * Add order number to the display id of Bolt transaction
	 *
	 * Filter: woocommerce_payment_successful_result
	 *
	 * @param array $result Order creation result.
	 * @param int $order_id Order ID.
	 *
	 * @return array
	 * @access public
	 */
	public function add_order_number_to_display_id( $result, $order_id ) {
		$order                          = wc_get_order( $order_id );
		$result[ BOLT_CART_DISPLAY_ID ] = $order->get_order_number();

		return $result;
	}

	/**
	 * Work with the Bolt event onEmailEnter, and save the email into cart seesion so that Bolt can capture the abandonded cart
	 */
	public function save_email() {
		BugsnagHelper::initBugsnag();
		try {
			if ( isset( $_POST['email'] ) && isset( $_POST[ BOLT_CART_ORDER_REFERENCE ] ) && is_email( $_POST['email'] )
			     && wc_bolt_data()->get_session( BOLT_PREFIX_SESSION_DATA . $_POST[ BOLT_CART_ORDER_REFERENCE ] )
			) {

				WC()->cart->get_customer()->set_billing_email( $_POST['email'] );
				WC()->cart->get_customer()->save();
				WC()->cart->set_session(); # we should force to refresh cart session before update
				WC()->session->save_data(); # we should force to refresh WC session before update
				wp_send_json( array(
					'result' => 'success',
				) );

			} else {
				$this->send_ajax_failure_response();
			}
		} catch ( \Exception $e ) {
			BugsnagHelper::notifyException( $e );
			$this->send_ajax_failure_response();
		}
	}

	/**
	 * Get the bolt transaction info for the extensions
	 */
	public static function get_bolt_transaction() {
		return static::$bolt_transaction;
	}

	/**
	 * Process an order that does require payment.
	 *
	 * @param int $order_id Order ID.
	 * @param string $payment_method Payment method.
	 *
	 * @since 1.1.5
	 * @access public
	 *
	 */
	protected function process_order_payment( $order_id, $payment_method ) {
		$available_gateways = WC()->payment_gateways->get_available_payment_gateways();

		if ( ! isset( $available_gateways[ $payment_method ] ) ) {
			return;
		}

		// Store Order ID in session so it can be re-used after payment failure.
		WC()->session->set( 'order_awaiting_payment', $order_id );

		// Process Payment.
		$result = $available_gateways[ $payment_method ]->process_payment( $order_id );

		// Redirect to success/confirmation/payment page.
		if ( isset( $result['result'] ) && 'success' === $result['result'] ) {
			$result = apply_filters( 'woocommerce_payment_successful_result', $result, $order_id );
			// For orphaned transaction we need to return WP_REST_Response object as response
			if ( @$_POST['orphaned_transaction'] ) {
				$result[ BOLT_FIELD_NAME_STATUS ] = BOLT_STATUS_SUCCESS;

				return Bolt_HTTP_Handler::prepare_http_response(
					$result,
					HTTP_STATUS_OK
				);
			} elseif ( ! is_ajax() ) {
				wp_redirect( $result['redirect'] );
				exit;
			}

			wp_send_json( $result );
		}
	}

	/**
	 * Process an order that doesn't require payment.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @since 1.1.5
	 * @access public
	 *
	 */
	protected function process_order_without_payment( $order_id ) {
		$order                 = wc_get_order( $order_id );
		$original_wc_total     = $order->get_total();
		$bolt_price_difference = ( static::$bolt_transaction->amount->amount / get_currency_divider() ) - $original_wc_total;
		if ( empty( $bolt_price_difference ) ) {
			$order->payment_complete();
		}
		wc_empty_cart();

		// For orphaned transaction we need to return WP_REST_Response object as response
		if ( @$_POST['orphaned_transaction'] ) {
			return Bolt_HTTP_Handler::prepare_http_response(
				array( BOLT_FIELD_NAME_STATUS => BOLT_STATUS_SUCCESS ),
				HTTP_STATUS_OK
			);
		} elseif ( ! is_ajax() ) {
			wp_safe_redirect(
				apply_filters( 'woocommerce_checkout_no_payment_needed_redirect', $order->get_checkout_order_received_url(), $order )
			);
			exit;
		}

		wp_send_json(
			array(
				'result'   => 'success',
				'redirect' => apply_filters( 'woocommerce_checkout_no_payment_needed_redirect', $order->get_checkout_order_received_url(), $order ),
			)
		);
	}

	/**
	 * TODO : Move to bolt-checkout-functions.php
	 * Remove `cancel` action from the list of account orders actions
	 *
	 * @param array $actions List of account orders actions.
	 * @param object $order WC Order object.
	 *
	 * @return array
	 * @since 1.2.8
	 * @access public
	 *
	 */
	public function remove_cancel_action( $actions, $order ) {
		#If the customer choose other payment method to checkout, then we should not do our process
		$order_payment_method = $order->get_payment_method();
		if ( $order_payment_method !== BOLT_GATEWAY_NAME ) {
			return $actions;
		}

		// When the order paid with bolt payment method is in pending status, it can not be cancelled by the customer
		if ( $order->get_status() === WC_ORDER_STATUS_PENDING ) {
			unset( $actions['cancel'] );
		}

		return $actions;
	}

	/**
	 * Get billing address from Bolt transaction
	 *
	 * @return stdClass
	 * @since 1.3.2
	 *
	 */
	private function get_billing_address() {
		$billing_address = static::$bolt_transaction->order->cart->billing_address;

		if ( ! $this->is_in_pre_auth ) {
			$card_billing_address = static::$bolt_transaction->from_credit_card->billing_address;
			$billing_address      = (object) array_merge( (array) $billing_address, (array) $card_billing_address );
		}

		// In some contexts the billing address has email/phone properties while the properties are email_address/phone_number in other contexts,
		// so we need to unify the properties with same names for further use.
		if ( ! isset( $billing_address->phone ) ) {
			$billing_address->phone = $billing_address->phone_number ?: "";
		}

		if ( isset( $billing_address->phone_number ) ) {
			unset( $billing_address->phone_number );
		}

		if ( ! isset( $billing_address->email ) ) {
			$billing_address->email = $billing_address->email_address ?: "";
		}

		if ( isset( $billing_address->email_address ) ) {
			unset( $billing_address->email_address );
		}

		return $billing_address;
	}

	/**
	 * Get shipping address from Bolt transaction
	 *
	 * @return stdClass
	 * @since 2.7.0
	 *
	 */
	private function get_shipping_address() {
		$shipping_address = ! empty( static::$bolt_transaction->order->cart->shipments ) ? static::$bolt_transaction->order->cart->shipments[0]->shipping_address : new \stdClass();

		// In some contexts the shipping address has email/phone properties while the properties are email_address/phone_number in other contexts,
		// so we need to unify the properties with same names for further use.
		if ( ! isset( $shipping_address->phone ) ) {
			$shipping_address->phone = $shipping_address->phone_number ?: "";
		}

		if ( isset( $shipping_address->phone_number ) ) {
			unset( $shipping_address->phone_number );
		}

		if ( ! isset( $shipping_address->email ) ) {
			$shipping_address->email = $shipping_address->email_address ?: "";
		}

		if ( isset( $shipping_address->email_address ) ) {
			unset( $shipping_address->email_address );
		}

		return $shipping_address;
	}

	/**
	 * Set $_POST array for mimic the expected checkout page data state
	 *
	 * @param string $shipping_method reference of shipping method
	 *
	 * @since 1.3.3
	 * @access public
	 *
	 */
	public function set_post_array_from_bolt_transaction( $shipping_method ) {
		$shipping_address          = $shipping_method ? $this->get_shipping_address() : array();
		$billing_address           = $this->get_billing_address();
		$ship_to_different_address = "";
		$billing_address_array     = (array) $billing_address;
		unset( $billing_address_array['id'] );
		if ( ! empty( $shipping_address ) ) {
			$shipping_address_array = (array) $shipping_address;
			unset( $shipping_address_array['id'] );
		} else {
			$shipping_address_array = array();
		}


		// If the number of elements in shipping address and billing address are different, the two addresses must be different.
		// If they are equal, then computes the difference of addresses with additional index check.
		if ( count( (array) $billing_address_array ) != count( (array) $shipping_address_array )
		     || ! empty( array_diff_assoc( $shipping_address_array, $billing_address_array ) ) ) {
			$ship_to_different_address = "1";
		}

		$add_post_array = array(
			WC_BILLING_FIRST_NAME      => $billing_address->first_name,
			WC_BILLING_LAST_NAME       => $billing_address->last_name,
			WC_BILLING_COMPANY         => $billing_address->company,
			WC_BILLING_EMAIL           => $billing_address->email,
			WC_BILLING_PHONE           => $billing_address->phone,
			WC_BILLING_ADDRESS_1       => $billing_address->street_address1,
			WC_BILLING_ADDRESS_2       => $billing_address->street_address2,
			WC_BILLING_CITY            => $billing_city = $billing_address->locality,
			WC_BILLING_COUNTRY         => $billing_country_code = bolt_addr_helper()->verify_country_code( $billing_address->country_code, $billing_address->region ),
			WC_BILLING_STATE           => $billing_region = bolt_addr_helper()->get_region_code_without_encoding( $billing_country_code, $billing_address->region ?: ( bolt_addr_helper()->check_if_address_field_required( WC_BILLING_STATE, $billing_country_code, WC_BILLING_PREFIX ) ? $billing_city : '' ) ),
			WC_BILLING_POSTCODE        => $billing_post_code = $billing_address->postal_code,
			WC_ORDER_COMMENTS          => '',
			WC_SHIPPING_METHOD . '[0]' => $shipping_method,
			WC_PAYMENT_METHOD          => BOLT_GATEWAY_NAME,
			WC_TERMS_FIELD             => '1',
			WC_TERMS                   => '1',
			WC_WP_HTTP_REFERER         => get_permalink( wc_get_page_id( 'cart' ) )
		);
		if ( ! empty( $shipping_address ) ) {
			$add_post_array = $add_post_array + array(
					WC_SHIPPING_FIRST_NAME       => $shipping_address->first_name,
					WC_SHIPPING_LAST_NAME        => $shipping_address->last_name,
					WC_SHIPPING_COMPANY          => $shipping_address->company,
					WC_SHIPPING_EMAIL            => $shipping_address->email,
					WC_SHIPPING_PHONE            => $shipping_address->phone,
					WC_SHIPPING_ADDRESS_1        => $shipping_address->street_address1,
					WC_SHIPPING_ADDRESS_2        => $shipping_address->street_address2,
					WC_SHIPPING_CITY             => $city = $shipping_address->locality,
					WC_SHIPPING_COUNTRY          => $country_code = bolt_addr_helper()->verify_country_code( $shipping_address->country_code, $shipping_address->region ),
					WC_SHIPPING_STATE            => $region = bolt_addr_helper()->get_region_code_without_encoding( $country_code, $shipping_address->region ?: ( bolt_addr_helper()->check_if_address_field_required( WC_SHIPPING_STATE, $country_code, WC_SHIPPING_PREFIX ) ? $city : '' ) ),
					WC_SHIPPING_POSTCODE         => $post_code = $shipping_address->postal_code,
					WC_SHIP_TO_DIFFERENT_ADDRESS => $ship_to_different_address,
				);
		}
		$_POST = $add_post_array + $_POST;

		//////////////////////////////////////////////////////////////////////////
		WC()->customer->set_billing_location( $billing_country_code, $billing_region, $billing_post_code, $billing_city );
		WC()->customer->set_shipping_location( $country_code, $region, $post_code, $city );
		WC()->customer->set_calculated_shipping( true );
		WC()->customer->save();
	}

	/**
	 * Compare shipping method from created order and shipping information from $bolt_transaction
	 *
	 * @param object $bolt_transaction
	 * @param WC_Order $order
	 * @param WC_Order_Item_Shipping $shipping_method
	 *
	 * @return bool true if we found difference and we need to override shipping method
	 */
	private function check_if_need_override_shipping( $bolt_transaction, $order, $shipping_method ) {
		if ( isset( $bolt_transaction->order->cart->shipments ) ) {
			if ( convert_monetary_value_to_bolt_format( $shipping_method->get_total() ) != $bolt_transaction->order->cart->shipments[0]->cost->amount ) {
				return true;
			}
			if ( convert_monetary_value_to_bolt_format( wc_bolt_get_order_tax_total( $order ) ) != $this->get_tax_total_by_bolt_transaction( $bolt_transaction ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Compare Bolt data with Woocommerce data
	 *
	 * We know that cart content is the same otherwise we return error before
	 * Now we need to check shipping total, tax and discount total
	 *
	 * TODO: check that shipping method, addreses and name are the same
	 *
	 * @param array $shipping_methods selected shipping methods
	 *
	 * @since 2.0.0
	 * @access protected
	 *
	 */
	protected function compare_with_bolt_data_before_order_creation( $shipping_methods ) {
		$shipping_error_found = $this->compare_cart_shipping_data_with_bolt_data( $shipping_methods );
		$discount_error_found = $this->compare_cart_discount_data_with_bolt_data();
		$tax_error_found      = $this->compare_cart_tax_data_with_bolt_data();

		if ( ! $shipping_error_found && ! $discount_error_found && ! $tax_error_found ) {
			return $this->compare_cart_totals_with_bolt_data();
		} else {
			return false;
		}
	}

	/**
	 * Compare cart shipping data with Bolt data
	 *
	 * TODO: check the shipping method, addreses and name as well
	 *
	 * @param array $shipping_methods selected shipping methods
	 *
	 * @return boolean $error_found True if found any difference between cart shipping data and Bolt data, false if no difference.
	 * @since 2.0.10
	 * @access protected
	 *
	 */
	protected function compare_cart_shipping_data_with_bolt_data( $shipping_methods ) {
		$error_found = false;
		// To get the original shipping total for comparison.
		$shipping_total = isset( WC()->cart->bolt_original_shipping_total ) ? WC()->cart->bolt_original_shipping_total : WC()->cart->get_shipping_total();
		$shipping_total = apply_filters( 'wc_bolt_cart_shipping_total', convert_monetary_value_to_bolt_format( $shipping_total ), static::$bolt_transaction, $this->error_handler );
		if ( $shipping_total <> static::$bolt_transaction->order->cart->shipping_amount->amount ) {
			// If we just created a new user, user session is changed and we need to set
			// shipping method again and recalculate cart
			if ( $shipping_methods && ! wc_bolt_if_cart_has_multiple_packages() ) {
				wc_bolt_set_chosen_shipping_method_for_first_package( $shipping_methods[0] );
				WC()->cart->calculate_totals();
			}
			$shipping_total = apply_filters( 'wc_bolt_cart_shipping_total', convert_monetary_value_to_bolt_format( WC()->cart->get_shipping_total() ), static::$bolt_transaction, $this->error_handler );
			if ( $shipping_total <> static::$bolt_transaction->order->cart->shipping_amount->amount ) {
				$this->error_handler->handle_error( E_BOLT_SHIPPING_EXPIRED, (object) array(
					BOLT_ERR_REASON    => 'Shipping total is changed',
					BOLT_ERR_OLD_VALUE => static::$bolt_transaction->order->cart->shipping_amount->amount,
					BOLT_ERR_NEW_VALUE => convert_monetary_value_to_bolt_format( WC()->cart->get_shipping_total() )
				) );
				$error_found = true;
			}
		}

		return $error_found;
	}

	/**
	 * Compare cart discount data with Bolt data
	 *
	 * @return boolean $error_found True if found any difference between cart discount data and Bolt data, false if no difference.
	 * @since 2.0.10
	 * @access protected
	 *
	 */
	protected function compare_cart_discount_data_with_bolt_data() {
		$cart_discount_total = WC()->cart->get_discount_total();
		$cart_coupons        = WC()->cart->get_coupon_discount_totals();
		$bolt_discounts      = new Bolt_Discounts_Helper();
		$currency_divider    = get_currency_divider();
		foreach ( $bolt_discounts->get_third_party_discounts() as $discount ) {
			$cart_coupons[ $discount[ BOLT_CART_DISCOUNT_REFERENCE ] ] = $discount[ BOLT_CART_DISCOUNT_AMOUNT ] / $currency_divider;
			$cart_discount_total                                       += $discount[ BOLT_CART_DISCOUNT_AMOUNT ] / $currency_divider;
		}
		$discount_total = apply_filters( 'wc_bolt_cart_discount_total', convert_monetary_value_to_bolt_format( $cart_discount_total ), static::$bolt_transaction, $this->error_handler );
		if ( $discount_total <> static::$bolt_transaction->order->cart->discount_amount->amount ) {
			$discount_difference = $discount_total - static::$bolt_transaction->order->cart->discount_amount->amount;
			if ( abs( $discount_difference ) == 1 ) {
				// If discount total differs only for $0.01 it can mean we changed it when created bolt cart
				// to handle Woocommerce rounding issue
				// In this case, if cart total is the same (we check it later) we can create order
				// TODO: This is temporary solution, we need to remove it once Pre-auth v2 is done
				BugsnagHelper::notifyException( new \Exception( 'Discount difference $0.01 is found' ) );

				return false;
			}
			$error_discount_found = false;
			$cart_coupons         = WC()->cart->get_coupon_discount_totals();
			$bolt_discounts       = new Bolt_Discounts_Helper();
			foreach ( $bolt_discounts->get_third_party_discounts() as $discount ) {
				$cart_coupons[ $discount[ BOLT_CART_DISCOUNT_REFERENCE ] ] = $discount[ BOLT_CART_DISCOUNT_AMOUNT ] / 100;
				$cart_discount_total                                       += $discount[ BOLT_CART_DISCOUNT_AMOUNT ] / 100;
			}
			if ( isset( static::$bolt_transaction->order->cart->discounts ) ) {
				foreach ( static::$bolt_transaction->order->cart->discounts as $discount ) {
					if ( convert_monetary_value_to_bolt_format( $cart_coupons[ $discount->reference ] ) <> $discount->amount->amount ) {
						$this->error_handler->handle_error( E_BOLT_DISCOUNT_CANNOT_APPLY, (object) array(
							BOLT_ERR_REASON        => 'Discount amount is changed',
							BOLT_ERR_DISCOUNT_CODE => $discount->reference,
							BOLT_ERR_OLD_VALUE     => $discount->amount->amount,
							BOLT_ERR_NEW_VALUE     => convert_monetary_value_to_bolt_format( $cart_coupons[ $discount->reference ] )
						) );
						$error_discount_found = true;
					}
				}
			}
			if ( ! $error_discount_found ) {
				$this->error_handler->handle_error( E_BOLT_DISCOUNT_CANNOT_APPLY, (object) array(
					BOLT_ERR_REASON    => 'Discount total is changed',
					BOLT_ERR_OLD_VALUE => static::$bolt_transaction->order->cart->discount_amount->amount,
					BOLT_ERR_NEW_VALUE => convert_monetary_value_to_bolt_format( WC()->cart->get_discount_total() )
				) );
			}

			return true;
		}

		return false;
	}

	/**
	 * Compare cart tax data with Bolt data
	 *
	 * @return boolean $error_found True if found any difference between cart tax data and Bolt data, false if no difference.
	 * @since 2.0.10
	 * @access protected
	 *
	 */
	protected function compare_cart_tax_data_with_bolt_data() {
		$error_found = false;
		$bolt_taxes  = $this->get_tax_total_by_bolt_transaction( static::$bolt_transaction );
		$tax_total   = apply_filters( 'wc_bolt_cart_tax_total', convert_monetary_value_to_bolt_format( WC()->cart->get_total_tax() ), static::$bolt_transaction, $this->error_handler );

		if ( $tax_total <> $bolt_taxes ) {
			$this->error_handler->handle_error( E_BOLT_CART_HAS_EXPIRED, (object) array(
				BOLT_ERR_REASON         => 'Tax amount is changed',
				BOLT_ERR_OLD_VALUE      => $bolt_taxes,
				BOLT_ERR_NEW_VALUE      => $tax_total,
				BOLT_FIELD_CART_TAX     => convert_monetary_value_to_bolt_format( WC()->cart->get_cart_contents_tax() ),
				BOLT_FIELD_FEE_TAX      => convert_monetary_value_to_bolt_format( WC()->cart->get_fee_tax() ),
				BOLT_FIELD_SHIPPING_TAX => convert_monetary_value_to_bolt_format( WC()->cart->get_shipping_tax() ),
			) );
			$error_found = true;
		}

		return $error_found;
	}

	/**
	 * Compare cart totals with Bolt data
	 *
	 * @since 2.0.10
	 * @access protected
	 *
	 */
	protected function compare_cart_totals_with_bolt_data() {
		$cart_total = apply_filters( 'wc_bolt_cart_total', convert_monetary_value_to_bolt_format( WC()->cart->get_total( "float" ) ), static::$bolt_transaction, $this->error_handler );
		if ( $cart_total <> static::$bolt_transaction->order->cart->total_amount->amount ) {
			$this->error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array(
				BOLT_ERR_REASON    => 'Total is changed by unknown reason',
				BOLT_ERR_OLD_VALUE => static::$bolt_transaction->order->cart->total_amount->amount,
				BOLT_ERR_NEW_VALUE => convert_monetary_value_to_bolt_format( WC()->cart->get_total( "float" ) ),
				BOLT_CART          => wc_bolt()->get_bolt_data_collector()->build_order( BOLT_CART_ORDER_TYPE_CART, static::$bolt_transaction->order->cart->order_reference, false )
			) );

			return false;
		}

		return true;
	}

	/**
	 * Insert flag that this process starts order creation
	 *
	 * @param string $order_reference order reference.
	 *
	 * @return true if flag inserted false if we can't insert flag during ~5 seconds
	 * @since 2.0.0
	 * @access private
	 *
	 */
	private function acquire_order_creation_lock( $order_reference ) {
		$kol_attempt = 5;
		$session_key = 'create_order_' . $order_reference;
		while ( $kol_attempt > 0 ) {
			if ( wc_bolt_data()->insert_session( $session_key, true ) ) {
				return true;
			}
			sleep( 1 );
			$kol_attempt --;

			// If the session lifetime is greater then php maximum execution time,
			// then process is frozen and we should try to create order again
			// If the running time of the script isn't limited we assume
			// that the script can't work more then 120 seconds.
			$max_lock_seconds = ini_get( 'max_execution_time' ) ?: self::MAX_LOCK_SECONDS_DEFAULT;
			$created_at       = wc_bolt_data()->get_session_created_at_time( $session_key );
			if ( $created_at && ( $created_at + $max_lock_seconds < time() ) ) {
				BugsnagHelper::getBugsnag()->notifyException(
					new \Exception( "Delete frozen session key {$session_key}" ),
					array(),
					'info'
				);
				$this->release_order_creation_lock( $order_reference );
				// Do one more attempt if necessary
				if ( 0 == $kol_attempt ) {
					$kol_attempt = 1;
				}
			}
		}

		return false;
	}

	/**
	 * Delete flag about order creation process
	 *
	 * @param string $order_reference order reference.
	 *
	 * @return true if flag deleted, false otherwise
	 * @since 2.0.0
	 * @access private
	 *
	 */
	private function release_order_creation_lock( $order_reference ) {
		return wc_bolt_data()->delete_session( 'create_order_' . $order_reference );
	}

	/**
	 * Check if order already exists
	 *
	 * @param string $order_reference order reference.
	 * @param stdClass $bolt_shipping Shipping information from bolt order.
	 *
	 * @return mixed
	 * WP_REST_Response If order found and we want to send answer 'success'
	 * false If order not exists or any error occurs
	 * @since  2.0.0
	 * @access public
	 *
	 */
	public function check_if_order_exists_by_order_reference_and_shipping( $order_reference, $bolt_shipping ) {
		$order_id = wc_bolt_get_order_id_by_order_reference( $order_reference );
		if ( is_wp_error( $order_id ) ) {
			$this->error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array( BOLT_ERR_REASON => $order_id->get_error_message() ) );

			return false;
		}
		if ( $order_id <= 0 ) {
			return false;
		}

		$order        = wc_get_order( $order_id );
		$order_status = $order->get_status();
		if ( WC_ORDER_STATUS_PENDING == $order_status ) {
			// If we isn't in pay-order process
			// check if created order has the same shipping cost and shipping address
			if ( 'checkout' === $order->get_created_via() ) {
				// TODO check also changes in billing address. For now if order doen't required shipping
				// and user change the address after wrong attempt to pay, we don't notice it
				$changes = $this->check_if_shipping_changed( $order_reference, $order, $bolt_shipping );
				if ( $changes ) {
					BugsnagHelper::getBugsnag()->notifyException(
						new \Exception( "User changed shipping information" ),
						array(
							'order_id'                => $order_id,
							BOLT_CART_ORDER_REFERENCE => $order_reference,
							'changes'                 => $changes,
							'bolt_shipping'           => $bolt_shipping
						),
						'info' );
					$order->update_status( WC_ORDER_STATUS_CANCELLED, 'Unpaid order cancelled by Bolt because a client create new one, with another shipping.' );

					return false;
				}
			}

			return Bolt_HTTP_Handler::prepare_http_response(
				array(
					BOLT_FIELD_NAME_STATUS             => BOLT_STATUS_SUCCESS,
					BOLT_CART_DISPLAY_ID               => $order->get_order_number(),
					BOLT_FIELD_NAME_TOTAL              => convert_monetary_value_to_bolt_format( $order->get_total(), $order->get_currency() ),
					BOLT_CART_ORDER_REFERENCE          => $order_reference,
					BOLT_FIELD_NAME_ORDER_RECEIVED_URL => $order->get_checkout_order_received_url(),
					BOLT_FIELD_NAME_ORDER              => wc_bolt()->get_bolt_data_collector()->format_order_as_bolt_cart( $order_id, $order_reference )
				),
				HTTP_STATUS_OK
			);
		} else {
			if ( WC_ORDER_STATUS_FAILED == $order_status ) {
				$this->error_handler->handle_error( E_BOLT_ORDER_ALREADY_FAILED, (object) array(
					BOLT_ERR_REASON      => __( 'The order was already created and its status is failed now.', 'bolt-checkout-woocommerce' ),
					BOLT_CART_DISPLAY_ID => $order->get_order_number()
				) );
			} else {
				$this->error_handler->handle_error( E_BOLT_ORDER_ALREADY_EXISTS, (object) array(
					BOLT_ERR_REASON       => __( 'The order was already created.', 'bolt-checkout-woocommerce' ),
					BOLT_CART_DISPLAY_ID  => $order->get_order_number(),
					BOLT_ERR_ORDER_STATUS => $order_status
				) );
			}

			return false;
		}

	}

	/**
	 * If order element is changed then add information about it to array $changes
	 *
	 * @param $name
	 * @param $order_value
	 * @param $bolt_value
	 * @param $changes
	 */
	private function extract_order_changes( $name, $order_value, $bolt_value, &$changes ) {
		if ( $order_value <> sanitize_text_field( $bolt_value ) ) {
			$changes[ $name ] = array(
				'order_value' => $order_value,
				'bolt_value'  => $bolt_value
			);
		}
	}

	/**
	 * Compare shipping address and cost in created order with shipping in bolt_order
	 *
	 * @param string $order_reference
	 * @param wc_order $order
	 * @param stdClass $bolt_shipping
	 *
	 * @return array list of changes. If there is no change return empty array
	 *
	 */
	private function check_if_shipping_changed( $order_reference, $order, $bolt_shipping ) {
		$changes = array();

		$shipping_methods = $order->get_shipping_methods();
		if ( $shipping_methods ) {
			$shipping_method = current( $shipping_methods );

			$this->extract_order_changes( 'first_name', $order->get_shipping_first_name(), $bolt_shipping->shipping_address->first_name, $changes );
			$this->extract_order_changes( 'last_name', $order->get_shipping_last_name(), $bolt_shipping->shipping_address->last_name, $changes );
			$this->extract_order_changes( 'address_1', $order->get_shipping_address_1(), $bolt_shipping->shipping_address->street_address1, $changes );
			$this->extract_order_changes( 'address_2', $order->get_shipping_address_2(), $bolt_shipping->shipping_address->street_address2, $changes );
			$this->extract_order_changes( 'city', $order->get_shipping_city(), $bolt_shipping->shipping_address->locality, $changes );

			$country_code = bolt_addr_helper()->verify_country_code( $bolt_shipping->shipping_address->country_code, $bolt_shipping->shipping_address->region, $changes );
			$region       = bolt_addr_helper()->get_region_code( $country_code, $bolt_shipping->shipping_address->region ?: ( bolt_addr_helper()->check_if_address_field_required( WC_SHIPPING_STATE, $country_code, WC_SHIPPING_PREFIX ) ? $bolt_shipping->shipping_address->city : '' ) );
			$postcode     = wc_format_postcode( $bolt_shipping->shipping_address->postal_code, $country_code );

			$this->extract_order_changes( 'state', $order->get_shipping_state(), $region, $changes );
			$this->extract_order_changes( 'postcode', $order->get_shipping_postcode(), $postcode, $changes );

			$this->extract_order_changes( 'country', $order->get_shipping_country(), $country_code, $changes );
			$this->extract_order_changes( 'company', $order->get_shipping_company(), $bolt_shipping->shipping_address->company, $changes );

			if ( ! $order->get_user_id() ) {
				//if order created by registered user then email and phone from bolt weren't saved
				$this->extract_order_changes( 'email', $order->get_billing_email(), $bolt_shipping->shipping_address->email_address, $changes );
				$this->extract_order_changes( 'phone', $order->get_billing_phone(), $bolt_shipping->shipping_address->phone_number, $changes );
			}

			$this->extract_order_changes( 'shipping_total', convert_monetary_value_to_bolt_format( $shipping_method->get_total() ), $bolt_shipping->cost->amount, $changes );
			$split_tax_enable_key = BOLT_PREFIX_TAX . $order_reference . '_split_tax';
			$if_enable_split_tax  = wc_bolt_data()->get_session( $split_tax_enable_key );

			if ( ! $if_enable_split_tax ) {
				$tax_amount = wc_bolt_get_order_tax_total( $order );
			} else {
				$tax_amount = $order->get_shipping_tax();
			}
			$this->extract_order_changes( 'tax', convert_monetary_value_to_bolt_format( $tax_amount ), $bolt_shipping->tax_amount->amount, $changes );
		} else {
			if ( $bolt_shipping->reference !== 'no_shipping_required' ) {
				$changes['shipping'] = array(
					'order_value' => 'none',
					'bolt_value'  => $bolt_shipping->reference
				);
			}
		}

		return $changes;
	}

	public function cancel_unpaid_order( $is_cancel, $order ) {
		//If woocommerce doesn't want to cancel order we don't mind
		if ( false === $is_cancel ) {
			return $is_cancel;
		}

		$order_payment_method = $order->get_payment_method();
		//don't do anything if order is paid via other payment method or still isn't paid
		if ( $order_payment_method !== BOLT_GATEWAY_NAME || wc_bolt_get_order_meta( $order, BOLT_ORDER_META_TRANSACTION_REFERENCE_ID, true ) === '' ) {
			return true;
		}

		//If we here then order status is pending and transaction_id is setting for order.
		//If means that order is under review.
		//We don't want to cancel it
		wc_bolt_add_order_note_only_once( $order, 'Order is still under review.' );

		return false;
	}

	/**
	 * Count total taxes by bolt_transaction
	 *
	 * @param $bolt_transaction
	 *
	 * @return int taxes (cart tax+shipping tax)     *
	 * @since 2.0.2
	 */
	public function get_tax_total_by_bolt_transaction( $bolt_transaction ) {
		if ( isset( $bolt_transaction->order->cart->tax_amount->amount ) && ( $bolt_transaction->order->cart->tax_amount->amount > 0 ) ) {
			//total taxes for payment-only
			return $bolt_transaction->order->cart->tax_amount->amount;
		}
		if ( isset( $bolt_transaction->order->cart->shipments[0]->tax_amount->amount ) && ( $bolt_transaction->order->cart->shipments[0]->tax_amount->amount ) ) {
			//total taxes for multi-step
			return $bolt_transaction->order->cart->shipments[0]->tax_amount->amount;
		}

		return 0;
	}

	/**
	 * Delete the bolt_data from WC session once the order is successfully created
	 *
	 * @param $order_bolt_reference  The Bolt Order Reference
	 *
	 * @since 2.0.2
	 *
	 */
	protected function destroy_bolt_order_details_unique_data( $order_bolt_reference ) {
		global $wpdb;

		$original_session_data = wc_bolt_data()->get_session( BOLT_PREFIX_SESSION_DATA . $order_bolt_reference );

		if ( ! $original_session_data
		     || ( ! is_array( $original_session_data ) && (string) $original_session_data == BOLT_CART_ORDER_TYPE_ORDER_INVOICE )
		     || ! isset( $original_session_data['bolt_data'] ) ) {
			return;
		}

		$bolt_order_unique_data = maybe_unserialize( $original_session_data['bolt_data'] );
		$customer_id            = $bolt_order_unique_data['customer_id'];
		$wc_session_data        = WC()->session->get_session( $customer_id );
		if ( ! empty( $wc_session_data ) && isset( $wc_session_data['bolt_data'] ) ) {
			$bolt_data = maybe_unserialize( $wc_session_data['bolt_data'] );
			unset( $bolt_data[ BOLT_CART_ORDER_REFERENCE ] );
			$wc_session_data['bolt_data'] = maybe_serialize( $bolt_data );

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}woocommerce_sessions SET `session_value` = %s WHERE `session_key` = %s",
					maybe_serialize( $wc_session_data ),
					$customer_id
				)
			);
		}
	}

	/**
	 * API for record frontend errors to Bugsnag
	 *
	 * @since 2.0.10
	 */
	public function record_frontend_error() {
		BugsnagHelper::initBugsnag();
		$error_text = 'Frontend error: ' . ( isset( $_REQUEST['text'] ) ? $_REQUEST['text'] : '' );
		BugsnagHelper::notifyException( new \Exception( $error_text ), array(), 'info' );
	}

	/**
	 * If we are on woocommerce checkout page and user select option
	 * "Create an account" we should check if user is already created
	 * If user clicked "pay" with wrong bank card and that order is failed,
	 * now we should create second order. If we try to create user again woocommerce returns error
	 * registration-error-email-exists
	 *
	 * @param array $data Posted data.
	 *
	 * @since 2.0.11
	 */
	private function check_if_user_just_created( &$data, $order_reference ) {

		if ( ! is_user_logged_in() && ( $this->is_registration_required() || ! empty( $data['createaccount'] ) ) ) {
			$email       = $data[ WC_BILLING_EMAIL ];
			$customer_id = email_exists( $email );
			if ( $customer_id ) {
				$should_assign_user = true;
				// Check that all orders associated with this user have the same order reference
				$orders = wc_get_orders( array(
					'return'      => 'ids',
					'type'        => 'shop_order',
					'limit'       => - 1,
					'customer_id' => $customer_id,
				) );
				foreach ( $orders as $order_id ) {
					if ( wc_bolt_get_order_meta( $order_id, BOLT_ORDER_META_TRANSACTION_ORDER, true ) != $order_reference ) {
						$should_assign_user = false;
						break;
					}
				}

				if ( $should_assign_user ) {
					// prevent new attempt to create user in method $this->process_customer()
					unset( $data['createaccount'] );
					wp_set_current_user( $customer_id );

					// do the same actions as $this->process_customer() when user created
					wc_set_customer_auth_cookie( $customer_id );
					WC()->cart->calculate_totals();
				}
			}
		}
	}

	/**
	 * Update BOLT_ORDER_META_TRANSACTION_ORDER right after saving order to the DB
	 *
	 * @since 2.11.0
	 */
	public function save_bolt_transaction_order( $order, $data_store ) {
		if ( ! empty( static::$bolt_transaction->order->cart->order_reference ) ) {
			if ( wc_bolt_is_hpos() ) {
				global $wpdb;
				$order_meta_table = \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_meta_table_name();
				$meta_exists      = $wpdb->get_var( $wpdb->prepare( "SELECT order_id FROM {$order_meta_table} WHERE meta_key = %s AND meta_value = %s LIMIT 0,1", BOLT_ORDER_META_TRANSACTION_ORDER, static::$bolt_transaction->order->cart->order_reference ) );
				if ( ! $meta_exists ) {
					$wpdb->insert(
						$order_meta_table,
						array(
							'order_id'   => $order->get_id(),
							'meta_key'   => BOLT_ORDER_META_TRANSACTION_ORDER,
							'meta_value' => static::$bolt_transaction->order->cart->order_reference
						),
						array(
							'%d',
							'%s',
							'%s'
						)
					);
					if ( wc_bolt_is_custom_order_tables_in_sync() ) {
						update_post_meta( $order->get_id(), BOLT_ORDER_META_TRANSACTION_ORDER, static::$bolt_transaction->order->cart->order_reference );
					}
				}
			} else {
				update_post_meta( $order->get_id(), BOLT_ORDER_META_TRANSACTION_ORDER, static::$bolt_transaction->order->cart->order_reference );
			}
		}
	}

	/**
	 * Send non-Bolt order information to the Bolt backend
	 *
	 * Filter: woocommerce_checkout_order_processed
	 *
	 * @param int $order_id The id of the newly created order
	 * @param array $posted_data Cart data that was used to create the order
	 * @param WC_Order $order Order object of the newly created order
	 *
	 */
	public function save_non_bolt_order( $order_id, $posted_data, $order = null ) {
		if ( ! Bolt_Feature_Switch::instance()->is_track_non_bolt_order_enabled() ) {
			return;
		}
		// If the customer choose other payment method to checkout, then we should do our process
		$order_payment_method = $order->get_payment_method() ?: 'unknown';
		if ( $order_payment_method === BOLT_GATEWAY_NAME ) {
			return;
		}
		$transaction_id = wc_bolt_get_order_meta( $order, BOLT_ORDER_META_TRANSACTION_REFERENCE_ID, true );
		// If the order is already tracked
		if ( ! empty( $transaction_id ) ) {
			return;
		}
		BugsnagHelper::initBugsnag();
		try {
			$formatted_order = wc_bolt()->get_bolt_data_collector()->format_order_as_bolt_cart( $order_id );
			if ( empty( $formatted_order ) ) {
				return;
			}
			$order_details = array(
				'cart'            => $formatted_order,
				'user_identifier' => array(
					'email' => $formatted_order[ BOLT_CART_BILLING_ADDRESS ][ BOLT_EMAIL ],
					'phone' => $formatted_order[ BOLT_CART_BILLING_ADDRESS ][ BOLT_PHONE ],
				),
				'user_identity'   => array(
					'first_name' => $formatted_order[ BOLT_CART_BILLING_ADDRESS ][ BOLT_FIRST_NAME ],
					'last_name'  => $formatted_order[ BOLT_CART_BILLING_ADDRESS ][ BOLT_LAST_NAME ],
				),
				'payment_method'  => $order_payment_method,
			);
			$response      = wc_bolt()->get_api_request()->handle_api_request( 'non_bolt_order', $order_details, '' );
		} catch ( \Exception $e ) {
			BugsnagHelper::notifyException( $e );
		}

		if ( ! empty( $response->errors ) ) {
			BugsnagHelper::notifyException( new \Exception( "Non Bolt order creation error.  request: " . json_encode( $order_details, JSON_PRETTY_PRINT ) . "\n\n response: " . json_encode( $response, JSON_PRETTY_PRINT ) ) );
		}

		wc_bolt_update_order_meta( $order, BOLT_ORDER_META_TRANSACTION_REFERENCE_ID, $response->reference );
	}
}
