<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class to handle with errors
 *
 * @class   Bolt_Page_Checkout
 * @author  Bolt
 */
class Bolt_Error_Handler {

	/**
	 * Id of endpoint.
	 *
	 * @since 2.2.0
	 * @var integer
	 */
	private $endpoint_id;

	/**
	 * Error information.
	 *
	 * @since 2.2.0
	 * @var object WP_ERROR
	 */
	private $errors;

	/**
	 * Flag to set cart data to bugsnag only once
	 *
	 * @since 2.2.0
	 * @var integer
	 */
	private $cart_data_is_sent_to_bugsnag;

	public function __construct( $endpoint_id = BOLT_ENDPOINT_ID_DEFAULT ) {
		$this->endpoint_id                  = $endpoint_id;
		$this->errors                       = new \WP_ERROR;
		$this->cart_data_is_sent_to_bugsnag = false;
	}

	/**
	 * Generate error code by endpoint_id and error_id
	 *
	 * @param int $endpoint_id for example BOLT_ENDPOINT_ID_CREATE_ORDER
	 * @param int $error_id for example E_BOLT_GENERAL_ERROR
	 *
	 * @return int error code
	 * @since 2.2.0
	 *
	 */
	private function get_bolt_error_code( $error_id ) {
		if ( $this->endpoint_id == BOLT_ENDPOINT_ID_DEFAULT ) {
			// update_order
			$error_code = 6600 + $error_id;
		} elseif ( $this->endpoint_id > BOLT_ENDPOINT_ID_IN_OLD_FORMAT ) {
			// old format: shipping&tax, discounts, etc.
			$error_code = $error_id;
		} else {
			// create_order
			$error_code = 2000000 + 1000 * $this->endpoint_id + $error_id;
		}

		return (int) $error_code;
	}

	/**
	 * Check if class already has any error
	 * @return bool
	 */
	public function has_error() {
		return $this->errors->get_error_code() !== '';
	}

	/**
	 * Add error to error object
	 *
	 * @param string $error_code error code
	 * @param string or array $error_data error details
	 *
	 * @since  2.2.0
	 * @access protected
	 *
	 */
	public function handle_error( $error_code, $error_data ) {
		if ( ! is_array( $error_data ) ) {
			$error_data = array( 0 => $error_data );
		}

		// Add error into $this->error WP_Error object
		if ( isset( $this->errors->error_data[ $error_code ] ) ) {
			$error_data = array_merge( $this->errors->error_data[ $error_code ], $error_data );
			$this->errors->add_data( $error_data, $error_code );
		} else {
			$this->errors->add( $error_code, '', $error_data );
		}

		// Generate bugsnag
		$bugsnag_data = array(
			BOLT_FIELD_NAME_ERROR => array(
				BOLT_FIELD_NAME_ERROR_CODE => $error_code,
				BOLT_FIELD_NAME_ERROR_DATA => $error_data
			)
		);
		// include cart data only to first Bugsnag message
		if ( ! $this->cart_data_is_sent_to_bugsnag ) {
			$cart = print_r( WC()->cart, true );
			if ( strlen( $cart ) > BugsnagHelper::MAX_ELEMENT_SIZE ) {
				$cart = substr( $cart, 0, BugsnagHelper::MAX_ELEMENT_SIZE );
			}
			$bugsnag_data[ BOLT_CART ]          = $cart;
			$this->cart_data_is_sent_to_bugsnag = true;
		}

		if ( isset( $error_data[0]->reason ) ) {
			$error_header = $error_data[0]->reason;
		} else {
			$error_header = "Exception from endpoint_id {$this->endpoint_id}";
		}

		BugsnagHelper::notifyException(
			new \Exception( $error_header ),
			$bugsnag_data
		);
	}

	/**
	 * Create WP_REST_Response with error data
	 *
	 * @return WP_REST_Response
	 */
	public function build_error() {
		$buffer = Bolt_HTTP_Handler::clean_buffers();
		if ( ! empty( $buffer ) ) {
			$this->handle_error( E_BOLT_GENERAL_ERROR, (object) array(
				BOLT_ERR_REASON => 'third party plugin error',
				BOLT_ERR_TEXT   => $buffer,
			) );
		}

		if ( $this->endpoint_id > BOLT_ENDPOINT_ID_IN_OLD_FORMAT ) {
			// Old format - return only the first error
			$code = $this->errors->get_error_code();

			return Bolt_HTTP_Handler::prepare_http_response(
				array(
					BOLT_FIELD_NAME_STATUS => BOLT_STATUS_FAILURE,
					BOLT_FIELD_NAME_ERROR  => array(
						BOLT_FIELD_NAME_ERROR_CODE    => $this->get_bolt_error_code( $code ),
						BOLT_FIELD_NAME_ERROR_MESSAGE => $this->errors->get_error_data( $code )[0]->reason
					)
				),
				HTTP_STATUS_UNPROCESSABLE
			);
		} else if ( $this->endpoint_id == BOLT_ENDPOINT_ID_CREATE_ORDER ) {
			// Also legacy format for create_order: if we have two errors with the same error_code
			// combine them into one error. In this case $error->data is array.
			$error_array = array();
			foreach ( $this->errors->get_error_codes() as $code ) {
				$error_array[] = (object) array(
					BOLT_FIELD_NAME_ERROR_CODE => $this->get_bolt_error_code( $code ),
					BOLT_FIELD_NAME_ERROR_DATA => $this->errors->get_error_data( $code )
				);
			}

			return Bolt_HTTP_Handler::prepare_http_response(
				array(
					BOLT_FIELD_NAME_STATUS => BOLT_STATUS_FAILURE,
					BOLT_FIELD_NAME_ERROR  => $error_array
				),
				HTTP_STATUS_UNPROCESSABLE
			);
		} else {
			$error_array = array();
			foreach ( $this->errors->get_error_codes() as $code ) {
				$bolt_error_code  = $this->get_bolt_error_code( $code );
				$error_data_array = $this->errors->get_error_data( $code );
				foreach ( $error_data_array as $error_data_object ) {
					$error_array[] = (object) array(
						BOLT_FIELD_NAME_ERROR_CODE => $bolt_error_code,
						BOLT_FIELD_NAME_ERROR_DATA => $error_data_object
					);
				}
			}

			return Bolt_HTTP_Handler::prepare_http_response(
				array(
					BOLT_FIELD_NAME_STATUS => BOLT_STATUS_FAILURE,
					BOLT_FIELD_NAME_ERRORS => $error_array
				),
				HTTP_STATUS_UNPROCESSABLE
			);
		}
	}
}