<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bolt Third-party addons support Functions
 *
 * Functions to support WooCommerce ShipperHQ plugin.
 * Tested up to: 1.6.0
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */


/**
 * Add method description which holds the estimated delivery date if present.
 *
 *
 * @since 2.19.0
 * @access public
 *
 */
function bolt_add_shipperhq_descr_loading_shipping_options( $shipping_option, $method_key, $shipping_method, $bolt_order ) {
	try {
		if ( class_exists( 'ShipperHQ_Shipping' ) ) {
			if ($shipping_method->get_method_id() == "shipperhq") {
			$metaData = $shipping_method->get_meta_data();
			if (array_key_exists("method_description", $metaData)) {
				$shipping_option->service = $shipping_option->service . ' (' . $metaData['method_description'] . ')';
			}
		}
		}
	} catch ( Exception $e ) {
		throw $e;
	}

	return $shipping_option;
}

add_filter( 'wc_bolt_loading_shipping_option', 'BoltCheckout\bolt_add_shipperhq_descr_loading_shipping_options', 99, 4 );
