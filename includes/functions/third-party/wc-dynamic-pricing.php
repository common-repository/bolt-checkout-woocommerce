<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bolt Third-party addons support
 *
 * Class to support WooCommerce Dynamic Pricing
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */

/**
 * Collect cart info to apply dynamic pricing rules before loading Bolt cart session.
 *
 * @since 2.14.0
 * @access public
 */
function bolt_fix_wc_dynamic_pricing_before_set_cart( $reference, $original_session_data ) {
	bolt_fix_wc_dynamic_pricing_register_counter();
}

add_action( 'wc_bolt_before_set_cart_by_bolt_reference', 'BoltCheckout\bolt_fix_wc_dynamic_pricing_before_set_cart', 10, 2 );

/**
 * Collect cart info to apply dynamic pricing rules before loading WooCommerce native cart session.
 *
 * @since 2.15.0
 * @access public
 */
function bolt_fix_wc_dynamic_pricing_before_restore_native_session( $customer_id, $reference ) {
	bolt_fix_wc_dynamic_pricing_register_counter();
}

add_action( 'wc_bolt_before_load_cart_from_native_wc_session', 'BoltCheckout\bolt_fix_wc_dynamic_pricing_before_restore_native_session', 10, 2 );

/**
 * Fix the compatibility issue with WooCommerce Dynamic Pricing.
 *
 * Create instance of WC_Dynamic_Pricing_Counter then it can collect wc cart info for applying rules.
 *
 * @since 2.15.0
 * @access public
 */
function bolt_fix_wc_dynamic_pricing_register_counter() {
	if ( class_exists( 'WC_Dynamic_Pricing_Counter' ) ) {
		WC()->session->set( 'bolt_fix_wc_dynamic_pricing', null );
		\WC_Dynamic_Pricing_Counter::register();
	}
}

/**
 * Fix the compatibility issue with WooCommerce Dynamic Pricing.
 *
 * Apply the dynamic pricing rules to the cart after loading cart from the Bolt session.
 *
 *
 * @since 2.14.0
 * @access public
 *
 */
function bolt_fix_wc_dynamic_pricing_after_set_cart( $reference, $original_session_data ) {
	global $wp_filter;
	if ( isset( $wp_filter['woocommerce_cart_loaded_from_session'] ) ) {
		foreach ( $wp_filter['woocommerce_cart_loaded_from_session'] as $pri => $funcs ) {
			foreach ( $funcs as $name => $content ) {
				if ( isset( $content['function'][0] ) && is_a( $content['function'][0], '\WC_Dynamic_Pricing' ) ) {
					// Since WooCommerce Dynamic Pricing 3.1.28, it would adjust item price per rule for rest api,
					// so there is no need to initialize the dynamic pricing counter in our plugin.
					return;
				}
			}
		}
	}
	bolt_fix_wc_dynamic_pricing_force_cart_totals_calculation();
}

add_action( 'wc_bolt_after_set_cart_by_bolt_reference', 'BoltCheckout\bolt_fix_wc_dynamic_pricing_after_set_cart', 10, 2 );

/**
 * Force calculation of totals after loading WooCommerce native cart session.
 *
 * @since 2.15.0
 * @access public
 */
function bolt_fix_wc_dynamic_pricing_after_restore_native_session( $customer_id, $reference ) {
	bolt_fix_wc_dynamic_pricing_force_cart_totals_calculation();
}

add_action( 'wc_bolt_after_load_cart_from_native_wc_session', 'BoltCheckout\bolt_fix_wc_dynamic_pricing_after_restore_native_session', 10, 2 );

/**
 * Fix the compatibility issue with WooCommerce Dynamic Pricing.
 *
 * Force calculation of totals so that they are updated.
 *
 * @since 2.15.0
 * @access public
 */
function bolt_fix_wc_dynamic_pricing_force_cart_totals_calculation() {
	if ( class_exists( 'WC_Dynamic_Pricing' ) && ! wc_bolt_is_update_cart_api_request() ) {
		WC()->session->set( 'bolt_fix_wc_dynamic_pricing', true );
		//Initialize the dynamic pricing counter.  Records various counts when items are restored from session.
		\WC_Dynamic_Pricing::instance()->on_cart_loaded_from_session( WC()->cart );
	}
}

/**
 * Fix the compatibility issue with WooCommerce Dynamic Pricing.
 *
 * Apply the dynamic pricing rules before woocommerce calculates cart totals for Bolt checkout.
 *
 *
 * @since 2.14.0
 * @access public
 *
 */
function bolt_fix_dynamic_pricing_before_wc_calculate_totals( $cart ) {
	if ( class_exists( 'WC_Dynamic_Pricing' ) && wc_bolt_is_bolt_rest_api_request() ) {
		\WC_Dynamic_Pricing::instance()->on_calculate_totals( WC()->cart );
		// Reset the shipping cache, so the taxes can be calculated correctly.
		$shipping_packages = WC()->cart->get_shipping_packages();
		foreach ( $shipping_packages as $package_key => $package ) {
			$wc_session_key = 'shipping_for_package_' . $package_key;
			WC()->session->set( $wc_session_key, null );
		}
	}
}

add_action( 'woocommerce_before_calculate_totals', 'BoltCheckout\bolt_fix_dynamic_pricing_before_wc_calculate_totals', 99, 1 );

/**
 * Fix the compatibility issue with WooCommerce Dynamic Pricing.
 *
 * Load the dynamic pricing Advanced_Totals modules for the Bolt api request.
 *
 *
 * @since 2.14.0
 * @access public
 *
 */
function bolt_fix_dynamic_pricing_load_modules_for_bolt_api( $modules ) {
	if ( ! class_exists( 'WC_Dynamic_Pricing' ) || ! WC()->session ) {
		return;
	}

	if ( wc_bolt_is_bolt_rest_api_request() && ! array_key_exists( 'advanced_totals', $modules ) ) {
		$modules['advanced_totals'] = \WC_Dynamic_Pricing_Advanced_Totals::instance();
	}

	if ( WC()->session->get( 'bolt_fix_wc_dynamic_pricing', null ) ) {
		foreach ( $modules as $module ) {
			if ( method_exists( $module, 'initialize_rules' ) ) {
				$module->initialize_rules();
			}
		}

		WC()->session->set( 'bolt_fix_wc_dynamic_pricing', null );
	}

	return $modules;
}

add_filter( 'wc_dynamic_pricing_load_modules', 'BoltCheckout\bolt_fix_dynamic_pricing_load_modules_for_bolt_api', 999, 1 );
