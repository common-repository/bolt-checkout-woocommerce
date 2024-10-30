<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bolt Third-party addons support
 *
 * Class to support WP rocket module
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */
class WP_Rocket_Helper {
	/**
	 * Return true if we should support taxjar plugin
	 */
	public static function is_enabled() {
		return apply_filters( 'bolt_woocommerce_is_wp_rocket_enabled', class_exists( '\WP_Rocket\Plugin' ) );
	}


	/**
	 * Prevent cache clear when users data is changed
	 *
	 * WP rocket clears the cache when we update any user
	 * They do it in case user privileges are changed
	 * We change only user address so we can prevent cache clearing
	 */
	public static function remove_empty_cache_on_user_change() {
		remove_action( 'profile_update', 'rocket_clean_domain', 10 );
	}
}
