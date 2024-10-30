<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bolt Third-party addons support Functions
 *
 * Functions to support YITH WooCommerce Gift Cards Premium.
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */


/**
 * Due to the special logic of yith premium gift card,
 * we have to apply the gift card amount to order total during order creation.
 *
 * @param $order WC_Order  The order that was created
 *
 * @since  1.3.6
 *
 */
function apply_yith_ywgc_premium_discount( $order ) {
	if ( defined( 'YITH_YWGC_PREMIUM' ) ) {
		$applied_gift_card_amount = yit_get_prop( $order, '_ywgc_applied_gift_cards_totals' );
		if ( ! empty( $applied_gift_card_amount ) ) {
			$cart_subtotal     = 0;
			$cart_total        = 0;
			$fee_total         = 0;
			$cart_subtotal_tax = 0;
			$cart_total_tax    = 0;
			$and_taxes         = yit_get_prop( $order, 'prices_include_tax' );
			if ( $and_taxes && apply_filters( 'yith_ywgc_update_totals_calculate_taxes', true ) ) {
				$order->calculate_taxes();
			}
			// line items
			foreach ( $order->get_items() as $item ) {
				$cart_subtotal     += $item->get_subtotal();
				$cart_total        += $item->get_total();
				$cart_subtotal_tax += $item->get_subtotal_tax();
				$cart_total_tax    += $item->get_total_tax();
			}
			$cart_total -= $applied_gift_card_amount;
			$order->calculate_shipping();
			foreach ( $order->get_fees() as $item ) {
				$fee_total += $item->get_total();
			}
			$grand_total = round( $cart_total + $fee_total + $order->get_shipping_total() + wc_bolt_get_order_tax_total( $order ), wc_get_price_decimals() );
			$order->set_discount_total( $cart_subtotal - $cart_total );
			$order->set_discount_tax( $cart_subtotal_tax - $cart_total_tax );
			$order->set_total( $grand_total );
			$order->save();
		}
	}
}

/**
 * Set array of applied yith premium gift card discount in cart
 *
 * @param $discounts Applied discounts from the third-party addons
 * @param $applied_discounts_code To keep coupons from being duplicated, this is an array which contains coupon codes of original wc coupons&discounts from other third-party addons.
 *
 * @return array
 * @since  2.0.2
 *
 */
function set_yith_ywgc_premium_discount_cart( $discounts, $applied_discounts_code ) {
	if ( defined( 'YITH_YWGC_PREMIUM' ) && isset( WC()->cart->applied_gift_cards ) ) {
		foreach ( WC()->cart->applied_gift_cards as $code ) {
			if ( array_key_exists( (string) $code, $applied_discounts_code ) ) {
				continue;
			}
			$gift_card = YITH_YWGC()->get_gift_card_by_code( $code );
			$label     = apply_filters( 'yith_ywgc_cart_totals_gift_card_label', esc_html( __( 'Gift card', 'yith-woocommerce-gift-cards' ) . ' (' . $code . ')' ), $code );
			//here we send all the available credits to bolt server
			$amount = $gift_card->get_balance();
			if ( abs( $amount ) < 0.01 ) {
				continue;
			}
			$applied_discounts_code[ (string) $code ] = 1;
			$discounts[]                              = array(
				BOLT_CART_DISCOUNT_AMOUNT      => abs( convert_monetary_value_to_bolt_format( $amount ) ),
				BOLT_CART_DISCOUNT_DESCRIPTION => $label,
				BOLT_CART_DISCOUNT_REFERENCE   => (string) $code,
				BOLT_CART_DISCOUNT_CATEGORY    => BOLT_DISCOUNT_CATEGORY_GIFTCARD,
				BOLT_CART_DISCOUNT_ON_TOTAL    => 0
			);
		}
	}

	return $discounts;
}

add_filter( 'wc_bolt_get_third_party_discounts_cart', 'BoltCheckout\set_yith_ywgc_premium_discount_cart', 10, 2 );

