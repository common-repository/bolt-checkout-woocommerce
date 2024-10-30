<?php
/**
 * @package Woocommerce_Bolt_Checkout/Traits
 */

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait Bolt_Controller.
 *
 * @since
 */
trait Bolt_Controller {
	protected $namespace = 'bolt';
	protected $route;
	protected $request_payload;
	protected $decode_request_payload;

	abstract public function handle_endpoint();

	/**
	 * Register WP REST API route
	 */
	public function register_wp_rest_api_route( $routes ) {
		$routes[ '/' . $this->namespace . '/' . $this->route ] = array(
			array(
				array( $this, 'handle_endpoint' ),
				\WP_JSON_Server::CREATABLE | \WP_JSON_Server::ACCEPT_JSON
			),
		);

		return $routes;
	}

	/**
	 * Register wordpress endpoints
	 */
	public function register_endpoint() {
		register_rest_route( $this->namespace, '/' . $this->route, array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_endpoint' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Ensure everything is setup properly before handling specific actions
	 */
	public function presetup() {
		/////////////////////////////////////////////////////////////////
		// In the case of long processing, we ignore Bolt's aborts
		// And give the calculation 40 seconds to complete
		// If it takes longer, then custom merchant-side optimization is needed
		/////////////////////////////////////////////////////////////////
		ignore_user_abort( true );
		set_time_limit( BOLT_API_ENDPOINT_TIME_LIMIT );
		$GLOBALS['is_webhook_request'] = true;

		// Prevent third party plugin write their errors to output
		ob_start();

		// Get request body that Bolt has sent us
		$this->request_payload        = file_get_contents( 'php://input' );
		$this->decode_request_payload = json_decode( $this->request_payload );
		BugsnagHelper::addBreadCrumbs( array( 'BOLT ' . strtoupper( $this->route ) . ' API REQUEST' => $this->request_payload ) );
		$this->verify_hmac_header();
	}

	/**
	 * Check that data sent by Bolt server
	 */
	public function verify_hmac_header() {
		# Get the authentication header that Bolt has sent us
		$hmac_header = @$_SERVER[ BOLT_HEADER_HMAC ] ?: '';

		if ( ! verify_signature( $this->request_payload, $hmac_header ) ) {
			//////////////////////////////////////////////////////////
			// Request can not be verified as originating from Bolt
			// So we return an error
			//////////////////////////////////////////////////////////
			wc_bolt()->get_metrics_client()->process_metric( BOLT_METRIC_NAME_SHIP_TAX, BOLT_STATUS_FAILURE );
			throw new \Exception( __( 'Invalid HMAC header.', 'bolt-checkout-woocommerce' ) );
		}
	}

}
