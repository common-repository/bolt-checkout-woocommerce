<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class to implement OAuthRedirect on log in page
 *
 * @class   Bolt_Page_Checkout
 * @version 1.3.5
 * @author  Bolt
 */
class Bolt_Auth_Redirect {

	/**
	 * Bolt request for OAuthRedirect
	 *
	 * @since 1.3.5
	 * @var object
	 */
	private $request;

	/**
	 * Error information.
	 *
	 * @since 1.3.5
	 * @var Bolt_Error_Handler
	 */
	private $error_handler;

	/**
	 * Constructor Function.
	 *
	 * @since  1.3.5
	 * @access public
	 *
	 */
	public function __construct() {
		if ( WC_BOLT_WP_REST_API_ADDON ) {
			add_filter( 'json_endpoints', array( $this, 'register_wp_rest_api_route' ) );
		} else {
			add_action( 'rest_api_init', array( $this, 'register_auth_redirect_endpoint' ) );
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

		$routes['/bolt/redirect'] = array(
			array( array( $this, 'auth_redirect' ), \WP_JSON_Server::READABLE ),
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
	public function register_auth_redirect_endpoint() {
		register_rest_route( 'bolt', '/redirect', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( $this, 'auth_redirect' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Implement OAuthRedirect endpoint
	 *
	 * @return WP_REST_Response
	 * @since  1.3.5
	 * @access public
	 *
	 */
	public function auth_redirect() {
		BugsnagHelper::initBugsnag();

		try {
			$this->error_handler = new Bolt_Error_Handler();

			//to prevent third party plugin write their errors to output
			ob_start();

			$bolt_settings = wc_bolt()->get_settings();

			if ( 'yes' !== $bolt_settings[ Bolt_Settings::SETTING_NAME_ENABLE_SSO ] ) {
				$this->error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array( 'reason' => 'SSO not enabled' ) );

				return $this->error_handler->build_error();
			}

			if ( ! isset( $_GET['code'] ) || ! isset( $_GET['scope'] ) || ! isset( $_GET['state'] ) ) {
				$this->error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array( 'reason' => 'Bad Request' ) );

				return $this->error_handler->build_error();
			}

			$order_id = isset( $_GET['order_id'] ) ? $_GET['order_id'] : '';

			$key = $bolt_settings[ Bolt_Settings::SETTING_NAME_MERCHANT_KEY ];

			if ( empty( $key ) ) {
				$this->error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array( 'reason' => 'Empty merchant key' ) );

				return $this->error_handler->build_error();
			}

			$publishable_key = $bolt_settings[ Bolt_Settings::SETTING_NAME_PUBLISHABLE_KEY_MULTISTEP ];

			$publishable_key_split = explode( '.', $publishable_key );
			$client_id             = end( $publishable_key_split );


			$token = $this->exchange_token( $_GET['code'], $_GET['scope'], $key, $client_id );

			if ( $token->error ) {
				return Bolt_HTTP_Handler::prepare_http_response( array(
					BOLT_FIELD_NAME_STATUS => BOLT_STATUS_FAILURE,
					BOLT_FIELD_NAME_ERROR  => array( BOLT_FIELD_NAME_ERROR_MESSAGE => $token->error )
				), HTTP_STATUS_INTERNAL_ERROR );
			}

			$bolt_public_key = $this->get_public_key();

			$payload = $this->parse_and_validate_JWT( $token->{'id_token'}, $client_id, $bolt_public_key );
			if ( is_string( $payload ) ) {
				return Bolt_HTTP_Handler::prepare_http_response( array(
					BOLT_FIELD_NAME_STATUS => BOLT_STATUS_FAILURE,
					BOLT_FIELD_NAME_ERROR  => array( BOLT_FIELD_NAME_ERROR_MESSAGE => $payload )
				), HTTP_STATUS_INTERNAL_ERROR );
			}

			$external_id = $payload['sub'];

			$external_customer = null;
			$customer          = null;

			$external_customer = wc_bolt_data()->get_by_external_id( $external_id );

			if ( $external_customer !== null ) {
				$customer_return = get_user_by( 'ID', $external_customer );
				if ( $customer_return !== false ) {
					$customer = $customer_return;
				}


			} else {
				$customer_return = get_user_by( 'email', $payload['email'] );
				if ( $customer_return !== false ) {
					$customer = $customer_return;
				}

			}

			if ( $external_customer !== null && $customer === null ) {
				$this->error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array(
					'reason' => 'external customer entity ' . $external_id . ' linked to nonexistent customer ' . $external_customer
				) );

				return $this->error_handler->build_error();
			}

			// If
			// - external customer entity isn't linked
			// - customer exists in WC
			// - email is not verified
			// throw an exception
			if ( $external_customer === null && $customer !== null && ! $payload['email_verified'] ) {
				$this->error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array( 'reason' => 'customer with email ' . $payload['email'] . ' found but email is not verified' ) );

				return $this->error_handler->build_error();
			}

			if ( ! $customer ) {
				$new_user_email    = $payload['email'];
				$new_user_name     = '';
				$new_user_password = '';
				if ( 'yes' !== get_option( 'woocommerce_registration_generate_password' ) ) {
					$new_user_name     = wc_create_new_customer_username( $new_user_email );
					$new_user_password = wp_generate_password();
				}
				$user_id  = wc_create_new_customer( $new_user_email, $new_user_name, $new_user_password );
				$customer = get_user_by( 'ID', $user_id );
			}

			if ( $order_id !== '' ) {
				$order = wc_get_order( $order_id );
				if ( $order !== false ) {
					wc_bolt_update_order_meta( $order, '_customer_user', $customer->ID );
				}

				$customer_id = wc_bolt_data()->get_customer_id( $order_id );
				if ( $customer_id !== false ) {
					wc_bolt_data()->update_customer_id( $order_id, $customer->ID );
				}

			}

			return $this->link_login_and_redirect( $external_id, $customer->ID );


		} catch ( \Exception $e ) {
			$this->error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array( BOLT_ERR_REASON => $e->getMessage() ) );

			return $this->error_handler->build_error();
		}
	}