/**
 * Set array of applied yith premium gift card discount to order
 *
 * @param $discounts Applied discounts from the third-party addons
 *
 * @return array
 * @since  2.0.2
 *
 */
function set_yith_ywgc_premium_discount_order( $discounts, $order ) {
	if ( defined( 'YITH_YWGC_PREMIUM' ) && class_exists( "\YITH_YWGC_Cart_Checkout" ) ) {
		// From v3.1.9 of YITH WooCommerce Gift Cards Premium, it abandons the constant ORDER_GIFT_CARDS,
		// so we need to check the existence of constant before processing.
		if ( defined( 'YITH_YWGC_Cart_Checkout::ORDER_GIFT_CARDS' ) ) {
			$gift_cards = yit_get_prop( $order, \YITH_YWGC_Cart_Checkout::ORDER_GIFT_CARDS, true );
		} else {
			$gift_cards = yit_get_prop( $order, '_ywgc_applied_gift_cards', true );
		}
		if ( $gift_cards ) {
			foreach ( $gift_cards as $code => $amount ) {
				$amount      = apply_filters( 'yith_ywgc_gift_card_coupon_amount', $amount, YITH_YWGC()->get_gift_card_by_code( $code ) );
				$discounts[] = array(
					BOLT_CART_DISCOUNT_AMOUNT      => abs( (int) ( round( $amount ) ) ),
					BOLT_CART_DISCOUNT_DESCRIPTION => 'Gift card (' . $code . ')',
					BOLT_CART_DISCOUNT_REFERENCE   => $code,
					BOLT_CART_DISCOUNT_CATEGORY    => BOLT_DISCOUNT_CATEGORY_GIFTCARD
				);
			}
		}
	}

	return $discounts;
}

add_filter( 'wc_bolt_get_third_party_discounts_order', 'BoltCheckout\set_yith_ywgc_premium_discount_order', 10, 2 );

/**
 * Return javascript code to re-navigate to the cart page with a fresh session when the yiyh gift card is applied or removed
 *
 * @return string
 * @since  2.0.2
 *
 */
function create_yith_ywgc_premium_refresh_cart_js() {
	return defined( 'YITH_YWGC_PREMIUM' )
		? "jQuery(document).ready(function () {
                jQuery(document).on('update_checkout',function(){
                    // Re-navigate to the cart page with a fresh session when the yiyh gift card is applied or removed
                    window.location = window.location.href;
                });
            });"
		: "";
}

/**
 * Add javascript code for yith premium gift card to additional javascript of Bolt on cart page.
 *
 * @param array $template_params Paramters sent to template
 * @param array $render_bolt_checkout_params Paramters from related show button function
 *
 * @return array
 * @since  2.0.3
 *
 */
function add_yith_ywgc_premium_js_cart_page( $template_params, $render_bolt_checkout_params ) {
	if ( ! $render_bolt_checkout_params['exclude_additional_javascript'] && wc_bolt_is_cart_page() ) {
		$template_params['javascript_additional'] .= create_yith_ywgc_premium_refresh_cart_js();
	}

	return $template_params;
}

add_filter( 'wc_bolt_cart_js_params', 'BoltCheckout\add_yith_ywgc_premium_js_cart_page', 10, 2 );

/**
 * When applying the yith gift card through its default code input box,
 * and the current balance of gift card can cover shipping total fully or partially,
 * WC()->cart->get_shipping_total() just return the discounted amount,
 * so we need to store the original shipping total for comparison in the function compare_cart_shipping_data_with_bolt_data during order creation.
 *
 * @param WC_Cart $cart
 *
 * @since  2.5.0
 *
 */
function store_original_shipping_total_yith( $cart ) {
	if ( defined( 'YITH_YWGC_PREMIUM' ) && ! empty( WC()->session->get( 'applied_gift_cards', array() ) ) ) {
		$cart->bolt_original_shipping_total = $cart->shipping_total;
	}
}

