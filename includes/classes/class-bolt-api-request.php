<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Bolt API requests
 *
 * @class   Bolt_API_Request
 * @version 1.0
 * @since   2.0.6
 * @author  Bolt
 */
class Bolt_API_Request {

	/**
	 * MerchantAPI graphQL endpoint
	 */
	const MERCHANT_API_GQL_ENDPOINT = 'v2/merchant/api';

	/**
	 * The single instance of the class.
	 *
	 * @since 2.0.6
	 * @var Bolt_API_Request|null
	 */
	private static $instance = null;

	/**
	 * Get Bolt_API_Request Instance.
	 *
	 * @return Bolt_API_Request Instance
	 * @since 2.0.6
	 * @static
	 *
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Calls the Bolt API endpoints by path
	 *
	 * @param string $path Path to the endpoint excluding base URL and version and category (ex. 'orders' or 'transactions/credit')
	 * @param array|null $data The POST body of the request to be sent as JSON, null for GET
	 * @param string $category The category grouping of the request, default: 'merchant'
	 *
	 * @return array      The response received from Bolt
	 * @throws \Exception  Throws an exception on failed attempts to communicate with the Bolt API
	 * @since 2.0.6
	 *
	 */
	public function handle_api_request( $path, $data = null, $category = 'merchant' ) {

		$api_url = $this->get_api_url( $path, $category );

		return $this->handle_api_request_by_url( $api_url, $data );
	}

	/**
	 * Calls the Bolt API endpoints by url
	 *
	 * @param string $api_url Full url to the endpoint including base URL
	 * @param array|null $data The POST body of the request to be sent as JSON, null for GET
	 *
	 * @return array      The response received from Bolt
	 * @throws \Exception  Throws an exception on failed attempts to communicate with the Bolt API
	 * @since 2.4.0
	 */
	private function handle_api_request_by_url( $api_url, $data ) {
		$bolt_gateway_settings = wc_bolt()->get_settings();
		$key                   = $bolt_gateway_settings[ Bolt_Settings::SETTING_NAME_MERCHANT_KEY ];

		if ( empty( $key ) ) {
			$e = new \Exception( 'Empty merchant key' );
			BugsnagHelper::notifyException( $e, array( 'api_url' => $api_url, 'data' => $data ) );
			throw $e;
		}

		$params = ( ! is_null( $data ) )
			? wp_json_encode( $data, JSON_PRETTY_PRINT )
			: null;

		$response = $this->send_curl_request(
			$api_url,
			array(
				'Content-Type: application/json',
				'Content-Length: ' . ( ! empty( $params ) ? strlen( $params ) : 0 ),
				'X-Api-Key: ' . $key,
				'X-Nonce: ' . rand( 100000000, 999999999 ),
				'User-Agent: BoltPay/WooCommerce-' . WC()->version . '/' . WC_BOLT_CHECKOUT_VERSION,
				'X-Bolt-Plugin-Version: ' . WC_BOLT_CHECKOUT_VERSION,
				'X-BOLT-SOURCE-NAME: ' . BOLT_HEADER_SOURCE_NAME_VALUE,
				'X-BOLT-SOURCE-VERSION: ' . WC_BOLT_CHECKOUT_VERSION
			),
			$params ? HTTP_METHOD_POST : HTTP_METHOD_GET,
			$params
		);
		BugsnagHelper::addBreadCrumbs( array( 'BOLT API RESPONSE' => array( 'BOLT-RESPONSE' => $response ) ) );
		if ( $response && ( ! array_key_exists( BOLT_FIELD_NAME_ERROR, $response ) || empty( $response[ BOLT_FIELD_NAME_ERROR ] ) )
		     && ( array_key_exists( BOLT_FIELD_NAME_BODY, $response ) && ! empty( $response[ BOLT_FIELD_NAME_BODY ] ) ) ) {
			$response_body = json_decode( $response[ BOLT_FIELD_NAME_BODY ] );
			if ( empty( $response_body ) ) {
				throw new \Exception( "Failed Bolt API Request: " . $params . "\n\nResponse Headers: {$response[BOLT_FIELD_NAME_HEADERS]}\n\nResponse Body:\n\n" . $response['body'] );
			} elseif ( property_exists( $response_body, 'errors' ) || property_exists( $response_body, 'error_code' ) ) {
				if ( $response_body->errors ) {
					$primary_error = $response_body->errors[0];
					if ( ! isset( $primary_error->field ) ) {
						$primary_error->field = '';
					}
					if ( ! isset( $primary_error->code ) ) {
						$primary_error->code = E_BOLT_GENERAL_ERROR_FULL_CODE;
					}
					$err_msg = "-- Failed Bolt API Request --\n[reason] " . $primary_error->message . "\n[field] {$primary_error->field}\n\nRequest params: $params\n\nResponse Headers: {$response[BOLT_FIELD_NAME_HEADERS]}";
					throw new BOLT_REST_Exception( $primary_error->code, $err_msg, HTTP_STATUS_UNPROCESSABLE, (array) $primary_error );
				} else {
					throw new \Exception( "Failed Bolt API Request: " . $params . "\n\nResponse Headers: {$response[BOLT_FIELD_NAME_HEADERS]}\n\nResponse Body:\n\n" . $response['body'] );
				}
			}
		} else {
			$curl_info = ( $response && array_key_exists( BOLT_FIELD_NAME_ERROR, $response ) && ! empty( $response[ BOLT_FIELD_NAME_ERROR ] ) ) ? json_encode( $response[ BOLT_FIELD_NAME_ERROR ], JSON_PRETTY_PRINT ) : 'no error description found';
			throw new \Exception( "Failed Bolt API request: " . $params . "\n\nCurl info: " . $curl_info );
		}

		return $response_body;
	}


