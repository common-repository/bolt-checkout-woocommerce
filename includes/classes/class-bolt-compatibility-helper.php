<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compatibility Helper class
 *
 * @class   Bolt_Compatibility_Helper
 * @version 1.1.5
 * @author  Bolt
 */

/**
 * Bolt_Compatibility_Helper.
 */
class Bolt_Compatibility_Helper {

	/**
	 * The single instance of the class.
	 *
	 * @var Bolt_Compatibility_Helper
	 * @since 1.1.5
	 */
	private static $_instance;

	/**
	 * Get the instance and use the functions inside it.
	 *
	 * This plugin utilises the PHP singleton design pattern.
	 *
	 * @return object self::$_instance Instance
	 *
	 * @since     1.1.5
	 * @static
	 * @access    public
	 *
	 */
	public static function get_instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Throw error on object clone.
	 *
	 * The whole idea of the singleton design pattern is that there is a single
	 * object therefore, we don't want the object to be cloned.
	 *
	 * @since  1.1.5
	 * @access public
	 *
	 */
	public function __clone() {
		// Cloning instances of the class is forbidden.
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cheatin&#8217; huh?', 'bolt-checkout-woocommerce' ), '1.0' );
	}

	/**
	 * Disable Unserialize of the class.
	 *
	 * @since  1.1.5
	 * @access public
	 *
	 */
	public function __wakeup() {
		// Unserialize instances of the class is forbidden.
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cheatin&#8217; huh?', 'bolt-checkout-woocommerce' ), '1.0' );
	}

	/**
	 * Constructor Function.
	 *
	 * @since  1.1.5
	 * @access public
	 */
	public function __construct() {
		self::$_instance = $this;
	}

	/**
	 * Reset the instance of the class
	 *
	 * @since  1.1.5
	 * @access public
	 */
	public static function reset() {
		self::$_instance = null;
	}

	/*
	|--------------------------------------------------------------------------
	| Methods for WC_Cart.
	|--------------------------------------------------------------------------
	|
	|
	*/

	/**
	 * Checks if the given email address(es) matches the ones specified on the coupon.
	 *
	 * @param array $wc_cart The WooCommerce cart object.
	 * @param array $check_emails Array of customer email addresses.
	 * @param array $restrictions Array of allowed email addresses.
	 *
	 * @return bool
	 */
	public function check_wc_cart_is_coupon_emails_allowed( $wc_cart, $check_emails, $restrictions ) {
		if ( version_compare( WC_VERSION, '3.4.0', '<' ) ) {
			foreach ( $check_emails as $check_email ) {
				// With a direct match we return true.
				if ( in_array( $check_email, $restrictions, true ) ) {
					return true;
				}

				// Go through the allowed emails and return true if the email matches a wildcard.
				foreach ( $restrictions as $restriction ) {
					// Convert to PHP-regex syntax.
					$regex = '/^' . str_replace( '*', '(.+)?', $restriction ) . '$/';
					preg_match( $regex, $check_email, $match );
					if ( ! empty( $match ) ) {
						return true;
					}
				}
			}

			// No matches, this one isn't allowed.
			return false;
		} else {
			return $wc_cart->is_coupon_emails_allowed( $check_emails, $restrictions );
		}
	}

	/*
	|--------------------------------------------------------------------------
	| General functions available on both the front-end and admin.
	|--------------------------------------------------------------------------
	|
	|
	*/

	/**
	 * Wrapper for mb_strtoupper which see's if supported first.
	 *
	 * @param string $string String to format.
	 *
	 * @return string
	 * @since 1.1.5
	 * @static
	 * @access public
	 *
	 */
	public static function make_string_uppercase( $string ) {
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $string ) : strtoupper( $string );
	}

	/**
	 * Is registration required to checkout?
	 *
	 * @return boolean
	 * @since  1.1.5
	 * @access public
	 *
	 */
	public function check_is_registration_required() {
		return apply_filters( 'woocommerce_checkout_registration_required', 'yes' !== get_option( 'woocommerce_enable_guest_checkout' ) );
	}

	/**
	 * See how much stock is being held in pending orders.
	 *
	 * @param WC_Product $product Product to check.
	 * @param integer $exclude_order_id Order ID to exclude.
	 *
	 * @return int
	 * @since 2.0.2
	 */
	function get_held_stock_quantity( $product, $exclude_order_id = 0 ) {
		$hold_stock_minutes = (int) get_option( 'woocommerce_hold_stock_minutes', 0 );

		return ( $hold_stock_minutes > 0 ) ? wc_get_held_stock_quantity( $product, $exclude_order_id ) : 0;
	}

	// Methods for backward compatibility with extensions
	// TODO: remove them after update extensions

	public function wc_cart_get_total_tax() {
		return WC()->cart->get_total_tax();
	}

	public function wc_order_get_order_key( $order, $context = 'view' ) {
		return $order->get_order_key( $context );
	}

	public function wc_customer_set_location( $country, $state, $postcode = '', $city = '' ) {
		WC()->customer->set_location( $country, $state, $postcode, $city );
	}

}

/**
 * Returns the instance of Bolt_Compatibility_Helper to use globally.
 *
 * @return Bolt_Compatibility_Helper
 * @since  1.1.5
 */
function bolt_compat() {
	return Bolt_Compatibility_Helper::get_instance();
}
