<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bolt Third-party addons support Functions
 *
 * Functions to support Wholesale For WooCommerce.
 * Tested up to: 2.1.0
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */

/**
 * For the callback function of Bolt order.create api request,
 * it sets the current user (log him in) before the Bolt cart is loaded from session,
 * and the "init" hook of WordPress has been triggered before that,
 * so the wholesale price rules can not be applied to cart items,
 * and it would cause the price mismatch error in the order creation.
 * This callback function is to fix the issue by applying wholesale price rules once Bolt cart is loaded from session.
 *
 * @since 2.18.0
 * @access public
 *
 */
function wc_bolt_wwp_apply_wholesale_price_after_boltcart_loaded( $reference, $original_session_data ) {
	if ( class_exists( 'Wwp_Wholesale_Pricing' ) ) {
		\Wwp_Wholesale_Pricing::include_wholesale_functionality();
	}
}

add_action( 'wc_bolt_after_set_cart_by_bolt_reference', '\BoltCheckout\wc_bolt_wwp_apply_wholesale_price_after_boltcart_loaded', 10, 2 );
add_action( 'wc_bolt_after_load_cart_from_native_wc_session', '\BoltCheckout\wc_bolt_wwp_apply_wholesale_price_after_boltcart_loaded', 10, 2 );

/**
 * Support the cart total discount applied by Wholesale For WooCommerce.
 * The discount is applied as negative fee, and it causes "coupon invalid" error when implementing discounts.code.apply hook.
 * Adds a callback function to the filter hook wc_bolt_is_coupon_negative_fee to return discount info without validation&calculation.
 *
 * @since 2.18.0
 * @access public
 *
 */
function wc_bolt_wwp_check_cart_total_discount( $is_coupon_negative_fee, $api_request ) {
	if ( class_exists( 'Wwp_Wholesale_Pricing' ) ) {
		$discount_code = $api_request->discount_code;
		WC()->cart->calculate_fees();
		foreach ( WC()->cart->get_fees() as $fee_item_id => $fee_item ) {
			if ( $fee_item->name == $discount_code ) {
				$is_coupon_negative_fee = true;
				break;
			}
		}
	}

	return $is_coupon_negative_fee;
}

add_filter( 'wc_bolt_is_coupon_negative_fee', '\BoltCheckout\wc_bolt_wwp_check_cart_total_discount', 10, 2 );

/**
 * Support the Wholesale Tier Pricing applied by Wholesale For WooCommerce.
 * The Wholesale Tier Pricing can be only applied on cart page or checkout page,
 * so we save a flag variable into session to make the tier pricing also works for Bolt webhooks.
 *
 * @since 2.18.0
 * @access public
 *
 */
function wc_bolt_wwp_set_cart_page_session_for_build_cart( $reference, $original_session_data ) {
	if ( class_exists( 'Wwp_Wholesale_Pricing' ) ) {
		WC()->session->set( 'bolt_wwp_cart_page', true );
	}
}

add_action( 'wc_bolt_before_set_cart_by_bolt_reference', '\BoltCheckout\wc_bolt_wwp_set_cart_page_session_for_build_cart', 10, 2 );

/**
 * Support the Wholesale Tier Pricing applied by Wholesale For WooCommerce.
 * The Wholesale Tier Pricing can be only applied on cart page or checkout page,
 * so we use filter `wwp_is_cart_or_checkout_page` to make the tier pricing also works for Bolt webhooks.
 *
 * @since 2.18.0
 * @access public
 *
 */
function wc_bolt_wwp_set_cart_page_for_build_cart( $is_cart_or_checkout_page ) {
	if ( wc_bolt_is_bolt_rest_api_request() && WC()->session && WC()->session->get( 'bolt_wwp_cart_page', false ) ) {
		$is_cart_or_checkout_page = true;
	}

	return $is_cart_or_checkout_page;
}

add_filter( 'wwp_is_cart_or_checkout_page', '\BoltCheckout\wc_bolt_wwp_set_cart_page_for_build_cart', 10, 1 );

/**
 * Support the Wholesale Tier Pricing applied by Wholesale For WooCommerce.
 * The Wholesale Tier Pricing can be only applied on cart page or checkout page,
 * so we use filter `woocommerce_is_checkout` to make the tier pricing also works for Bolt webhooks.
 *
 * @since 2.18.0
 * @access public
 *
 */
function wc_bolt_wwp_set_checkout_page_for_build_cart( $is_checkout ) {
	if ( class_exists( 'Wwp_Wholesale_Pricing' ) && wc_bolt_is_bolt_rest_api_request() && WC()->session && WC()->session->get( 'bolt_wwp_cart_page', false ) ) {
		$is_checkout = true;
	}

	return $is_checkout;
}

add_filter( 'woocommerce_is_checkout', '\BoltCheckout\wc_bolt_wwp_set_checkout_page_for_build_cart', 999, 1 );