add_action( 'woocommerce_after_calculate_totals', 'BoltCheckout\store_original_shipping_total_yith', 19 );

/**
 * Check if the gift card code provided is valid and store the amount for applying the discount to the cart for discounts.code.apply.
 *
 * @param array $discount_info Discount data sent to response
 * @param array $api_request Reference to the transaction object retrieved from the Bolt API endpoint
 *
 * @return array
 * @since  2.5.0
 *
 */
function add_yith_gift_card_from_discount_hook( $discount_info, $code ) {
	if ( empty( $code ) || ! defined( 'YITH_YWGC_PREMIUM' ) || ! class_exists( "\YITH_YWGC_Cart_Checkout" ) ) {
		return $discount_info;
	}
	$code = sanitize_text_field( $code );
	$gift = YITH_YWGC()->get_gift_card_by_code( $code );
	if ( ! $gift->exists() ) {
		return $discount_info;
	}
	if ( YITH_YWGC()->check_gift_card( $gift ) ) {
		$applied_gift_cards = array();
		if ( isset( WC()->session ) ) {
			$applied_gift_cards = WC()->session->get( 'applied_gift_cards', array() );
			$code               = strtoupper( $code );
			if ( ! in_array( $code, $applied_gift_cards ) ) {
				$applied_gift_cards[] = $code;
				WC()->session->set( 'applied_gift_cards', $applied_gift_cards );
			}
			// Since Bolt would send the discounts.code.apply api hook with same data for several times,
			// although the gift card is already in the session applied_gift_cards,
			// we still return the $discount_info to make success response to api hook.
			$discount_info = array(
				'discount_code'             => $code,
				'discount_type'             => 'fixed_amount',
				BOLT_CART_DISCOUNT_CATEGORY => BOLT_DISCOUNT_CATEGORY_GIFTCARD
			);
		}
	} else {
		if ( $notices = wc_get_notices( WC_NOTICE_TYPE_ERROR ) ) {
			$error_msg = '';
			foreach ( $notices as $notice ) {
				// TODO : Change wp_kses_post to wc_kses_notice
				$error_msg .= wp_kses_post( get_wc_notice_message( $notice ) );
			}
			throw new \Exception( $error_msg, Bolt_Discounts_Helper::E_BOLT_YITH_YWGC_PREMIUM_GENERAL_ERROR );
		}
	}

	return $discount_info;
}

add_filter( 'wc_bolt_add_third_party_discounts_to_cart_from_discount_hook', '\BoltCheckout\add_yith_gift_card_from_discount_hook', 10, 2 );

/**
 * Check if the gift card code provided is valid and store the amount for applying the discount to the cart.
 *
 * @param mixed $the_coupon Coupon object or null
 * @param string $code Discount code to be validated and added.
 *
 * @return mixed Coupon object or null
 * @since  2.15.0
 *
 */
function add_yith_gift_card_to_cart( $the_coupon, $code ) {
	if ( ! empty( $the_coupon )
	     || empty( $code )
	     || ! defined( 'YITH_YWGC_PREMIUM' )
	     || ! class_exists( "\YITH_YWGC_Cart_Checkout" )
	     || ! isset( WC()->session ) ) {
		return $the_coupon;
	}
	$code = sanitize_text_field( $code );
	$gift = YITH_YWGC()->get_gift_card_by_code( $code );
	if ( ! $gift->exists() || ! YITH_YWGC()->check_gift_card( $gift ) ) {
		return $the_coupon;
	}
	$applied_gift_cards = WC()->session->get( 'applied_gift_cards', array() );
	$code               = strtoupper( $code );
	if ( ! in_array( $code, $applied_gift_cards ) ) {
		$applied_gift_cards[] = $code;
		WC()->session->set( 'applied_gift_cards', $applied_gift_cards );
	}

	return $gift;
}

