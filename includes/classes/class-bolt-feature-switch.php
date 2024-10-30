<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class for manages feature switches
 *
 * Class Bolt_Feature_Switch
 *
 */
class Bolt_Feature_Switch {

	// Option name to save data
	const OPTION_NAME = 'woocommerce_wc-bolt-payment-gateway_switches';

	// Switches field names
	const VAL_KEY = 'value';
	const DEFAULT_VAL_KEY = 'default_value';
	const ROLLOUT_KEY = 'rollout_percentage';

	/**
	 * This switch is a sample of how to set up a feature switch.
	 * Every feature switch added here should have a corresponding helper
	 * in this class
	 */
	const WOOC_SAMPLE_SWITCH_NAME = 'WOOC_SAMPLE_SWITCH';
	const WOOC_MERCHANT_METRICS_SWITCH_NAME = 'WOOC_MERCHANT_METRICS';
	const WOOC_BOLT_ENABLED_SWITCH_NAME = 'WOOC_BOLT_ENABLED';
	const WOOC_HOOK_PRIORITY_CHANGED_SWITCH_NAME = 'WOOC_HOOK_PRIORITY_CHANGED';
	const WOOC_ENABLE_NATIVE_CART_SESSION_NAME = 'WOOC_ENABLE_NATIVE_CART_SESSION';
	const WOOC_BOLT_ORDER_MANAGEMENT = 'WOOC_BOLT_ORDER_MANAGEMENT';
	const WOOC_TRACK_SHIPMENT = 'WOOC_TRACK_SHIPMENT';
	const WOOC_TRACK_NON_BOLT = 'WOOC_TRACK_NON_BOLT';
	const WOOC_BOLT_SSO = 'WOOC_BOLT_SSO';

	/**
	 * The single instance of the class.
	 *
	 * @since 2.4.0
	 * @var Bolt_Feature_Switch|null
	 */
	private static $instance = null;

	/**
	 * @since 2.4.0
	 * @var array Bolt features.
	 */
	private $switches;

	/**
	 * @since 2.4.0
	 * @var array Default values for Bolt features
	 */
	private $default_switches;

	/**
	 * Gets Bolt_Feature_Switch Instance.
	 *
	 * @return Bolt_Feature_Switch Instance
	 * @since 2.4.0
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
	 * Bolt_Feature_Switch constructor.
	 *
	 * @since 2.4.0
	 */
	public function __construct() {
		$this->read_switches();

		$this->default_switches = array(
			self::WOOC_SAMPLE_SWITCH_NAME                => (object) array(
				self::VAL_KEY         => true,
				self::DEFAULT_VAL_KEY => false,
				self::ROLLOUT_KEY     => 0
			),
			self::WOOC_MERCHANT_METRICS_SWITCH_NAME      => (object) array(
				self::VAL_KEY         => true,
				self::DEFAULT_VAL_KEY => false,
				self::ROLLOUT_KEY     => 0
			),
			self::WOOC_BOLT_ENABLED_SWITCH_NAME          => (object) array(
				self::VAL_KEY         => true,
				self::DEFAULT_VAL_KEY => false,
				self::ROLLOUT_KEY     => 100
			),
			self::WOOC_HOOK_PRIORITY_CHANGED_SWITCH_NAME => (object) array(
				self::VAL_KEY         => true,
				self::DEFAULT_VAL_KEY => false,
				self::ROLLOUT_KEY     => 100
			),
			self::WOOC_BOLT_ORDER_MANAGEMENT             => (object) array(
				self::VAL_KEY         => true,
				self::DEFAULT_VAL_KEY => false,
				self::ROLLOUT_KEY     => 0
			),
			self::WOOC_ENABLE_NATIVE_CART_SESSION_NAME   => (object) array(
				self::VAL_KEY         => true,
				self::DEFAULT_VAL_KEY => false,
				self::ROLLOUT_KEY     => 0
			),
			self::WOOC_TRACK_SHIPMENT                    => (object) array(
				self::VAL_KEY         => true,
				self::DEFAULT_VAL_KEY => false,
				self::ROLLOUT_KEY     => 0
			),
			self::WOOC_TRACK_NON_BOLT                    => (object) array(
				self::VAL_KEY         => true,
				self::DEFAULT_VAL_KEY => false,
				self::ROLLOUT_KEY     => 0
			),
			self::WOOC_BOLT_SSO                          => (object) array(
				self::VAL_KEY         => true,
				self::DEFAULT_VAL_KEY => false,
				self::ROLLOUT_KEY     => 0
			),
		);
	}

	/**
	 * Read Bolt feature switches from database.
	 *
	 * @since 2.4.0
	 */
	private function read_switches() {
		$this->switches = get_option( self::OPTION_NAME, array() );
	}

