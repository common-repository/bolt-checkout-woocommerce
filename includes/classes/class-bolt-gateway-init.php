<?php

namespace BoltCheckout;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Loads a single instance of WooCommerce Bolt Payment Gateway.
 *
 * @return object Bolt_Gateway_Init Returns an instance of the class
 * @see     Bolt_Gateway_Init::get_instance()
 *
 * @since   1.0.0
 *
 */

/**
 * Bolt_Gateway_Init Class
 *
 * @package Bolt_Gateway_Init
 * @since   1.0
 */
final class Bolt_Gateway_Init {
	/**
	 * Holds the instance
	 *
	 * @var object
	 * @static
	 */
	private static $instance;
	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 *
	 * @var      string $plugin_name The ID of this plugin.
	 * @static
	 */
	private static $plugin_name = BOLT_PLUGIN_NAME;
	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 *
	 * @var      string $version The current version of this plugin.
	 * @static
	 */
	private static $version = WC_BOLT_CHECKOUT_VERSION;
	/**
	 * Notices (array).
	 *
	 * @var array
	 */
	public static $notices = array();
	/**
	 * WooCommerce Bolt Payment Gateway Admin Object.
	 *
	 * @since  1.0
	 * @access public
	 *
	 * @var    Bolt_Gateway_Init_Admin object.
	 */
	public $plugin_admin;
	/**
	 * WooCommerce Bolt Payment Gateway Frontend Object.
	 *
	 * @since  1.0
	 * @access public
	 *
	 * @var    Bolt_Gateway_Init_Public object.
	 */
	public $plugin_public;

	/**
	 * Get the instance and store the class inside it. This plugin utilises
	 * the PHP singleton design pattern.
	 *
	 * @return object self::$instance Instance
	 * @see       Bolt_Gateway_Init();
	 *
	 * @uses      Bolt_Gateway_Init::init_hooks() Setup hooks and actions.
	 * @uses      Bolt_Gateway_Init::includes() Loads all the classes.
	 * @uses      Bolt_Gateway_Init::licensing() Add WooCommerce Bolt Payment Gateway License.
	 *
	 * @since     1.0.0
	 * @static
	 * @staticvar array $instance
	 * @access    public
	 *
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) && ! ( self::$instance instanceof Bolt_Gateway_Init ) ) {
			self::$instance = new Bolt_Gateway_Init();
			self::$instance->init_hooks();
			self::$instance->includes();
			self::$instance->init();
		}

		return self::$instance;
	}

	/**
	 * Throw error on object clone.
	 *
	 * The whole idea of the singleton design pattern is that there is a single
	 * object therefore, we don't want the object to be cloned.
	 *
	 * @return void
	 * @since  1.0.0
	 * @access protected
	 *
	 */
	public function __clone() {
		// Cloning instances of the class is forbidden.
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cheatin&#8217; huh?', 'bolt-checkout-woocommerce' ), '1.0' );
	}

	/**
	 * Disable Unserialize of the class.
	 *
	 * @return void
	 * @since  1.0.0
	 * @access protected
	 *
	 */
	public function __wakeup() {
		// Unserialize instances of the class is forbidden.
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cheatin&#8217; huh?', 'bolt-checkout-woocommerce' ), '1.0' );
	}

	/**
	 * Constructor Function.
	 *
	 * @since  1.0.0
	 * @access protected
	 */
	public function __construct() {
		self::$instance = $this;
	}

	/**
	 * Reset the instance of the class
	 *
	 * @since  1.0.0
	 * @access public
	 */
	public static function reset() {
		self::$instance = null;
	}

