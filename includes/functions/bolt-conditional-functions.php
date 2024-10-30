<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bolt Conditional Functions
 *
 * Functions for determining the current query/page.
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */

/**
 * Determine if show Bolt button on the checkout page
 *
 * @param array $gateways Available payment gateways.
 *                        Default null.
 *
 * @return bool  Returns true if decide to show Bolt button on the checkout page and false if not
 *
 * @since 1.3.6
 * @access public
 *
 */
function wc_bolt_if_show_on_checkout_page( $gateways = null ) {
	global $wp;
	$settings_instance = wc_bolt()->get_bolt_settings();

	$if_show_on_checkout_page = true;

	if ( ! apply_filters( 'wc_bolt_pre_is_show_on_checkout_page', $if_show_on_checkout_page, $gateways ) ) {
		return false;
	}

	$is_enable_checkout_page = $settings_instance->is_setting_enabled( Bolt_Settings::SETTING_NAME_ENABLE_CHECKOUT_PAGE );
	$is_enable_order_pay     = $settings_instance->is_setting_enabled( Bolt_Settings::SETTING_NAME_ENABLE_ORDER_PAY );
	$is_checkout_page        = wc_bolt_is_checkout_page();
	$is_normal_checkout      = $is_checkout_page && empty( $wp->query_vars['order-pay'] );
	$is_orderpay             = $is_checkout_page && ! empty( $wp->query_vars['order-pay'] );

	// if the Bolt payment gateway is unavailable.
	if ( ( ! ( $is_enable_checkout_page && $is_normal_checkout && ! WC()->cart->is_empty() ) && ! ( $is_enable_order_pay && $is_orderpay ) )
	     || ! wc_bolt_if_bolt_payment_gateway_available( $gateways ) ) {
		$if_show_on_checkout_page = false;
	}

	// The filter `wc_bolt_is_show_on_checkout_page` allow for a merchant hook to do customization per condition.
	return apply_filters( 'wc_bolt_is_show_on_checkout_page', $if_show_on_checkout_page, $gateways );
}

/**
 * Determine if show Bolt button on the cart page
 *
 * @param array $gateways Available payment gateways.
 *                        Default null.
 *
 * @return bool  Returns true if decide to show Bolt button on the cart page and false if not
 *
 * @since 1.3.6
 * @access public
 *
 */
function wc_bolt_if_show_on_cart_page( $gateways = null ) {
	$settings_instance    = wc_bolt()->get_bolt_settings();
	$if_show_on_cart_page = true;

	if ( ! apply_filters( 'wc_bolt_pre_is_show_on_cart_page', $if_show_on_cart_page, $gateways ) ) {
		return false;
	}

	// if the Bolt payment gateway is unavailable.
	if ( ! $settings_instance->is_setting_enabled( Bolt_Settings::SETTING_NAME_ENABLE_SHOPPING_CART )
	     || ! wc_bolt_is_cart_page()
	     || WC()->cart->is_empty()
	     || ! wc_bolt_if_bolt_payment_gateway_available( $gateways ) ) {
		$if_show_on_cart_page = false;
	}

	// The filter `wc_bolt_is_show_on_cart_page` allow for a merchant hook to do customization per condition.
	return apply_filters( 'wc_bolt_is_show_on_cart_page', $if_show_on_cart_page, $gateways );
}

/**
 * Determine if show Bolt button on the single product page
 *
 * @param string $type The type of Bolt button shown on single product page
 *                           - normal        Bolt button for normal checkout
 *                           - subscription  Bolt button for subscription checkout
 *                           - either        Either for normal checkout or subscription checkout, this value is for loading Bolt resource.
 *
 * @param array $gateways Available payment gateways.
 *                        Default null.
 *
 * @return bool  Returns true if decide to show Bolt button on the single product page and false if not
 *
 * @since 1.3.6
 * @access public
 *
 */