	private function exchange_token( $code, $scope, $key, $client_id ) {

		if ( empty( $key ) ) {
			$this->error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array( 'reason' => 'Empty merchant key' ) );

			return $this->error_handler->build_error();
		}

		$api_url = wc_bolt()->get_bolt_settings()->get_bolt_api_host() . '/v1/' . 'oauth/' . 'token';

		$data = 'grant_type=authorization_code&' . 'code=' . $code . '&scope=' . $scope . '&client_id=' . $client_id . '&client_secret=' . $key;

		$response = wc_bolt()->get_api_request()->send_curl_request( $api_url, array(
			'Content-Type: application/x-www-form-urlencoded',
			'X-Api-Key: ' . $key,
			'Content-Length: ' . strlen( $data ),
			'X-Nonce: ' . rand( 100000000, 999999999 ),
			'User-Agent: BoltPay/WooCommerce-' . WC()->version . '/' . WC_BOLT_CHECKOUT_VERSION,
			'X-Bolt-Plugin-Version: ' . WC_BOLT_CHECKOUT_VERSION
		), HTTP_METHOD_POST, $data );

		BugsnagHelper::addBreadCrumbs( array( 'BOLT API RESPONSE' => array( 'BOLT-RESPONSE' => $response ) ) );
		if ( ! @$response[ BOLT_FIELD_NAME_ERROR ] ) {
			$response_body = json_decode( $response[ BOLT_FIELD_NAME_BODY ] );
			if ( property_exists( $response_body, 'errors' ) || property_exists( $response_body, 'error_code' ) ) {
				if ( $response_body->errors ) {
					$primary_error = $response_body->errors[0];
					if ( ! isset( $primary_error->field ) ) {
						$primary_error->field = '';
					}
					$err_msg = "-- Failed Bolt API Request --\n[reason] " . $primary_error->message . "\n[field] {$primary_error->field}\n\nRequest: $data\n\nResponse Headers: {$response[BOLT_FIELD_NAME_HEADERS]}";
					throw new BOLT_REST_Exception( $primary_error->code, $err_msg, HTTP_STATUS_UNPROCESSABLE, (array) $primary_error );
				} else {
					throw new \Exception( "Failed Bolt API Request: " . $data . "\n\nResponse Headers: {$response[BOLT_FIELD_NAME_HEADERS]}\n\nResponse Body:\n\n" . $response['body'] );
				}
			}
		} else {
			throw new \Exception( "Failed Bolt API request: " . $data . "\n\nCurl info: " . json_encode( $response[ BOLT_FIELD_NAME_ERROR ], JSON_PRETTY_PRINT ) );
		}

