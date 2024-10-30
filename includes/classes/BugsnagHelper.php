<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once( plugin_dir_path( __FILE__ ) . "../../lib/Bugsnag/Autoload.php" );

/**
 * Class BugsnagHelper
 *
 * This class is a wrapper class around the Bugsnag client.  It is used to manage Bugsnag construction
 * and breadcrumb sent to Bugsnag
 */
class BugsnagHelper {

	const MAX_ELEMENT_SIZE = 300000;

	/**
	 * @var string  The Bugsnag notification API key for the Bolt WooCommerce project
	 */
	private static $apiKey = "1c0f30690fb1bdd0deb462978e930da1";

	/**
	 * @var Bugsnag_Client  The native Bugsnag client that this object wraps
	 */
	private static $bugsnag;

	/**
	 * @var array   The metadata array that is used to set breadcrumbs
	 */
	private static $metaData = array( "breadcrumbs_" => array() );

	/**
	 * @var bool  Flag for whether is plugin is set in sandbox or production mode
	 */
	public static $is_sandbox_mode = true;

	/**
	 * Breadcrumbs to be added to the Bugsnag notification
	 *
	 * @param array $metaData an array in the format of [key => value] for breadcrumb data
	 */
	public static function addBreadCrumbs( $metaData ) {
		static::$metaData['breadcrumbs_'] = array_merge( $metaData, static::$metaData['breadcrumbs_'] );
	}

	/**
	 * Initialize bugsnag, including setting app and release stage
	 */
	public static function initBugsnag() {
		if ( ! static::$bugsnag ) {
			$bugsnag = new \Bugsnag_Client( static::$apiKey );

			$bugsnag->setErrorReportingLevel( E_ERROR );
			$bugsnag->setAppVersion( WC_BOLT_CHECKOUT_VERSION );
			if ( defined( 'BOLT_TEST_MODE' ) ) {
				$release_stage = 'testing';
			} else {
				$release_stage = static::$is_sandbox_mode ? 'development' : 'production';
			}
			$bugsnag->setReleaseStage( $release_stage );
			$bugsnag->setNotifyReleaseStages( array( 'development', 'production' ) );
			$bugsnag->setHostname( get_site_url() );
			$bugsnag->setBatchSending( false );
			$bugsnag->setBeforeNotifyFunction( array( '\BoltCheckout\BugsnagHelper', 'beforeNotifyFunction' ) );

			static::$bugsnag = $bugsnag;

			// Hook up automatic error handling
			set_error_handler( array( static::$bugsnag, "errorHandler" ) );
			set_exception_handler( array( static::$bugsnag, "exceptionHandler" ) );
		}
	}

	/**
	 * Returns the bugsnag client for direct manipulation
	 *
	 * @return \Bugsnag_Client
	 */
	public static function getBugsnag() {
		static::initBugsnag();

		return static::$bugsnag;
	}

	/**
	 * Method for coercing the Bugsnag_Error object, just prior to it being sent to the Bugsnag
	 * server.  Here, we use it to set the WooCommerce version, plugin version, and Trace-Id if
	 * available
	 *
	 * @param Bugsnag_Error $error
	 */
	public static function beforeNotifyFunction( $error ) {
		$meta_data = array(
			'WooCommerce-Version' => WC_VERSION,
			'Bolt-Plugin-Version' => WC_BOLT_CHECKOUT_VERSION,
			'Store-URL'           => get_site_url()
		);

		if ( isset( $_SERVER['HTTP_X_BOLT_TRACE_ID'] ) ) {
			$meta_data['Bolt-Trace-Id'] = $_SERVER['HTTP_X_BOLT_TRACE_ID'];
		}
		$error->setMetaData( $meta_data );

		if ( count( static::$metaData['breadcrumbs_'] ) ) {
			$error->setMetaData( static::$metaData );
		}
	}

	/**
	 * Notify Bugsnag of a non-fatal/handled throwable.
	 *
	 * @param Throwable $throwable the throwable to notify Bugsnag about
	 * @param array $metaData optional metaData to send with this error
	 * @param string $severity optional severity of this error (error/warning/info)
	 *
	 * @return void
	 * @since  2.0.3
	 *
	 */
	public static function notifyException( $throwable, $metaData = array(), $severity = 'error' ) {
		$settings        = wc_bolt()->get_settings();
		$severity_levels = array(
			'error'   => 1,
			'warning' => 2,
			'info'    => 3,
		);
		if ( $severity_levels[ $severity ] > $settings[ Bolt_Settings::SETTING_NAME_SEVERITY_LEVEL ] ) {
			return;
		}
		static::getBugsnag()->notifyException( $throwable, $metaData, $severity );
	}
}
