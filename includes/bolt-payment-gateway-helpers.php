<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


class CartErrorException extends \Exception {
}

/* @var bool $is_request_from_hooks True if request initiated from hooks, otherwise false */
global $is_webhook_request;
$is_webhook_request = false;

if ( WC_BOLT_WP_REST_API_ADDON ) {
	function register_wp_rest_api_routes( $routes ) {
		$routes['/bolt/response']                = array(
			array(
				'BoltCheckout\handle_bolt_endpoint',
				\WP_JSON_Server::CREATABLE | \WP_JSON_Server::ACCEPT_JSON
			),
		);
		$routes['/bolt/featureswitches/changed'] = array(
			array(
				'BoltCheckout\handle_featureswitches_changed',
				\WP_JSON_Server::CREATABLE | \WP_JSON_Server::ACCEPT_JSON
			),
		);

		return $routes;
	}

	add_filter( 'json_endpoints', 'BoltCheckout\register_wp_rest_api_routes' );

} else {
	function register_bolt_api_endpoints() {
		/**
		 * Sync and handle Bolt API.
		 */
		register_rest_route( 'bolt', '/response', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => 'BoltCheckout\handle_bolt_endpoint',
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'bolt', '/featureswitches/changed', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => 'BoltCheckout\handle_featureswitches_changed',
			'permission_callback' => '__return_true',
		) );
	}

	add_action( 'rest_api_init', 'BoltCheckout\register_bolt_api_endpoints' );
}


/**
 * Function to handle bolt webhook.
 *
 * @param $request_data
 *
 * @return WP_REST_Response array   Well-formed response sent to the Bolt Server
 */
