<?php

namespace BoltCheckout;

/**
 * Handle with woocommerce hooks around order creation:
 * postpone or remove them in necessary cases
 *
 * @package Woocommerce_Bolt_Checkout/Classes
 * @version 1.0.0
 * @since   2.0.11
 */

defined( 'ABSPATH' ) || exit;

/**
 * WC_Bolt_Data_Manager Class.
 */
class WC_Bolt_Hook_Manager {
	public static $postponed_order_creation_hook = null;

	/**
	 * Postpone hook matching the criteria
	 *
	 * @param string $hook_name hook name
	 * @param string $class_name class name. We postpone hooks that calls methods from this class.
	 *
	 * @since   2.0.11
	 */
	private static function postpone_hook( $hook_name, $class_name ) {
		global $wp_filter;
		if ( isset( $wp_filter[ $hook_name ] ) ) {
			foreach ( $wp_filter[ $hook_name ] as $priority => $functions ) {
				foreach ( $functions as $name => $content ) {
					if ( isset( $content['function'] ) && is_array( $content['function'] ) && isset( $content['function'][0] ) && is_a( $content['function'][0], $class_name ) ) {
						self::$postponed_order_creation_hook[ $hook_name ][ $priority ][] = $content['function'];
					}
				}
			}
		}
		if ( isset( self::$postponed_order_creation_hook[ $hook_name ] ) ) {
			foreach ( self::$postponed_order_creation_hook[ $hook_name ] as $priority => $functions ) {
				foreach ( $functions as $function ) {
					remove_action( $hook_name, $function, $priority );
				}
			}
		}

	}

	/**
	 * Remove all order creation hooks that we can postpone and do when
	 * we send answer and close connection
	 *
	 * @since   2.0.11
	 */
	public static function postpone_order_creation_hooks() {
		self::$postponed_order_creation_hook = array();

		$postpone_hook_array = apply_filters( 'wc_bolt_postpone_order_creation_hooks', array(
			array( 'hook_name' => 'woocommerce_new_order', 'class_name' => 'WC_Webhook' ),
			array( 'hook_name' => 'woocommerce_update_order', 'class_name' => 'WC_Webhook' ),
		) );
		foreach ( $postpone_hook_array as $hook ) {
			self::postpone_hook( $hook['hook_name'], $hook['class_name'] );
		}

	}

	/**
	 * Call order creation hooks we postponed before
	 *
	 * @param $order_id
	 *
	 * @since   2.0.11
	 */
	public static function execute_order_creation_hooks( $order_id ) {
		if ( empty( self::$postponed_order_creation_hook ) ) {
			return;
		}
		// We don't want to call woocommerce_update_order hooks, because we sent order into woocommerce_new_order
		// with all changed we did before.
		if ( isset( self::$postponed_order_creation_hook['woocommerce_new_order'] ) ) {
			asort( self::$postponed_order_creation_hook['woocommerce_new_order'], SORT_NUMERIC );
			foreach ( self::$postponed_order_creation_hook['woocommerce_new_order'] as $priority => $functions ) {
				foreach ( $functions as $function ) {
					call_user_func( $function, $order_id );
				}
			}
		}
		self::$postponed_order_creation_hook = null;

		do_action( 'wc_bolt_execute_order_creation_hooks' );
	}
}