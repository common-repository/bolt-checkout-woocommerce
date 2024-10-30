<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bolt Third-party addons support
 *
 * Class to support WooCommerce FedEx Shipping
 * Tested up to: 3.8.5
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */


/*
 * Hotfix - fix compatibility issue with WooCommerce FedEx Shipping plugin,
 * it adds a condition to perform shipping calculations only on cart or checkout page,
 * that's why the Fedex shipping options only shows on cart page but disappear on the Bolt modal..
 *
 */
function bolt_enable_fedex_calculation_on_modal( $shipping_options, $bolt_order, $error_handler ) {
	if ( defined( 'WC_SHIPPING_FEDEX_VERSION' ) ) {
		add_filter( 'woocommerce_is_checkout', '\BoltCheckout\bolt_emulate_checkout_for_fedex', 10 );
	}

	return $shipping_options;
}

/**
 * Hook to emulate that we are on checkout page. It needs when we do calculation on shipping&tax step
 */
function bolt_emulate_checkout_for_fedex( $is_checkout ) {
	return true;
}

add_filter( 'wc_bolt_before_load_shipping_options', '\BoltCheckout\bolt_enable_fedex_calculation_on_modal', 10, 3 );

/**
 * For some cases such as smart coupon applied to cart, we need to recalculate tax in the tax endpoint.
 *
 * @since 2.19.0
 * @access public
 *
 */
function bolt_enable_fedex_calculation_on_tax_endpoint( $reference, $original_session_data ) {
	if ( defined( 'WC_SHIPPING_FEDEX_VERSION' ) ) {
		add_filter( 'woocommerce_is_checkout', '\BoltCheckout\bolt_emulate_checkout_for_fedex', 10 );
	}
}

add_action( 'wc_bolt_before_calculate_tax', '\BoltCheckout\bolt_enable_fedex_calculation_on_tax_endpoint', 10, 2 );