function wc_bolt_if_show_on_single_product_page( $type, $gateways = null ) {
	$settings_instance              = wc_bolt()->get_bolt_settings();
	$if_show_on_single_product_page = true;

	if ( ! apply_filters( 'wc_bolt_pre_is_show_on_single_product_page', $if_show_on_single_product_page, $gateways ) ) {
		return false;
	}

	$setting_enable = $type !== 'subscription' && $settings_instance->is_setting_enabled( Bolt_Settings::SETTING_NAME_PRODUCT_PAGE_CHECKOUT );


	// if the Bolt payment gateway is unavailable.
	if ( ! $settings_instance->is_setting_enabled( Bolt_Settings::SETTING_NAME_ENABLED ) || ! $setting_enable || ! is_product() ) {
		$if_show_on_single_product_page = false;
	}

	// The filter `wc_bolt_is_show_on_single_product_page` allow for a merchant hook to do customization per condition.
	return apply_filters( 'wc_bolt_is_show_on_single_product_page', $if_show_on_single_product_page, $type, $gateways );
}

/**
 * Determine if show Bolt button on the mini-cart widget
 *
 * @param array $gateways Available payment gateways.
 *                        Default null.
 *
 * @return bool  Returns true if decide to show Bolt button on the mini-cart widget and false if not
 *
 * @since 1.3.6
 * @access public
 *
 */
function wc_bolt_if_show_on_mini_cart( $gateways = null ) {
	$settings_instance    = wc_bolt()->get_bolt_settings();
	$if_show_on_mini_cart = true;

	if ( ! apply_filters( 'wc_bolt_pre_is_show_on_mini_cart', $if_show_on_mini_cart, $gateways ) ) {
		return false;
	}

	// if the Bolt payment gateway is unavailable.
	if ( ! $settings_instance->is_setting_enabled( Bolt_Settings::SETTING_NAME_ENABLED )
	     || ! $settings_instance->is_setting_enabled( Bolt_Settings::SETTING_NAME_ENABLE_MINI_CART )
	     || WC()->cart->is_empty() ) {
		$if_show_on_mini_cart = false;
	}

	// The filter `wc_bolt_is_show_on_mini_cart` allow for a merchant hook to do customization per condition.
	return apply_filters( 'wc_bolt_is_show_on_mini_cart', $if_show_on_mini_cart, $gateways );
}

/**
 * Check if a product is available for adding to Bolt cart.
 *
 * @return bool  Returns true if this product is available and false if not
 *
 * @since 1.3.6
 * @access public
 *
 */
function wc_bolt_if_product_available() {
	global $post;
	if ( $post && get_post_type( $post ) === 'product' ) {
		$product = wc_get_product( $post );
		if ( $product->is_type( 'variable' ) ) {
			// For variable product, its variations could manage stock at variation level, so get product availability at variation level.
			$get_variations       = count( $product->get_children() ) <= apply_filters( 'woocommerce_ajax_variation_threshold', 30, $product );
			$available_variations = $get_variations ? $product->get_available_variations() : false;
			$product_available    = ( ! empty( $available_variations ) || false === $available_variations );
		} else {
			$product_available = ( $product->is_in_stock() && $product->is_purchasable() );
		}
	} else {
		$product_available = false;
	}

	// The filter `wc_bolt_is_product_available` allow for a merchant hook to do customization per condition.
	return apply_filters( 'wc_bolt_is_product_available', $product_available, $post );
}

/**
 * Check if the Bolt payment gateway is available.
 *
 * @param array $gateways Available payment gateways.
 *                        Default null.
 *
 * @return bool Returns true if this Bolt payment gateway is available and false if not
 *
 * @since 1.3.6
 * @access public
 *
 */
function wc_bolt_if_bolt_payment_gateway_available( $gateways ) {
	$gateways = $gateways ?: WC()->payment_gateways->get_available_payment_gateways();

	return isset( $gateways[ BOLT_GATEWAY_NAME ] );
}

