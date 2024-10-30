<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bolt Cart Functions
 *
 * Functions for bolt cart specific things.
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */


/**
 * Return users data for Bolt if user logged in and blank value otherwise
 *
 * @param boolean $is_ppc_request true if we generate hint data for Product page checkout (PPC)
 *
 * @return string return users data in JSON format
 * @since  1.3.3
 *
 */

function wc_bolt_cart_get_hint_data( $is_ppc_request = false ) {

	if ( ! is_user_logged_in() ) {
		$hint_data = apply_filters( 'wc_bolt_order_creation_hint_data', array() );

		// Use option JSON_FORCE_OBJECT to have {} for empty array
		return json_encode( $hint_data, JSON_FORCE_OBJECT );
	}

	$customer  = WC()->customer;
	$hint_data = array();

	$settings_instance = wc_bolt()->get_bolt_settings();

	if ( $settings_instance->is_setting_enabled( Bolt_Settings::SETTING_NAME_BOLT_MERCHANT_SCOPE ) ) {
		$signed_request  = array(
			'merchant_user_id' => (string) $customer->get_id(),
		);
		$signed_response = wc_bolt()->get_bolt_data_collector()->handle_api_request( 'sign', $signed_request );

		$hint_data['signed_merchant_user_id'] = [
			'merchant_user_id' => $signed_response->merchant_user_id,
			'signature'        => $signed_response->signature,
			'nonce'            => $signed_response->nonce,
		];
	}
	// For logged in users using Product page checkout (PPC), we need to pass their encrypted user ID.
	if ( $is_ppc_request ) {
		$hint_data['metadata']['encrypted_user_id'] = wc_bolt_encode_user_id( get_current_user_id() );
	}

	if ( wc_bolt_is_pay_exist_order() ) {
		global $wp;
		$order_id = absint( $wp->query_vars['order-pay'] );
		$order    = wc_get_order( $order_id );
		if ( $order ) {
			$hint_data[ BOLT_HINT_PREFILL ] = bolt_addr_helper()->generate_shipping_address_data( $order );
		}
	} else {
		$hint_data[ BOLT_HINT_PREFILL ] = bolt_addr_helper()->generate_shipping_address_data( WC()->customer );
	}

	$hint_data = apply_filters( 'wc_bolt_order_creation_hint_data', $hint_data );

	if ( $hint_data[ BOLT_HINT_PREFILL ] ) {
		foreach ( $hint_data[ BOLT_HINT_PREFILL ] as $name => $value ) {
			if ( '' === $value ) {
				unset( $hint_data[ BOLT_HINT_PREFILL ][ $name ] );
			}
		}
	}

	return json_encode( $hint_data, JSON_FORCE_OBJECT );
}

/**
 * Check all Woocommerce cart items for errors.
 *
 * @return true|WP_error
 * @since 2.0.3
 */
function wc_bolt_cart_availability() {
	$result = WC()->cart->check_cart_item_validity();

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$result = WC()->cart->check_cart_item_stock();

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return true;
}

/**
 * Send bolt cart via AJAX
 *
 * @since  2.0.3
 */
function wc_bolt_get_bolt_cart() {
	BugsnagHelper::initBugsnag();
	try {
		$result           = wc_bolt()->get_html_handler()->get_cart_data_js( BOLT_CART_ORDER_TYPE_CART );
		$result['result'] = 'success';
		wp_send_json( $result );
	} catch ( \Exception $e ) {
		BugsnagHelper::notifyException( $e );
		wp_send_json( array( 'result' => 'failure' ) );
	}

}

add_action( 'wc_ajax_wc_bolt_get_bolt_cart', '\BoltCheckout\wc_bolt_get_bolt_cart' );

/**
 * Check if woocommerce cart has multiple_packages
 *
 * @return boolean
 * @since  2.0.3
 *
 */
function wc_bolt_if_cart_has_multiple_packages() {
	// The first condition should cover most cases, and in PHP, this conditional statement would return true immediately,
	// so the second condition would not waste any resource or extra time, just in case of any rare condition in the future.

	return ( ( count( WC()->cart->get_shipping_packages() ) > 1 )
	         || ( is_countable( WC()->session->get( 'chosen_shipping_methods' ) ) && count( WC()->session->get( 'chosen_shipping_methods' ) ) > 1 ) );
}

/**
 * Get shipping rates for the first of shipping packages
 *
 * @return array|bool
 * @since  2.0.6
 *
 */
function wc_bolt_get_shipping_methods_for_first_package() {
	$shipping_packages = WC()->cart->get_shipping_packages();
	$first_package     = current( $shipping_packages );
	$shipping_methods  = WC()->shipping()->calculate_shipping_for_package( $first_package );

	return $shipping_methods;
}

/**
 * Set chosen shipping method for first package
 *
 * @param $shipping_method_key string
 *
 * @since 2.1.0
 */
function wc_bolt_set_chosen_shipping_method_for_first_package( $shipping_method_key ) {
	$shipping_packages = WC()->cart->get_shipping_packages();
	$first_package_key = key( $shipping_packages );
	WC()->session->set( 'chosen_shipping_methods', array( $first_package_key => $shipping_method_key ) );
}

/**
 * Generate JSON data contains user_id and signature
 *
 * @param $user_id int
 *
 * @return JSON string
 * @since 2.1.0
 *
 */
function wc_bolt_encode_user_id( $user_id ) {
	$result              = array(
		'user_id'   => $user_id,
		'timestamp' => time()
	);
	$result['signature'] = compute_signature( json_encode( $result ) );

	return json_encode( $result );
}

/**
 * Add product to WooCommerce cart by id
 *
 * @param $product_id
 * @param $quantity
 *
 * @return bool|\WP_ERROR
 * @since 2.2.0
 */
function add_to_cart( $product_id, $quantity ) {
	$product = wc_get_product( $product_id );
	if ( ! $product || 'publish' !== get_post_status( $product_id ) ) {
		return new \WP_ERROR( E_BOLT_INVALID_REFERENCE, 'Invalid reference' );
	}
	$quantity     = wc_stock_amount( $quantity );
	$variation_id = 0;
	$variation    = array();


	if ( 'variation' === $product->get_type() ) {
		$variation_id = $product_id;
		$product_id   = $product->get_parent_id();
		$variation    = $product->get_variation_attributes();
	}
	$passed_validation = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $quantity, $variation_id, $variation );

	if ( ! $passed_validation || false === WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation ) ) {
		$notices = wc_get_notices();
		if ( $notices && isset( $notices[ WC_NOTICE_TYPE_ERROR ][0] ) ) {
			$notice = $notices[ WC_NOTICE_TYPE_ERROR ][0];
			// WooCommerce notice has different structures in different versions.
			$error_msg = get_wc_notice_message( $notice );
			if ( strpos( $error_msg, 'is not enough stock' ) !== false ) {
				return new \WP_ERROR( E_BOLT_INVALID_QUANTITY, $error_msg );
			}
		}

		return new \WP_ERROR( E_BOLT_OUT_OF_STOCK, 'out of stock' );
	}

	return true;
}

/**
 * Convert monetary value to bolt format using currency precision
 *
 * @param float $amount
 * @param string $currency_code 3-digit ISO currency code. If argument isn't passed we use default woocommerce currency.
 *
 * @return int
 * @throws \Exception when unknown currency code is passed
 *
 * @since 2.4.0
 */
function convert_monetary_value_to_bolt_format( $amount, $currency_code = null ) {
	$precision = get_precision_for_currency_code( $currency_code );
	if ( $precision == 0 ) {
		return (int) $amount;
	} else {
		return (int) round( $amount * 10 ** $precision );
	}
}

/**
 * Get divider we need to convert value in bolt format to monetary value
 *
 * @param string $currency_code 3-digit ISO currency code. If argument isn't passed we use default woocommerce currency.
 *
 * @return int
 * @throws \Exception when unknown currency code is passed
 *
 * @since 2.4.0
 */
function get_currency_divider( $currency_code = null ) {
	$precision = get_precision_for_currency_code( $currency_code );

	return 10 ** $precision;
}


/**
 * Returns "precision" of currency. For instance USD has precision of 2 because $1.23 is valid amount white $1.234 is not
 * (there is no such thing as 0.1 cent). Likewise precision of JPY is 0 because there is no 0.1 yen.
 *
 * @param $currency_code 3-digit ISO currency code. If argument isn't passed we use default woocommerce currency.
 *
 * @return int precision
 * @throws \Exception when unknown currency code is passed
 *
 * @since 2.4.0
 */