	/**
	 * Includes.
	 *
	 * @since  1.0.0
	 * @access private
	 */
	private function includes() {
		// Load Bolt Server constants.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/bolt-constants.php' );
		// Load BOLT_REST_Exception.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/bolt-rest-exception.php' );
		// Load Bolt constants for Graph QL.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/bolt-graphql-constants.php' );
		// Load Woocommerce constants.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/bolt-woocommerce-constants.php' );
		// Load Bugsnag Class.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/BugsnagHelper.php' );
		// Load Compatibility Class.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-compatibility-helper.php' );
		// Load Error handler Class.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-error-handler.php' );
		// Load WC_Bolt_Install Class.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-install.php' );
		// Load WC_Bolt_Data_Manager Class.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-data-manager.php' );
		// Load the admin area functionality.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/admin/class-bolt-checkout-admin.php' );
		// Load the front side functionality.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/public/class-bolt-payment-gateway-public.php' );
		// Load the Bolt helpers.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/bolt-payment-gateway-helpers.php' );
		// Load the Bolt_Address_Helper
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-address-helper.php' );
		// Load the Bolt_Discounts_Helper
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-discounts-helper.php' );
		// Load Bolt Payment Gateway Class.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-payment-gateway.php' );
		// Load Bolt Payment Gateway Class.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-checkout.php' );
		// Load Bolt Shipping&Tax Class.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-shipping-and-tax.php' );
		// Load Bolt Pre Auth Class.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-order-creator.php' );
		// Load Bolt API manager Class.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-data-collector.php' );
		// Load Bolt Settings Class.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-settings.php' );
		// Load Bolt Feature Switches Class.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-feature-switch.php' );
		// Load Bolt HTML Handler Class.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-html-handler.php' );
		// Load Bolt HTTP Handler Class.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-http-handler.php' );
		// Load Bolt Subscription Class.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-subscription.php' );
		// Load Bolt Page Checkout Class.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-page-checkout.php' );
		// Load Bolt Update Cart Class.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-update-cart.php' );
		// Load Bolt Hook Manager Class.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-hook-manager.php' );
		// Load Bolt Logger Class.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-logger.php' );
		// Load Bolt Woocommerce cart calculation Class.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-woocommerce-cart-calculation.php' );
		// Load Bolt Woocommerce checkout tracking Class.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-checkout-tracking.php' );
		// Load Bolt Metrics classes.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-metrics-client.php' );
		// Load WooCommerce Bolt Cart Functions.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/bolt-cart-functions.php' );
		// Load WooCommerce Bolt Checkout Functions.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/bolt-checkout-functions.php' );
		// Load WooCommerce Bolt Order Functions.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/bolt-order-functions.php' );
		// Load WooCommerce Bolt Helper Functions.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/bolt-conditional-functions.php' );
		// Load WooCommerce Bolt Email Functions.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/bolt-email-functions.php' );
		// Load WooCommerce Bolt Tax Functions.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/bolt-tax-functions.php' );
		// Load  WooCommerce Get Account Endpoint.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-get-account.php' );
		// Load  WooCommerce OAuthRedirect Endpoint.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-auth-redirect.php' );

		// Load Trait.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/traits/trait-bolt-controller.php' );
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/traits/trait-bolt-shipping-tax.php' );

		// Load WooCommerce Bolt Shipping Endpoint Class.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-shipping-api-handler.php' );
		// Load WooCommerce Bolt Tax Endpoint Class.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-tax-api-handler.php' );
		// Load WooCommerce Bolt JWT Class.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-jwt.php' );

		// Load Bolt Cart Data Load Test Class.
		if ( get_option( 'bolt_load_test' ) ) {
			require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-cart-data-load-test.php' );
		}

		// Load Bolt debug classes.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-debug.php' );

		$this->includes_addons_support_fuc();
	}

