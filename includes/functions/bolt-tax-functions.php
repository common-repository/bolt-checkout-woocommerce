<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bolt Tax Functions
 *
 * Functions for tax calculation specific things.
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */

/**
 * Apply rounding to an array of taxes before summing. Rounds to store DP setting, ignoring precision.
 *
 * @param float $value Tax value.
 * @param bool $in_cents Whether precision of value is in cents.
 *
 * @return float
 * @since  2.6.0
 */
function wc_bolt_round_line_tax( $value, $in_cents = true ) {
	if ( ! ( 'yes' === get_option( 'woocommerce_tax_round_at_subtotal' ) ) ) {
		$value = wc_round_tax_total( $value, $in_cents ? 0 : null );
	}

	return $value;
}

/**
 * Get taxes merged.
 *
 * @param array $tax_items Tax items to merge and return. Defaults to empty array.
 *
 * @return array
 * @since 2.6.0
 */
function wc_bolt_get_merged_tax( $tax_items = array() ) {
	$merged_taxes = array();
	foreach ( $tax_items as $item ) {
		foreach ( $item as $rate_id => $rate ) {
			if ( ! isset( $merged_taxes[ $rate_id ] ) ) {
				$merged_taxes[ $rate_id ] = 0;
			}
			$merged_taxes[ $rate_id ] += wc_bolt_round_line_tax( $rate );
		}
	}

	return array_sum( wc_remove_number_precision_deep( $merged_taxes ) );
}

/**
 * Combine taxes into a single array, preserving keys.
 *
 * @param array $item_taxes Taxes to combine.
 *
 * @return array
 * @since 2.6.0
 */
function wc_bolt_combine_taxes( $item_taxes ) {
	$merged_taxes = array();
	foreach ( $item_taxes as $taxes ) {
		foreach ( $taxes as $tax_id => $tax_amount ) {
			if ( ! isset( $merged_taxes[ $tax_id ] ) ) {
				$merged_taxes[ $tax_id ] = 0;
			}
			$merged_taxes[ $tax_id ] += $tax_amount;
		}
	}

	return $merged_taxes;
}

/**
 * Get tax statuses of all shipping methods which are hooked in.
 *
 * @return array
 * @since 2.7.0
 */
function wc_bolt_get_shipping_methods_tax_status() {
	$shipping_tax_rates            = \WC_Tax::get_shipping_tax_rates();
	$shipping_methods_tax_statuses = array();
	if ( ! empty( $shipping_tax_rates ) ) {
		$loaded_shipping_methods = WC()->shipping()->get_shipping_methods();

		foreach ( $loaded_shipping_methods as $shipping_method ) {
			if ( $shipping_method->is_enabled() && $shipping_method->is_taxable() ) {
				$shipping_methods_tax_statuses[ $shipping_method->instance_id . '_' . $shipping_method->id ] = $shipping_method;
			}
		}
	}

	return apply_filters( 'wc_bolt_get_shipping_methods_tax_status', $shipping_methods_tax_statuses );
}

/**
 * Calculate shipping taxes.
 *
 * @param string $shipping_method_key Shipping method key, combined with shipping method id and instance id. eg. flat_rate:1
 *
 * @return array
 * @since 2.6.0
 */
function wc_bolt_calculate_shipping_method_tax( $shipping_method_key ) {
	wc_bolt_set_chosen_shipping_method_for_first_package( $shipping_method_key );

	$shipping_lines = array();
	foreach ( WC()->cart->calculate_shipping() as $key => $shipping_object ) {
		$shipping_line          = (object) array(
			'taxes'     => array(),
			'total_tax' => 0,
		);
		$shipping_line->taxes   = wc_add_number_precision_deep( $shipping_object->taxes, false );
		$shipping_lines[ $key ] = $shipping_line;

	}

	$shipping_taxes = wc_bolt_combine_taxes( wp_list_pluck( $shipping_lines, 'taxes' ) );

	return $shipping_taxes;
}