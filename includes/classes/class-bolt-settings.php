<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bolt_Settings
 *
 */
class Bolt_Settings {

	/**
	 * The single instance of the class.
	 *
	 * @since 2.0.3
	 * @var Bolt_Settings|null
	 */
	private static $instance = null;

	/**
	 * @since 2.0.3
	 * @var array Bolt settings.
	 */
	private $settings;

	/**
	 * Gets Bolt_Settings Instance.
	 *
	 * @return Bolt_Settings Instance
	 * @since 2.0.3
	 * @static
	 *
	 */
	// setting names
	const SETTING_NAME_ENABLED = 'enabled';
	const SETTING_NAME_SANDBOX_MODE = 'testmode';
	const SETTING_NAME_API_DETAILS = 'api_details';
	const SETTING_NAME_MERCHANT_KEY = 'merchant_key';
	const SETTING_NAME_PAYMENT_SECRET_KEY = 'payment_secret_key';
	const SETTING_NAME_PUBLISHABLE_KEY_MULTISTEP = 'quick_payment_secret_key';
	const SETTING_NAME_PUBLISHABLE_KEY_PAYMENTONLY = 'processing_key';
	const SETTING_NAME_PUBLISHABLE_KEY_BACKOFFICE = 'backoffice_order_key';
	const SETTING_NAME_WHERE_TO_ADD_BOLT = 'where_to_add_bolt';
	const SETTING_NAME_ENABLE_SHOPPING_CART = 'enable_shopping_cart';
	const SETTING_NAME_ENABLE_MINI_CART = 'enable_mini_cart';
	const SETTING_NAME_ENABLE_CHECKOUT_PAGE = 'enable_checkout_page';
	const SETTING_NAME_ENABLE_ORDER_PAY = 'enable_order_pay';
	const SETTING_NAME_HIDE_DEFAULT_CHECKOUT_BUTTONS = 'hide_default_checkout_buttons';
	const SETTING_NAME_URL_CONFIGURATIONS = 'url_configurations';
	const SETTING_NAME_ADDITIONAL_OPTIONS = 'additional_options';
	const SETTING_NAME_PRODUCT_PAGE_CHECKOUT = 'quick_checkout';
	const SETTING_NAME_SUBSCRIPTION = 'subscription';
	const SETTING_NAME_BOLT_BUTTON_COLOR = 'bolt_button_color';
	const SETTING_NAME_ADDITIONAL_CSS = 'additional_css';
	const SETTING_NAME_ADVANCED_SETTINGS = 'advanced_settings';
	const SETTING_NAME_PAYMENT_METHOD_TITLE = 'title';
	const SETTING_NAME_PAYMENT_METHOD_DESCRIPTION = 'description';
	const SETTING_NAME_BOLT_MERCHANT_SCOPE = 'bolt_merchant_scope';
	const SETTING_NAME_PAYMENT_FIELD_BUTTON_CLASS = 'payment_field_button_class';
	const SETTING_NAME_SHOPPING_CART_BUTTON_CLASS = 'shopping_cart_button_class';
	const SETTING_NAME_MINI_CART_BUTTON_CLASS = 'mini_cart_button_class';
	const SETTING_NAME_PPC_BUTTON_CLASS = 'ppc_button_class';
	const SETTING_NAME_SUBSCRIPTION_BUTTON_CLASS = 'subscription_button_class';
	const SETTING_NAME_ALLOW_SHIPPING_POBOX = 'allow_shipping_pobox';
	const SETTING_NAME_ENABLE_ABANDONDED_CART = 'enable_abandonded_cart';
	const SETTING_NAME_ABANDONDED_CART_KEY = 'abandonded_cart_key';
	const SETTING_NAME_REQUIRED_LOGIN_MESSAGE = 'required_login_message';
	const SETTING_NAME_CLEAN_UP_BOLT_SESSION_PERIOD = 'clean_up_bolt_session_period';
	const SETTING_NAME_DISABLE_WC_STATE_VALIDATION = 'disable_wc_state_validation';
	const SETTING_NAME_DISPLAY_NOTICES_SELECTOR = 'display_notices_selector';
	const SETTING_NAME_SEVERITY_LEVEL = 'severity_level';
	const SETTING_NAME_ENABLE_BOLT_CHECKOUT_ANALYTICS = 'enable_bolt_checkout_analytics';
	const SETTING_NAME_JAVASCRIPT = 'javascript';
	const SETTING_NAME_JAVASCRIPT_CHECK = 'javascript_check';
	const SETTING_NAME_JAVASCRIPT_SUCCESS = 'javascript_success';
	const SETTING_NAME_JAVASCRIPT_CLOSE = 'javascript_close';
	const SETTING_NAME_JAVASCRIPT_ADDITIONAL = 'javascript_additional';
	const SETTING_NAME_JAVASCRIPT_ONEMAILENTER = 'javascript_onemailenter';
	const SETTING_NAME_JAVASCRIPT_ONCHECKOUTSTART = 'javascript_oncheckoutstart';
	const SETTING_NAME_JAVASCRIPT_ONSHIPPINGDETAILSCOMPLETE = 'javascript_onshippingdetailscomplete';
	const SETTING_NAME_JAVASCRIPT_ONSHIPPINGOPTIONSCOMPLETE = 'javascript_onshippingoptionscomplete';
	const SETTING_NAME_JAVASCRIPT_ONPAYMENTSUBMIT = 'javascript_onpaymentsubmit';
	const SETTING_NAME_CUSTOM_API = 'custom_api';
	const SETTING_NAME_CUSTOM_MERCHANT_DASH = 'custom_merchant_dash';
	const SETTING_NAME_CUSTOM_CDN = 'custom_merchant_cdn';
	const SETTING_NAME_ENABLE_ORDER_MANAGEMENT = 'enable_order_management';
	const SETTING_NAME_ENABLE_SSO = 'enable_sso';
	const SETTING_NAME_CUSTOM_ACCOUNT = 'custom_account';