	/**
	 * Includes third-party addons support functions.
	 *
	 * @since  1.3.6
	 * @access private
	 */
	private function includes_addons_support_fuc() {
		// Load Functions for YITH WooCommerce Gift Cards Premium.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/third-party/yith-wc-gift-cards-premium.php' );
		// Load Support for TaxJar plugin.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/third-party/taxjar.php' );
		// Load Support for WooCommerce Smart Coupons.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/third-party/wc-smart-coupons.php' );
		// Load Support for WP rocket plugin.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/third-party/wp-rocket.php' );
		// Load Support for WooCommerce Google Analytics Pro
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/third-party/wc-google-analytics-pro.php' );
		// Load Support for WooCommerce Avatax
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/third-party/wc-avatax.php' );
		// Load Support for WooCommerce Extra Shipping Options
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/third-party/wc-extra-shipping-options.php' );
		// Load Support for WooCommerce Dynamic Pricing
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/third-party/wc-dynamic-pricing.php' );
		// Load Support for Woo Discount Rules
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/third-party/wc-discount-rules.php' );
		// Load Support for WooCommerce Conditional Shipping and Payments
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/third-party/wc-conditional-shipping-and-payments.php' );
		// Load Support for WooCommerce All Products For Subscriptions
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/third-party/wc-all-products-for-subscriptions.php' );
		// Load Support for Route App
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/third-party/routeapp.php' );
		// Load Support for WooCommerce Shipment Tracking
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/third-party/wc-shipment-tracking.php' );
		// Load Support for WooCommerce TM Extra Product Options
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/third-party/tm-woo-extra-product-options.php' );
		// Load Support for XootiX WooCommerce Side Cart
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/third-party/xoo-wsc.php' );
		// Load Support for PW WooCommerce Gift Cards
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/third-party/wc-pw-gift-cards.php' );
		// Load Support for Klaviyo
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/third-party/klaviyo.php' );
		// Load Support for Checkout for WooCommerce Side Cart
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/third-party/cfw-side-cart.php' );
		// Load Support for WooCommerce Store Credit
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/third-party/wc-store-credit.php' );
		// Load Support for WooCommerce Advanced Shipment Tracking
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/third-party/wc-advanced-shipment-tracking.php' );
		// Load Support for WooCommerce Free Gift Support
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/third-party/wc-free-gift-for-woocommerce.php' );
		// Load Support for Wholesale For WooCommerce
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/third-party/wc-wholesale-pricing.php' );
		// Load Support for WooCommerce FedEx Shipping
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/third-party/wc-shipping-fedex.php' );
		// Load Support for WooCommerce ShipperHQ
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/functions/third-party/wc-shipperhq.php' );
	}

	private function init() {
		$plugin_name                   = self::$plugin_name;
		$version                       = self::$version;
		self::$instance->plugin_admin  = new Bolt_Checkout_Admin( $plugin_name, $version );
		self::$instance->plugin_public = new Bolt_Payment_Gateway_Public();
		// Create Bolt_Checkout instance and hook into related actions and filters during woocommerce initiation.
		self::$instance->get_bolt_checkout();
	}

	/**
	 * Add the Gateway to WooCommerce
	 *
	 * @param $methods
	 *
	 * @return array
	 **/
	function register_bolt_payment_gateway( $methods ) {
		// Load Bolt Payment Gateway Class.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-payment-gateway.php' );
		$methods[] = '\BoltCheckout\Bolt_Payment_Gateway';

		return $methods;
	}

	/**
	 * Hooks.
	 *
	 * @since  1.0.0
	 * @access public
	 */
	public function init_hooks() {
		add_action( 'init', array( $this, 'load_bolt_textdomain' ) );
		add_action( 'init', array( $this, 'register_new_post_bolt_reversibly_reject_statuses' ) );
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_bolt_payment_gateway' ) );
		add_filter( 'wc_order_statuses', array( $this, 'register_new_wc_order_bolt_reversibly_reject_statuses' ) );
		add_action( 'wp_print_scripts', array( $this, 'output_bolt_order_status_styling' ) );
	}

	/**
	 * Load Plugin Text Domain
	 *
	 * Looks for the plugin translation files in certain directories and loads
	 * them to allow the plugin to be localised
	 *
	 * @return bool True on success, false on failure.
	 * @since  1.0.0
	 * @access public
	 *
	 */
	public function load_bolt_textdomain() {
		// Traditional WordPress plugin locale filter.
		$locale = apply_filters( 'plugin_locale', get_locale(), 'bolt-checkout-woocommerce' );
		$mofile = sprintf( '%1$s-%2$s.mo', 'bolt-checkout-woocommerce', $locale );
		// Setup paths to current locale file.
		$mofile_local = WC_BOLT_CHECKOUT_PLUGIN_DIR_LANGUAGES . $mofile;
		if ( file_exists( $mofile_local ) ) {
			return load_textdomain( 'bolt-checkout-woocommerce', $mofile_local );
		} else {
			// Load the default language files.
			return load_plugin_textdomain( 'bolt-checkout-woocommerce', false, WC_BOLT_CHECKOUT_PLUGIN_DIR_LANGUAGES );
		}
	}

