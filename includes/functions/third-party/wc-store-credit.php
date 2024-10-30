<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bolt Third-party addons support
 *
 * Class to support WooCommerce Store Credit
 * Tested up to: 4.0.5
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */

/**
 * Make WC Store Credit work with the callback of Bolt api endpoints.
 */
function wc_bolt_enable_bolt_rest_api_for_wc_store_credit( $is_request, $type ) {
	if ( $type == 'frontend' && ! empty( $_SERVER['REQUEST_URI'] ) && false !== strpos( $_SERVER['REQUEST_URI'], '/bolt/' ) ) {
		return true;
	}

	return $is_request;
}

add_filter( 'wc_store_credit_is_request', '\BoltCheckout\wc_bolt_enable_bolt_rest_api_for_wc_store_credit', 10, 2 );

/**
 * Returns proper discount type for the coupon of WC Store Credit.
 */
function wc_bolt_add_wc_store_credit_from_discount_hook( $discount_info, $discount_code ) {
	$code = sanitize_text_field( $discount_code );
	if ( empty( $code ) || ! class_exists( "\WC_Store_Credit_Cart" ) ) {
		return $discount_info;
	}
	$coupon = wc_store_credit_get_coupon( $code );
	if ( wc_is_store_credit_coupon( $coupon ) ) {
		$discount_info = array(
			'discount_code'     => $code,
			'discount_type'     => 'fixed_amount',
			'discount_category' => 'coupon',
			'discount_amount'   => convert_monetary_value_to_bolt_format( $coupon->get_amount() ),
		);
	}

	return $discount_info;
}

add_filter( 'wc_bolt_add_third_party_discounts_to_cart_from_discount_hook', '\BoltCheckout\wc_bolt_add_wc_store_credit_from_discount_hook', 10, 2 );

/**
 * Returns proper discount type for the coupon of WC Store Credit.
 */
function wc_bolt_add_wc_store_credit_for_bolt_cart_creation( $bolt_cart ) {
	$discounts = $bolt_cart[ BOLT_CART_DISCOUNTS ];
	if ( empty( $discounts ) || ! class_exists( "\WC_Store_Credit_Cart" ) ) {
		return $bolt_cart;
	}
	$tmp_discounts = array();
	foreach ( $discounts as $discount ) {
		$coupon_code = $discount[ BOLT_CART_DISCOUNT_REFERENCE ];
		$coupon      = wc_store_credit_get_coupon( $coupon_code );
		if ( wc_is_store_credit_coupon( $coupon ) ) {
			$discount[ BOLT_CART_DISCOUNT_AMOUNT ] = convert_monetary_value_to_bolt_format( $coupon->get_amount() );
		}
		$tmp_discounts[] = $discount;
	}
	$bolt_cart[ BOLT_CART_DISCOUNTS ] = $tmp_discounts;

	return $bolt_cart;
}

add_filter( 'wc_bolt_order_creation_cart_data', '\BoltCheckout\wc_bolt_add_wc_store_credit_for_bolt_cart_creation', 10, 1 );

/**
 * Add the applied store credit to the shipping costs.
 */
function wc_bolt_calculate_store_credit_for_shipping( $shipping_options, $bolt_order ) {
	if ( empty( $shipping_options ) || ! class_exists( "\WC_Store_Credit_Cart" ) ) {
		return $shipping_options;
	}
	$new_shipping_options = array();
	foreach ( $shipping_options as $shipping_option ) {
		wc_bolt_set_chosen_shipping_method_for_first_package( $shipping_option->reference );
		Bolt_woocommerce_cart_calculation::calculate();
		$precision          = get_precision_for_currency_code( 'USD' );
		$new_shipping_total = convert_monetary_value_to_bolt_format( WC()->cart->get_shipping_total() );
		$discount           = $shipping_option->cost - $new_shipping_total;
		$discount           = round( $discount / ( 10 ** $precision ), $precision );
		if ( $discount > 0 ) {
			$shipping_option->service = $shipping_option->service . ' [$' . $discount . ' discount]';
		}
		$shipping_option->tax_amount = convert_monetary_value_to_bolt_format( WC()->cart->get_taxes_total() );
		if ( (float) $shipping_option->tax_amount <= 0 ) {
			$shipping_option->tax_amount = 0;
		}
		$new_shipping_options[] = $shipping_option;
	}
	$shipping_options = $new_shipping_options;

	return $shipping_options;
}

add_filter( 'wc_bolt_after_load_shipping_options', '\BoltCheckout\wc_bolt_calculate_store_credit_for_shipping', 999, 2 );

/**
 * Normallly WC()->cart->get_total_tax() and WC()->cart->get_taxes_total() are identical,
 * but after applying store credit, the return values are differnt, that's a bug of the WooCommerce Store Credit plugin.
 * This function is to fix this bug for cart tax calculation.
 */
