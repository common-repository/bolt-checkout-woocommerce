<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bolt Third-party addons support Functions
 *
 * Functions to support Route App.
 * Tested up to: 2.2.6
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */


/**
 * By default, the route widget is only loaded on the checkout page,
 * for Bolt checkout, we add this widget to the cart page, before the Bolt button.
 *
 * @since 2.15.0
 * @access public
 *
 */
function add_route_widget_to_cart_page() {
	if ( class_exists( 'Routeapp_Public' ) ) {
		echo do_shortcode( '[route]' );
	}
}

if ( Bolt_Feature_Switch::instance()->is_hook_priority_changed() ) {
	$priority = apply_filters( 'wc_bolt_set_priority_for_html_hooks', BOLT_PRIORITY_FOR_HTML_HOOKS_CHANGED ) - 1;
} else {
	$priority = BOLT_PRIORITY_FOR_HTML_HOOKS_DEFAULT - 1;
}

add_filter( 'woocommerce_proceed_to_checkout', '\BoltCheckout\add_route_widget_to_cart_page', $priority );

/**
 * Remove Route fee via update cart endpoint
 *
 * @since 2.16.0
 * @access public
 *
 */
function remove_route_fee_from_cart_by_update_cart_endpoint( $result, $item, $request, $error_handler ) {
	if ( 'ROUTEINS' == $item->product_id ) {
		WC()->session->set( 'checkbox_checked', false );

		return true;
	}

	return $result;
}

add_filter( 'wc_bolt_update_cart_endpoint_remove_item', '\BoltCheckout\remove_route_fee_from_cart_by_update_cart_endpoint', 10, 4 );

/**
 * Add Route fee via update cart endpoint
 *
 * @since 2.16.0
 * @access public
 *
 */
function add_route_fee_from_cart_by_update_cart_endpoint( $result, $item, $request, $error_handler ) {
	if ( 'ROUTEINS' == $item->product_id ) {
		WC()->session->set( 'checkbox_checked', true );

		return true;
	}

	return $result;
}

add_filter( 'wc_bolt_update_cart_endpoint_add_item', '\BoltCheckout\add_route_fee_from_cart_by_update_cart_endpoint', 10, 4 );

/**
 * Convert item reference of Route fee from route-shipping-protection to ROUTEINS,
 * so Bolt modal can recognize Route product addon
 *
 * @since 2.16.0
 * @access public
 *
 */
function update_route_fee_reference( $result ) {
	foreach ( $result[ BOLT_CART_ITEMS ] as &$item ) {
		if ( $item[ BOLT_CART_ITEM_REFERENCE ] == 'route-shipping-protection' ) {
			$item[ BOLT_CART_ITEM_REFERENCE ] = 'ROUTEINS';
			$item[ BOLT_CART_ITEM_IMAGE_URL ] = 'https://protect-lightning-bolt-widget.route.com/assets/logo-lighting-light.svg';
		}
	}

	return $result;
}

/**
 * Steps for Route plugin to sync order between WooC and Route:
 * 1. When the customer adds product to cart, the Route App plugin creates quote in Route via API and adds route insurance fee in checkout and cart.
 * 2. Right after the order is created, the Route App plugin saves the quote from the last request made on session into WooC order meta_data.
 * 3. Once the order status is updated, Route would retrieve info saved in WooC order meta_data and update related fields in Route order.
 *
 * This function is to setup a flag to let Bolt extension follow the logic in Bolt checkout.
 *
 * @since 2.17.0
 * @access public
 */
function setup_flag_route_fee_in_order_meta( $posted_data, $shipping_methods ) {
	WC()->session->set( 'bolt_routeapp_quote', true );
}

/**
 * This function is to save the quote cache from the last request of Route into WC seesion related to Bolt.
 *
 * @since 2.17.0
 * @access public
 */
function checkout_route_insurance_fee_save_session( $cart ) {
	if ( ( is_admin() && ! defined( 'DOING_AJAX' ) )
	     || ! WC()->session
	     || ! WC()->session->get( 'bolt_routeapp_quote', false )
	) {
		return;
	}

	$cart     = empty( $cart ) ? WC()->cart : $cart;
	$cartRef  = $cart->get_cart_hash();
	$main_key = get_routeapp_cache_api_session_key() . '-' . $cartRef;
	$cached   = WC()->session->get( $main_key );
	if ( ! empty( $cached ) ) {
		WC()->session->set( 'bolt_routeapp_quote_cache', $cached );
	}
	$key    = $main_key . '-latest';
	$cached = WC()->session->get( $key );
	if ( ! empty( $cached ) ) {
		WC()->session->set( 'bolt_routeapp_quote_cache_result', $cached );
	}

	WC()->session->set( 'bolt_routeapp_quote', false );
}

/**
 * This function is to restore the quote cache when the new WC order is created, so Route App plugin can read those info properly.
 *
 * @since 2.17.0
 * @access public
 */
function restore_route_fee_in_order_meta( $order_id ) {
	if ( ! $order_id && ! WC()->session ) {
		return;
	}
	$order   = wc_get_order( $order_id );
	$cartRef = $order->get_cart_hash();
	if ( ! $cartRef ) {
		return;
	}
	$main_key = get_routeapp_cache_api_session_key() . '-' . $cartRef;
	$cached   = WC()->session->get( 'bolt_routeapp_quote_cache' );
	if ( $cached ) {
		WC()->session->set( $main_key, $cached );
	}
	$key    = $main_key . '-latest';
	$cached = WC()->session->get( 'bolt_routeapp_quote_cache_result' );
	if ( $cached ) {
		WC()->session->set( $key, $cached );
	}
}

/**
 * RouteApp plugin has a feature that can exclude specific shipping methods from cart when Route fee is added.
 * This function is to support this feature for Bolt checkout.
 *
 * @since 2.19.0
 * @access public
 */
function filter_routeapp_excluded_shipping_methods( $shipping_options, $bolt_order ) {
	if ( ! WC()->session->get( 'checkbox_checked' ) ) {
		return $shipping_options;
	}
	global $routeapp_public;
	if ( ! $routeapp_public ) {
		return $shipping_options;
	}
	if ( empty( $shipping_options ) ) {
		return $shipping_options;
	}
	$tmp_options = array();
	foreach ( $shipping_options as $shipping_option ) {
		if ( ! $routeapp_public->routeapp_is_shipping_method_allowed( $shipping_option->reference ) ) {
			continue;
		}

		$tmp_options[] = $shipping_option;
	}

	return $tmp_options;
}

if ( class_exists( '\Routeapp' ) ) {
	/**
	 * Get cache api session key generated by Route app
	 */
	function get_routeapp_cache_api_session_key() {
		$routeapp_api_client = new \Routeapp_API_Client( get_option( 'routeapp_public_token' ), get_option( 'routeapp_secret_token' ) );

		return $routeapp_api_client->get_cache_api_session_key();
	}

	add_filter( 'wc_bolt_order_creation_cart_data', '\BoltCheckout\update_route_fee_reference', 10, 1 );
	add_action( 'wc_bolt_before_update_session_in_checkout', '\BoltCheckout\setup_flag_route_fee_in_order_meta', 10, 2 );
	add_action( 'woocommerce_cart_calculate_fees', '\BoltCheckout\checkout_route_insurance_fee_save_session', 21, 1 );
	add_action( 'woocommerce_new_order', '\BoltCheckout\restore_route_fee_in_order_meta', 9, 1 );
	add_filter( 'wc_bolt_after_load_shipping_options', '\BoltCheckout\filter_routeapp_excluded_shipping_methods', 9, 2 );
}