function handle_bolt_endpoint() {
	global $is_webhook_request;

	wc_bolt()->get_metrics_client()->save_start_time();

	BugsnagHelper::initBugsnag();
	$is_webhook_request = true;

	/////////////////////////////////////////////////////////////////
	// In the case of long processing, we ignore Bolt's aborts
	// And give the calculation 40 seconds to complete
	// If it takes longer, then custom merchant-side optimization is needed
	/////////////////////////////////////////////////////////////////
	ignore_user_abort( true );
	set_time_limit( 40 );
	/////////////////////////////////////////////////////////////////
	///
	try {
		$hmac_header = @$_SERVER[ BOLT_HEADER_HMAC ];
		$get_data    = file_get_contents( 'php://input' );
		BugsnagHelper::addBreadCrumbs( array( 'BOLT Webhook API REQUEST' => $get_data ) );
		$response = json_decode( $get_data );

		if ( ! verify_signature( $get_data, $hmac_header ) ) {
			//////////////////////////////////////////////////////////
			// Request can not be verified as originating from Bolt
			//////////////////////////////////////////////////////////
			BugsnagHelper::notifyException( new \Exception( "Invalid HMAC header" ) );

			return Bolt_HTTP_Handler::prepare_http_response(
				array(
					BOLT_FIELD_NAME_STATUS => BOLT_STATUS_FAILURE,
					BOLT_FIELD_NAME_ERROR  => array( BOLT_FIELD_NAME_ERROR_MESSAGE => 'Invalid HMAC header' )
				),
				HTTP_STATUS_UNAUTHORIZED,
				array( 'WWW-Authenticate' => 'HTTP_X_BOLT_HMAC_SHA256 realm="Bolt Order Update API"' )
			);
		}

		// Implement discount hook
		if ( BOLT_HOOK_TYPE_DISCOUNTS_CODE_APPLY === $response->type ) {
			$bolt_discounts = new Bolt_Discounts_Helper( $response );

			return $bolt_discounts->apply_coupon_from_discount_hook();
		}

		// Implement subscription hook
		if ( BOLT_HOOK_TYPE_ORDER_CREATE === $response->type ) {
			$bolt_subscription = new Bolt_Subscription( $response );

			return $bolt_subscription->implement_order_create_api( $response );
		}

		// Implement bolt page checkout hook
		if ( BOLT_HOOK_TYPE_CART_CREATE === $response->type ) {
			$bolt_page_checkout = new Bolt_Page_Checkout;

			return $bolt_page_checkout->implement_bolt_order_create_api( $response );
		}

		// Implement failed payment hook
		if ( BOLT_HOOK_TYPE_FAILED_PAYMENT === $response->type ) {
			return wc_bolt_failed_payment_endpoint_handler( $response );
		}

		$retry_order_search = 5;

		/*********************************************************
		 * Attempt to find the order
		 *********************************************************/
		do {
			$transaction_reference = @$response->source_transaction_reference ?: $response->reference;
			$transaction_id        = @$response->source_transaction_id ?: $response->id;

			//check if order is created in pre-auth process
			$order_id = wc_bolt_get_order_id_by_order_reference( $response->order );

			if ( $order_id ) {
				if ( ! wc_bolt_is_hpos() ) {
					update_post_meta( $order_id, BOLT_ORDER_META_TRANSACTION_ID, $transaction_id );
					update_post_meta( $order_id, BOLT_ORDER_META_TRANSACTION_REFERENCE_ID, $transaction_reference );
				}
			} else {
				$result_arr = wc_bolt_data()->get_bolt_post_meta( 'bolt_transaction_reference_id', $transaction_reference );

				if ( is_wp_error( $result_arr ) ) {
					return prepare_http_error_response( $result_arr );
				} else if ( ! empty( $result_arr ) ) {
					$order_id = $result_arr[0]->post_id;
				}
			}
			if ( ! $order_id ) {
				##########################
				# Order was not found.
				# Wait 5 seconds and try again to give natural
				# order creation an chance to complete if the process
				# is time consuming
				##########################
				sleep( 5 );
			}

		} while ( empty( $order_id ) && -- $retry_order_search );
		/*********************************************************/

		$order = wc_get_order( $order_id );

		if ( ! empty( $order ) ) {
			if ( wc_bolt_is_hpos() ) {
				wc_bolt_update_order_meta( $order, BOLT_ORDER_META_TRANSACTION_ID, $transaction_id );
				wc_bolt_update_order_meta( $order, BOLT_ORDER_META_TRANSACTION_REFERENCE_ID, $transaction_reference );
			}
			if ( isset( $response->checkboxes ) ) {
				wc_bolt_save_custom_checkboxes( $order_id, $response->checkboxes );
			}

			if ( isset( $response->custom_fields ) ) {
				wc_bolt_save_custom_fields( $order_id, $response->custom_fields );
			}

			// Note 'amount' field of Bolt transaction always have a full authorized amount,
			// even for partial capture case
			$original_wc_total = $order->get_total();
			$price_difference  = ( $response->amount / get_currency_divider() ) - $original_wc_total;
			// Ignore price difference if Bolt hook type is void or rejected_irreversible or credit.
			$ignore_price_difference_hook_types = array(
				BOLT_HOOK_TYPE_VOID,
				BOLT_HOOK_TYPE_REJECTED_IRREVERSIBLE,
				BOLT_HOOK_TYPE_CREDIT
			);
			if ( ! in_array( $response->type, $ignore_price_difference_hook_types ) && ( (int) abs( $price_difference ) ) > 0 ) {
				throw new \Exception( sprintf( __( 'Order ID : %s. Bolt Total and WooCommerce total difference (%s) resolved in tax.  Bolt total: [%s], WooCommerce total: [%s]', 'woocommerce-bolt-payment-gateway' ), $order_id, $price_difference, ( $response->amount / get_currency_divider() ), $original_wc_total ) );
			}
			if ( BOLT_HOOK_TYPE_PENDING === $response->type ) { // pending payment
				$order->set_transaction_id( $transaction_reference );
				wc_bolt_save_additional_payment_info( $order, $response->reference );
				$order->save();
			} elseif ( BOLT_HOOK_TYPE_PAYMENT === $response->type ) { // payment a sale occurred (auth+capture)
				wc_bolt_update_order_meta( $order, BOLT_ORDER_META_TRANSACTION_ID, $response->id );
				wc_bolt_update_order_meta( $order, BOLT_ORDER_META_TRANSACTION_ORDER, $response->order );
				wc_bolt_update_order_meta( $order, BOLT_ORDER_META_TRANSACTION_DISPLAY_ID, $response->display_id );
				$order->payment_complete( $transaction_reference );
				$order_status = apply_filters( 'woocommerce_payment_complete_order_status', $order->needs_processing() ? WC_ORDER_STATUS_PROCESSING : WC_ORDER_STATUS_COMPLETED, $order->get_id(), $order );
				$order->update_status( $order_status );
			} elseif ( BOLT_HOOK_TYPE_CREDIT === $response->type ) { // credit a credit/refund was issued
				wc_bolt_refund_endpoint_handler( $response, $order );
			} elseif ( BOLT_HOOK_TYPE_CAPTURE === $response->type ) { // capture a capture occurred
				wc_bolt_update_order_meta( $order, BOLT_ORDER_META_TRANSACTION_ID, $response->id );
				wc_bolt_update_order_meta( $order, BOLT_ORDER_META_TRANSACTION_ORDER, $response->order );
				wc_bolt_update_order_meta( $order, BOLT_ORDER_META_TRANSACTION_DISPLAY_ID, $response->display_id );
				$order->payment_complete( $transaction_reference );
				if ( WC_ORDER_STATUS_COMPLETED !== $order->get_status() ) {
					$order->update_status( WC_ORDER_STATUS_PROCESSING );
				}
			} elseif ( BOLT_HOOK_TYPE_VOID === $response->type ) { // void a void occurred
				wc_bolt_update_order_meta( $order, BOLT_ORDER_META_TRANSACTION_ID, $response->id );
				wc_bolt_update_order_meta( $order, BOLT_ORDER_META_TRANSACTION_ORDER, $response->order );
				wc_bolt_update_order_meta( $order, BOLT_ORDER_META_TRANSACTION_DISPLAY_ID, $response->display_id );

				if ( $order->has_status( array(
					WC_ORDER_STATUS_PENDING,
					WC_ORDER_STATUS_FAILED,
					WC_ORDER_STATUS_ON_HOLD
				) ) ) {
					$order->update_status( WC_ORDER_STATUS_CANCELLED );
				}
			} elseif ( BOLT_HOOK_TYPE_AUTH === $response->type ) { // auth an authorization was issued
				wc_bolt_update_order_meta( $order, BOLT_ORDER_META_TRANSACTION_ID, $response->id );
				wc_bolt_update_order_meta( $order, BOLT_ORDER_META_TRANSACTION_ORDER, $response->order );
				wc_bolt_update_order_meta( $order, BOLT_ORDER_META_TRANSACTION_DISPLAY_ID, $response->display_id );
				$order->set_transaction_id( $transaction_reference );
				$order->update_status( WC_ORDER_STATUS_ON_HOLD );
				wc_reduce_stock_levels( $order_id );
			} elseif ( BOLT_HOOK_TYPE_REJECTED_REVERSIBLE === $response->type ) { // rejected_reversible a transaction was rejected but decision can be overridden.
				wc_bolt_update_order_meta( $order, BOLT_ORDER_META_TRANSACTION_ID, $response->id );
				wc_bolt_update_order_meta( $order, BOLT_ORDER_META_TRANSACTION_ORDER, $response->order );
				wc_bolt_update_order_meta( $order, BOLT_ORDER_META_TRANSACTION_DISPLAY_ID, $response->display_id );
				$order->update_status( WC_ORDER_STATUS_BOLT_REJECT );
			} elseif ( BOLT_HOOK_TYPE_REJECTED_IRREVERSIBLE === $response->type ) { // rejected_irreversible a transaction was rejected and decision can not be overridden.
				wc_bolt_update_order_meta( $order, BOLT_ORDER_META_TRANSACTION_ID, $response->id );
				wc_bolt_update_order_meta( $order, BOLT_ORDER_META_TRANSACTION_ORDER, $response->order );
				wc_bolt_update_order_meta( $order, BOLT_ORDER_META_TRANSACTION_DISPLAY_ID, $response->display_id );
				$order->update_status( WC_ORDER_STATUS_FAILED );
			}

			if ( ( BOLT_HOOK_TYPE_AUTH === $response->type ) || ( BOLT_HOOK_TYPE_PAYMENT === $response->type ) ) {
				add_action( 'shutdown', array(
					wc_bolt_data(),
					'cleanup_expired_session'
				), 0 );
			}

			return Bolt_HTTP_Handler::prepare_http_response(
				array(
					BOLT_FIELD_NAME_STATUS => BOLT_STATUS_SUCCESS,
					BOLT_CART_DISPLAY_ID   => $order->get_order_number()
				)
			);
		}

		/*********************************************************
		 * The order was not found.  We will create it
		 *********************************************************/
		add_action( 'woocommerce_checkout_order_processed', '\BoltCheckout\tag_order_created_via_orphaned_transaction', 9, 3 );

		$api_response = wc_bolt()->get_bolt_data_collector()->handle_api_request( 'transactions/' . $transaction_reference );

		set_post_array_by_bolt_order( $api_response );
		$_POST['orphaned_transaction'] = '1';

		// For orphaned transaction we need to return WP_REST_Response object as response,
		// and in case that some 3rd-party addon may interrupt the normal process and force to return something else,
		// then we check if save_order function does returns WP_REST_Response
		$result = wc_bolt()->get_bolt_checkout()->save_order( $api_response );
		if ( is_a( $result, 'WP_REST_Response' ) ) {
			return $result;
		} else {
			throw new \Exception( var_export( $result, true ) );
		}
		################################################################
	} catch ( \Exception $e ) {
		BugsnagHelper::notifyException( $e );

		if ( isset( $api_response->order->cart->order_reference ) ) {
			wc_bolt_data()->update_session_time( BOLT_PREFIX_SESSION_DATA . $api_response->order->cart->order_reference );
		}

		return Bolt_HTTP_Handler::prepare_http_response(
			array(
				BOLT_FIELD_NAME_STATUS => BOLT_STATUS_FAILURE,
				BOLT_FIELD_NAME_ERROR  => array( BOLT_FIELD_NAME_ERROR_MESSAGE => $e->getMessage() )
			),
			HTTP_STATUS_UNPROCESSABLE
		);
	} finally {
		wc_bolt()->get_metrics_client()->process_metric( BOLT_METRIC_NAME_WEBHOOKS, ( Bolt_HTTP_Handler::$status == HTTP_STATUS_OK ) ? BOLT_STATUS_SUCCESS : BOLT_STATUS_FAILURE );
		# Cleanup session data created by this pseudo-session
		if ( ! Bolt_Feature_Switch::instance()->is_native_cart_session_enabled() ) {
			@WC()->session->destroy_session();
		}
	}
}

