<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bolt Third-party addons support Functions
 *
 * Functions to support Free Gifts For Woocommerce.
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */

/**
 * 
 * Gets WC cart and checks each product whether it's a free gift. If it is, set its price to zero. 
 * This price setting will help us pass Bolt's comparison of cart pre and post auth.
 *
 */
function set_free_products_price_in_wc_session() {
	foreach ( WC()->cart->get_cart() as $item ) {
		if ( isset( $item[ 'fgf_gift_product' ] ) ) {
			$item['data']->set_price(0);
		}
	}
}
add_action( 'wc_bolt_presetup_set_cart_by_bolt_reference', 'BoltCheckout\set_free_products_price_in_wc_session' );
