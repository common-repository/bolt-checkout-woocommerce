<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bolt Third-party addons support Functions
 *
 * Functions to support PW WooCommerce Gift Cards
 * Tested up to: 1.349
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */


if ( defined( 'PWGC_SESSION_KEY' ) ) {
	/**
	 * Set array of applied pw gift card discount in cart
	 *
	 * @param $discounts Applied discounts from the third-party addons
	 * @param $applied_discounts_code To keep coupons from being duplicated, this is an array which contains coupon codes of original wc coupons&discounts from other third-party addons.
	 *
	 * @return array
	 * @since  2.15.0
	 *
	 */
	function set_pw_gift_card_discount_cart( $discounts, $applied_discounts_code ) {
		$session_data = (array) WC()->session->get( PWGC_SESSION_KEY );

		if ( isset( $session_data['gift_cards'] ) ) {
			foreach ( $session_data['gift_cards'] as $card_number => $amount ) {
				if ( array_key_exists( (string) $card_number, $applied_discounts_code ) ) {
					continue;
				}
				$gift_card = new \PW_Gift_Card( $card_number );
				if ( $gift_card->get_id() ) {
					$balance = $gift_card->get_balance( true );
					if ( $balance > 0 ) {
						$applied_discounts_code[ (string) $card_number ] = 1;
						// WC()->cart->get_total() already count the discount amount in,
						// so there is no need to subtract it.
						$discounts[] = array(
							BOLT_CART_DISCOUNT_AMOUNT      => convert_monetary_value_to_bolt_format( $balance ),
							BOLT_CART_DISCOUNT_DESCRIPTION => 'Gift card (' . $card_number . ')',
							BOLT_CART_DISCOUNT_REFERENCE   => (string) $card_number,
							BOLT_CART_DISCOUNT_CATEGORY    => BOLT_DISCOUNT_CATEGORY_GIFTCARD,
							BOLT_CART_DISCOUNT_ON_TOTAL    => 0
						);
					}
				}
			}
		}

		return $discounts;
	}

	add_filter( 'wc_bolt_get_third_party_discounts_cart', 'BoltCheckout\set_pw_gift_card_discount_cart', 10, 2 );
}

// Fixes a conflict with the 'WooCommerce AvaTax' plugin by SkyVerge.
if ( defined( 'PWGC_SESSION_KEY' ) && class_exists( '\WC_AvaTax_Checkout_Handler' ) && ! defined( 'PWGC_BYPASS_FIX_FOR_AVATAX' ) ) {
	function set_pw_gift_card_avatax_flag( $shipping_total, $bolt_transaction, $error_handler ) {
		$session_data = (array) WC()->session->get( PWGC_SESSION_KEY );
		if ( ! property_exists( WC()->cart, 'recurring_cart_key' ) && isset( $session_data['gift_cards'] ) ) {
			$_POST['reset_pwgc_calculated_total'] = 1;
		}

		return $shipping_total;
	}

	add_filter( 'wc_bolt_cart_shipping_total', 'BoltCheckout\set_pw_gift_card_avatax_flag', 10, 3 );

	function wc_avatax_after_checkout_tax_calculated() {
		$session_data = (array) WC()->session->get( PWGC_SESSION_KEY );
		if ( isset( $_POST['reset_pwgc_calculated_total'] )
		     && ! property_exists( WC()->cart, 'recurring_cart_key' )
		     && isset( $session_data['gift_cards'] ) ) {
			unset( WC()->cart->pwgc_calculated_total );
			unset( $_POST['reset_pwgc_calculated_total'] );
		}
	}

	add_action( 'wc_avatax_after_checkout_tax_calculated', 'BoltCheckout\wc_avatax_after_checkout_tax_calculated', 9 );
}