/**
 * Prepare a http error message
 *
 * @param \WP_Error $wp_error
 *
 * @return WP_REST_Response/WP_JSON_Response Well-formed response sent to the Bolt Server
 * @since  2.0.12
 * @access public
 *
 */
function prepare_http_error_response( $wp_error ) {
	//////////////////////////////////////////////////////////
	// Return because of an unexpected error
	//////////////////////////////////////////////////////////
	$meta_data = array(
		BOLT_FIELD_NAME_ERROR_CODE => $wp_error->get_error_code(),
		BOLT_FIELD_NAME_ERROR_DATA => $wp_error->get_error_data()
	);
	BugsnagHelper::notifyException( new \Exception( $wp_error->get_error_message() ), $meta_data );

	return Bolt_HTTP_Handler::prepare_http_response(
		array(
			BOLT_FIELD_NAME_STATUS => BOLT_STATUS_FAILURE,
			BOLT_FIELD_NAME_ERROR  => array( BOLT_FIELD_NAME_ERROR_MESSAGE => $wp_error->get_error_message() )
		),
		HTTP_STATUS_INTERNAL_ERROR
	);
}

/**
 * Set $_POST by bolt api_response for simulate Bolt checkout experience
 *
 * @param object $api_response
 *
 * @since  1.3.5
 * @access public
 *
 */
