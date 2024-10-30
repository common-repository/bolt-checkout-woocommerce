<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bolt Order Functions
 *
 * Functions for bolt order specific things.
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */

/**
 * Implement refund in WooCommerce when the Bolt credit transaction arrive
 *
 * @param object $response the data of Bolt api webhook.
 * @param WC_Order $order the order to refund.
 *
 * @since  2.0.0
 */
function wc_bolt_refund_endpoint_handler( $response, $order ) {

	if ( $order->get_status() == WC_ORDER_STATUS_REFUNDED ) {
		$failure_msg = 'A refund was issued on Bolt, but the order is already refunded';
		$order->add_order_note( $failure_msg );
		BugsnagHelper::getBugsnag()->notifyException( new \Exception( $failure_msg ), array(), 'info' );

		return;
	}

	$order_id = $order->get_id();
	$amount   = $response->amount / get_currency_divider();

	$created_refund = wc_bolt_get_order_meta( $order, BOLT_ORDER_META_REFUND_CREATED, true );
	if ( empty( $created_refund ) ) {
		$created_refund = array();
	}
	if ( BOLT_TRANSACTION_STATUS_IN_PROGRESS === $response->status || BOLT_TRANSACTION_STATUS_CREATED === $response->status ) {
		if ( ! isset( $created_refund[ $response->id ] ) ) {
			$created_refund[ $response->id ] = $response->type;
			wc_bolt_update_order_meta( $order, BOLT_ORDER_META_REFUND_CREATED, $created_refund );
			$order->add_order_note( sprintf( __( 'Refunded %1$s via Bolt is in progress. See actual refund status in Bolt merchant dashboard. - Refund ID: %2$s with reference ID %3$s.', 'woocommerce-bolt-payment-gateway' ), wc_price( $amount ), $response->id, $response->reference ) );
		}

		return;
	}

	// If the refund transaction was initiated via WooC dashboard and started with `in progress` status,
	// there is no need to create another refund in WooC for this transaction with `completed` status.
	if ( isset( $created_refund[ $response->id ] ) ) {
		$order->add_order_note( sprintf( __( 'Refunded %1$s was issued successfully via Bolt - Refund ID: %2$s with reference ID %3$s.', 'woocommerce-bolt-payment-gateway' ), wc_price( $amount ), $response->id, $response->reference ) );

		return;
	}

	//we define a constant here, cause for some merchant site, the cache system (such as W3TC) may cache the database query
	//then with a constant it can exclude the refund process
	if ( ! defined( 'WOOCOMMERCE_BOLT_REFUND' ) ) {
		define( 'WOOCOMMERCE_BOLT_REFUND', true );
	}

	$line_items = array();
	// If order has free item and we see there is a full refund we need to provide to WooC full list of items
	// otherwise WooC doesn't change order status to refunded
	if ( ( $order->get_remaining_refund_amount() - $amount < 0.01 ) && $order->has_free_item() ) {
		$items = $order->get_items( array( 'line_item', 'fee', 'shipping' ) );
		foreach ( $items as $item_id => $item ) {
			if ( is_callable( array( $item, 'get_total' ) ) ) {
				$line_items[ $item_id ]['refund_total'] = $item->get_total();
			}
			if ( is_callable( array( $item, 'get_quantity' ) ) ) {
				$line_items[ $item_id ]['qty'] = $item->get_quantity();
			}
			if ( is_callable( array( $item, 'get_taxes' ) ) ) {
				$line_items[ $item_id ]['refund_tax'] = $item->get_taxes()['total'];
			}
		}
	}

	$refund_result = wc_create_refund(
		array(
			'amount'         => $amount,
			'reason'         => 'A refund was issued on Bolt',
			'order_id'       => $order_id,
			'refund_payment' => false,
			'line_items'     => $line_items,
		)
	);

	// Only record the order note once for unique Bolt refund trasaction.
	if ( is_wp_error( $refund_result ) ) {
		$failure_msg = sprintf( __( 'Refund %1$s failed - Refund ID: %2$s with reference ID %3$s - Failed reason : %4$s.', 'woocommerce-bolt-payment-gateway' ), wc_price( $amount ), $response->id, $response->reference, $refund_result->get_error_message() );
		wc_bolt_add_order_note_only_once( $order, $failure_msg, $response->reference . ' - Failed reason' );
		throw new \Exception( $refund_result->get_error_message() );
	} elseif ( ! $refund_result ) { // just in case the result is false/non-wp-error when failed
		$unknown_failure_msg = sprintf( __( 'Refund %1$s failed - Refund ID: %2$s with reference ID %3$s - Failed reason : Cannot create order refund.', 'woocommerce-bolt-payment-gateway' ), wc_price( $amount ), $response->id, $response->reference );
		wc_bolt_add_order_note_only_once( $order, $unknown_failure_msg, $response->reference . ' - Failed reason' );
		throw new \Exception( $unknown_failure_msg );
	} else {
		$success_msg = sprintf( __( 'Refunded %1$s via Bolt merchant dashboard - Refund ID: %2$s with reference ID %3$s.', 'woocommerce-bolt-payment-gateway' ), wc_price( $amount ), $response->id, $response->reference );
		wc_bolt_add_order_note_only_once( $order, $success_msg, $response->reference . '.' );
	}
}

