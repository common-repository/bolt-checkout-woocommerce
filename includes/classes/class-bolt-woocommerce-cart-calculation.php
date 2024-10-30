<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class to call woocommerce cart calculation in safe way
 *
 * @class   Bolt_Subscription
 * @version 2.0.11
 * @author  Bolt
 */
class Bolt_woocommerce_cart_calculation {

	public static $filter_added = false;

	/**
	 * We use this function as filter for wc_get_default_shipping_method_for_package
	 * It should return its third argument
	 *
	 * @since  2.0.11
	 *
	 */
	public static function override_default_shipping_method( $default, $rates, $chosen_method ) {
		if ( ! empty( $chosen_method ) ) {
			return $chosen_method;
		} else {
			return $default;
		}
	}

	/**
	 * Add filter before WC cart calculation to do it in safe way
	 *
	 * Woocommerce expects that first shipping method is already selected by default and only
	 * after that user can select another shipping method.
	 * So if no shipping method selected woocoommerce consider that shipping methods set are changed
	 * and set default shipping method
	 * Bolt works in another way: when we create order and user selects, for example, second shipping method,
	 * no shipping method is selected at this moment
	 * Usually we don't have this problem on shipping&tax step, because we set shipping methods in order starting from first
	 * When we ask woocommerce to set first method, it set the default and it's the same
	 * But sometimes third party plugins can override default shipping method.
	 *
	 * The goal of this function is to change work of woocommerce function wc_get_chosen_shipping_method_for_package()
	 * so it always return shipping method from session, even if no shipping method was selected before.
	 */
	public static function add_filter() {
		if ( WC()->session->get( 'chosen_shipping_methods' ) && ! wc_bolt_if_cart_has_multiple_packages() ) {
			add_filter( 'woocommerce_shipping_chosen_method', array(
				'\BoltCheckout\Bolt_woocommerce_cart_calculation',
				'override_default_shipping_method'
			), 100, 3 );
			self::$filter_added = true;
		}

	}

	/**
	 * Remove filter after WC cart calculation
	 *
	 * @since  2.0.11
	 *
	 */
	public static function remove_filter() {
		if ( self::$filter_added ) {
			remove_filter( 'woocommerce_shipping_chosen_method', array(
				'\BoltCheckout\Bolt_woocommerce_cart_calculation',
				'override_default_shipping_method'
			), 100 );
			self::$filter_added = false;
		}

	}

	/**
	 * Safe call for WC()->cart->calculate_totals
	 *
	 * @since  2.0.11
	 *
	 */
	public static function calculate() {

		self::add_filter();
		WC()->cart->calculate_totals();
		self::remove_filter();

	}
}