function set_post_array_by_bolt_order( $api_response ) {

	$bolt_callback_formatted_data = array(
		"reference"       => $api_response->order->cart->order_reference,
		"status"          => @$api_response->status,
		"billing_address" => $api_response->order->cart->billing_address,
		"amount"          => @$api_response->amount,
		"cart"            => $api_response->order->cart
	);

	if ( $api_response->order->cart->shipments ) {
		$bolt_callback_formatted_data["shipping_address"] = $api_response->order->cart->shipments[0]->shipping_address;

		if ( $api_response->order->cart->shipments[0]->reference ) {
			$bolt_callback_formatted_data["shipping_option"] = array(
				"rawValue" => $api_response->order->cart->shipments[0]->service,
				"value"    => array(
					"reference" => $api_response->order->cart->shipments[0]->reference,
					"service"   => $api_response->order->cart->shipments[0]->service,
					"cost"      => $api_response->order->cart->shipments[0]->cost
				)
			);

			if ( $api_response->order->cart->shipments[0]->tax_amount ) {
				$bolt_callback_formatted_data["shipping_option"]["value"]["tax_amount"] = $api_response->order->cart->shipments[0]->tax_amount;
			}
		}
	}

	$_POST['transaction_details'] = json_encode( $bolt_callback_formatted_data );

	///////////////////////////////////////////////////////
	// Restore posted data
	///////////////////////////////////////////////////////
	$posted_data = wc_bolt_data()->get_session( BOLT_PREFIX_SESSION_POSTED_DATA . $api_response->order->cart->order_reference, '' );
	if ( ! empty( $posted_data ) ) {
		$posted_data = maybe_unserialize( $posted_data );
		$_POST       = array_merge( $posted_data, $_POST );
	}
}


/**
 * Tags an order which was creatd by orphaned transaction
 *
 * @param int $order_id The id of the order
 * @param array $posted_data The post data used for this order
 * @param WC_Order $order The order that was created
 */
function tag_order_created_via_orphaned_transaction( $order_id, $posted_data, $order ) {
	if ( empty( $order ) ) {
		$order = wc_get_order( $order_id );
	}
	$order->add_order_note( __( 'Order was created by Bolt api hook.', 'woocommerce-bolt-payment-gateway' ) );
}


/**
 * Updates the order status by the bolt transaction status with a transaction note
 *
 * @param WC_Order $order The order to be updated
 * @param string|null $status The new status of bolt transaction.  If null, the old status will remain
 * @param string|null $note An optional note for this change. If null, the default note for the status is used
 */
