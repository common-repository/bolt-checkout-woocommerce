<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bolt Email Functions
 *
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */

/**
 * Sending email when order status changes from bolt-reject to processing by force-approval
 *
 * @param int $order_id The id of order which status is changing
 * @param WC_Order $order The order which status is changing
 *
 * @since  2.0.0
 *
 */
function wc_bolt_order_status_bolt_reject_to_processing_notification( $order_id, $order = false ) {
	// Getting all WC_emails objects
	$email_notifications = WC()->mailer()->get_emails();

	// Sending the new order email
	$email_notifications['WC_Email_New_Order']->trigger( $order_id, $order );
}

add_action( 'woocommerce_order_status_bolt-reject_to_processing', '\BoltCheckout\wc_bolt_order_status_bolt_reject_to_processing_notification' );

/**
 * Sending email when order status changes from bolt-reject to failed by confirm-rejection
 *
 * @param int $order_id The id of order which status is changing
 * @param WC_Order $order The order which status is changing
 *
 * @since  2.0.0
 *
 */
function wc_bolt_order_status_bolt_reject_to_failed_notification( $order_id, $order = false ) {
	// Getting all WC_emails objects
	$email_notifications = WC()->mailer()->get_emails();

	// Sending the payment fails email
	$email_notifications['WC_Email_Failed_Order']->trigger( $order_id, $order );
}

add_action( 'woocommerce_order_status_bolt-reject_to_failed', '\BoltCheckout\wc_bolt_order_status_bolt_reject_to_failed_notification' );