/**
 * Implement failed payment hook
 *
 * @param object $response the data of Bolt api webhook.
 *
 * @since  2.0.0
 */
function wc_bolt_failed_payment_endpoint_handler( $response ) {
	$order_id       = wc_bolt_get_order_id_by_order_number( $response->display_id );
	$order          = wc_get_order( $order_id );
	$success_answer = array(
		BOLT_FIELD_NAME_STATUS => BOLT_STATUS_SUCCESS,
		BOLT_CART_DISPLAY_ID   => $response->display_id
	);
	if ( ! $order ) {
		BugsnagHelper::notifyException( new \Exception( 'Can not cancel order because it does not exist now' ) );

		return Bolt_HTTP_Handler::prepare_http_response( $success_answer );
	}
	if ( isset( $response->checkboxes ) ) {
		wc_bolt_save_custom_checkboxes( $order_id, $response->checkboxes );
	}
	if ( isset( $response->custom_fields ) ) {
		wc_bolt_save_custom_fields( $order_id, $response->custom_fields );
	}
	if ( ! $order->has_status( array( WC_ORDER_STATUS_PENDING, WC_ORDER_STATUS_CANCELLED, WC_ORDER_STATUS_FAILED ) ) ) {
		throw new \Exception( sprintf( 'Try to cancel order # %s with status %s', $order_id, $order->get_status() ) );
	}
	if ( $order->has_status( WC_ORDER_STATUS_PENDING ) ) {
		// We shouldn't cancel an order created via backend.
		// Also we give third party modules opportunity to abort cancellation
		// in the same way as woocommerce does it.
		if ( apply_filters( 'woocommerce_cancel_unpaid_order', 'checkout' === $order->get_created_via(), $order ) ) {
			$order->update_status( WC_ORDER_STATUS_CANCELLED, 'Unpaid order cancelled by Bolt - time limit reached.' );
		}
	}

	return Bolt_HTTP_Handler::prepare_http_response( $success_answer );
}

/**
 * Add order note to woocommerce order only if it doesn't contain similar note
 *
 * @param WC_order $order
 * @param string $note text that we need to add
 * @param $search_phrase text for search if note is already added
 *                       If empty, function uses $note for search
 *
 * @return comment_id if we added note, 0 otherwise
 * @since 2.0.2
 */
function wc_bolt_add_order_note_only_once( $order, $note, $search_phrase = '' ) {
	if ( '' == $search_phrase ) {
		$search_phrase = $note;
	}
	$args        = array(
		'order_id' => $order->get_id(),
	);
	$added_notes = wc_get_order_notes( $args );

	foreach ( $added_notes as $added_note ) {
		if ( strpos( $added_note->content, $search_phrase ) !== false ) {
			return 0;
		}
	}

	return $order->add_order_note( $note );
}

/**
 * Check if woocommerce order has multiple_packages
 *
 * @param WC_order $order
 *
 * @return boolean
 * @since  2.0.3
 *
 */
function wc_bolt_if_order_has_multiple_packages( $order ) {
	return count( $order->get_shipping_methods() ) > 1;
}

/**
 * Save data about bolt custom checkboxes
 *
 * @param int $order_id order id
 * @param array $checkboxes array with checkboxes data
 *
 * @since  2.0.12
 */