	/**
	 * Register our custom post status, used for order status - reversibly_reject.
	 */
	public function register_new_post_bolt_reversibly_reject_statuses() {
		register_post_status( 'wc-bolt-reject', array(
			'label'                     => 'Bolt Rejected',
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Bolt Rejected <span class="count">(%s)</span>', 'Bolt Rejected<span class="count">(%s)</span>' )
		) );
	}

	/**
	 * Register our custom order status - reversibly_reject.
	 */
	public function register_new_wc_order_bolt_reversibly_reject_statuses( $order_statuses ) {
		$new_order_statuses = array();
		// add new order status after processing
		foreach ( $order_statuses as $key => $status ) {
			$new_order_statuses[ $key ] = $status;
			if ( 'wc-processing' === $key ) {
				$new_order_statuses['wc-bolt-reject'] = 'Bolt Rejected';
			}
		}

		return $new_order_statuses;
	}

	public function output_bolt_order_status_styling() {
		echo '<style type="text/css">
				/* On-Hold Dash */
				.widefat .column-order_status mark.bolt-reject::after {
					content: "\e033";
					font-family: FontAwesome;
					color: #ff0000;
				}
				.widefat .column-order_status mark.bolt-reject::after {
					font-family: WooCommerce;
					speak: none;
					font-weight: 400;
					font-variant: normal;
					text-transform: none;
					line-height: 1;
					-webkit-font-smoothing: antialiased;
					margin: 0;
					text-indent: 0;
					position: absolute;
					top: 0;
					left: 0;
					width: 100%;
					height: 100%;
					text-align: center;
				}
			</style>';
	}

	/**
	 * Get Bolt_Checkout instance.
	 *
	 * @return Bolt_Checkout
	 * @since 1.3.2
	 * @access public
	 *
	 */
	public function get_bolt_checkout() {
		return Bolt_Checkout::instance();
	}

	/**
	 * Get Bolt Payment Gateway instance.
	 *
	 * @return Bolt_Payment_Gateway
	 * @since 1.3.6
	 * @access public
	 *
	 */
	public function get_bolt_payment_gateway() {
		$payment_gateways = WC()->payment_gateways()->payment_gateways();

		return isset( $payment_gateways[ BOLT_GATEWAY_NAME ] )
			? $payment_gateways[ BOLT_GATEWAY_NAME ]
			: new Bolt_Payment_Gateway();
	}

	/**
	 * Get Bolt_settings instance.
	 *
	 * @return Bolt_Settings
	 * @since 2.0.3
	 * @access public
	 *
	 */
	public function get_bolt_settings() {
		return Bolt_Settings::instance();
	}

	/**
	 * Get Bolt settings
	 *
	 * @return Bolt settings
	 * @since 2.0.3
	 * @access public
	 *
	 */
	public function get_settings() {
		return $this->get_bolt_settings()->get_settings();
	}

	/**
	 * Get Bolt_Data_Collector instance.
	 *
	 * @return Bolt_Data_Collector
	 * @since 1.3.2
	 * @access public
	 *
	 */
	public function get_bolt_data_collector() {
		return Bolt_Data_Collector::instance();
	}

	/**
	 * Get MetricsClient instance.
	 *
	 * @return Bolt_Metrics_Client
	 * @since 2.0.13
	 * @access public
	 *
	 */
	public function get_metrics_client() {
		return Bolt_Metrics_Client::get_instance();
	}

	/**
	 * Get Bolt_API_Request instance.
	 *
	 * @return Bolt_API_Request
	 * @since 2.4.0
	 * @access public
	 *
	 */
	public function get_api_request() {
		// Load Bolt_API_Request Class when needed.
		require_once( WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/classes/class-bolt-api-request.php' );

		return Bolt_API_Request::instance();
	}

	/**
	 * Get Bolt_HTML_Handler instance.
	 *
	 * @return Bolt_HTML_Handler
	 * @since 2.10.0
	 * @access public
	 *
	 */
	public function get_html_handler() {
		return Bolt_HTML_Handler::instance();
	}


	// Methods for backward compatibility with extensions
	// TODO: remove them after update extensions

	public function bolt_api_manager() {
		return $this->get_bolt_data_collector();
	}

} //End Bolt_Gateway_Init Class.