add_filter( 'wc_bolt_add_third_party_discounts_to_cart', '\BoltCheckout\add_yith_gift_card_to_cart', 10, 2 );

/**
 * The customer could apply YITH gift card as WC coupon, there may be discount tax when cart get shipping address info,
 * but Bolt can not update the discount data with shipping&tax api hook. And to fix this bug :
 * When there is any YITH gift card applied as WC coupon, remove it from WC coupon and re-add as normal YITH gift card,
 * in this way, Bolt can calculate the cart totals correctly.
 * This is only executed for sending Bolt order or creating/updating Bolt cart session.
 *
 * @param string $type The type of checkout that this cart is being built for checkout|shopping_cart|product
 * @param string $order_id The order id of bolt cart session if exist
 *
 * @since  2.6.0
 *
 */
function change_yith_coupon_to_gift_card( $type, $order_id ) {
	if ( ! defined( 'YITH_YWGC_PREMIUM' ) || ! class_exists( "\YITH_YWGC_Cart_Checkout" ) ) {
		return;
	}
	foreach ( WC()->cart->get_applied_coupons() as $code ) {
		$gift_card = YITH_YWGC()->get_gift_card_by_code( $code );
		if ( ! $gift_card instanceof \YWGC_Gift_Card_Premium || ! $gift_card->exists() ) {
			continue;
		}
		if ( WC()->cart->remove_coupon( $code ) ) {
			$applied_gift_cards = WC()->session->get( 'applied_gift_cards', array() );
			$upper_code         = strtoupper( $code );
			if ( ! in_array( $upper_code, $applied_gift_cards ) ) {
				$applied_gift_cards[] = $upper_code;
				WC()->session->set( 'applied_gift_cards', $applied_gift_cards );
				WC()->cart->applied_gift_cards    = $applied_gift_cards;
				$bolt_changed_gift_cards          = WC()->session->get( 'bolt_changed_gift_cards', array() );
				$bolt_changed_gift_cards[ $code ] = $upper_code;
				WC()->session->set( 'bolt_changed_gift_cards', $bolt_changed_gift_cards );
			}
		}
	}
	wc_clear_notices();
}

add_action( 'wc_bolt_before_build_cart', '\BoltCheckout\change_yith_coupon_to_gift_card', 10, 2 );
add_action( 'wc_bolt_before_update_cart_session', '\BoltCheckout\change_yith_coupon_to_gift_card', 10, 2 );

/**
 * The customer could apply YITH gift card as WC coupon, there may be discount tax when cart get shipping address info,
 * but Bolt can not update the discount data with shipping&tax api hook. And to fix this bug :
 * After completing the Bolt order creation or creating/updating the Bolt cart session, restore the YITH gift card to
 * WC coupon, if the gift card is applied via WC coupon input box initially.
 *
 * @param string $type The type of checkout that this cart is being built for checkout|shopping_cart|product
 * @param string $order_id The order id of bolt cart session if exist
 * @param array $bolt_data The Bolt cart session data
 *
 * @since  2.6.0
 *
 */