/**
 * Check if processing on the checkout page, excluding order received page.
 *
 * @return bool
 *
 * @since 1.3.6
 * @access public
 *
 *
 */
function wc_bolt_is_checkout_page() {
	global $wp;
	$page_id = wc_get_page_id( 'checkout' );
	// If the current request is for an administrative interface page, return false immediately.
	$is_checkout_page = ! is_admin() && ! isset( $wp->query_vars['order-received'] ) && ( ( $page_id && is_page( $page_id ) ) || wc_post_content_has_shortcode( 'woocommerce_checkout' ) || apply_filters( 'woocommerce_is_checkout', false ) || doing_action( 'woocommerce_checkout_order_review' ) || check_ajax_referer( 'update-order-review', 'security', false ) );

	// The filter `wc_bolt_is_checkout_page` allow for a merchant hook to do customization per condition.
	return apply_filters( 'wc_bolt_is_checkout_page', $is_checkout_page );
}

/**
 * Check if processing on the cart page.
 *
 * @return bool
 *
 * @since 1.3.6
 * @access public
 *
 *
 */
function wc_bolt_is_cart_page() {
	$page_id = wc_get_page_id( 'cart' );
	// Including the condition if invoked by ajax which is to update shipping method on cart page.
	// If the current request is for an administrative interface page, return false immediately.
	$is_cart_page = ! is_admin() && ( ( $page_id && is_page( $page_id ) ) || wc_post_content_has_shortcode( 'woocommerce_cart' ) || check_ajax_referer( 'update-shipping-method', 'security', false ) );

	// The filter `wc_bolt_is_cart_page` allow for a merchant hook to do customization per condition.
	return apply_filters( 'wc_bolt_is_cart_page', $is_cart_page );
}

/**
 * Check if Bolt is eliminated from current page.
 *
 * @param array $gateways Available payment gateways.
 *                        Default null.
 *
 * @return bool Returns true if Bolt is eliminated from current page and false if not
 *
 * @since 1.3.6
 * @access public
 *
 */
function wc_bolt_is_eliminated_from_current_page( $gateways = null ) {
	global $wp;
	$settings_instance       = wc_bolt()->get_bolt_settings();
	$is_enable_bolt          = $settings_instance->is_setting_enabled( Bolt_Settings::SETTING_NAME_ENABLED );
	$is_enable_shopping_cart = $settings_instance->is_setting_enabled( Bolt_Settings::SETTING_NAME_ENABLE_SHOPPING_CART );
	$is_enable_checkout_page = $settings_instance->is_setting_enabled( Bolt_Settings::SETTING_NAME_ENABLE_CHECKOUT_PAGE );
	$is_enable_order_pay     = $settings_instance->is_setting_enabled( Bolt_Settings::SETTING_NAME_ENABLE_ORDER_PAY );
	$is_enable_mini_cart     = $settings_instance->is_setting_enabled( Bolt_Settings::SETTING_NAME_ENABLE_MINI_CART );
	$is_enable_ppc           = $settings_instance->is_setting_enabled( Bolt_Settings::SETTING_NAME_PRODUCT_PAGE_CHECKOUT );

	if ( ! $is_enable_bolt ) {
		// if the Bolt payment gateway is unavailable.
		$condition = true;
	} elseif ( is_admin() ) {
		// If the current request is for an administrative interface page.
		$condition = true;
	} elseif ( apply_filters( 'wc_bolt_is_show_on_mini_cart', $is_enable_mini_cart, $gateways ) ) {
		// The mini-cart widget can be loaded on any page, so if Bolt is enabled on mini-cart it means Bolt button can be loaded on any page.
		// Then only when Bolt is disabled on mini-cart, we check the other conditions.
		$condition = false;
	} elseif ( wc_bolt_is_cart_page() ) { // If on cart page
		$condition = ! apply_filters( 'wc_bolt_is_show_on_cart_page', ( $is_enable_shopping_cart && ! WC()->cart->is_empty() ), $gateways );
	} elseif ( wc_bolt_is_checkout_page() ) { // If on checkout page.
		// If need to load Bolt in default checkout page or order pay page.
		$condition = ! ( ( ( $is_enable_order_pay && ! empty( $wp->query_vars['order-pay'] ) )
		                   || ( $is_enable_checkout_page && empty( $wp->query_vars['order-pay'] ) && ! WC()->cart->is_empty() ) )
		                 && apply_filters( 'wc_bolt_is_show_on_checkout_page', true, $gateways ) );
	} elseif ( is_product() && wc_bolt_if_product_available() ) { // If show on product page
		global $post;
		$condition = ! apply_filters( 'wc_bolt_is_show_on_single_product_page', $is_enable_ppc, 'either', $gateways );
	} else {
		$condition = true;
	}

	// The filter `wc_bolt_is_eliminated_from_current_page` allow for a merchant hook to do customization per condition.
	return apply_filters( 'wc_bolt_is_eliminated_from_current_page', $condition );
}