function update_transaction_status( $order, $status, $note = null ) {

	$transaction_id = wc_bolt_get_order_meta( $order, BOLT_ORDER_META_TRANSACTION_REFERENCE_ID, true );
	$dashboard_url  = wc_bolt()->get_bolt_settings()->get_merchant_dashboard_host() . '/transaction/' . $transaction_id;
	switch ( $status ) {
		case BOLT_TRANSACTION_STATUS_COMPLETED: // payment is completed
			$order->payment_complete( $transaction_id );
			$order->update_status( WC_ORDER_STATUS_PROCESSING, $note );
			break;
		case BOLT_TRANSACTION_STATUS_AUTHORIZED: // transaction is authorized, ready for capture or void
			wc_reduce_stock_levels( $order->get_id() );
			$order->update_status( WC_ORDER_STATUS_ON_HOLD, ! is_null( $note ) ? $note : sprintf( __( 'Authorized payment is ready for capture on Bolt.<br/><a href="%s">%s</a><br/>', 'woocommerce-bolt-payment-gateway' ), $dashboard_url, $dashboard_url ) );
			break;
		case BOLT_TRANSACTION_STATUS_PENDING: // transaction is pending review
			$order->update_status( WC_ORDER_STATUS_PENDING, ! is_null( $note ) ? $note : __( 'In Review On Bolt.', 'woocommerce-bolt-payment-gateway' ) );
			break;
		case BOLT_TRANSACTION_STATUS_CANCELLED: // transaction was cancelled
			$order->update_status( WC_ORDER_STATUS_CANCELLED, ! is_null( $note ) ? $note : __( 'Payment was cancelled On Bolt.', 'woocommerce-bolt-payment-gateway' ) );
			break;
		case BOLT_TRANSACTION_STATUS_FAILED: // transaction was declined / permanently rejected
			$order->update_status( WC_ORDER_STATUS_FAILED, ! is_null( $note ) ? $note : __( 'Payment was declined On Bolt.', 'woocommerce-bolt-payment-gateway' ) );
			break;
		case BOLT_TRANSACTION_STATUS_REVERSIBLE: //  transaction was rejected but decision can be overridden
			$order->update_status( WC_ORDER_STATUS_BOLT_REJECT, ! is_null( $note ) ? $note : sprintf( __( 'Payment was declined but you can update the decision on Bolt.<br/><a href="%s">%s</a><br/>', 'woocommerce-bolt-payment-gateway' ), $dashboard_url, $dashboard_url ) );
			break;
		case BOLT_TRANSACTION_STATUS_IRREVERSIBLE: // transaction was rejected and decision can not be overridden
			$order->update_status( WC_ORDER_STATUS_FAILED, ! is_null( $note ) ? $note : __( 'Payment was permanently rejected on Bolt.', 'woocommerce-bolt-payment-gateway' ) );
			break;
		default:
			// do nothing
			break;
	}
}


/**
 * Check that data sent by Bolt server. We compare the payload and
 * signature with the expected results when processed against the Bolt payment secret
 *
 * @param $payload        Data sent by Bolt
 * @param $signature      signature
 *
 * @return bool     true if the request is authenticated, otherwise false
 */
function verify_signature( $payload, $signature ) {
	return ( compute_signature( $payload ) == $signature );
}

/**
 * Compute signature using payment secret key
 *
 * @param $payload a string for which a signature is required
 *
 * @return string
 * @since 2.1.0
 */
function compute_signature( $payload ) {
	$settings           = wc_bolt()->get_settings();
	$payment_secret_key = $settings[ Bolt_Settings::SETTING_NAME_PAYMENT_SECRET_KEY ];

	return base64_encode( hash_hmac( 'sha256', $payload, $payment_secret_key, true ) );
}

/**
 * Upon changing the status from on-hold to cancelled via the WooCommerce
 * admin, the order is cancelled on Bolt
 *
 * @var int $order_id the id of the order whose Bolt transaction is being captured
 *
 * action: woocommerce_order_status_on-hold_to_cancelled
 */
function implement_void_action( $order_id ) {
	global $is_webhook_request;

	BugsnagHelper::initBugsnag();
	try {
		$order = wc_get_order( $order_id );
		if ( ( $order->get_payment_method() !== BOLT_GATEWAY_NAME ) || $is_webhook_request ) {
			return;
		}

		$transaction_id = wc_bolt_get_order_meta( $order, BOLT_ORDER_META_TRANSACTION_ID, true );

		$data = array(
			BOLT_FIELD_NAME_TRANSACTION_ID => (string) $transaction_id,
		);

		wc_bolt()->get_bolt_data_collector()->handle_api_request( 'transactions/void', $data );

	} catch ( \Exception $e ) {
		BugsnagHelper::notifyException( $e );
	}
}

add_action( 'woocommerce_order_status_on-hold_to_cancelled', '\BoltCheckout\implement_void_action' );


/**
 * Upon changing the status from on-hold to processing or completed via the WooCommerce
 * admin, the order is captured on Bolt
 *
 * @var int $order_id the id of the order whose Bolt transaction is being captured
 *
 * action: woocommerce_order_status_on-hold_to_processing
 * action: woocommerce_order_status_on-hold_to_completed
 */
function implement_capture_action( $order_id ) {
	global $is_webhook_request;

	BugsnagHelper::initBugsnag();

	$order = wc_get_order( $order_id );
	if ( ( $order->get_payment_method() !== BOLT_GATEWAY_NAME ) || $is_webhook_request ) {
		return;
	}

	$transaction_id           = wc_bolt_get_order_meta( $order, BOLT_ORDER_META_TRANSACTION_ID, true );
	$transaction_reference_id = wc_bolt_get_order_meta( $order, BOLT_ORDER_META_TRANSACTION_REFERENCE_ID, true );

	if ( $transaction_id && $transaction_reference_id ) {
		$data = array(
			BOLT_FIELD_NAME_TRANSACTION_ID         => (string) $transaction_id,
			BOLT_FIELD_NAME_AMOUNT                 => convert_monetary_value_to_bolt_format( $order->get_total(), $order->get_currency() ),
			BOLT_FIELD_NAME_CURRENCY               => $order->get_currency(),
			BOLT_FIELD_NAME_SKIP_HOOK_NOTIFICATION => true,
		);
		try {
			wc_bolt()->get_bolt_data_collector()->handle_api_request( 'transactions/capture', $data );
			$order->set_transaction_id( $transaction_reference_id );
		} catch ( \Exception $e ) {
			$order->add_order_note( 'Failed to capture transaction {$transaction_id} from Bolt Capture Endpoint.' );
			BugsnagHelper::getBugsnag()->notifyException( $e );
		}
	} else {
		throw new \Exception( "bolt_transaction_id/bolt_transaction_reference_id isn't set: order_id:{$order_id}, transaction_id {$transaction_id},transaction_reference_id:{$transaction_reference_id}" );
	}

}

