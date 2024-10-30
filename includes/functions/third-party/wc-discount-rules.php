<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bolt Third-party addons support
 *
 * Class to support Woo Discount Rules
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */

/**
 * Apply Woo Discount Rules to the cart after the Bolt cart session loaded
 *
 * @since 2.14.0
 * @access public
 *
 */
function bolt_wdr_calculate_cart_after_load_bolt_session( $reference, $original_session_data ) {
	bolt_wdr_apply_rule_after_restore_cart();
}

add_action( 'wc_bolt_after_set_cart_by_bolt_reference', 'BoltCheckout\bolt_wdr_calculate_cart_after_load_bolt_session', 10, 2 );

/**
 * Apply Woo Discount Rules to the cart after the WooCommerce cart session loaded
 *
 * @since 2.15.0
 * @access public
 *
 */
function bolt_wdr_calculate_cart_after_load_native_session( $customer_id, $reference ) {
	bolt_wdr_apply_rule_after_restore_cart();
}

add_action( 'wc_bolt_after_load_cart_from_native_wc_session', 'BoltCheckout\bolt_wdr_calculate_cart_after_load_native_session', 10, 2 );

function bolt_wdr_apply_rule_after_restore_cart() {
	if ( class_exists( 'FlycartWooDiscountRules' ) ) {
		global $woocommerce, $flycart_woo_discount_rules;

		$flycart_woo_discount_rules = \FlycartWooDiscountRules::init();
		$flycart_wdr_cart_rules     = $flycart_woo_discount_rules->discountBase->getInstance( 'FlycartWooDiscountRulesCartRules' );

		// Load shipping address and billing address into the postData of FlycartWooDiscountRulesCartRules
		if ( $_POST['transaction_details'] ) {
			$transaction_details       = json_decode( $_POST['transaction_details'] );
			$transaction_shipping_addr = $transaction_details->shipping_address;
			$transaction_billing_addr  = $transaction_details->billing_address;

			$country_code = bolt_addr_helper()->verify_country_code( $transaction_shipping_addr->country_code, $transaction_shipping_addr->region ) ?: '';
			$post_code    = $bolt_order->shipping_address->postal_code ?: '';
			$region       = bolt_addr_helper()->get_region_code( $country_code, $transaction_shipping_addr->region ?: ( bolt_addr_helper()->check_if_address_field_required( WC_SHIPPING_STATE, $country_code, WC_SHIPPING_PREFIX ) ? $transaction_shipping_addr->locality : '' ) );
			$city         = $transaction_shipping_addr->locality ?: '';

			$shipping_address_data = array(
				WC_SHIPPING_FIRST_NAME => $transaction_shipping_addr->first_name ?: '',
				WC_SHIPPING_LAST_NAME  => $transaction_shipping_addr->last_name ?: '',
				WC_SHIPPING_ADDRESS_1  => $transaction_shipping_addr->street_address1,
				WC_SHIPPING_ADDRESS_2  => $transaction_shipping_addr->street_address2 ?: '',
				WC_SHIPPING_CITY       => $city,
				WC_SHIPPING_STATE      => $region,
				WC_SHIPPING_POSTCODE   => $post_code,
				WC_SHIPPING_COUNTRY    => $country_code,
			);

			foreach ( $shipping_address_data as $key => $val ) {
				$flycart_wdr_cart_rules->postData->def( $key, $val );
			}

			$billing_address_data = array(
				WC_BILLING_FIRST_NAME => $transaction_billing_addr->first_name ?: '',
				WC_BILLING_LAST_NAME  => $transaction_billing_addr->last_name ?: '',
				WC_BILLING_EMAIL      => $transaction_billing_addr->email_address,
				WC_BILLING_PHONE      => $transaction_billing_addr->phone_number ?: '',
				WC_BILLING_ADDRESS_1  => $transaction_billing_addr->street_address1,
				WC_BILLING_ADDRESS_2  => $transaction_billing_addr->street_address2 ?: '',
				WC_BILLING_CITY       => $transaction_billing_addr->locality ?: '',
				WC_BILLING_COUNTRY    => $billing_country_code = bolt_addr_helper()->verify_country_code( $transaction_billing_addr->country_code, $transaction_billing_addr->region ?: '' ) ?: '',
				WC_BILLING_STATE      => bolt_addr_helper()->get_region_code( $billing_country_code, $transaction_billing_addr->region ?: ( bolt_addr_helper()->check_if_address_field_required( WC_BILLING_STATE, $billing_country_code, WC_BILLING_PREFIX ) ? $transaction_billing_addr->locality : '' ) ),
				WC_BILLING_POSTCODE   => $transaction_billing_addr->postal_code ?: '',
			);

			foreach ( $billing_address_data as $key => $val ) {
				$flycart_wdr_cart_rules->postData->def( $key, $val );
			}
		}

		// To Analyzing the Rules to Apply the Discount in terms of price.
		$flycart_wdr_cart_rules->cart_items = WC()->cart->cart_contents;
		$flycart_wdr_cart_rules->calculateCartSubtotal();
		$flycart_wdr_cart_rules->analyse( $woocommerce, 0 );
    } else if ( class_exists( '\Wdr\App\Router' ) && get_option( 'advanced_woo_discount_rules_load_version', null ) == 'v2' ) {
        \Wdr\App\Router::$manage_discount->applyCartProductDiscount(WC()->cart);
    }
}

