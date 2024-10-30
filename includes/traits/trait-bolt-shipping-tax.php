<?php
/**
 * @package Woocommerce_Bolt_Checkout/Traits
 */

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait Bolt_Shipping_Tax.
 *
 * @since
 */
trait Bolt_Shipping_Tax {

	protected $error_handler;

	/**
	 * Put shipping address data into array with specific keys
	 */
	public function format_shipping_address( $shipping_address ) {
		$country_code               = bolt_addr_helper()->verify_country_code( $shipping_address->country_code, $shipping_address->region ) ?: '';
		$region                     = bolt_addr_helper()->get_region_code_without_encoding( $country_code, $shipping_address->region ?: ( bolt_addr_helper()->check_if_address_field_required( WC_SHIPPING_STATE, $country_code, WC_SHIPPING_PREFIX ) ? $shipping_address->locality : '' ) );
		$formatted_shipping_address = array(
			WC_SHIPPING_EMAIL      => $shipping_address->email ?: '',
			WC_SHIPPING_FIRST_NAME => $shipping_address->first_name ?: '',
			WC_SHIPPING_LAST_NAME  => $shipping_address->last_name ?: '',
			WC_SHIPPING_ADDRESS_1  => $shipping_address->street_address1,
			WC_SHIPPING_ADDRESS_2  => $shipping_address->street_address2 ?: '',
			WC_SHIPPING_CITY       => $shipping_address->locality ?: '',
			WC_SHIPPING_STATE      => bolt_addr_helper()->get_region_code( $country_code, $region, true ),
			WC_SHIPPING_POSTCODE   => $shipping_address->postal_code ?: '',
			WC_SHIPPING_COUNTRY    => $country_code,
		);

		return $formatted_shipping_address;
	}

	/**
	 * Put billing address data into array with specific keys
	 */
	public function format_billing_address( $billing_address ) {
		$billing_country_code      = bolt_addr_helper()->verify_country_code( $billing_address->country_code, $billing_address->region ?: '' ) ?: '';
		$billing_region            = bolt_addr_helper()->get_region_code_without_encoding( $billing_country_code, $billing_address->region ?: ( bolt_addr_helper()->check_if_address_field_required( WC_BILLING_STATE, $billing_country_code, WC_BILLING_PREFIX ) ? $billing_address->locality : '' ) );
		$formatted_billing_address = array(
			WC_BILLING_FIRST_NAME => $billing_address->first_name ?: '',
			WC_BILLING_LAST_NAME  => $billing_address->last_name ?: '',
			WC_BILLING_EMAIL      => $billing_address->email,
			WC_BILLING_PHONE      => $billing_address->phone ?: '',
			WC_BILLING_ADDRESS_1  => $billing_address->street_address1,
			WC_BILLING_CITY       => $billing_address->locality ?: '',
			WC_BILLING_COUNTRY    => $billing_country_code,
			WC_BILLING_STATE      => bolt_addr_helper()->get_region_code( $billing_country_code, $billing_region, true ),
			WC_BILLING_POSTCODE   => $billing_address->postal_code ?: '',
		);

		return $formatted_billing_address;
	}

	/**
	 * Validates the billing address data based on field properties
	 */
	public function validate_billing_address( $decode_request_payload, $billing_address ) {
		if ( $error_msg = bolt_addr_helper()->validate_address( $billing_address, WC_BILLING_PREFIX, $this->get_if_apply_pay( $decode_request_payload ) ) ) {
			throw new BOLT_REST_Exception( E_BOLT_SHIPPING_CUSTOM_ERROR, $error_msg, HTTP_STATUS_UNPROCESSABLE );
		}

		return true;
	}

	/**
	 * Validates the shipping address data based on field properties
	 */
	public function validate_shipping_address( $decode_request_payload, $shipping_address ) {
		$shipping_validation = apply_filters( 'wc_bolt_shipping_validation', $decode_request_payload );

		if ( is_wp_error( $shipping_validation ) ) {
			throw new BOLT_REST_Exception( $shipping_validation->get_error_code(), $shipping_validation->get_error_message(), HTTP_STATUS_UNPROCESSABLE );
		}

		//if the config "Allow shipping to PO Box" is set to false
		//check if address contains text like "PO box", and if so return error code 6101 (integer)
		$settings = wc_bolt()->get_settings();
		if ( 'no' === $settings[ Bolt_Settings::SETTING_NAME_ALLOW_SHIPPING_POBOX ] && bolt_addr_helper()->check_if_address_contain_pobox( $shipping_address[ WC_SHIPPING_ADDRESS_1 ], $shipping_address[ WC_SHIPPING_ADDRESS_2 ] ) ) {
			throw new BOLT_REST_Exception( E_BOLT_SHIPPING_PO_BOX_SHIPPING_DISALLOWED, __( 'Address with P.O. Box is not allowed.', 'bolt-checkout-woocommerce' ), HTTP_STATUS_UNPROCESSABLE );
		}

		if ( $error_msg = bolt_addr_helper()->validate_address( $shipping_address, WC_SHIPPING_PREFIX, $this->get_if_apply_pay( $decode_request_payload ) ) ) {
			throw new BOLT_REST_Exception( E_BOLT_SHIPPING_CUSTOM_ERROR, $error_msg, HTTP_STATUS_UNPROCESSABLE );
		}

		return true;
	}