add_action( 'woocommerce_order_status_on-hold_to_processing', '\BoltCheckout\implement_capture_action' );
add_action( 'woocommerce_order_status_on-hold_to_completed', '\BoltCheckout\implement_capture_action' );


/**
 * Set the cart content by Bolt Reference
 *
 * @param string $reference The Bolt Order Reference
 * @param boolean $set_current_user true - should update current user_id, false - shouldn't
 *
 * TODO: Add FEEs to this routine???  Perhaps they are already included
 */
function set_cart_by_bolt_reference( $reference, $set_current_user = true ) {

	try {
		WC()->cart->empty_cart( false );
		$original_session_data = wc_bolt_data()->get_session( BOLT_PREFIX_SESSION_DATA . $reference );
		// If payment from "invoice for order" email, directly retrun with sign
		if ( $original_session_data && ! is_array( $original_session_data ) && (string) $original_session_data == BOLT_CART_ORDER_TYPE_ORDER_INVOICE ) {
			return 'orderinvoice';
		}
		if ( Bolt_Feature_Switch::instance()->is_native_cart_session_enabled() ) {
			$customer_id = wc_bolt_data()->get_customer_id( $reference );
			if ( $customer_id ) {
				load_cart_from_native_wc_session( $customer_id, $reference );

				return true;
			}
		}
		////////////////////////////////////////////////////////////////////////////
		// Replace session data with the original data at Bolt Order Creation time
		////////////////////////////////////////////////////////////////////////////
		if ( ! $original_session_data ) {
			throw new \Exception( 'Please refresh the page and try again.' );
		}
		foreach ( $original_session_data as $key => $value ) {
			if ( ( $key == 'customer' ) && ! $set_current_user ) {
				continue;
			}
			if ( $key == BOLT_FIELD_NAME_ADDITIONAL ) {
				continue;
			}
			WC()->session->set( $key, maybe_unserialize( $value ) );
		}
		////////////////////////////////////////////////////////////////////////////
		$totals = (array) maybe_unserialize( $original_session_data['cart_totals'] );
		$cart   = (array) maybe_unserialize( $original_session_data['cart'] );
		$fees   = isset( $original_session_data['bolt_wc_fees'] ) ? (array) maybe_unserialize( $original_session_data['bolt_wc_fees'] ) : array();

		if ( empty( $cart ) ) {
			throw new \Exception( 'The cart saved in the session is empty for bolt reference ' . $reference );
		}
		// If we create order via frontend call (non pre-auth) we don't want to override user_id
		// because current session can be more actual.
		if ( $set_current_user ) {
			$customer = (array) maybe_unserialize( @$original_session_data['customer'] );
			if ( $customer ) {
				$user = get_user_by( 'ID', $customer['id'] );

				if ( ! is_user_logged_in() && $user ) {
					wp_set_current_user( $user->ID );
					WC()->customer = new \WC_Customer( $user->ID, true );
					// Customer should be saved during shutdown.
					add_action( 'shutdown', array( WC()->customer, 'save' ), 10 );
				}
			}
		}

		do_action( 'wc_bolt_before_set_cart_by_bolt_reference', $reference, $original_session_data );

		WC()->cart->set_totals( $totals );
		WC()->cart->set_applied_coupons( (array) maybe_unserialize( $original_session_data['applied_coupons'] ) );
		WC()->cart->set_coupon_discount_totals( (array) maybe_unserialize( $original_session_data['coupon_discount_totals'] ) );
		WC()->cart->set_coupon_discount_tax_totals( (array) maybe_unserialize( $original_session_data['coupon_discount_tax_totals'] ) );
		WC()->cart->set_removed_cart_contents( (array) maybe_unserialize( $original_session_data['removed_cart_contents'] ) );
		if ( $fees ) {
			WC()->cart->fees_api()->set_fees( $fees );
		}
		if ( is_array( $cart ) ) {
			$cart_contents = array();
			foreach ( $cart as $key => $values ) {
				$the_product_id = $values['variation_id'] ? $values['variation_id'] : $values['product_id'];
				$product        = wc_get_product( $the_product_id );
				if ( ! empty( $product ) && $product->exists() && $values['quantity'] > 0 && $product->is_purchasable() ) {
					$values = (array) maybe_unserialize( $values );
					# Put session data into array. Run through filter so other plugins can load their own session data.
					$session_data          = array_merge( $values, array( 'data' => $product ) );
					$cart_contents[ $key ] = apply_filters( 'woocommerce_get_cart_item_from_session', $session_data, $values, $key );

					# Add to cart right away so the product is visible in woocommerce_get_cart_item_from_session hook.
					WC()->cart->set_cart_contents( $cart_contents );
				} else {
					throw new \Exception( 'The product with id ' . $the_product_id . ' in the cart session of bolt reference ' . $reference . ' is not purchasable' );
				}
			}
		}

		// Do a stock check at this point
		$check_cart_item_result = WC()->cart->check_cart_item_validity();
		if ( is_wp_error( $check_cart_item_result ) ) {
			throw new \Exception( $check_cart_item_result->get_error_message() );
		}
		$check_cart_item_result = WC()->cart->check_cart_item_stock();
		if ( is_wp_error( $check_cart_item_result ) ) {
			throw new CartErrorException( $check_cart_item_result->get_error_message() );
		}

		// extensions can do customization per merchant in this action hook
		do_action( 'wc_bolt_after_set_cart_by_bolt_reference', $reference, $original_session_data );

		return true;
	} catch ( \Exception $e ) {
		throw $e;
	}
}