/**
 * Apply Woo Discount Rules to the cart after loading shipping options
 *
 * @since 2.14.0
 * @access public
 *
 */
function bolt_wdr_calculate_cart_before_load_shipping_options( $shipping_options, $bolt_order, $error_handler ) {
	if ( class_exists( 'FlycartWooDiscountRules' ) ) {
		global $woocommerce, $flycart_woo_discount_rules;

		$last_applied_coupons = $woocommerce->cart->applied_coupons;

		$country_code = bolt_addr_helper()->verify_country_code( $bolt_order->shipping_address->country_code, $bolt_order->shipping_address->region ) ?: '';
		$post_code    = $bolt_order->shipping_address->postal_code ?: '';
		$region       = bolt_addr_helper()->get_region_code( $country_code, $bolt_order->shipping_address->region ?: ( bolt_addr_helper()->check_if_address_field_required( WC_SHIPPING_STATE, $country_code, WC_SHIPPING_PREFIX ) ? $bolt_order->shipping_address->locality : '' ) );
		$city         = $bolt_order->shipping_address->locality ?: '';

		$flycart_woo_discount_rules = \FlycartWooDiscountRules::init();
		$flycart_wdr_cart_rules     = $flycart_woo_discount_rules->discountBase->getInstance( 'FlycartWooDiscountRulesCartRules' );

		$shipping_address_data = array(
			WC_SHIPPING_FIRST_NAME => $bolt_order->shipping_address->first_name ?: '',
			WC_SHIPPING_LAST_NAME  => $bolt_order->shipping_address->last_name ?: '',
			WC_SHIPPING_ADDRESS_1  => $bolt_order->shipping_address->street_address1,
			WC_SHIPPING_ADDRESS_2  => $bolt_order->shipping_address->street_address2 ?: '',
			WC_SHIPPING_CITY       => $city,
			WC_SHIPPING_STATE      => $region,
			WC_SHIPPING_POSTCODE   => $post_code,
			WC_SHIPPING_COUNTRY    => $country_code,
		);

		foreach ( $shipping_address_data as $key => $val ) {
			$flycart_wdr_cart_rules->postData->def( $key, $val );
		}

		$billing_address_data = array(
			WC_BILLING_FIRST_NAME => $bolt_order->cart->billing_address->first_name ?: '',
			WC_BILLING_LAST_NAME  => $bolt_order->cart->billing_address->last_name ?: '',
			WC_BILLING_EMAIL      => $bolt_order->cart->billing_address->email,
			WC_BILLING_PHONE      => $bolt_order->cart->billing_address->phone ?: '',
			WC_BILLING_ADDRESS_1  => $bolt_order->cart->billing_address->street_address1,
			WC_BILLING_ADDRESS_2  => $bolt_order->cart->billing_address->street_address2 ?: '',
			WC_BILLING_CITY       => $bolt_order->cart->billing_address->locality ?: '',
			WC_BILLING_COUNTRY    => $billing_country_code = bolt_addr_helper()->verify_country_code( $bolt_order->cart->billing_address->country_code, $bolt_order->cart->billing_address->region ?: '' ) ?: '',
			WC_BILLING_STATE      => bolt_addr_helper()->get_region_code( $billing_country_code, $bolt_order->cart->billing_address->region ?: ( bolt_addr_helper()->check_if_address_field_required( WC_BILLING_STATE, $billing_country_code, WC_BILLING_PREFIX ) ? $bolt_order->cart->billing_address->locality : '' ) ),
			WC_BILLING_POSTCODE   => $bolt_order->cart->billing_address->postal_code ?: '',
		);

		foreach ( $billing_address_data as $key => $val ) {
			$flycart_wdr_cart_rules->postData->def( $key, $val );
		}

		$flycart_woo_discount_rules->discountBase->getInstance( 'FlycartWooDiscountRulesCartRules' )->calculateCartSubtotal();
		$flycart_woo_discount_rules->discountBase->getInstance( 'FlycartWooDiscountRulesCartRules' )->analyse( $woocommerce, 0 );
		WC()->cart->calculate_totals();

		$new_applied_coupons = $woocommerce->cart->applied_coupons;

		// Woo Discount Rules may create coupon after the value of specific filed loaded, eg. billing email, shipping citry,
		// and the Bolt checkout can not add coupon via the shipping&tax endpoint in that case,
		// so we have to ask the customer to refresh to cart page to get coupon applied properly
		if ( $last_applied_coupons != $new_applied_coupons ) {
			$bolt_data = WC()->session->get( 'bolt_data', array() );
			if ( ! empty( $bolt_data ) && ! empty( $bolt_data[ BOLT_CART_ORDER_REFERENCE ] ) ) {
				$original_session_data = wc_bolt_data()->get_session( BOLT_PREFIX_SESSION_DATA . $bolt_data[ BOLT_CART_ORDER_REFERENCE ] );

				if ( ! empty( $original_session_data ) ) {
					$original_session_data = array(
						                         'wdr_shipping_address_data' => $shipping_address_data,
						                         'wdr_billing_address_data'  => $billing_address_data,
					                         ) + $original_session_data;
					wc_bolt_data()->update_session( BOLT_PREFIX_SESSION_DATA . $bolt_data[ BOLT_CART_ORDER_REFERENCE ], $original_session_data );
				}
			}
			$error_handler->handle_error( E_BOLT_SHIPPING_CUSTOM_ERROR, (object) array( BOLT_ERR_REASON => 'Please refresh the cart page to apply your coupon.' ) );
		}
	}

	return $shipping_options;
}