/**
 * Check if a WooCommerce order is already paid via Bolt
 *
 * @param WC_Order $order Woocommerce order
 *
 * @return bool Returns true if a WooCommerce order is already paid via Bolt and false if not
 *
 * @since 1.3.6
 * @access public
 *
 */
function wc_bolt_is_paid( $order ) {
	// for non-pre-auth checkout
	$transaction_id = wc_bolt_get_order_meta( $order, BOLT_ORDER_META_TRANSACTION_REFERENCE_ID, true );
	// for pre-auth
	$transaction_order = wc_bolt_get_order_meta( $order, BOLT_ORDER_META_TRANSACTION_ORDER, true );

	// The filter `wc_bolt_is_paid` allow for a merchant hook to do customization per condition.
	return apply_filters( 'wc_bolt_is_paid', ( ! empty( $transaction_id ) || ! empty( $transaction_order ) ), $order->get_id() );
}

/**
 * Check if pay for existing order
 *
 * @return bool Returns true if pay for existing order and false if not
 *
 * @since 2.4.0
 * @access public
 *
 */
function wc_bolt_is_pay_exist_order() {
	global $wp;
	$is_pay_exist_order = false;
	if ( ! empty( $wp->query_vars['order-pay'] ) ) {
		$order_id = absint( $wp->query_vars['order-pay'] );
		// Pay for existing order.
		if ( isset( $_GET['pay_for_order'], $_GET['key'] ) && $order_id ) {
			$is_pay_exist_order = true;
		}
	}

	return apply_filters( 'wc_bolt_is_pay_exist_order', $is_pay_exist_order );
}

/**
 * Check if the request uri is Bolt api request.
 *
 * @since 2.14.0
 * @access public
 *
 */
function wc_bolt_is_bolt_rest_api_request() {
	if ( empty( $_SERVER['REQUEST_URI'] ) ) {
		return false;
	}

	$rest_prefix = trailingslashit( rest_get_url_prefix() );

	return apply_filters( 'wc_bolt_is_bolt_rest_api_request', ( false !== strpos( $_SERVER['REQUEST_URI'], $rest_prefix . 'bolt/' ) ) );
}

/**
 * Check if the request uri is Bolt update-cart api request.
 *
 * @since 2.14.0
 * @access public
 *
 */
function wc_bolt_is_update_cart_api_request() {
	if ( empty( $_SERVER['REQUEST_URI'] ) ) {
		return false;
	}

	$rest_prefix = trailingslashit( rest_get_url_prefix() );

	return apply_filters( 'wc_bolt_is_update_cart_api_request', ( false !== strpos( $_SERVER['REQUEST_URI'], $rest_prefix . 'bolt/update-cart' ) ) );
}