function wc_bolt_save_custom_checkboxes( $order_id, $checkboxes ) {

	if ( wc_bolt_get_order_meta( $order_id, BOLT_ORDER_META_CHECKBOXES, true ) ) {
		return;
	}

	$checkboxes = apply_filters( 'wc_bolt_save_custom_checkboxes', $checkboxes, $order_id );

	wc_bolt_update_order_meta( $order_id, BOLT_ORDER_META_CHECKBOXES, $checkboxes );
}

function wc_bolt_save_custom_fields( $order_id, $custom_fields ) {

	if ( wc_bolt_get_order_meta( $order_id, BOLT_ORDER_META_CUSTOM_FIELDS, true ) ) {
		return;
	}

	$custom_fields = apply_filters( 'wc_bolt_save_custom_fields', $custom_fields, $order_id );

	wc_bolt_update_order_meta( $order_id, BOLT_ORDER_META_CUSTOM_FIELDS, $custom_fields );
}

/**
 * There is a bug in WC core (https://github.com/woocommerce/woocommerce/issues/25641),
 * this function is to get the tax total by calculating the sum of order taxes for fixing the bug in Bolt plugin.
 *
 * @param WC_order $order
 *
 * @return float
 * @since  2.5.0
 */
function wc_bolt_get_order_tax_total( $order ) {
	$order_tax_total = 0;
	foreach ( $order->get_tax_totals() as $k => $tax ) {
		$order_tax_total += wc_round_tax_total( $tax->amount );
	}

	return $order_tax_total;
}

/**
 * If user pays for existing order check if order has an error that prevent checkout
 *
 * @param int $order_id Woocommerce order_id
 *
 * @return string Error message for user. Empty string if we don't have any error
 *
 * @since 2.6.0
 * @access public
 */
function wc_bolt_check_error_for_pay_exist_order( $order_id ) {
	$order = wc_get_order( $order_id );

	$contains_physical_products = false;
	foreach ( $order->get_items() as $item_id => $item ) {
		$product = $item->get_product();
		if ( ! $product->is_virtual() ) {
			$contains_physical_products = true;
			break;
		}
	}

	if ( $contains_physical_products ) {
		$address_types = array( 'billing', 'shipping' );
	} else {
		$address_types = array( 'billing' );
	}
	$address_fields = array( 'country', 'state', 'postcode', 'city', 'address_1', 'email' );
	foreach ( $address_types as $address_type ) {
		foreach ( $address_fields as $address_field ) {
			if ( $address_type == 'shipping' && $address_field == 'email' ) {
				continue;
			}
			$getter_name = 'get_' . $address_type . '_' . $address_field;
			if ( empty( $order->$getter_name() ) ) {
				return 'Please edit order and add ' . $address_type . ' details to continue payment';
			}
		}
	}

	return '';
}

/**
 * Save the payment method used for Bolt transaction into order meta data
 *
 * @param WC_Order $order Woocommerce order
 * @param string $transaction_reference Reference of Bolt transaction
 *
 * @since 2.10.0
 * @access public
 */
function wc_bolt_save_additional_payment_info( $order, $transaction_reference ) {
	$api_response = wc_bolt()->get_bolt_data_collector()->handle_api_request( 'transactions/' . $transaction_reference );
	if ( isset( $api_response->processor ) && $api_response->processor != BOLT_PROCESSOR_VANTIV ) {
		$method_display_title = array_key_exists( $api_response->processor, BOLT_PROCESSOR_DISPLAY )
			? 'Bolt-' . BOLT_PROCESSOR_DISPLAY[ $api_response->processor ]
			: 'Bolt-' . strtoupper( $api_response->processor );
		$order->set_payment_method_title( $method_display_title );
	}
}

/**
 * Display saved payment method in the order detail section of backend page and email
 *
 * @param string $title The title of payment gateway
 * @param string $id The id of payment gateway
 *
 * @since 2.10.0
 * @access public
 */
function wc_bolt_display_order_apm( $title, $id ) {
	global $theorder;

	if ( $id != BOLT_GATEWAY_NAME || ! is_object( $theorder ) || ! is_a( $theorder, 'WC_Order' ) ) {
		return $title;
	}
	// For draft order, the payment title could be still empty.
	$order_payment_title = $theorder->get_payment_method_title() ?: wc_bolt()->get_settings()[ Bolt_Settings::SETTING_NAME_PAYMENT_METHOD_TITLE ];

	return $order_payment_title;

}