	/**
	 * Get if checkout with Apply Pay from Bolt session data
	 */
	public function get_if_apply_pay( $decode_request_payload ) {
		$original_session_data = wc_bolt_data()->get_session( BOLT_PREFIX_SESSION_DATA . $decode_request_payload->cart->order_reference );

		return isset( $original_session_data['bolt_apply_pay'] ) && ( $original_session_data['bolt_apply_pay'] == $decode_request_payload->order_token );
	}

	/**
	 * Set if checkout with Apply Pay into Bolt session data
	 */
	public function set_if_apply_pay( $decode_request_payload ) {
		$is_apple_pay = isset( $decode_request_payload->request_source ) && ( $decode_request_payload->request_source == 'applePay' );
		if ( $is_apple_pay ) {
			$original_session_data                   = wc_bolt_data()->get_session( BOLT_PREFIX_SESSION_DATA . $decode_request_payload->cart->order_reference );
			$original_session_data['bolt_apply_pay'] = $decode_request_payload->order_token;
			wc_bolt_data()->update_session( BOLT_PREFIX_SESSION_DATA . $decode_request_payload->cart->order_reference, $original_session_data );
		}
	}

	/**
	 * Set customer session data for the location.
	 */
	public function set_customer_shipping_address( $shipping_address ) {
		WC()->customer->set_location( $shipping_address[ WC_SHIPPING_COUNTRY ], $shipping_address[ WC_SHIPPING_STATE ], $shipping_address[ WC_SHIPPING_POSTCODE ], $shipping_address[ WC_SHIPPING_CITY ] );
		WC()->customer->set_shipping_address( $shipping_address[ WC_SHIPPING_ADDRESS_1 ] );
		WC()->customer->set_shipping_address_2( $shipping_address[ WC_SHIPPING_ADDRESS_2 ] );
		WC()->customer->save();
	}

	/**
	 * Validate discounts in the cart.
	 */
	public function validate_discounts( $decode_request_payload, $coupon_err_mapping ) {
		// Validate the applied coupons
		try {
			$bolt_discounts = new Bolt_Discounts_Helper( WC()->cart );
			$raw_data       = array( WC_BILLING_EMAIL => isset( $decode_request_payload->shipping_address->email ) ? $decode_request_payload->shipping_address->email : '' );
			$bolt_discounts->validate_applied_coupons( $raw_data, $this->error_handler, $coupon_err_mapping );
		} catch ( \Exception $e ) {
			throw new BOLT_REST_Exception( $e->getCode(), $e->getMessage(), HTTP_STATUS_UNPROCESSABLE );
		}
	}

	/**
	 * Check whether there is already any error notice in case of other errors
	 */
	public function check_error_notices() {
		if ( $notices = wc_get_notices( WC_NOTICE_TYPE_ERROR ) ) {
			$error_msg = '';
			foreach ( $notices as $notice ) {
				// WooCommerce notice has different structures in different versions.
				$error_msg .= wc_kses_notice( get_wc_notice_message( $notice ) );
			}
			throw new BOLT_REST_Exception( E_BOLT_SHIPPING_CUSTOM_ERROR, $error_msg, HTTP_STATUS_UNPROCESSABLE );
		}
	}

	/**
	 * Set the cart content by Bolt reference for calculating shipping options
	 */
	public function retrive_active_cart_session_by_order_reference( $decode_request_payload ) {
		try {
			do_action( 'wc_bolt_shipping_tax_before_restore_cart_session', $decode_request_payload );

			set_cart_by_bolt_reference( $decode_request_payload->cart->order_reference );

			// Always check if cart is empty or not after retrieve the active cart session by order reference.
			if ( WC()->cart->is_empty() ) {
				throw new \Exception( 'Empty cart' );
			}
		} catch ( \Exception $e ) {
			throw new BOLT_REST_Exception( E_BOLT_SHIPPING_CUSTOM_ERROR, $e->getMessage(), HTTP_STATUS_UNPROCESSABLE );
		}
		reset_wc_notices();
	}

	/**
	 * See if the cart data have changed since the last request.
	 *
	 * @return bool
	 */
	public function check_cart_have_changed( $order_reference ) {
		$order_details = wc_bolt()->get_bolt_data_collector()->build_cart( BOLT_CART_ORDER_TYPE_CART, $order_reference, false );
		if ( $order_details ) {
			$order_details = array( BOLT_CART => $order_details );
			$order_md5     = wc_bolt()->get_bolt_data_collector()->calc_bolt_order_md5( $order_details );
			$bolt_data     = WC()->session->get( 'bolt_data', array() );
			if ( $bolt_data && isset( $bolt_data['order_md5'] )
			     && ( $bolt_data['order_md5'] == $order_md5 ) ) {
				return false;
			}
		}

		return true;
	}

	public function calculate_cache_session_key( $session_key_prefix, $request_payload ) {
		// Check if md5 return empty, and md5 always expect string as parameter
		if ( ! ( $bolt_cart_md5 = md5( $request_payload ) ) ) {
			throw new \Exception( __( 'Failed to encrypt api request data :', 'bolt-checkout-woocommerce' ) . $request_payload );
		}

		return $session_key_prefix . "_" . $bolt_cart_md5;
	}

	/**
	 * Check first for a cached estimate from a previous aborted attempt. If found, return it and exit
	 */
	public function get_cached_estimate( $session_key ) {
		$cached_estimate = wc_bolt_data()->get_session( $session_key );

		return $cached_estimate;
	}

	/**
	 * Cache the shipping or tax response in case of a timed out request
	 */
	public function set_cached_estimate( $session_key, $data ) {
		wc_bolt_data()->update_session( $session_key, $data );
	}

}