	// setting key names
	const KEY_TITLE = 'title';
	const KEY_TYPE = 'type';
	const KEY_LABEL = 'label';
	const KEY_CLASS = 'class';
	const KEY_DESCRIPTION = 'description';
	const KEY_DESC_TIP = 'desc_tip';
	const KEY_OPTIONS = 'options';
	const KEY_PLACEHOLDER = 'placeholder';
	const KEY_DISABLED = 'disabled';
	const KEY_CSS = 'css';
	const KEY_CUSTOM_ATTRIBUTES = 'custom_attributes';
	const KEY_DEFAULT = 'default';

	// field type names
	const TYPE_CHECKBOX = 'checkbox';
	const TYPE_TITLE = 'title';
	const TYPE_TEXT = 'text';
	const TYPE_SELECT = 'select';
	const TYPE_JAVASCRIPT = 'javascript';

	// values
	const VALUE_YES = 'yes';
	const VALUE_NO = 'no';

	// Bolt urls
	const BOLT_API_HOST_SANDBOX = 'https://api-sandbox.bolt.com';
	const BOLT_API_HOST_PROD = 'https://api.bolt.com';

	const BOLT_CDN_HOST_SANDBOX = 'https://connect-sandbox.bolt.com';
	const BOLT_CDN_HOST_PROD = 'https://connect.bolt.com';

	const BOLT_MERCHANT_DASHBOARD_HOST_SANDBOX = "https://merchant-sandbox.bolt.com";
	const BOLT_MERCHANT_DASHBOARD_HOST_PROD = "https://merchant.bolt.com";

	const BOLT_ACCOUNT_HOST_SANDBOX = 'https://account-sandbox.bolt.com';
	const BOLT_ACCOUNT_HOST_PROD = 'https://account.bolt.com';

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Bolt_settings constructor.
	 *
	 * @since 2.0.3
	 */
	public function __construct() {
		$this->read_settings();
		BugsnagHelper::$is_sandbox_mode = $this->is_sandbox_mode();

		add_action( 'update_option_' . SETTINGS_OPTION_NAME, array( $this, 'after_settings_update' ), 10, 3 );
	}

	/**
	 * Read Bolt settings from database.
	 *
	 * @since 2.0.3
	 */
	public function read_settings() {
		$settings       = get_option( SETTINGS_OPTION_NAME, array() );
		$this->settings = array_merge( $this->get_default_settings(), $settings );
		// Few settings can be overwritten via feature switches
		if ( $this->is_setting_enabled( self::SETTING_NAME_ENABLED )
		     && ! Bolt_Feature_Switch::instance()->is_bolt_enabled() ) {
			$this->settings[ self::SETTING_NAME_ENABLED ] = self::VALUE_NO;
		}
	}

	/**
	 * Return Bolt settings
	 *
	 * @since 2.0.3
	 */
	public function get_settings() {
		return $this->settings;
	}

