<?php
/**
 * Installation related functions and actions.
 *
 * @package Woocommerce_Bolt_Checkout/Classes
 * @version 1.0.0
 * @since   1.2.8
 */

namespace BoltCheckout;

defined( 'ABSPATH' ) || exit;

/**
 * WC_Bolt_Install Class.
 */
class WC_Bolt_Install {

	/**
	 * Hook in tabs.
	 *
	 * @since 1.2.8
	 * @static
	 * @access    public
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'check_version' ), 6 );
	}

	/**
	 * Check WooCommerce Bolt Checkout version and run the updater is required.
	 * This check is done on all requests and runs if the versions do not match.
	 *
	 * @since 1.2.8
	 * @static
	 * @access    public
	 */
	public static function check_version() {
		if ( ! defined( 'IFRAME_REQUEST' ) && version_compare( get_option( 'woocommerce_bolt_checkout_version', '1.0.0' ), WC_BOLT_CHECKOUT_VERSION, '<' ) ) {
			self::install();
		}
	}

	/**
	 * Install WooCommerce Bolt Checkout.
	 *
	 * @since 1.2.8
	 * @static
	 * @access    public
	 */
	public static function install() {
		if ( ! is_blog_installed() ) {
			return;
		}

		// Check if we are not already running this routine.
		if ( 'yes' === get_transient( 'wc_bolt_checkout_installing' ) ) {
			return;
		}

		// If we made it till here nothing is running yet, lets set the transient now.
		set_transient( 'wc_bolt_checkout_installing', 'yes', MINUTE_IN_SECONDS * 10 );
		wc_maybe_define_constant( 'WC_BOLT_CHECKOUT_INSTALLING', true );

		$old_bolt_version = get_option( 'woocommerce_bolt_checkout_version' );

		// Delete old session data when we first time upgrade to version 2.0.8 or above
		if ( $old_bolt_version && version_compare( $old_bolt_version, '2.0.8', '<' ) ) {
			update_option( 'bolt_should_delete_historic_session', true );
		}

		$settings = get_option( SETTINGS_OPTION_NAME, array() );
		// If upgrading from below 2.0.11 and there are no values for enable_checkout_page or enable_order_pay
		// then use the old default value of 'yes'
		if ( $old_bolt_version && version_compare( $old_bolt_version, '2.0.11', '<' ) ) {
			$settings_updated = false;
			foreach (
				array(
					Bolt_Settings::SETTING_NAME_ENABLE_CHECKOUT_PAGE,
					Bolt_Settings::SETTING_NAME_ENABLE_ORDER_PAY
				) as $key
			) {
				if ( ! array_key_exists( $key, $settings ) ) {
					$settings[ $key ] = Bolt_Settings::VALUE_YES;
					$settings_updated = true;
				}
			}
			if ( $settings_updated ) {
				update_option( SETTINGS_OPTION_NAME, $settings );
			}
		}

		// If we upgrade from below 2.3.0 and we had old default value 'Bolt' for 'title'
		// we update it to new default value 'Credit or Debit Card'
		if ( $old_bolt_version && version_compare( $old_bolt_version, '2.3.0', '<' ) ) {
			if ( isset( $settings[ Bolt_Settings::SETTING_NAME_PAYMENT_METHOD_TITLE ] )
			     && $settings[ Bolt_Settings::SETTING_NAME_PAYMENT_METHOD_TITLE ] == 'Bolt' ) {
				$settings[ Bolt_Settings::SETTING_NAME_PAYMENT_METHOD_TITLE ] = 'Credit or Debit Card';
				update_option( SETTINGS_OPTION_NAME, $settings );
			}
		}

		// If upgrading from below 2.7.0 and there are no values for hide_default_checkout_buttons
		// then use 'no' value. We want have 'yes' by default only for new installations.
		if ( $old_bolt_version && version_compare( $old_bolt_version, '2.7.0', '<' ) ) {
			if ( ! isset( $settings[ Bolt_Settings::SETTING_NAME_HIDE_DEFAULT_CHECKOUT_BUTTONS ] ) ) {
				$settings[ Bolt_Settings::SETTING_NAME_HIDE_DEFAULT_CHECKOUT_BUTTONS ] = 'no';
				update_option( SETTINGS_OPTION_NAME, $settings );
			}
		}

		// If upgrading from below 2.16.0 and the Subscriptions option needs to be hidden.
		if ( $old_bolt_version && version_compare( $old_bolt_version, '2.16.0', '<' ) ) {
			unset( $settings[ Bolt_Settings::SETTING_NAME_SUBSCRIPTION ] );
			unset( $settings[ Bolt_Settings::SETTING_NAME_SUBSCRIPTION_BUTTON_CLASS ] );
			update_option( SETTINGS_OPTION_NAME, $settings );
		}

		wc_bolt_data()->create_bolt_sessions_table();
		wc_bolt_data()->create_bolt_session_customer_id_table();
		wc_bolt_data()->create_woocommerce_bolt_external_customer_ids_table();
		self::update_wc_bolt_version();
		if ( ! empty( $settings[ Bolt_Settings::SETTING_NAME_MERCHANT_KEY ] ) ) {
			self::update_feature_switches();
		}
		self::check_if_log_directory_writable();

		delete_transient( 'wc_bolt_checkout_installing' );
	}

	/**
	 * Update WooCommerce Bolt Checkout version to current.
	 *
	 * @since 1.2.8
	 * @static
	 * @access    private
	 */
	private static function update_wc_bolt_version() {
		update_option( 'woocommerce_bolt_checkout_version', WC_BOLT_CHECKOUT_VERSION );
	}

	/**
	 * Check if Woocommerce log directory writable and create Bugsnag record if not
	 *
	 * @since 2.0.13
	 * @static
	 * @access    private
	 */
	private static function check_if_log_directory_writable() {
		$log_directory_writable = (bool) @fopen( WC_LOG_DIR . WC_TEST_LOG_FILE_NAME, 'a' );
		if ( ! $log_directory_writable ) {
			BugsnagHelper::notifyException( new \Exception( 'Woocommerce log directory is not writable' ), array( 'WC_LOG_DIR' => WC_LOG_DIR ) );
		}
	}

	/**
	 * Update feature switches from Bolt server and save metric
	 *
	 * @since 2.4.0
	 * @static
	 * @access    private
	 */
	public static function update_feature_switches() {
		wc_bolt()->get_metrics_client()->save_start_time();
		try {
			Bolt_Feature_Switch::instance()->update_switches_from_bolt();
		} catch ( BOLT_REST_Exception $e ) {
			BugsnagHelper::notifyException( $e );
			wc_bolt()->get_metrics_client()->process_metric( BOLT_METRIC_NAME_FEATURE_SWITCH_UPGRADE_DATA, BOLT_STATUS_FAILURE );

			return;
		} catch ( \Exception $e ) {
			BugsnagHelper::notifyException( $e );
			wc_bolt()->get_metrics_client()->process_metric( BOLT_METRIC_NAME_FEATURE_SWITCH_UPGRADE_DATA, BOLT_STATUS_FAILURE );

			return;
		}
		wc_bolt()->get_metrics_client()->process_metric( BOLT_METRIC_NAME_FEATURE_SWITCH_UPGRADE_DATA, BOLT_STATUS_SUCCESS );
	}

}

WC_Bolt_Install::init();
?>