add_filter( 'woocommerce_gateway_title', '\BoltCheckout\wc_bolt_display_order_apm', 99, 2 );

/**
 * Wrapper function to check if HPOS is enabled or not
 *
 * @return boolean
 *
 * @since 2.20.0
 * @access public
 */
function wc_bolt_is_hpos() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
	     && wc_bolt_is_callable( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) ) {
		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	return false;
}

/**
 * Is the real-time data sync between old and new tables currently enabled?
 *
 * @return boolean
 *
 * @since 2.20.0
 * @access public
 */
function wc_bolt_is_custom_order_tables_in_sync() {
	if ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer' )
	     && wc_bolt_is_callable( '\Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer', 'custom_orders_table_usage_is_enabled' ) ) {
		return wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class )->data_sync_is_enabled();
	}

	return false;
}

/**
 * Function to update order meta
 *
 * @param WC_Order|integer $order The WC order object or the order id.
 * @param string $meta_key The Metadata key.
 * @param mixed $meta_value The Metadata value. Must be serializable if non-scalar.
 *
 * @return boolean
 *
 * @since 2.20.0
 * @access public
 */
function wc_bolt_update_order_meta( $order, $meta_key, $meta_value ) {
	if ( empty( $order ) || empty( $meta_key ) ) {
		return false;
	}
	// For HPOS order 
	if ( wc_bolt_is_hpos() ) {
		if ( ! is_object( $order ) ) {
			$the_order = wc_get_order( $order );
		} else {
			$the_order = $order;
		}
		if ( ! empty( $the_order ) && is_a( $the_order, 'WC_Order' ) ) {
			if ( wc_bolt_is_callable( $the_order, 'update_meta_data' ) ) {
				$the_order->update_meta_data( $meta_key, $meta_value );
				if ( wc_bolt_is_custom_order_tables_in_sync() ) {
					update_post_meta( $the_order->get_id(), $meta_key, $meta_value );
				}

				return true;
			}
		}
	} else { // For legacy order
		if ( is_object( $order ) && is_a( $order, 'WC_Order' ) ) {
			$order_id = $order->get_id();
		} else {
			$order_id = intval( $order );
		}
		if ( $order_id ) {
			update_post_meta( $order_id, $meta_key, $meta_value );

			return true;
		}
	}

	return false;
}

/**
 * Function to get order meta
 *
 * @param WC_Order|integer $order The WC order object or the order id.
 * @param string $meta_key The meta key to retrieve.
 * @param boolean $single Whether to return a single value.
 *
 * @return boolean
 *
 * @since 2.20.0
 * @access public
 */
function wc_bolt_get_order_meta( $order, $meta_key, $single = true ) {
	if ( empty( $order ) || empty( $meta_key ) ) {
		return $single ? '' : array();
	}
	// For HPOS order 
	if ( wc_bolt_is_hpos() ) {
		if ( ! is_object( $order ) ) {
			$the_order = wc_get_order( $order );
		} else {
			$the_order = $order;
		}
		if ( ! empty( $the_order ) && is_object( $the_order ) && is_a( $the_order, 'WC_Order' ) && wc_bolt_is_callable( $the_order, 'get_meta' ) ) {
			return $the_order->get_meta( $meta_key );
		}
	} else { // For legacy order
		if ( is_object( $order ) && is_a( $order, 'WC_Order' ) ) {
			$order_id = $order->get_id();
		} else {
			$order_id = $order;
		}
		if ( $order_id ) {
			return get_post_meta( $order_id, $meta_key, $single );
		}
	}

	return $single ? '' : array();
}


/**
 * Check if a method is callable w.r.t. given object or not
 *
 * @param mixed $object The object.
 * @param string $method The method name.
 *
 * @return boolean
 *
 * @since 2.20.0
 * @access public
 */
function wc_bolt_is_callable( $object = null, $method = '' ) {
	if ( empty( $object ) || empty( $method ) ) {
		return false;
	}
	$type = gettype( $object );
	if ( ! in_array( $type, array( 'string', 'object' ), true ) ) {
		return false;
	}
	if ( 'string' === $type && ! class_exists( $object ) ) {
		return false;
	}

	return is_callable( array( $object, $method ) );
}