/**
 * Set the cart content by Bolt Reference and collect all errors
 *
 * @param string $reference The Bolt Order Reference. It shouldn't be reference to already created order
 * @param Bolt_Error_Handler $error_handler Error storage
 *
 * @return boolean true - if cart is restored successfully, false - otherwise
 * @since  2.0.0
 *
 */
function set_cart_by_bolt_reference_return_all_error( $reference, $error_handler ) {

	try {
		WC()->cart->empty_cart( false );
		if ( Bolt_Feature_Switch::instance()->is_native_cart_session_enabled() ) {
			$customer_id = wc_bolt_data()->get_customer_id( $reference );
			if ( $customer_id ) {
				load_cart_from_native_wc_session( $customer_id, $reference );

				return true;
			}
		}

		//restore session data
		$original_session_data = wc_bolt_data()->get_session( BOLT_PREFIX_SESSION_DATA . $reference );

		if ( ! $original_session_data ) {
			$error_handler->handle_error( E_BOLT_CART_HAS_EXPIRED, (object) array(
				BOLT_ERR_REASON           => 'cart does not exist with reference',
				BOLT_CART_ORDER_REFERENCE => $reference
			) );

			return false;
		}

		// We shouldn't be here if it's the order pay process
		// In this case we had to return "order is already created answer"
		if ( ! is_array( $original_session_data ) && (string) $original_session_data == BOLT_CART_ORDER_TYPE_ORDER_INVOICE ) {
			$error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array(
				BOLT_ERR_REASON           => 'try to restore cart in pay for order process',
				BOLT_CART_ORDER_REFERENCE => $reference
			) );

			return false;
		}

		do_action( 'wc_bolt_presetup_set_cart_by_bolt_reference', $reference, $original_session_data );

		foreach ( $original_session_data as $key => $value ) {
			if ( $key == BOLT_FIELD_NAME_ADDITIONAL ) {
				continue;
			}
			WC()->session->set( $key, maybe_unserialize( $value ) );
		}
		////////////////////////////////////////////////////////////////////////////
		$totals   = (array) maybe_unserialize( $original_session_data['cart_totals'] );
		$cart     = (array) maybe_unserialize( $original_session_data['cart'] );
		$customer = (array) maybe_unserialize( @$original_session_data['customer'] );
		$fees     = isset( $original_session_data['bolt_wc_fees'] ) ? (array) maybe_unserialize( $original_session_data['bolt_wc_fees'] ) : array();
		if ( empty( $cart ) ) {
			$error_handler->handle_error( E_BOLT_CART_HAS_EXPIRED, (object) array(
				BOLT_ERR_REASON => 'cart is empty'
			) );

			return false;
		}
		if ( $customer ) {
			$user = get_user_by( 'ID', $customer['id'] );

			if ( ! is_user_logged_in() && $user ) {
				wp_set_current_user( $user->ID );
				WC()->customer = new \WC_Customer( $user->ID, true );
				// Customer should be saved during shutdown.
				add_action( 'shutdown', array( WC()->customer, 'save' ), 10 );
			}
		}

		do_action( 'wc_bolt_before_set_cart_by_bolt_reference', $reference, $original_session_data );

		WC()->cart->set_totals( $totals );
		WC()->cart->set_applied_coupons( (array) maybe_unserialize( $original_session_data['applied_coupons'] ) );
		WC()->cart->set_coupon_discount_totals( (array) maybe_unserialize( $original_session_data['coupon_discount_totals'] ) );
		WC()->cart->set_coupon_discount_tax_totals( (array) maybe_unserialize( $original_session_data['coupon_discount_tax_totals'] ) );
		WC()->cart->set_removed_cart_contents( (array) maybe_unserialize( $original_session_data['removed_cart_contents'] ) );
		if ( $fees ) {
			WC()->cart->fees_api()->set_fees( $fees );
		}
		if ( is_array( $cart ) ) {
			$cart_contents = array();
			foreach ( $cart as $key => $values ) {
				$product_id = $values['variation_id'] ? $values['variation_id'] : $values['product_id'];
				$product    = wc_get_product( $product_id );
				if ( empty( $product ) || ! $product->exists() ) {
					$error_handler->handle_error( E_BOLT_CART_HAS_EXPIRED, (object) array(
						BOLT_ERR_REASON     => 'The product doesnot exist',
						BOLT_ERR_PRODUCT_ID => $product_id,

					) );

					return false;
				}
				if ( $product->is_purchasable() && $values['quantity'] > 0 ) {
					$values = (array) maybe_unserialize( $values );
					# Put session data into array. Run through filter so other plugins can load their own session data.
					$session_data          = array_merge( $values, array( 'data' => $product ) );
					$cart_contents[ $key ] = apply_filters( 'woocommerce_get_cart_item_from_session', $session_data, $values, $key );

					# Add to cart right away so the product is visible in woocommerce_get_cart_item_from_session hook.
					WC()->cart->set_cart_contents( $cart_contents );
				} else {
					$error_handler->handle_error( E_BOLT_CART_HAS_EXPIRED, (object) array(
						BOLT_ERR_REASON       => 'The product is not purchasable',
						BOLT_ERR_PRODUCT_ID   => $product_id,
						BOLT_ERR_PRODUCT_NAME => $product->get_name(),
					) );

					return false;
				}
			}
		}
		do_action( 'woocommerce_cart_loaded_from_session', WC()->cart );

		// extensions can do customization per merchant in this action hook
		do_action( 'wc_bolt_after_set_cart_by_bolt_reference', $reference, $original_session_data );

		return true;
	} catch ( \Exception $e ) {
		$error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array( BOLT_ERR_REASON => $e->getMessage() ) );

		return false;
	}
}