		return $response_body;
	}

	private function get_public_key() {

		$api_url = wc_bolt()->get_bolt_settings()->get_bolt_api_host() . '/v1/' . 'oauth/jwks.json';

		$response = wc_bolt()->get_api_request()->send_curl_request( $api_url, array(
			'User-Agent: BoltPay/WooCommerce-' . WC()->version . '/' . WC_BOLT_CHECKOUT_VERSION,
			'X-Bolt-Plugin-Version: ' . WC_BOLT_CHECKOUT_VERSION
		), HTTP_METHOD_GET );

		BugsnagHelper::addBreadCrumbs( array( 'BOLT API RESPONSE' => array( 'BOLT-RESPONSE' => $response ) ) );
		if ( ! @$response[ BOLT_FIELD_NAME_ERROR ] ) {
			$response_body = json_decode( $response[ BOLT_FIELD_NAME_BODY ] );
			if ( property_exists( $response_body, 'errors' ) || property_exists( $response_body, 'error_code' ) ) {
				if ( $response_body->errors ) {
					$primary_error = $response_body->errors[0];
					if ( ! isset( $primary_error->field ) ) {
						$primary_error->field = '';
					}
					$err_msg = "-- Failed Bolt API Request --\n[reason] " . $primary_error->message . "\n[field] {$primary_error->field}\n\nRequest: \n\nResponse Headers: {$response[BOLT_FIELD_NAME_HEADERS]}";
					throw new BOLT_REST_Exception( $primary_error->code, $err_msg, HTTP_STATUS_UNPROCESSABLE, (array) $primary_error );
				} else {
					throw new \Exception( "Failed Bolt API Request " . "\n\nResponse Headers: {$response[BOLT_FIELD_NAME_HEADERS]}\n\nResponse Body:\n\n" . $response['body'] );
				}
			}
		} else {
			throw new \Exception( "Failed Bolt API request " . "\n\nCurl info: " . json_encode( $response[ BOLT_FIELD_NAME_ERROR ], JSON_PRETTY_PRINT ) );
		}

		$publicKey = bolt_jwt()->parse_key( $response_body->keys[0] );

		return $publicKey;
	}


	private function get_payload( $token, $pubkey ) {
		$multi_line_key = chunk_split( $pubkey, 64, "\n" );
		$formatted_key  = "-----BEGIN PUBLIC KEY-----\n$multi_line_key-----END PUBLIC KEY-----";

		return (array) bolt_jwt()->decode( $token, $formatted_key, [ 'RS256' ] );
	}

	private function parse_and_validate_JWT( $token, $audience, $pubkey ) {
		try {
			$payload = $this->get_payload( $token, $pubkey );
		} catch ( Exception $e ) {
			return $e->getMessage();
		}

		$iss = wc_bolt()->get_bolt_settings()->get_bolt_api_host();

		// Issuing authority should be https://bolt.com
		if ( ! isset( $payload['iss'] ) ) {
			return 'iss must be set';
		}
		if ( $payload['iss'] !== 'https://bolt.com' && $payload['iss'] !== $iss ) {
			return 'incorrect iss ' . $payload['iss'];
		}

		// The aud field should contain $audience
		if ( ! isset( $payload['aud'] ) ) {
			return 'aud must be set';
		}
		if ( ! in_array( $audience, $payload['aud'] ) ) {
			return 'aud ' . implode( ',', $payload['aud'] ) . ' does not contain audience ' . $audience;
		}

		// Validate other expected Bolt fields
		if ( ! isset( $payload['sub'] ) ) {
			return 'sub must be set';
		}
		if ( ! isset( $payload['first_name'] ) ) {
			return 'first_name must be set';
		}
		if ( ! isset( $payload['last_name'] ) ) {
			return 'last_name must be set';
		}
		if ( ! isset( $payload['email'] ) ) {
			return 'email must be set';
		}
		if ( ! isset( $payload['email_verified'] ) ) {
			return 'email_verified must be set';
		}

		return $payload;
	}

	/**
	 * Link the external ID to customer if needed, log in, and redirect
	 *
	 * @param string $external_id
	 * @param int $customer_id
	 */
	private function link_login_and_redirect( $external_id, $customer_id ) {
		wc_bolt_data()->insert_external_customer_id( $external_id, $customer_id );
		if ( Bolt_Feature_Switch::instance()->is_native_cart_session_enabled() ) {
			// Setup cookie for customer, and the cart can be loaded properly then
			$session_expiring        = time() + intval( apply_filters( 'wc_session_expiring', 60 * 60 * 47 ) ); // 47 Hours.
			$session_expiration      = time() + intval( apply_filters( 'wc_session_expiration', 60 * 60 * 48 ) ); // 48 Hours.
			$to_hash                 = $customer_id . '|' . $session_expiration;
			$cookie_hash             = hash_hmac( 'md5', $to_hash, wp_hash( $to_hash ) );
			$cookie_value            = $customer_id . '||' . $session_expiration . '||' . $session_expiring . '||' . $cookie_hash;
			$cookie_name             = apply_filters( 'woocommerce_cookie', 'wp_woocommerce_session_' . COOKIEHASH );
			$_COOKIE[ $cookie_name ] = $cookie_value;
			WC()->session->init_session_cookie();
			unset( $wp_actions['woocommerce_load_cart_from_session'] );
			WC()->cart->get_cart();
		}
		wc_set_customer_auth_cookie( $customer_id );

		wp_redirect( wc_get_page_permalink( 'myaccount' ) );
		exit;
	}
}

new Bolt_Auth_Redirect();