function restore_gift_card_to_yith_coupon( $type, $order_id, $bolt_data ) {
	$bolt_changed_gift_cards = WC()->session->get( 'bolt_changed_gift_cards', array() );
	if ( ! defined( 'YITH_YWGC_PREMIUM' )
	     || ! class_exists( "\YITH_YWGC_Cart_Checkout" )
	     || empty( $bolt_changed_gift_cards ) ) {
		return;
	}
	$restore_codes      = array();
	$applied_gift_cards = WC()->session->get( 'applied_gift_cards', array() );
	foreach ( $bolt_changed_gift_cards as $original_code => $yith_code ) {
		if ( ( $key = array_search( $yith_code, $applied_gift_cards ) ) !== false ) {
			unset( $applied_gift_cards[ $key ] );
			$restore_codes[] = $original_code;
		}
	}
	WC()->session->set( 'applied_gift_cards', $applied_gift_cards );
	WC()->cart->applied_gift_cards = $applied_gift_cards;
	WC()->session->set( 'bolt_changed_gift_cards', array() );
	$success_msg_count  = 0;
	$applied_notice_msg = '';
	// Re-add the gift card as WC coupon
	foreach ( $restore_codes as $code ) {
		WC()->cart->apply_coupon( $code );
		// Get the text of coupon success message, and remove the duplicate message later
		if ( empty( $applied_notice_msg ) ) {
			$coupon             = new \WC_Coupon( $code );
			$applied_notice_msg = $coupon->get_coupon_message( \WC_Coupon::WC_COUPON_SUCCESS );
		}
		++ $success_msg_count;
	}
	// Remove the duplicate coupon success message
	$wc_notices      = WC()->session->get( 'wc_notices', array() );
	$success_notices = isset( $wc_notices[ WC_NOTICE_TYPE_SUCCESS ] ) ? $wc_notices[ WC_NOTICE_TYPE_SUCCESS ] : array();
	if ( ! empty( $success_notices ) ) {
		$wc_notices[ WC_NOTICE_TYPE_SUCCESS ] = array();
		WC()->session->set( 'wc_notices', $wc_notices );
		foreach ( $success_notices as $notice ) {
			$message = get_wc_notice_message( $notice );

			if ( $message !== $applied_notice_msg || $success_msg_count == 0 ) {
				wc_add_notice( $message, WC_NOTICE_TYPE_SUCCESS );
			}

			if ( $message === $applied_notice_msg && $success_msg_count > 0 ) {
				-- $success_msg_count;
			}
		}
	}
}

add_action( 'wc_bolt_after_build_cart', '\BoltCheckout\restore_gift_card_to_yith_coupon', 10, 3 );
add_action( 'wc_bolt_after_update_cart_session', '\BoltCheckout\restore_gift_card_to_yith_coupon', 10, 3 );

/**
 * Check if the gift card code provided is valid and remove gift card from current cart.
 *
 * @param bool $if_removed If the code is already removed from current cart.
 * @param string $discount_code Discount code to be validated and removed.
 *
 * @return bool
 * @since  2.15.0
 *
 */
function remove_yith_gift_card_from_cart( $if_removed, $discount_code ) {
	if ( empty( $discount_code )
	     || $if_removed
	     || ! defined( 'YITH_YWGC_PREMIUM' )
	     || ! class_exists( "\YITH_YWGC_Cart_Checkout" ) ) {
		return $if_removed;
	}
	$gift = YITH_YWGC()->get_gift_card_by_code( $discount_code );
	if ( YITH_YWGC()->check_gift_card( $gift, true ) ) {
		remove_yith_gift_card_code_from_session( $discount_code );
		$if_removed = true;
		wc_add_notice( $gift->get_gift_card_message( \YITH_YWGC_Gift_Card::GIFT_CARD_REMOVED ) );
	}

	return $if_removed;
}

add_filter( 'wc_bolt_remove_coupon_from_cart', 'BoltCheckout\remove_yith_gift_card_from_cart', 10, 2 );

/**
 * Retrieve YITH gift cards from WooC session.
 *
 * @return array
 * @since  2.15.0
 *
 */
function get_yith_gift_cards_from_session() {
	$value = array();
	if ( isset( WC()->session ) ) {
		$value = WC()->session->get( 'applied_gift_cards', array() );
	}

	return $value;
}

/**
 * Remove a gift card discount from current cart.
 *
 * @return array
 * @since  2.15.0
 *
 */
function remove_yith_gift_card_code_from_session( $code ) {
	$applied_gift_cards = get_yith_gift_cards_from_session();
	if ( ( $key = array_search( $code, $applied_gift_cards ) ) !== false ) {
		unset( $applied_gift_cards[ $key ] );
	}

	WC()->session->set( 'applied_gift_cards', $applied_gift_cards );
}
