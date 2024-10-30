<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bolt Third-party addons support Functions
 *
 * Functions to support Advanced Shipment Tracking for WooCommerce.
 * Plugin URL: https://wordpress.org/plugins/woo-advanced-shipment-tracking/
 * Vendor: zorem
 * Tested up to: 3.3.2
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */

/**
 * Send order shipment tracking info to Bolt for order management feature.
 *
 * @since 2.15.0
 * @access public
 *
 */
function record_advanced_shipment_tracking_number( $mid, $order_id, $meta_key, $_meta_value ) {
	if ( ! class_exists( '\Zorem_Woocommerce_Advanced_Shipment_Tracking' )
	     || ! Bolt_Feature_Switch::instance()->is_track_shipment_enabled()
	     || '_wc_shipment_tracking_items' !== $meta_key ) {

		return;
	}
	BugsnagHelper::initBugsnag();
	try {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$transaction_id = wc_bolt_get_order_meta( $order, BOLT_ORDER_META_TRANSACTION_REFERENCE_ID, true );
		if ( ! $transaction_id ) {
			return;
		}
		$order_payment_method = $order->get_payment_method();
		$is_non_bolt_order    = empty( $order_payment_method ) || $order_payment_method !== BOLT_GATEWAY_NAME;
		if ( isset( $_POST['tracking_number'] ) && strlen( $_POST['tracking_number'] ) > 0 ) {
			$tracking_provider = ! empty( $_POST['custom_tracking_provider'] )
				? wc_clean( $_POST['custom_tracking_provider'] )
				: wc_clean( $_POST['tracking_provider'] );
			$tracking_number   = wc_clean( $_POST['tracking_number'] );
			$formatted_order   = wc_bolt()->get_bolt_data_collector()->format_order_as_bolt_cart( $order_id );
			$order_items       = array();
			foreach ( $formatted_order[ BOLT_CART_ITEMS ] as $item_data ) {
				$properties = array();
				foreach ( $item_data[ BOLT_CART_ITEM_PROPERTIES ] as $item_property ) {
					$properties[] = array(
						BOLT_ORDER_SHIPMENT_TRACK_ITEMS_PROPERTY_NAME  => $item_property->name,
						BOLT_ORDER_SHIPMENT_TRACK_ITEMS_PROPERTY_VALUE => $item_property->value
					);
				}
				$order_items[] = array(
					BOLT_ORDER_SHIPMENT_TRACK_ITEMS_REFERENCE => $item_data[ BOLT_CART_ITEM_REFERENCE ],
					BOLT_ORDER_SHIPMENT_TRACK_ITEMS_OPTIONS   => $properties,
				);
			}
		}
		$tracking_data = array(
			BOLT_ORDER_SHIPMENT_TRACK_TRANSACTION_REFERENCE => (string) $transaction_id,
			BOLT_ORDER_SHIPMENT_TRACK_TRACKING_NUMBER       => (string) $tracking_number,
			BOLT_ORDER_SHIPMENT_TRACK_CARRIER               => (string) $tracking_provider,
			BOLT_ORDER_SHIPMENT_TRACK_ITEMS                 => $order_items,
			BOLT_ORDER_SHIPMENT_TRACK_IS_NON_BOLT_ORDER     => $is_non_bolt_order,
		);
		wc_bolt()->get_api_request()->handle_api_request( 'track_shipment', $tracking_data, 'merchant' );
	} catch ( \Exception $e ) {
		BugsnagHelper::notifyException( $e );
	}
}

add_action( 'updated_postmeta', '\BoltCheckout\record_advanced_shipment_tracking_number', 10, 4 );
add_action( 'added_post_meta', '\BoltCheckout\record_advanced_shipment_tracking_number', 10, 4 );