add_filter( 'wc_bolt_before_load_shipping_options', 'BoltCheckout\bolt_wdr_calculate_cart_before_load_shipping_options', 10, 3 );

/**
 * Woo Discount Rules may create coupon after the value of specific filed loaded, eg. billing email, shipping citry,
 * and the Bolt checkout can not add coupon via the shipping&tax endpoint in that case,
 * so we have to ask the customer to refresh to cart page to get coupon applied properly.
 *
 * @since 2.14.0
 * @access public
 *
 */
function bolt_wdr_load_shipping_billing_info_before_build_cart() {
	if ( class_exists( 'FlycartWooDiscountRules' ) && is_cart() ) {
		global $woocommerce, $flycart_woo_discount_rules;

		$bolt_data = WC()->session->get( 'bolt_data', array() );
		if ( ! empty( $bolt_data ) && ! empty( $bolt_data[ BOLT_CART_ORDER_REFERENCE ] ) ) {
			$original_session_data = wc_bolt_data()->get_session( BOLT_PREFIX_SESSION_DATA . $bolt_data[ BOLT_CART_ORDER_REFERENCE ] );
			// Restore the shipping&billing address fields added via the Bolt modal,
			// so Woo Discount Rules can add coupon to the cart properly
			if ( ! empty( $original_session_data )
			     && ! empty( $original_session_data['wdr_shipping_address_data'] )
			     && ! empty( $original_session_data['wdr_billing_address_data'] ) ) {
				$flycart_woo_discount_rules = \FlycartWooDiscountRules::init();
				$flycart_wdr_cart_rules     = $flycart_woo_discount_rules->discountBase->getInstance( 'FlycartWooDiscountRulesCartRules' );

				foreach ( $original_session_data['wdr_shipping_address_data'] as $key => $val ) {
					$flycart_wdr_cart_rules->postData->def( $key, $val );
				}

				foreach ( $original_session_data['wdr_billing_address_data'] as $key => $val ) {
					$flycart_wdr_cart_rules->postData->def( $key, $val );
				}

				$flycart_woo_discount_rules->discountBase->getInstance( 'FlycartWooDiscountRulesCartRules' )->calculateCartSubtotal();
				$flycart_woo_discount_rules->discountBase->getInstance( 'FlycartWooDiscountRulesCartRules' )->analyse( $woocommerce, 0 );

				unset( $original_session_data['wdr_shipping_address_data'] );
				unset( $original_session_data['wdr_billing_address_data'] );
				wc_bolt_data()->update_session( BOLT_PREFIX_SESSION_DATA . $bolt_data[ BOLT_CART_ORDER_REFERENCE ], $original_session_data );
			}
		}
	}
}

add_action( 'woocommerce_check_cart_items', 'BoltCheckout\bolt_wdr_load_shipping_billing_info_before_build_cart', 10 );