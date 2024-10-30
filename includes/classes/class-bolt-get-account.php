<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class to implement get account on log in page
 *
 * @class   Bolt_Get_Account
 * @version 2.15.0
 * @author  Bolt
 */
class Bolt_Get_Account {

	/**
	 * Bolt request for get account.
	 *
	 * @since 2.15.0
	 * @var object
	 */
	private $request;

	/**
	 * Error information.
	 *
	 * @since 2.15.0
	 * @var Bolt_Error_Handler
	 */
	private $error_handler;

	/**
	 * Constructor Function.
	 *
	 * @since  2.15.0
	 * @access public
	 *
	 */
	public function __construct() {
		if ( WC_BOLT_WP_REST_API_ADDON ) {
			add_filter( 'json_endpoints', array( $this, 'register_wp_rest_api_route' ) );
		} else {
			add_action( 'rest_api_init', array( $this, 'register_get_account_endpoint' ) );
		}
	}


	/**
	 * Register WP REST API route
	 *
	 * @param array $routes
	 *
	 * @return array
	 * @since  1.3.5
	 * @access public
	 *
	 */
	public function register_wp_rest_api_route( $routes ) {

		$routes['/bolt/login'] = array(
			array( array( $this, 'get_account' ), \WP_JSON_Server::CREATABLE | \WP_JSON_Server::ACCEPT_JSON ),
		);

		return $routes;
	}

	/**
	 * Register wordpress endpoints
	 *
	 * @since  1.3.5
	 * @access public
	 *
	 */
	public function register_get_account_endpoint() {
		register_rest_route( 'bolt', '/login', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'get_account' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Implement get account endpoint
	 *
	 * @return WP_REST_Response
	 * @since  1.3.5
	 * @access public
	 *
	 */
	public function get_account() {
		BugsnagHelper::initBugsnag();

		try {
			$this->error_handler = new Bolt_Error_Handler();
			$hmac_header         = @$_SERVER['HTTP_X_BOLT_HMAC_SHA256'];

			$get_data = file_get_contents( 'php://input' );

			//to prevent third party plugin write their errors to output
			ob_start();

			$this->request = json_decode( $get_data );

			if ( ! verify_signature( $get_data, $hmac_header ) ) {
				// Request can not be verified as originating from Bolt
				$this->error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array( 'reason' => 'Invalid HMAC header' ) );

				return $this->error_handler->build_error();
			}

			if ( ! isset( $this->request->email ) ) {
				$this->error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array( 'reason' => 'Missing email in the request body' ) );

				return $this->error_handler->build_error();
			}


			$customer = get_user_by( 'email', $this->request->email );

			if ( ! $customer ) {
				$this->error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array( 'reason' => 'Customer not found with given email.' ) );

				return Bolt_HTTP_Handler::prepare_http_response(
					array(
						BOLT_FIELD_NAME_STATUS => BOLT_STATUS_FAILURE,
						BOLT_FIELD_NAME_ERROR  => array( BOLT_FIELD_NAME_ERROR_MESSAGE => 'Customer not found with given email.' )
					),
					HTTP_STATUS_NOT_FOUND
				);
			}


			return Bolt_HTTP_Handler::prepare_http_response(
				json_encode( [ 'id' => $customer->ID ] ),
				HTTP_STATUS_OK,
				array( BOLT_HEADER_CACHED_VALUE => true )
			);

		} catch
		( \Exception $e ) {
			$this->error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array( BOLT_ERR_REASON => $e->getMessage() ) );

			return $this->error_handler->build_error();
		}
	}
}

new Bolt_Get_Account();