function wc_bolt_fix_wc_store_credit_cart_tax_total_bug( $tax_total, $bolt_transaction, $error_handler ) {
	if ( ! class_exists( "WC_Store_Credit_Cart" ) ) {
		return $tax_total;
	}
	$applied_coupons = WC()->cart->get_applied_coupons();
	foreach ( $applied_coupons as $coupon_code ) {
		if ( wc_is_store_credit_coupon( $coupon_code ) ) {
			$tax_total = convert_monetary_value_to_bolt_format( WC()->cart->get_taxes_total() );
			break;
		}
	}

	return $tax_total;
}

add_filter( 'wc_bolt_cart_tax_total', '\BoltCheckout\wc_bolt_fix_wc_store_credit_cart_tax_total_bug', 10, 3 );

/**
 * Normallly WC()->cart->get_total_tax() and WC()->cart->get_taxes_total() are identical,
 * but after applying store credit, the return values are differnt, that's a bug of the WooCommerce Store Credit plugin.
 * This function is to fix this bug for cart total.
 */
function wc_bolt_fix_wc_store_credit_cart_total_bug( $cart_total, $bolt_transaction, $error_handler ) {
	if ( ! class_exists( "WC_Store_Credit_Cart" ) ) {
		return $cart_total;
	}
	$applied_coupons = WC()->cart->get_applied_coupons();
	foreach ( $applied_coupons as $coupon_code ) {
		if ( wc_is_store_credit_coupon( $coupon_code ) ) {
			$cart_total = $bolt_transaction->order->cart->total_amount->amount;
			break;
		}
	}

	return $cart_total;
}

add_filter( 'wc_bolt_cart_total', '\BoltCheckout\wc_bolt_fix_wc_store_credit_cart_total_bug', 10, 3 );

/**
 * Normallly WC()->cart->get_total_tax() and WC()->cart->get_taxes_total() are identical,
 * but after applying store credit, the return values are differnt, that's a bug of the WooCommerce Store Credit plugin.
 * This function is to fix this bug for WooC order creation.
 */
function wc_bolt_fix_wc_store_credit_order_total_bug( $price_difference, $order_created, $bolt_transaction ) {
	if ( ! class_exists( "WC_Store_Credit_Cart" ) ) {
		return $price_difference;
	}
	$applied_coupons = $order_created->get_coupon_codes();
	foreach ( $applied_coupons as $coupon_code ) {
		if ( wc_is_store_credit_coupon( $coupon_code ) ) {
			$price_difference = 0;
			$order_total      = isset( $bolt_transaction->amount->amount ) ? $bolt_transaction->amount->amount : $bolt_transaction->order->cart->total_amount->amount;
			$order_total      = $order_total / get_currency_divider();
			$order_created->set_total( $order_total );
			$order_created->save();
			break;
		}
	}

	return $price_difference;
}

add_filter( 'wc_bolt_price_difference_recalculation_in_order', '\BoltCheckout\wc_bolt_fix_wc_store_credit_order_total_bug', 10, 3 );

/**
 * The WooCommerce Store Credit plugin directly applies discount to shipping cost, so we ignore comparison if cart has store credits applied.
 */
function wc_bolt_fix_wc_store_credit_ingore_shipping_difference( $shipping_total, $bolt_transaction, $error_handler ) {
	if ( ! class_exists( "WC_Store_Credit_Cart" ) ) {
		return $shipping_total;
	}
	$applied_coupons = WC()->cart->get_applied_coupons();
	foreach ( $applied_coupons as $coupon_code ) {
		if ( wc_is_store_credit_coupon( $coupon_code ) ) {
			return $bolt_transaction->order->cart->shipping_amount->amount;
		}
	}

	return $shipping_total;
}

add_filter( 'wc_bolt_cart_shipping_total', '\BoltCheckout\wc_bolt_fix_wc_store_credit_ingore_shipping_difference', 10, 3 );

/**
 * We send all the available store credits to Bolt server, and the WC cart may only use part of credits,
 * therefore the filter need to eliminate discounts difference.
 */
function wc_bolt_fix_wc_store_credit_discounts_difference( $cart_discount_total, $bolt_transaction, $error_handler ) {
	if ( ! isset( $bolt_transaction->order->cart->discounts ) || ! class_exists( "WC_Store_Credit_Cart" ) ) {
		return $cart_discount_total;
	}
	$cart_coupons = WC()->cart->get_coupon_discount_totals();
	foreach ( $bolt_transaction->order->cart->discounts as $discount ) {
		if ( isset( $cart_coupons[ $discount->reference ] ) ) {
			$coupon = wc_store_credit_get_coupon( $discount->reference );
			if ( wc_is_store_credit_coupon( $coupon ) ) {
				$cart_discount_total += $discount->amount->amount - convert_monetary_value_to_bolt_format( $cart_coupons[ $discount->reference ] );
			}
		}
	}

	return $cart_discount_total;
}

add_filter( 'wc_bolt_cart_discount_total', '\BoltCheckout\wc_bolt_fix_wc_store_credit_discounts_difference', 10, 3 );