<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class to implement API for Checkout on Product page.
 *
 * @class   Bolt_Page_Checkout
 * @version 1.3.2
 * @author  Bolt
 */
class Bolt_Page_Checkout {
	const ENCRYPTED_USER_ID_LIFETIME_SEC = 3600;

	public $error_code;
	public $error_message;

	/**
	 * Create WooCommerce cart based on data provided by Bolt and send information about cart to Bolt
	 *
	 * @param array $cart_data cart data sent from Bolt
	 *
	 * @return WP_REST_Response      Well-formed response sent to the Bolt Server
	 * @since  1.3.2
	 * @access public
	 *
	 */
	public function implement_bolt_order_create_api( $cart_data ) {

		BugsnagHelper::initBugsnag();

		if ( isset( $cart_data->metadata->encrypted_user_id ) ) {
			$result = $this->set_user_id( $cart_data->metadata->encrypted_user_id );

			if ( ! $result ) {
				return $this->create_error_response();
			}
		}

		if ( ! ( $cart_data->items[0] ) ) {
			$this->error_code    = E_BOLT_INVALID_REFERENCE;
			$this->error_message = "Not find product data";

			return $this->create_error_response();
		}
		if ( isset( $cart_data->items[0]->properties ) && ! empty( $cart_data->items[0]->properties ) ) {
			foreach ( $cart_data->items[0]->properties as $pk => $single_property ) {
				if ( $single_property->name == 'form_post' ) {
					$form_data       = $single_property->value;
					$form_data_array = explode( "&", $form_data );
					foreach ( $form_data_array as $post_item ) {
						$post_item_pair                           = explode( "=", $post_item );
						$_POST[ urldecode( $post_item_pair[0] ) ] = urldecode( $post_item_pair[1] );
					}
				}
			}
			// Some third-party addons may use $_REQUEST in the process of adding items to cart
			$_REQUEST = array_merge( $_REQUEST, $_POST );
		}

		$bolt_cart_data = $this->create_bolt_cart_by_items( $cart_data->items );

		if ( ! $bolt_cart_data ) {
			return $this->create_error_response();
		}

		$response                           = $bolt_cart_data;
		$response[ BOLT_FIELD_NAME_STATUS ] = BOLT_STATUS_SUCCESS;

		return Bolt_HTTP_Handler::prepare_http_response(
			$response,
			HTTP_STATUS_OK,
			array( BOLT_HEADER_CACHED_VALUE => false )
		);
	}

	/**
	 * Create Bolt order by item(s) from Product page checkout
	 * If we on grouped product page in array items maybe few different products
	 *
	 * @param $items
	 *
	 * @return array|false return bolt_cart or false if error
	 * @since  1.3.2
	 * @access public
	 *
	 */
	public function create_bolt_cart_by_items( $items ) {
		try {
			WC()->cart->empty_cart();
			foreach ( $items as $item ) {
				$result = add_to_cart( (int) $item->reference, $item->quantity );
				if ( is_wp_error( $result ) ) {
					$this->error_code    = $result->get_error_code();
					$this->error_message = $result->get_error_message();

					return false;
				}
			}
			WC()->cart->calculate_totals();
		} catch ( \Exception $e ) {
			$this->error_code    = $e->getCode();
			$this->error_message = $e->getMessage();

			return false;
		}

		$order_details = wc_bolt()->get_bolt_data_collector()->build_order( BOLT_CART_ORDER_TYPE_CART );

		return $order_details;
	}

	/**
	 * Set user_id by encrypted data received from server
	 *
	 * @param string $encrypted_user_id data created by function wc_bolt_encode_user_id()
	 *
	 * @return bool true if we set user_id, false if there is any error.
	 */
	private function set_user_id( $encrypted_user_id ) {
		$metadata = json_decode( $encrypted_user_id );
		if ( ! $metadata || ! isset( $metadata->user_id ) || ! isset( $metadata->timestamp ) || ! isset( $metadata->signature ) ) {
			$this->error_code    = E_BOLT_ENCRYPTED_USER_ID_ERROR;
			$this->error_message = 'Incorrect encrypted_user_id';

			return false;
		}

		$payload = array(
			'user_id'   => $metadata->user_id,
			'timestamp' => $metadata->timestamp
		);

		if ( ! verify_signature( json_encode( $payload ), $metadata->signature ) ) {
			$this->error_code    = E_BOLT_ENCRYPTED_USER_ID_ERROR;
			$this->error_message = 'Incorrect signature';

			return false;
		}

		if ( time() - $metadata->timestamp > SELF::ENCRYPTED_USER_ID_LIFETIME_SEC ) {
			$this->error_code    = E_BOLT_ENCRYPTED_USER_ID_ERROR;
			$this->error_message = 'Outdated encrypted_user_id';

			return false;
		}

		if ( ! get_user_by( 'id', $metadata->user_id ) ) {
			$this->error_code    = E_BOLT_ENCRYPTED_USER_ID_ERROR;
			$this->error_message = 'Incorrect user_id';;

			return false;
		}

		wp_set_current_user( $metadata->user_id );
		// WooCommerce doesn't expect that user can be change so late.
		// If we don't update WC()->customer than user_id will be cleared when we call WC()->customer->save()
		WC()->customer = new \WC_Customer( get_current_user_id(), true );

		return true;
	}


	/**
	 * Create http response with error code and error message
	 *
	 * @since  1.3.2
	 * @access private
	 */
	private function create_error_response() {
		return Bolt_HTTP_Handler::prepare_http_response(
			array(
				BOLT_FIELD_NAME_STATUS => BOLT_STATUS_FAILURE,
				BOLT_FIELD_NAME_ERROR  => array(
					BOLT_FIELD_NAME_ERROR_CODE    => $this->error_code,
					BOLT_FIELD_NAME_ERROR_MESSAGE => $this->error_message
				)
			),
			HTTP_STATUS_UNPROCESSABLE
		);
	}

}