	/**
	 * Update switches values from Bolt server
	 *
	 * @throws \Exception
	 * @since 2.4.0
	 */
	public function update_switches_from_bolt() {
		$response = wc_bolt()->get_api_request()->get_feature_switches();
		if ( $response && isset( $response->data->plugin->features ) ) {
			$features = array();
			foreach ( $response->data->plugin->features as $feature ) {
				$features[ $feature->name ] = (object) array(
					self::VAL_KEY         => $feature->value,
					self::DEFAULT_VAL_KEY => $feature->defaultValue,
					self::ROLLOUT_KEY     => $feature->rolloutPercentage
				);
			}

			update_option( self::OPTION_NAME, $features );
			$this->read_switches();
		}
	}

	/**
	 * Get unique ID from cookie or generate it and save it in cookie and session
	 * @return string
	 * @since 2.5.0
	 */
	private function get_unique_user_id() {
		$bolt_data = WC()->session->get( 'bolt_data', array() );
		if ( $bolt_data && isset( $bolt_data['id'] ) ) {
			return $bolt_data['id'];
		}

		if ( isset( $_COOKIE['bolt_customer_id'] ) ) {
			$switch_id = $_COOKIE['bolt_customer_id'];
		} else {
			$switch_id = uniqid( "BFS", false );
			wc_setcookie( 'bolt_customer_id', $switch_id );
		}

		// Also save id into Woocommerce session to have it when we handle API calls
		$bolt_data['id'] = $switch_id;
		WC()->session->set( 'bolt_data', $bolt_data );

		return $switch_id;
	}


	/**
	 * This method returns if a feature switch is enabled for a user.
	 * The way this is computed is as follows:
	 * - Get feature switch id
	 * - Set if unset.
	 * - Add switch name as salt to ID and find md5 hash
	 * - Get first 6 digits of MD5 and divide by 0xffffff. Should be between 0 and 1.
	 * - Multiply previous value by 100
	 *   and compare with rolloutPercentage to decide if in bucket.
	 *
	 * @param string $switch_name
	 * @param int $rollout_percentage
	 *
	 * @return bool
	 * @since 2.5.0
	 */
	private function is_in_bucket( $switch_name, $rollout_percentage ) {
		$bolt_feature_switch_id = $this->get_unique_user_id();
		$salted_string          = $bolt_feature_switch_id . '-' . $switch_name;
		$position               = crc32( $salted_string ) % 100;

		return $position < $rollout_percentage;
	}


	/**
	 * This returns if the switch is enabled.
	 *
	 * @param string $switchName name of the switch
	 *
	 * @return bool
	 * @throws \Exception
	 * @since 2.5.0
	 */
	private function is_switch_enabled( $switch_name ) {

		if ( ! isset( $this->default_switches[ $switch_name ] ) ) {
			throw new \Exception( 'Unknown feature switch' );
		}
		if ( isset( $this->switches[ $switch_name ] ) ) {
			$switch = $this->switches[ $switch_name ];
		} else {
			$switch = $this->default_switches[ $switch_name ];
		}
		switch ( $switch->rollout_percentage ) {
			case 0:
				return $switch->default_value;
			case 100:
				return $switch->value;
			default:
				$is_in_bucket = $this->is_in_bucket( $switch_name, $switch->rollout_percentage );

				return $is_in_bucket ? $switch->value : $switch->default_value;;
		}
	}

	/***************************************************
	 * Switch Helpers below
	 ***************************************************/
	public function is_sample_switch_enabled() {
		return $this->is_switch_enabled( SELF::WOOC_SAMPLE_SWITCH_NAME );
	}

	public function is_merchant_metrics_enabled() {
		return $this->is_switch_enabled( SELF::WOOC_MERCHANT_METRICS_SWITCH_NAME );
	}

	public function is_bolt_enabled() {
		return $this->is_switch_enabled( SELF::WOOC_BOLT_ENABLED_SWITCH_NAME );
	}

	public function is_hook_priority_changed() {
		return $this->is_switch_enabled( SELF::WOOC_HOOK_PRIORITY_CHANGED_SWITCH_NAME );
	}

	public function is_native_cart_session_enabled() {
		return $this->is_switch_enabled( SELF::WOOC_ENABLE_NATIVE_CART_SESSION_NAME );
	}

	public function is_bolt_order_management_enabled() {
		return $this->is_switch_enabled( SELF::WOOC_BOLT_ORDER_MANAGEMENT );
	}

	public function is_track_shipment_enabled() {
		return $this->is_switch_enabled( SELF::WOOC_TRACK_SHIPMENT );
	}

	public function is_track_non_bolt_order_enabled() {
		return $this->is_switch_enabled( SELF::WOOC_TRACK_NON_BOLT );
	}

	public function is_sso_enabled() {
		return $this->is_switch_enabled( SELF::WOOC_BOLT_SSO );
	}

}
