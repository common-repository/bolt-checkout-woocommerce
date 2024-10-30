<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class to fetch all the platform information
 *
 * @class   Bolt_Debug
 * @author  Bolt
 */
class Bolt_Debug {

	/**
	 * Bolt request for fetch all the platform information.
	 *
	 * @var object
	 */
	private $request;

	/**
	 * Error information.
	 *
	 * @var Bolt_Error_Handler
	 */
	private $error_handler;

	/**
	 * Constructor Function.
	 *
	 * @access public
	 */
	public function __construct() {
		if ( WC_BOLT_WP_REST_API_ADDON ) {
			add_filter( 'json_endpoints', array( $this, 'register_wp_rest_api_route' ) );
		} else {
			add_action( 'rest_api_init', array( $this, 'register_order_endpoint' ) );
		}
	}


	/**
	 * Register WP REST API route
	 *
	 * @param array $routes
	 *
	 * @return array
	 * @since  2.17.0
	 * @access public
	 *
	 */
	public function register_wp_rest_api_route( $routes ) {

		$routes['/bolt/debug'] = array(
			array( array( $this, 'debug' ), \WP_JSON_Server::CREATABLE | \WP_JSON_Server::ACCEPT_JSON ),
		);

		return $routes;
	}

	/**
	 * Register wordpress endpoints
	 *
	 * @since  2.17.0
	 * @access public
	 *
	 */
	public function register_order_endpoint() {
		register_rest_route( 'bolt', '/debug', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'debug' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Implement debug endpoint
	 * @return WP_REST_Response
	 * @since  2.17.0
	 */
	public function debug() {
		BugsnagHelper::initBugsnag();
		try {
			$this->error_handler = new Bolt_Error_Handler();
			$hmac_header         = @$_SERVER['HTTP_X_BOLT_HMAC_SHA256'];
			$get_data            = file_get_contents( 'php://input' );
			if ( ! verify_signature( $get_data, $hmac_header ) ) {
				// Request can not be verified as originating from Bolt
				$this->error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array( 'reason' => 'Invalid HMAC header' ) );

				return $this->error_handler->build_error();
			}
			$this->request = json_decode( $get_data );
			if ( ! empty( $this->request ) && isset( $this->request->type ) && $this->request->type == 'log' ) {
				$data = array(
					BOLT_DEBUG_LOGS => $this->get_current_wc_logs()
				);
			} else {
				$data = array(
					BOLT_DEBUG_PHP_VERSION           => PHP_VERSION,
					BOLT_DEBUG_PLATFORM_VERSION      => WC_VERSION,
					BOLT_DEBUG_BOLT_CONFIG_SETTINGS  => $this->get_bolt_config_settings(),
					BOLT_DEBUG_OTHER_PLUGIN_VERSIONS => $this->get_installed_plugins_info()
				);
			}

			return Bolt_HTTP_Handler::prepare_http_response(
				array(
					'status' => 'success',
					'event'  => 'integration.debug',
					'data'   => $data
				)
			);
		} catch ( \Exception $e ) {
			$this->error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array( 'reason' => $e->getMessage() ) );

			return $this->error_handler->build_error();
		}
	}

	/**
	 * Get configuration settings of Bolt
	 *
	 * @return array
	 * @since 2.17.0
	 */
	public function get_bolt_config_settings() {
		$raw_settings  = wc_bolt()->get_settings();
		$bolt_settings = array();
		foreach ( $raw_settings as $name => $value ) {
			if ( substr( $name, - strlen( '_key' ) ) === '_key' ) {
				continue;
			}
			$bolt_settings[] = array(
				'name'  => $name,
				'value' => $value
			);
		}

		return $bolt_settings;
	}

	/**
	 * Get all installed plugins info
	 *
	 * @return array
	 * @since 2.17.0
	 */
	public function get_installed_plugins_info() {
		// Get all plugins
		include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		$all_plugins = get_plugins();
		// Get active plugins
		$active_plugins = get_option( 'active_plugins' );
		// Assemble array of name, version, and whether plugin is active (boolean)
		$plugins_info = array();
		foreach ( $all_plugins as $key => $value ) {
			$is_active      = ( in_array( $key, $active_plugins ) ) ? true : false;
			$plugins_info[] = array(
				'name'    => $value['Name'],
				'version' => $value['Version']
			);
		}

		return $plugins_info;
	}

	/**
	 * Get logs from WooC
	 *
	 * @return array
	 * @since 2.17.0
	 */
	public function get_current_wc_logs() {
		$fatal_errors_log = wc_get_log_file_name( 'fatal-errors' );

		return explode( "\n", $this->tail_logs_file( WC_LOG_DIR . $fatal_errors_log ) );
	}

	/**
	 * Tail the logs file and return contents.
	 *
	 * @return string
	 * @since 2.17.0
	 */
	private function tail_logs_file( $logPath, $lines = 100 ) {
		try {
			//Open file, return informative error string if doesn't exist
			$file = fopen( $logPath, 'rb' );
		} catch ( \Exception $exception ) {
			$this->bugsnag->notifyException( $exception );

			return "No file found at " . $logPath;
		}
		$buffer = ( $lines < 2 ? 64 : ( $lines < 10 ? 512 : 4096 ) );
		fseek( $file, - 1, SEEK_END );
		//Correct for blank line at end of file
		if ( fread( $file, 1 ) != "\n" ) {
			$lines -= 1;
		}
		$output = '';
		while ( ftell( $file ) > 0 && $lines >= 0 ) {
			$seek = min( ftell( $file ), $buffer );
			fseek( $file, - $seek, SEEK_CUR );
			$chunk  = fread( $file, $seek );
			$output = $chunk . $output;
			fseek( $file, - mb_strlen( $chunk, '8bit' ), SEEK_CUR );
			$lines -= substr_count( $chunk, "\n" );
		}
		//possible that with the buffer we read too many lines.
		//find first newline char and remove all text before that
		while ( $lines ++ < 0 ) {
			$output = substr( $output, strpos( $output, "\n" ) + 1 );
		}
		fclose( $file );

		return trim( $output );
	}
}

new Bolt_Debug();