	/**
	 * Generate default settings by Form Fields array
	 *
	 * @since 2.0.3
	 */
	public function get_default_settings() {

		return array(
			self::SETTING_NAME_ENABLED                              => self::VALUE_YES,
			self::SETTING_NAME_SANDBOX_MODE                         => self::VALUE_NO,
			self::SETTING_NAME_MERCHANT_KEY                         => '',
			self::SETTING_NAME_PAYMENT_SECRET_KEY                   => '',
			self::SETTING_NAME_PUBLISHABLE_KEY_MULTISTEP            => '',
			self::SETTING_NAME_PUBLISHABLE_KEY_PAYMENTONLY          => '',
			self::SETTING_NAME_PUBLISHABLE_KEY_BACKOFFICE           => '',
			self::SETTING_NAME_ENABLE_SHOPPING_CART                 => self::VALUE_YES,
			self::SETTING_NAME_ENABLE_MINI_CART                     => self::VALUE_NO,
			self::SETTING_NAME_ENABLE_CHECKOUT_PAGE                 => self::VALUE_NO,
			self::SETTING_NAME_ENABLE_ORDER_PAY                     => self::VALUE_NO,
			self::SETTING_NAME_PRODUCT_PAGE_CHECKOUT                => self::VALUE_NO,
			self::SETTING_NAME_HIDE_DEFAULT_CHECKOUT_BUTTONS        => self::VALUE_YES,
			self::SETTING_NAME_BOLT_BUTTON_COLOR                    => '',
			self::SETTING_NAME_ADDITIONAL_CSS                       => '',
			self::SETTING_NAME_PAYMENT_METHOD_TITLE                 => __( 'Credit or Debit Card', 'bolt-checkout-woocommerce' ),
			self::SETTING_NAME_PAYMENT_METHOD_DESCRIPTION           => __( 'Pay via Bolt', 'bolt-checkout-woocommerce' ),
			self::SETTING_NAME_BOLT_MERCHANT_SCOPE                  => self::VALUE_NO,
			self::SETTING_NAME_PAYMENT_FIELD_BUTTON_CLASS           => '',
			self::SETTING_NAME_SHOPPING_CART_BUTTON_CLASS           => 'bolt-multi-step-checkout with-cards',
			self::SETTING_NAME_MINI_CART_BUTTON_CLASS               => 'bolt-multi-step-checkout with-cards',
			self::SETTING_NAME_PPC_BUTTON_CLASS                     => 'bolt-multi-step-checkout with-cards',
			self::SETTING_NAME_ALLOW_SHIPPING_POBOX                 => self::VALUE_YES,
			self::SETTING_NAME_ENABLE_ABANDONDED_CART               => self::VALUE_NO,
			self::SETTING_NAME_ABANDONDED_CART_KEY                  => '',
			self::SETTING_NAME_REQUIRED_LOGIN_MESSAGE               => __( "You must be registered and logged in to make this order.", 'bolt-checkout-woocommerce' ),
			self::SETTING_NAME_CLEAN_UP_BOLT_SESSION_PERIOD         => '72',
			self::SETTING_NAME_DISABLE_WC_STATE_VALIDATION          => self::VALUE_NO,
			self::SETTING_NAME_DISPLAY_NOTICES_SELECTOR             => '.entry-header',
			self::SETTING_NAME_SEVERITY_LEVEL                       => 3,
			self::SETTING_NAME_ENABLE_BOLT_CHECKOUT_ANALYTICS       => self::VALUE_NO,
			self::SETTING_NAME_JAVASCRIPT_CHECK                     => '',
			self::SETTING_NAME_JAVASCRIPT_SUCCESS                   => '',
			self::SETTING_NAME_JAVASCRIPT_CLOSE                     => '',
			self::SETTING_NAME_JAVASCRIPT_ADDITIONAL                => '',
			self::SETTING_NAME_JAVASCRIPT_ONEMAILENTER              => '',
			self::SETTING_NAME_JAVASCRIPT_ONCHECKOUTSTART           => '',
			self::SETTING_NAME_JAVASCRIPT_ONSHIPPINGDETAILSCOMPLETE => '',
			self::SETTING_NAME_JAVASCRIPT_ONSHIPPINGOPTIONSCOMPLETE => '',
			self::SETTING_NAME_JAVASCRIPT_ONPAYMENTSUBMIT           => '',
			self::SETTING_NAME_CUSTOM_API                           => '',
			self::SETTING_NAME_CUSTOM_MERCHANT_DASH                 => '',
			self::SETTING_NAME_CUSTOM_CDN                           => '',
			self::SETTING_NAME_ENABLE_ORDER_MANAGEMENT              => self::VALUE_NO,
			self::SETTING_NAME_ENABLE_SSO                           => self::VALUE_NO,
			self::SETTING_NAME_CUSTOM_ACCOUNT                       => '',

		);
	}

	/**
	 * Return list of hidden settings
	 *
	 * @since 2.8.0
	 */
	public function get_hidden_settings() {
		return array(
			self::SETTING_NAME_CUSTOM_API,
			self::SETTING_NAME_CUSTOM_MERCHANT_DASH,
			self::SETTING_NAME_CUSTOM_CDN,
			self::SETTING_NAME_CUSTOM_ACCOUNT
		);
	}

	public function get_disable_settings() {
		return array(
			self::SETTING_NAME_SUBSCRIPTION,
			self::SETTING_NAME_SUBSCRIPTION_BUTTON_CLASS
		);
	}

	/**
	 * Create array with Form Fields for class Bolt_Payment_Gateway
	 *
	 * @since 2.0.3
	 */
	public function get_form_fields() {

		/**
		 * Settings for Bolt Checkout plugin.
		 */
		$rest_url    = get_rest_url();
		$form_fields = array(
			self::SETTING_NAME_ENABLED                              => array(
				self::KEY_TITLE => __( 'Enabled', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE  => self::TYPE_CHECKBOX,
				self::KEY_LABEL => __( 'Enable Bolt Checkout', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_SANDBOX_MODE                         => array(
				self::KEY_TITLE => __( 'Sandbox Mode', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE  => self::TYPE_CHECKBOX,
				self::KEY_LABEL => __( 'Enable Sandbox for Testing', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_API_DETAILS                          => array(
				self::KEY_TITLE       => __( 'Keys', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_TITLE,
				self::KEY_DESCRIPTION => __( 'Keys and URL Configurations can be found at <a href="https://merchant.bolt.com/settings">merchant.bolt.com/settings</a> or <a href="https://merchant-sandbox.bolt.com/settings">merchant-sandbox.bolt.com/settings</a>.', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_MERCHANT_KEY                         => array(
				self::KEY_TITLE       => __( 'API Key', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_TEXT,
				self::KEY_DESCRIPTION => __( 'Used for calling Bolt API from your backend server.', 'bolt-checkout-woocommerce' ),
				self::KEY_DESC_TIP    => true,
				self::KEY_PLACEHOLDER => __( 'Enter API Key', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_PAYMENT_SECRET_KEY                   => array(
				self::KEY_TITLE       => __( 'Signing Secret', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_TEXT,
				self::KEY_DESCRIPTION => __( 'Used for signature verification', 'bolt-checkout-woocommerce' ),
				self::KEY_DESC_TIP    => true,
				self::KEY_PLACEHOLDER => __( 'Enter Signing Secret', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_PUBLISHABLE_KEY_MULTISTEP            => array(
				self::KEY_TITLE       => __( 'Publishable Key - Multistep', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_TEXT,
				self::KEY_DESCRIPTION => __( 'Used by Bolt JavaScript to display the Multistep Experience', 'bolt-checkout-woocommerce' ),
				self::KEY_DESC_TIP    => true,
				self::KEY_PLACEHOLDER => __( 'Enter Publishable Key - Multistep', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_PUBLISHABLE_KEY_PAYMENTONLY          => array(
				self::KEY_TITLE       => __( 'Publishable Key - Payment Only', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_TEXT,
				self::KEY_DESCRIPTION => __( 'Used by Bolt JavaScript to display the Payment Only Experience', 'bolt-checkout-woocommerce' ),
				self::KEY_DESC_TIP    => true,
				self::KEY_PLACEHOLDER => __( 'Leave Blank for Standard Configuration', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_PUBLISHABLE_KEY_BACKOFFICE           => array(
				self::KEY_TITLE       => __( 'Publishable Key - Backoffice Order', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_TEXT,
				self::KEY_DESCRIPTION => __( 'Used by Bolt JavaScript to display the Backoffice Order Experience', 'bolt-checkout-woocommerce' ),
				self::KEY_DESC_TIP    => true,
				self::KEY_PLACEHOLDER => __( 'Leave Blank for Standard Configuration', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_WHERE_TO_ADD_BOLT                    => array(
				self::KEY_TITLE => __( 'Where to add Bolt?', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE  => self::TYPE_TITLE,
			),
			self::SETTING_NAME_ENABLE_SHOPPING_CART                 => array(
				self::KEY_TITLE => __( 'Cart Page', 'bolt-checkout-woocommerce' ),
				self::KEY_LABEL => __( 'Display Bolt Checkout on the Cart Page', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE  => self::TYPE_CHECKBOX,
			),
			self::SETTING_NAME_ENABLE_MINI_CART                     => array(
				self::KEY_TITLE => __( 'Mini Cart', 'bolt-checkout-woocommerce' ),
				self::KEY_LABEL => __( 'Display Bolt Checkout in the Mini Cart', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE  => self::TYPE_CHECKBOX,
			),
			self::SETTING_NAME_ENABLE_CHECKOUT_PAGE                 => array(
				self::KEY_TITLE => __( 'Native Checkout Page', 'bolt-checkout-woocommerce' ),
				self::KEY_LABEL => __( 'Display Bolt Checkout in the Native Checkout Page', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE  => self::TYPE_CHECKBOX,
			),
			self::SETTING_NAME_ENABLE_ORDER_PAY                     => array(
				self::KEY_TITLE => __( 'Pay for Order', 'bolt-checkout-woocommerce' ),
				self::KEY_LABEL => __( 'Display Bolt Checkout on the Order Pay Page', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE  => self::TYPE_CHECKBOX,
			),
			self::SETTING_NAME_HIDE_DEFAULT_CHECKOUT_BUTTONS        => array(
				self::KEY_TITLE => __( 'Hide default button', 'bolt-checkout-woocommerce' ),
				self::KEY_LABEL => __( 'Hide default checkout button on cart page and on minicart', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE  => self::TYPE_CHECKBOX,
			),
			self::SETTING_NAME_URL_CONFIGURATIONS                   => array(
				self::KEY_TITLE       => __( 'URL Configurations', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_TITLE,
				self::KEY_DESCRIPTION => sprintf( __( 'Webhook: <a href="%s">%s</a><br>Shipping and Tax: <a href="%s">%s</a><br>Create order: <a href="%s">%s</a>', 'bolt-checkout-woocommerce' ),
					$rest_url . 'bolt/response',
					$rest_url . 'bolt/response',
					$rest_url . 'bolt/shippingtax',
					$rest_url . 'bolt/shippingtax',
					$rest_url . 'bolt/create-order',
					$rest_url . 'bolt/create-order' ),
			),
			self::SETTING_NAME_ADDITIONAL_OPTIONS                   => array(
				self::KEY_TITLE => __( 'Additional Options', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE  => self::TYPE_TITLE,
			),
			self::SETTING_NAME_PRODUCT_PAGE_CHECKOUT                => array(
				self::KEY_TITLE => __( 'Product Page Checkout', 'bolt-checkout-woocommerce' ),
				self::KEY_LABEL => __( 'Enable Bolt Checkout on Product Pages', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE  => self::TYPE_CHECKBOX,
			),
			self::SETTING_NAME_SUBSCRIPTION                         => array(
				self::KEY_TITLE => __( 'Subscriptions', 'bolt-checkout-woocommerce' ),
				self::KEY_LABEL => __( 'Enable Bolt Subscriptions', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE  => self::TYPE_CHECKBOX,
			),
			self::SETTING_NAME_BOLT_BUTTON_COLOR                    => array(
				self::KEY_TITLE       => __( 'Primary Color', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_TEXT,
				self::KEY_DESCRIPTION => __( 'The primary color to be used for the Bolt Checkout button and modal styling.', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_ADDITIONAL_CSS                       => array(
				self::KEY_TITLE       => __( 'Additional CSS', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_JAVASCRIPT,
				self::KEY_DESCRIPTION => __( 'This CSS will be added to any page that displays the Bolt Checkout button.', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_ADVANCED_SETTINGS                    => array(
				self::KEY_TITLE => __( 'Configure Advanced Settings', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE  => self::TYPE_TITLE,
			),
			self::SETTING_NAME_PAYMENT_METHOD_TITLE                 => array(
				self::KEY_TITLE       => __( 'Title in Checkout Page', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_TEXT,
				self::KEY_DESCRIPTION => __( 'This controls the title which the user sees during checkout. (Nonstandard)', 'bolt-checkout-woocommerce' ),
				self::KEY_DESC_TIP    => true,
			),
			self::SETTING_NAME_PAYMENT_METHOD_DESCRIPTION           => array(
				self::KEY_TITLE       => __( 'Description in Checkout Page', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_TEXT,
				self::KEY_DESC_TIP    => true,
				self::KEY_DESCRIPTION => __( 'This controls the description which the user sees during checkout.', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_BOLT_MERCHANT_SCOPE                  => array(
				self::KEY_TITLE => __( 'Merchant Scope Account', 'bolt-checkout-woocommerce' ),
				self::KEY_LABEL => __( 'Enable Merchant Scope Account', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE  => self::TYPE_CHECKBOX,
			),
			self::SETTING_NAME_PAYMENT_FIELD_BUTTON_CLASS           => array(
				self::KEY_TITLE       => __( 'CSS class for Bolt button of checkout payment field', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_TEXT,
				self::KEY_PLACEHOLDER => __( 'Enter CSS Class', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_SHOPPING_CART_BUTTON_CLASS           => array(
				self::KEY_TITLE       => __( 'CSS class for Bolt button of shopping cart', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_TEXT,
				self::KEY_PLACEHOLDER => __( 'Enter CSS Class', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_MINI_CART_BUTTON_CLASS               => array(
				self::KEY_TITLE       => __( 'CSS class for Bolt button of mini cart', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_TEXT,
				self::KEY_PLACEHOLDER => __( 'Enter CSS Class', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_PPC_BUTTON_CLASS                     => array(
				self::KEY_TITLE       => __( 'CSS class for Bolt button of product page checkout', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_TEXT,
				self::KEY_PLACEHOLDER => __( 'Enter CSS Class', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_SUBSCRIPTION_BUTTON_CLASS            => array(
				self::KEY_TITLE       => __( 'CSS class for Bolt button of subscription checkout', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_TEXT,
				self::KEY_PLACEHOLDER => __( 'Enter CSS Class', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_ALLOW_SHIPPING_POBOX                 => array(
				self::KEY_TITLE => __( 'Shipping to PO Box', 'bolt-checkout-woocommerce' ),
				self::KEY_LABEL => __( 'Allow shipping to PO Box', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE  => self::TYPE_CHECKBOX,
			),
			self::SETTING_NAME_ENABLE_ABANDONDED_CART               => array(
				self::KEY_TITLE       => __( 'Sync Abandonded Cart Emails with Woocommerce', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_SELECT,
				self::KEY_CLASS       => 'wc-enhanced-select',
				self::KEY_DESCRIPTION => __( 'Choose the abandonded cart service provider.', 'bolt-checkout-woocommerce' ),
				self::KEY_DESC_TIP    => true,
				self::KEY_OPTIONS     => array(
					'no'      => __( 'None', 'bolt-checkout-woocommerce' ),
					// we assign 'no' here is for backwards compatibility.
					'klaviyo' => __( 'Klaviyo', 'bolt-checkout-woocommerce' ),
					'other'   => __( 'Other', 'bolt-checkout-woocommerce' ),
				),
			),
			self::SETTING_NAME_ABANDONDED_CART_KEY                  => array(
				self::KEY_TITLE       => __( 'Abandonded Cart API Key', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_TEXT,
				self::KEY_DESCRIPTION => __( 'API key / Site ID provided by the abandonded cart service.', 'bolt-checkout-woocommerce' ),
				self::KEY_DESC_TIP    => true,
				self::KEY_PLACEHOLDER => __( 'Enter Api Key', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_REQUIRED_LOGIN_MESSAGE               => array(
				self::KEY_TITLE       => __( 'Login required message', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_TEXT,
				self::KEY_DESCRIPTION => __( 'This message is displayed to users on the Quick Buy and shopping cart pages if registration and login is enforced by the store.', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_CLEAN_UP_BOLT_SESSION_PERIOD         => array(
				self::KEY_TITLE       => __( 'Session Lifetime (in hours)', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_TEXT,
				self::KEY_DESCRIPTION => __( 'Defines the amount of time a Bolt session lives before being cleared.', 'bolt-checkout-woocommerce' ),
				self::KEY_DESC_TIP    => true,
			),
			self::SETTING_NAME_DISABLE_WC_STATE_VALIDATION          => array(
				self::KEY_TITLE => __( 'State field validation', 'bolt-checkout-woocommerce' ),
				self::KEY_LABEL => __( 'Bypass WooCommerce checkout validation on state field', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE  => self::TYPE_CHECKBOX,
			),
			self::SETTING_NAME_DISPLAY_NOTICES_SELECTOR             => array(
				self::KEY_TITLE       => __( 'Displace Notices Selector', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_TEXT,
				self::KEY_DESCRIPTION => __( 'The CSS class selector matches element to show the notices.', 'bolt-checkout-woocommerce' ),
				self::KEY_DESC_TIP    => true,
				self::KEY_PLACEHOLDER => __( 'Enter Displace Notices Selector', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_SEVERITY_LEVEL                       => array(
				self::KEY_TITLE       => __( 'Severity Level', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_SELECT,
				self::KEY_CLASS       => 'wc-enhanced-select',
				self::KEY_DESCRIPTION => __( 'Errors are assigned a severity based on their impact.', 'bolt-checkout-woocommerce' ),
				self::KEY_DESC_TIP    => true,
				self::KEY_OPTIONS     => array(
					1 => __( 'Error', 'bolt-checkout-woocommerce' ),
					2 => __( 'Warning', 'bolt-checkout-woocommerce' ),
					3 => __( 'Info', 'bolt-checkout-woocommerce' ),
				),
			),
			self::SETTING_NAME_ENABLE_BOLT_CHECKOUT_ANALYTICS       => array(
				self::KEY_TITLE => __( 'Enable Bolt checkout analytics', 'bolt-checkout-woocommerce' ),
				self::KEY_LABEL => __( 'Track checkout funnel with WooCommerce', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE  => self::TYPE_CHECKBOX,
			),
			self::SETTING_NAME_ENABLE_ORDER_MANAGEMENT              => array(
				self::KEY_TITLE       => __( '(BETA) Enable Bolt order management', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_CHECKBOX,
				self::KEY_DESCRIPTION => __( 'Allow users to view their past orders, track shipping, and display support information.', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_JAVASCRIPT                           => array(
				self::KEY_TITLE       => __( 'JavaScript', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_TITLE,
				self::KEY_DESCRIPTION => '',
			),
			self::SETTING_NAME_JAVASCRIPT_CHECK                     => array(
				self::KEY_TITLE       => __( 'Javascript event: check', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_JAVASCRIPT,
				self::KEY_DESCRIPTION => __( 'This function is called just before the checkout form loads. This is a hook to determine whether Bolt can actually proceed with checkout at this point. This function should return a boolean.', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_JAVASCRIPT_SUCCESS                   => array(
				self::KEY_TITLE       => __( 'Javascript event: success', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_JAVASCRIPT,
				self::KEY_DESCRIPTION => __( 'This function is called when the Bolt checkout transaction is successful.', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_JAVASCRIPT_CLOSE                     => array(
				self::KEY_TITLE       => __( 'Javascript event: close', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_JAVASCRIPT,
				self::KEY_DESCRIPTION => __( 'This function is called when the Bolt checkout modal is closed.', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_JAVASCRIPT_ADDITIONAL                => array(
				self::KEY_TITLE       => __( 'Additional Javascript', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_JAVASCRIPT,
				self::KEY_DESCRIPTION => __( 'This javascript is added to any page that has a Bolt checkout button.', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_JAVASCRIPT_ONEMAILENTER              => array(
				self::KEY_TITLE       => __( 'Javascript event: onEmailEnter', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_JAVASCRIPT,
				self::KEY_DESCRIPTION => __( 'The javascript code executed when user enters or changes email.', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_JAVASCRIPT_ONCHECKOUTSTART           => array(
				self::KEY_TITLE       => __( 'Javascript event: onCheckoutStart', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_JAVASCRIPT,
				self::KEY_DESCRIPTION => __( 'The javascript code executed when checkout modal pops up and the shipping details form is presented to the user.', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_JAVASCRIPT_ONSHIPPINGDETAILSCOMPLETE => array(
				self::KEY_TITLE       => __( 'Javascript event: onShippingDetailsComplete', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_JAVASCRIPT,
				self::KEY_DESCRIPTION => __( 'The javascript code executed when the user proceeds to the shipping options tab.', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_JAVASCRIPT_ONSHIPPINGOPTIONSCOMPLETE => array(
				self::KEY_TITLE       => __( 'Javascript event: onShippingOptionsComplete', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_JAVASCRIPT,
				self::KEY_DESCRIPTION => __( 'The javascript code executed when the user proceeds to the payment details tab.', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_JAVASCRIPT_ONPAYMENTSUBMIT           => array(
				self::KEY_TITLE       => __( 'Javascript event: onPaymentSubmit', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE        => self::TYPE_JAVASCRIPT,
				self::KEY_DESCRIPTION => __( 'The javascript code executed when the user clicks the pay button.', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_CUSTOM_API                           => array(
				self::KEY_TITLE => __( 'Custom API URL (dev only)', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE  => self::TYPE_TEXT,
			),
			self::SETTING_NAME_CUSTOM_MERCHANT_DASH                 => array(
				self::KEY_TITLE => __( 'Custom merchant dashboard URL (dev only)', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE  => self::TYPE_TEXT,
			),
			self::SETTING_NAME_CUSTOM_CDN                           => array(
				self::KEY_TITLE => __( 'Custom merchant CDN URL (dev only)', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE  => self::TYPE_TEXT,
			),
			self::SETTING_NAME_ENABLE_SSO                           => array(
				self::KEY_TITLE => __( 'Enabled SSO', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE  => self::TYPE_CHECKBOX,
				self::KEY_LABEL => __( 'Enable Bolt SSO', 'bolt-checkout-woocommerce' ),
			),
			self::SETTING_NAME_CUSTOM_ACCOUNT                       => array(
				self::KEY_TITLE => __( 'Custom ACCOUNT URL (dev only)', 'bolt-checkout-woocommerce' ),
				self::KEY_TYPE  => self::TYPE_TEXT,
			),
		);
		foreach ( $this->get_default_settings() as $setting_name => $default_value ) {
			$form_fields[ $setting_name ][ self::KEY_DEFAULT ] = $default_value;
		}
		foreach ( $this->get_hidden_settings() as $setting_name ) {
			if ( empty( $this->settings[ $setting_name ] ) ) {
				unset( $form_fields[ $setting_name ] );
			}
		}
		foreach ( $this->get_disable_settings() as $setting_name ) {
			unset( $form_fields[ $setting_name ] );
		}

		return $form_fields;
	}

	/**
	 * Check if sandbox or not.
	 *
	 * @return bool
	 * @since 2.0.3
	 */
	public function is_sandbox_mode() {
		// Return the testmode value.
		return ( self::VALUE_YES === $this->settings[ self::SETTING_NAME_SANDBOX_MODE ] );
	}

	/**
	 * Check if setting is enabled
	 *
	 * @param string $setting_name
	 *
	 * @return bool
	 * @since 2.0.3
	 *
	 */
	public function is_setting_enabled( $setting_name ) {
		return self::VALUE_YES === $this->settings[ $setting_name ];
	}

	/**
	 * Actions after updating Bolt settings
	 * We need to reload settings as well as update feature switches if necessary
	 *
	 * @param $old_value
	 * @param $value
	 * @param $option
	 */
	public function after_settings_update( $old_value, $value, $option ) {
		$old_settings = $this->settings;
		$this->read_settings();
		// If Merchant key is just updated probably it was wrong or empty before
		// We try to receive new features value.
		if ( ! empty( $this->settings[ SELF::SETTING_NAME_MERCHANT_KEY ] ) &&
		     $this->settings[ SELF::SETTING_NAME_MERCHANT_KEY ] != $old_settings[ SELF::SETTING_NAME_MERCHANT_KEY ] ) {
			WC_Bolt_Install::update_feature_switches();
		}
	}

	/**
	 * Returns setting value if it doesn't empty and default value otherwise
	 * Removes trailing slash from the default value
	 *
	 * @param $setting_name
	 * @param $default
	 *
	 * @return string
	 * @since 2.8.0
	 *
	 */
	private function get_custom_url_value_or_default( $setting_name, $default ) {
		$setting_value = $this->settings[ $setting_name ];

		return ! empty( $setting_value ) ? rtrim( $setting_value, '/' ) : rtrim( $default );
	}

	/**
	 * Returns the Bolt Merchant Dashboard URL
	 *
	 * @return string   The appropriate sandbox or live merchant dashboard host URL
	 */
	public function get_merchant_dashboard_host() {
		if ( $this->is_sandbox_mode() ) {
			return $this->get_custom_url_value_or_default( SELF::SETTING_NAME_CUSTOM_MERCHANT_DASH, self::BOLT_MERCHANT_DASHBOARD_HOST_SANDBOX );
		} else {
			return self::BOLT_MERCHANT_DASHBOARD_HOST_PROD;
		}
	}

	/**
	 * Get API host
	 *
	 * @return string
	 *
	 * @since 2.4.0
	 */
	public function get_bolt_api_host() {
		if ( $this->is_sandbox_mode() ) {
			return $this->get_custom_url_value_or_default( SELF::SETTING_NAME_CUSTOM_API, self::BOLT_API_HOST_SANDBOX );
		} else {
			return self::BOLT_API_HOST_PROD;
		}
	}

	/**
	 * Get CDN host
	 *
	 * @return string
	 *
	 * @since 2.8.0
	 */
	public function get_cdn_host() {
		if ( $this->is_sandbox_mode() ) {
			return $this->get_custom_url_value_or_default( SELF::SETTING_NAME_CUSTOM_CDN, self::BOLT_CDN_HOST_SANDBOX );
		} else {
			return self::BOLT_CDN_HOST_PROD;
		}
	}

	/**
	 * Get Account host
	 *
	 * @return string
	 *
	 * @since 2.16.0
	 */
	public function get_bolt_account_host() {
		if ( $this->is_sandbox_mode() ) {
			return $this->get_custom_url_value_or_default( SELF::SETTING_NAME_CUSTOM_ACCOUNT, self::BOLT_ACCOUNT_HOST_SANDBOX );
		} else {
			return self::BOLT_ACCOUNT_HOST_PROD;
		}
	}
}