/**
 * Code below is a workaround for known woocommerce issue:
 * When error message is added for the first time, in some php environment
 * it would result in the error 'cannot use string offset as an array'.
 * To avoid this, we create an empty array here and update wc_set_notices
 *
 * @param array $request_data A transaction object retrieved from the Bolt API endpoint.
 *
 * @return WP_REST_Response  Well-formed response sent to the Bolt Server
 * @since  1.2.7
 * @access public
 *
 */
function reset_wc_notices() {
	$notices = wc_get_notices();
	$notices = maybe_unserialize( $notices );
	if ( ! is_array( $notices ) ) {
		$notices = array();
	}
	if ( ! isset( $notices[ WC_NOTICE_TYPE_ERROR ] ) ) {
		$notices[ WC_NOTICE_TYPE_ERROR ] = array();
	}
	if ( ! isset( $notices[ WC_NOTICE_TYPE_SUCCESS ] ) ) {
		$notices[ WC_NOTICE_TYPE_SUCCESS ] = array();
	}
	wc_set_notices( $notices );
}

/**
 * Since WC 3.9.0 the notice structure changes, a notice has a text to display and an optional notice data.
 * This function is for backward compatibility with old wc version.
 *
 * @since 2.2.0
 *
 */
function get_wc_notice_message( $notice ) {
	return isset( $notice['notice'] ) ? $notice['notice'] : $notice;
}

/**
 * Function to handle bolt webhook.
 *
 * @return WP_REST_Response array   Well-formed response sent to the Bolt Server
 * @since 2.4.0
 */
function handle_featureswitches_changed() {
	wc_bolt()->get_metrics_client()->save_start_time();

	BugsnagHelper::initBugsnag();

	// TODO: verify signature after adding support on bolt side
	try {
		Bolt_Feature_Switch::instance()->update_switches_from_bolt();

		return Bolt_HTTP_Handler::prepare_http_response(
			array(
				BOLT_FIELD_NAME_STATUS => BOLT_STATUS_SUCCESS,
			)
		);
	} catch ( \Exception $e ) {
		BugsnagHelper::notifyException( $e );

		return Bolt_HTTP_Handler::prepare_http_response(
			array(
				BOLT_FIELD_NAME_STATUS => BOLT_STATUS_FAILURE,
				BOLT_FIELD_NAME_ERROR  => array( BOLT_FIELD_NAME_ERROR_MESSAGE => $e->getMessage() )
			),
			HTTP_STATUS_UNPROCESSABLE
		);
	} finally {
		wc_bolt()->get_metrics_client()->process_metric( BOLT_METRIC_NAME_FEATURE_SWITCH_WEB_HOOK, ( Bolt_HTTP_Handler::$status == HTTP_STATUS_OK ) ? BOLT_STATUS_SUCCESS : BOLT_STATUS_FAILURE );
	}
}