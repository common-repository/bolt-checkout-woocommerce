<?php
/**
 * Bolt Checkout for WooCommerce.
 *
 * @link                 https://bolt.com
 * @since                1.0.0
 * @package              WooCommerce_Bolt_Checkout
 *
 * Plugin Name:          WooCommerce Bolt Checkout
 * Plugin URI:           https://bolt.com
 * Description:          Adds Bolt Checkout for WooCommerce.
 * Version:              2.20.0
 * Author:               Bolt
 * Author URI:           https://www.bolt.com/?utm_source=partner&utm_medium=woocommerce-marketplace
 * License:              GPL-3.0
 * Text Domain:          bolt-checkout-woocommerce
 * Domain Path:          /languages
 * Requires at least:    5.0
 * Tested up to:         6.4.2
 * WC requires at least: 5.0
 * WC tested up to:      8.5.1
 */

namespace BoltCheckout;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

const BOLT_PLUGIN_NAME = 'bolt-checkout-woocommerce';
const BOLT_GATEWAY_NAME = 'wc-bolt-payment-gateway';

// TODO: remove url constants when we stop using them on extensions
const WC_BOLT_JS_TEST = 'https://connect-sandbox.bolt.com';
const WC_BOLT_JS_PROD = 'https://connect.bolt.com';

const BOLT_ENDPOINT_ID_DEFAULT      = 0;
const BOLT_ENDPOINT_ID_CREATE_ORDER = 1;
// For now Bolt core only have BOLT_ENDPOINT_ID_CREATE_ORDER defined,
// then for other endpoints we just setup a value for internal use. 
const BOLT_ENDPOINT_ID_SHIPPING_TAX  = 61;

const E_BOLT_GENERAL_ERROR               = 1;
const E_BOLT_ORDER_ALREADY_EXISTS        = 2;
const E_BOLT_CART_HAS_EXPIRED            = 3;
const E_BOLT_ITEM_PRICE_HAS_BEEN_UPDATED = 4;
const E_BOLT_OUT_OF_INVENTORY            = 5;
const E_BOLT_DISCOUNT_CANNOT_APPLY       = 6;
const E_BOLT_DISCOUNT_DOES_NOT_EXIST     = 7;
const E_BOLT_SHIPPING_EXPIRED            = 8;
const E_BOLT_WRONG_ADDRESS               = 9;
const E_BOLT_ORDER_ALREADY_FAILED        = 10;
const E_BOLT_PRODUCT_DOES_NOT_EXIST      = 11;
const E_BOLT_CART_ITEM_ADD_FAILED        = 12;
const E_BOLT_CART_ITEM_REMOVE_FAILED     = 13;


const BOLT_MAX_EXECUTION_SECONDS = 120;

const BOLT_ENDPOINT_ID_IN_OLD_FORMAT = 10;

const HTTP_STATUS_OK             = 200;
const HTTP_STATUS_UNAUTHORIZED   = 401;
const HTTP_STATUS_FORBIDDEN      = 403;
const HTTP_STATUS_UNPROCESSABLE  = 422;
const HTTP_STATUS_INTERNAL_ERROR = 500;
const HTTP_STATUS_NOT_FOUND      = 404;

const HTTP_METHOD_GET  = 'GET';
const HTTP_METHOD_POST = 'POST';

const SETTINGS_OPTION_NAME = 'woocommerce_wc-bolt-payment-gateway_settings';

/**
 * Define constants.
 * Required minimum versions, paths, urls, etc.
 *
 * @since    1.0.0
 */