function get_precision_for_currency_code( $currency_code = null ) {
	static $precision_for_default_currency = null;
	if ( ! $currency_code ) {
		if ( ! $precision_for_default_currency ) {
			$currency_code = get_woocommerce_currency();
			if ( ! $currency_code ) {
				throw new \Exception( 'Woocommerce currency is not set' );
			}
			$precision_for_default_currency = get_precision_for_currency_code( $currency_code );
		}

		return $precision_for_default_currency;
	} else {
		if ( $currency_code == 'US' ) {
			return 2;
		} else {
			require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-currency-utils.php' );

			return Bolt_Currency_Utils::get_precision_for_currency_code( $currency_code );
		}
	}
}

/**
 * Get image URL by product
 * If product doesn't have image it returns URL of parent product image
 *
 * @param WC_Product $product
 *
 * @return string|null
 * @since 2.6.0
 */
function get_image_url_by_product( $product ) {
	$image_id = $product->get_image_id();
	if ( ! $image_id ) {
		return null;
	}

	return wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' );
}

/**
 * Looks through the cart to check each item is in stock. If not, add an error.
 *
 * @param Bolt_Error_Handler $errors Error storage
 *
 * @since 2.0.0
 *
 */
function check_cart_item_stock( $errors ) {
	$product_qty_in_cart      = WC()->cart->get_cart_item_quantities();
	$current_session_order_id = isset( WC()->session->order_awaiting_payment ) ? absint( WC()->session->order_awaiting_payment ) : 0;

	foreach ( WC()->cart->get_cart() as $cart_item_key => $values ) {
		$product = $values['data'];

		if ( ( ! $product->managing_stock() && $product->is_in_stock() ) || $product->backorders_allowed() ) {
			continue;
		}

		// Check stock based on all items in the cart and consider any held stock within pending orders.
		$held_stock     = bolt_compat()->get_held_stock_quantity( $product, $current_session_order_id );
		$required_stock = $product_qty_in_cart[ $product->get_stock_managed_by_id() ];

		if ( $product->get_stock_quantity() < ( $held_stock + $required_stock ) ) {
			$errors->handle_error( E_BOLT_OUT_OF_INVENTORY,
				(object) array(
					BOLT_ERR_PRODUCT_ID   => $product->get_id(),
					BOLT_ERR_PRODUCT_NAME => $product->get_name(),
					BOLT_ERR_NEW_VALUE    => max( $product->get_stock_quantity() - $held_stock, 0 ),
					BOLT_ERR_OLD_VALUE    => $required_stock
				)
			);
		}
	}
}

/**
 * If the length of value exceed the limitation, we replace it with hint text
 *
 * @param string $value
 * @param int $limit
 * @param string $hint
 *
 * @since 2.12.0
 *
 */
function replace_limit_exceeded_value_with_hint( $value, $limit, $hint ) {
	if ( strlen( $value ) > $limit ) {
		$value = $hint;
	}

	return (string) $value;
}

/**
 * Restore WooCommerce session data from the database by customer id
 *
 * @param int|string $customer_id
 * @param string $reference
 *
 * @since 2.15.0
 *
 */
function load_cart_from_native_wc_session( $customer_id, $reference ) {
	global $wp_actions;
	if ( is_numeric( $customer_id ) ) {
		$user = get_user_by( 'ID', $customer_id );
		if ( ! is_user_logged_in() && $user ) {
			wp_set_current_user( $customer_id );
		}
	}
	do_action( 'wc_bolt_before_load_cart_from_native_wc_session', $customer_id, $reference );
	$session_expiring        = time() + intval( apply_filters( 'wc_session_expiring', 60 * 60 * 47 ) ); // 47 Hours.
	$session_expiration      = time() + intval( apply_filters( 'wc_session_expiration', 60 * 60 * 48 ) ); // 48 Hours.
	$to_hash                 = $customer_id . '|' . $session_expiration;
	$cookie_hash             = hash_hmac( 'md5', $to_hash, wp_hash( $to_hash ) );
	$cookie_value            = $customer_id . '||' . $session_expiration . '||' . $session_expiring . '||' . $cookie_hash;
	$cookie_name             = apply_filters( 'woocommerce_cookie', 'wp_woocommerce_session_' . COOKIEHASH );
	$_COOKIE[ $cookie_name ] = $cookie_value;
	WC()->session->init_session_cookie();
	unset( $wp_actions['woocommerce_load_cart_from_session'] );
	// reload cart from session
	WC()->cart->get_cart();
	do_action( 'wc_bolt_after_load_cart_from_native_wc_session', $customer_id, $reference );
}