	/**
	 * Get the API url for test or production.
	 *
	 * @param string $category The url grouping of the constructed URL
	 *
	 * @return string $api_url API url.
	 * @since 2.0.6
	 *
	 */
	private function get_api_url( $path, $category = 'merchant' ) {
		return wc_bolt()->get_bolt_settings()->get_bolt_api_host() . '/v1/' . ( $category ? $category . '/' . $path : $path );
	}

	/**
	 * Performs a curl request and returns the results.  This method is used in place of
	 * WordPress and WooCommerce methods for better control over request headers sent
	 * to Bolt
	 *
	 * @param string $url The target URL
	 * @param array $headers The headers to be sent with the request
	 * @param string $method GET|POST
	 * @param string $data The body of a POST request.  This is ignored for GET
	 *
	 * @return array    An associative array of the results of the call in the format
	 *                  {"error": array|false, "headers": string, "body": string}
	 * @since 2.0.6
	 */
	public function send_curl_request( $url, $headers = array(), $method = HTTP_METHOD_GET, $data = null ) {
		$ch = curl_init( $url );

		$breadcrumbs = array( 'url' => $url, 'method' => $method );

		if ( ! empty( $headers ) ) {
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
			$breadcrumbs[ BOLT_FIELD_NAME_HEADERS ] = $headers;
		}

		if ( strtoupper( $method ) == HTTP_METHOD_POST ) {
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
			$breadcrumbs['data'] = $data;
		}
		BugsnagHelper::addBreadCrumbs( array( 'BOLT API REQUEST' => $breadcrumbs ) );

		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_HEADER, true );

		$response       = curl_exec( $ch );
		$response_array = array( BOLT_FIELD_NAME_ERROR => false );

		if ( $response === false ) {
			$response_array[ BOLT_FIELD_NAME_ERROR ] = curl_getinfo( $ch );
		} else {
			$header_size                               = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
			$response_array[ BOLT_FIELD_NAME_HEADERS ] = substr( $response, 0, $header_size );
			$response_array[ BOLT_FIELD_NAME_BODY ]    = substr( $response, $header_size );
		}

		curl_close( $ch );

		return $response_array;
	}

	/*
	|--------------------------------------------------------------------------
	| Methods and constants for work with GraphQL queries
	|--------------------------------------------------------------------------
	|
	|
	*/
	/**
	 * Send GraphQL API request to serve
	 *
	 * @param string $query GraphQL query
	 * @param string $operation GraphQL operation
	 * @param array $variables GraphQL variablwa
	 *
	 * @return array The response received from Bolt
	 * @throws \Exception  Throws an exception on failed attempts to communicate with the Bolt API
	 *
	 * @since 2.4.0
	 */
	private function handle_graphql_request( $query, $operation, $variables ) {
		$GQL_request = array(
			"operationName" => $operation,
			"variables"     => $variables,
			"query"         => $query
		);

		$api_url = wc_bolt()->get_bolt_settings()->get_bolt_api_host() . '/' . self::MERCHANT_API_GQL_ENDPOINT;

		return $this->handle_api_request_by_url( $api_url, $GQL_request );
	}

	/**
	 * This Method makes a call to Bolt and returns the feature switches and their values for this server with
	 * its current version and the current merchant in question.
	 *
	 * @return mixed
	 * @return array The response received from Bolt
	 *
	 * @throws \Exception
	 *
	 * @since 2.4.0
	 */
	public function get_feature_switches() {
		$res = $this->handle_graphql_request( GET_FEATURE_SWITCHES_QUERY, GET_FEATURE_SWITCHES_OPERATION, array(
			'type'    => BOLT_PLUGIN_TYPE,
			'version' => WC_BOLT_CHECKOUT_VERSION,
		) );

		return $res;
	}
}