if ( ! defined( 'WC_BOLT_CHECKOUT_VERSION' ) ) {
	$plugin_data = get_file_data( __FILE__, array( 'Version' => 'Version' ), false );
	define( 'WC_BOLT_CHECKOUT_VERSION', $plugin_data['Version'] );
}
if ( ! defined( 'WC_BOLT_CHECKOUT_MAIN_PATH' ) ) {
	define( 'WC_BOLT_CHECKOUT_MAIN_PATH', plugin_basename( __FILE__ ) );
}
if ( ! defined( 'WC_BOLT_CHECKOUT_PLUGIN_FILE' ) ) {
	define( 'WC_BOLT_CHECKOUT_PLUGIN_FILE', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'WC_BOLT_CHECKOUT_PLUGIN_DIR' ) ) {
	define( 'WC_BOLT_CHECKOUT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE' ) ) {
	define( 'WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE', WC_BOLT_CHECKOUT_PLUGIN_DIR . 'includes' );
}
if ( ! defined( 'WC_BOLT_CHECKOUT_PLUGIN_DIR_VIEW' ) ) {
	define( 'WC_BOLT_CHECKOUT_PLUGIN_DIR_VIEW', WC_BOLT_CHECKOUT_PLUGIN_DIR_INCLUDE . '/view' );
}
if ( ! defined( 'WC_BOLT_CHECKOUT_PLUGIN_DIR_LANGUAGES' ) ) {
	define( 'WC_BOLT_CHECKOUT_PLUGIN_DIR_LANGUAGES', WC_BOLT_CHECKOUT_PLUGIN_DIR . 'languages/' );
}
if ( ! defined( 'WC_BOLT_CHECKOUT_PLUGIN_URL' ) ) {
	define( 'WC_BOLT_CHECKOUT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * Loads WooCommerce Bolt Checkout.
 */
function bolt_gateway_initiate() {
	$active_plugins = (array) get_option( 'active_plugins', array() );
	// Check if woocommerce is installed or not.
	if ( ! defined( 'WC_VERSION' ) && in_array( WC_BOLT_CHECKOUT_MAIN_PATH, $active_plugins ) ) {
		// Deactive plugin if woocommerce is not activated.
		$key = array_search( (array) WC_BOLT_CHECKOUT_MAIN_PATH, $active_plugins );
		unset( $active_plugins[ $key ] );
		update_option( 'active_plugins', $active_plugins );
		add_action( 'admin_notices', '\BoltCheckout\wc_bolt_chekcout_dependence_notice' );
	} else {
		// Detect if *WP REST API* is activated
		// When the *WP REST API* is activated on the merchant site, it would rewrite rules for the API, and response in specific json format.
		// So we need to register api endpoint and process response in its way
		define( 'WC_BOLT_WP_REST_API_ADDON', ( has_action( 'init', 'json_api_init' ) && defined( 'JSON_API_VERSION' ) ) );

		require 'includes/classes/class-bolt-gateway-init.php';

		// Get the Bolt Payment Gateway class instance.
		add_action( 'woocommerce_init', '\BoltCheckout\Bolt_Gateway_Init::get_instance' );
	}

}

add_action( 'plugins_loaded', '\BoltCheckout\bolt_gateway_initiate' );

/**
 * WooCommerce Bolt Checkout fallback notice.
 *
 * @since  2.0.2
 */
function wc_bolt_chekcout_dependence_notice() {
	echo '<div class="error"><p><strong>' . __( "<strong>WooCommerce Bolt Checkout</strong> requires <a href='https://wordpress.org/plugins/woocommerce/' target='_blank'><strong>WooCommerce</strong></a> in order to work.   <a href='" . get_admin_url( null, 'plugins.php' ) . "'><strong>Plugins page >></strong></a>", 'bolt-checkout-woocommerce' ) . '</strong></p></div>';
}

/**
 * Returns false if the request is a Bolt REST API request,
 * so the WC would load related resource.
 * Refer to WC PR https://github.com/woocommerce/woocommerce/pull/21090
 *
 * @return bool
 * @since  2.0.1
 */
function wc_bolt_is_rest_api_request( $is_rest_api_request ) {
	if ( false !== strpos( $_SERVER['REQUEST_URI'], '/bolt/' ) ) {
		// Bolt API request, pretend it's not REST endpoint so we can load resource
		return false;
	} else {
		return $is_rest_api_request;
	}
}

add_filter( 'woocommerce_is_rest_api_request', '\BoltCheckout\wc_bolt_is_rest_api_request', 999 );

/**
 * Main instance of WooCommerce Bolt Payment Gateway.
 *
 * Returns the main instance of WooCommerce Bolt Payment Gateway to prevent the need to use globals.
 *
 * @return Bolt_Gateway_Init
 * @since  1.3.2
 */
function wc_bolt() {
	return Bolt_Gateway_Init::get_instance();
}

/**
 * To declare the Bolt plugin compatible with HPOS.
 *
 * @since  2.20.0
 */
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );