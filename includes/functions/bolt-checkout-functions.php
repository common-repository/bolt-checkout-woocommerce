<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bolt Checkout Functions
 *
 * Functions for bolt checkout specific things.
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */

/**
 * Validate if the checkout with Bolt is called by appropriate entry
 *
 * @param array $data An array of posted data.
 * @param WP_Error $errors Validation errors.
 *
 * @since 1.3.2
 * @access public
 *
 */
function wc_bolt_validate_checkout_entry( $data, $errors ) {
	if ( isset( $data[ WC_PAYMENT_METHOD ] ) && $data[ WC_PAYMENT_METHOD ] == BOLT_GATEWAY_NAME && ! isset( $_POST['in_bolt_checkout'] ) ) {
		BugsnagHelper::initBugsnag();
		BugsnagHelper::notifyException( new \Exception(
			"Bolt checkout button is disappeared" ),
			array( '_POST' => $_POST ), 'warning' );
		$errors->add( 'payment', __( 'Please click on the Bolt Checkout button to continue.', 'woocommerce-bolt-payment-gateway' ) );
	}
}

// Validate if the checkout with Bolt is called by appropriate entry
add_action( 'woocommerce_after_checkout_validation', '\BoltCheckout\wc_bolt_validate_checkout_entry', 200, 2 );

/**
 * Remove the Bolt from the payment gateways list on checkout page.
 *
 * @param array $available_gateways Current available gateways.
 *
 * @since 1.3.5
 * @access public
 *
 */
function wc_bolt_remove_bolt_from_checkout_payment_list( $available_gateways ) {
	if ( ! is_admin() && isset( $available_gateways[ BOLT_GATEWAY_NAME ] )
	     && ! isset( $_POST['in_bolt_checkout'] )
	     && ! wc_bolt_if_show_on_checkout_page( $available_gateways )
	     && wc_bolt_is_checkout_page() ) {
		unset( $available_gateways[ BOLT_GATEWAY_NAME ] );
	}

	return $available_gateways;
}

add_filter( 'woocommerce_available_payment_gateways', '\BoltCheckout\wc_bolt_remove_bolt_from_checkout_payment_list', 9 );

/**
 * Remove `pay` action from the list of account orders actions,
 * in case of the order is already paid via Bolt.
 *
 * @param array $actions List of account orders actions.
 * @param object $order WC Order object.
 *
 */
function wc_bolt_remove_pay_action( $actions, $order ) {
	if ( wc_bolt_is_paid( $order ) && isset( $actions['pay'] ) ) {
		unset( $actions['pay'] );
	}

	return $actions;
}

add_filter( 'woocommerce_my_account_my_orders_actions', '\BoltCheckout\wc_bolt_remove_pay_action', 999, 2 );

/**
 * If a pending WooCommerce order is already paid via Bolt and the customer still try to go to checkout's pay page,
 * just redirect to order received page.
 *
 */
function wc_bolt_redirect_to_thankyou_page_if_paid() {
	global $wp;
	$order_id = absint( $wp->query_vars['order-pay'] );
	$order    = wc_get_order( $order_id );
	if ( $order && wc_bolt_is_paid( $order ) ) {
		wp_safe_redirect( $order->get_checkout_order_received_url() );
	}
}

add_filter( 'before_woocommerce_pay', '\BoltCheckout\wc_bolt_redirect_to_thankyou_page_if_paid', 1 );

/**
 * Get order id by order reference
 *
 * @param string $order_reference order reference.
 * In the most cases order_reference is string like BLT5cae6c1bb340c,
 * but for order payment from "invoice for order" email order_reference coincides with order_id
 *
 * @return bool|int order_id - if order found, 0 if not found, WP_ERROR if we have database error
 * @since  2.0.0
 * @access public
 *
 */
function wc_bolt_get_order_id_by_order_reference( $order_reference ) {
	if ( substr( $order_reference, 0, BOLT_PREFIX_ORDER_REFERENCE_LENGTH ) != BOLT_PREFIX_ORDER_REFERENCE ) {
		// We are in order payment process
		// so just make sure that order is exists and return the incoming argument
		$order = wc_get_order( $order_reference );
		if ( $order ) {
			return $order_reference;
		}

		return 0;
	}
	$result_arr = wc_bolt_data()->get_bolt_post_meta( BOLT_ORDER_META_TRANSACTION_ORDER, $order_reference );
	if ( is_wp_error( $result_arr ) ) {
		return $result_arr;
	}
	if ( ! empty( $result_arr ) ) {
		foreach ( $result_arr as $result ) {
			if ( empty( $result ) ) {
				continue;
			}
			if ( property_exists( $result, 'post_id' ) ) {
				$order = wc_get_order( $result->post_id );
			} elseif ( property_exists( $result, 'order_id' ) ) {
				$order = wc_get_order( $result->order_id );
			} else {
				$order = null;
			}
			if ( $order && $order->get_status() != WC_ORDER_STATUS_CANCELLED ) {
				return $order->get_id();
			}
		}
	}

	//order doesn't exist or already cancelled
	return 0;
}

/**
 * Get order id by order number
 *
 * Order number can be different from order id if merchant use special plugin for order numbers
 * like WooCommerce Sequential Order Numbers
 *
 * We use order number as bolt display_id. Also we use order_number instead order_id in
 * pay for order process
 *
 * @param string $order_number order number.
 *
 * @return int order_id
 * @since  2.0.6
 * @access public
 *
 */
function wc_bolt_get_order_id_by_order_number( $order_number ) {
	return apply_filters( 'woocommerce_shortcode_order_tracking_order_id', $order_number );
}

/**
 * If the customer try to directly click `Continue to payment` button to complete payment with Bolt payment gateway selected
 * on the `Pay for order` page, return an error notice and prevent him from processing.
 *
 * @param object $order WC Order object.
 */
function wc_bolt_validate_pay_action_entry( $order ) {
	if ( $order->needs_payment() ) {
		$payment_method = isset( $_POST[ WC_PAYMENT_METHOD ] ) ? wc_clean( $_POST[ WC_PAYMENT_METHOD ] ) : false;
		if ( BOLT_GATEWAY_NAME === $payment_method ) {
			wc_add_notice( __( 'Please click on the Bolt Checkout button to continue.', 'woocommerce-bolt-payment-gateway' ), WC_NOTICE_TYPE_ERROR );

			return;
		}
	}
}

add_filter( 'woocommerce_before_pay_action', '\BoltCheckout\wc_bolt_validate_pay_action_entry', 1 );

/**
 * After order success, we must clear the database session because of our manual changes.
 * If not, WooCommerce will have problems with subsequent orders because of how it continuously
 * keeps track of shipping via session with cart hashes and validation.
 *
 * Some merchant sites may customize the thankyou page and remove action in the tempalte,
 * so action `woocommerce_after_template_part` is reliable.
 *
 */
function wc_bolt_destroy_session( $template_name, $template_path, $located, $args ) {
	if ( $template_name === 'checkout/thankyou.php' ) {
		if ( ! empty( $args ) && is_array( $args ) ) {
			extract( $args );
			if ( isset( $order ) && $order ) {
				WC()->session->destroy_session();
			}
		}
	}
}

add_action( 'woocommerce_after_template_part', '\BoltCheckout\wc_bolt_destroy_session', 999, 4 );