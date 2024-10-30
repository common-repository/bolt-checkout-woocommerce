<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provide work around HTML code generation for Bolt buttons
 *
 * @class   Bolt_HTML_Handler
 * @version 1.0
 * @author  Bolt
 */
class Bolt_HTML_Handler {

	/**
	 * The single instance of the class.
	 *
	 * @since 2.10.0
	 * @var Bolt_HTML_Handler|null
	 */
	protected static $instance = null;

	/**
	 * Gets the main Bolt_HTML_Handler Instance.
	 *
	 * @return Bolt_HTML_Handler Main instance
	 * @since 2.10.0
	 * @static
	 *
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
			self::$instance->init_hooks();
		}

		return self::$instance;
	}

	/**
	 * Reset the instance of the class
	 *
	 * @since  2.10.0
	 * @access public
	 */
	public static function reset() {
		if ( ! is_null( self::$instance ) ) {
			self::$instance->remove_hooks();
			self::$instance = null;
		}
	}


	/**
	 * Bolt_HTML_Handler constructor.
	 */
	public function __construct() {

	}

	/**
	 * Hook into actions and filters.
	 *
	 * @since 2.10.0
	 */
	private function init_hooks() {
		if ( Bolt_Feature_Switch::instance()->is_hook_priority_changed() ) {
			$priority = apply_filters( 'wc_bolt_set_priority_for_html_hooks', BOLT_PRIORITY_FOR_HTML_HOOKS_CHANGED );
		} else {
			$priority = BOLT_PRIORITY_FOR_HTML_HOOKS_DEFAULT;
		}

		add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'show_bolt_button_on_product_page' ), 10 );
		add_action( 'woocommerce_proceed_to_checkout', array( $this, 'button_on_cart_page' ), $priority );
		add_action( 'woocommerce_widget_shopping_cart_buttons', array( $this, 'button_on_minicart' ), $priority );
		add_action( 'bolt_payment_checkout', array( $this, 'button_on_checkout_page' ), 10 );
		add_action( 'init', array( $this, 'register_bolt_account_shortcode' ) );
	}

	/**
	 * Removes functions attached to actions and filters.
	 *
	 * @since 2.10.0
	 */
	public function remove_hooks() {
		if ( Bolt_Feature_Switch::instance()->is_hook_priority_changed() ) {
			$priority = apply_filters( 'wc_bolt_set_priority_for_html_hooks', 19 );
		} else {
			$priority = 22;
		}

		remove_action( 'woocommerce_after_add_to_cart_button', array( $this, 'show_bolt_button_on_product_page' ), 10 );
		remove_action( 'woocommerce_proceed_to_checkout', array( $this, 'button_on_cart_page' ), $priority );
		remove_action( 'woocommerce_widget_shopping_cart_buttons', array( $this, 'button_on_minicart' ), $priority );
		remove_action( 'bolt_payment_checkout', array( $this, 'button_on_checkout_page' ), 10 );
	}

	/**
	 * Show button on minicart widget of woocommerce.
	 *
	 * @since 1.1
	 */
	public function button_on_minicart() {
		BugsnagHelper::initBugsnag();
		if ( ! wc_bolt_if_show_on_mini_cart() ) {
			return;
		}
		try {
			$settings = wc_bolt()->get_settings();
			if ( $settings[ Bolt_Settings::SETTING_NAME_HIDE_DEFAULT_CHECKOUT_BUTTONS ] == 'yes' ) {
				remove_action( 'woocommerce_widget_shopping_cart_buttons', 'woocommerce_widget_shopping_cart_proceed_to_checkout', 20 );
			}
			$bolt_button_class = esc_attr( $settings[ Bolt_Settings::SETTING_NAME_MINI_CART_BUTTON_CLASS ] );
			echo '<style>' . htmlspecialchars_decode( $settings[ Bolt_Settings::SETTING_NAME_ADDITIONAL_CSS ], ENT_QUOTES ) . '</style>
            <div class="bolt-quick-pay-btn" id="bolt-minicart">
			' . $this->get_button_html( $bolt_button_class, BOLT_CART_ORDER_TYPE_CART ) . '
            <style>
                #bolt-minicart .bolt-checkout-button-button {
                    min-width: 100% !important;
                }
            </style>
            <script id="minicart-bolt-script-data" type="text/javascript">';
			// Generate and print out the bolt js script.
			$bolt_response = $this->get_cart_data_js( BOLT_CART_ORDER_TYPE_CART );
			//every time when the mini-cart widget refresh, it would reload the js, so we should make the Bolt_Review obj unique and to be invoked correctly
			$unique_btn_id = str_replace( '.', '', array_sum( explode( ' ', microtime() ) ) );
			/*
			 * Since we should have only one bolt button on one page, then need to exclude the minicart-bolt button from single product/ cart/ checkout page
			 * But for mini-cart widget, the common Conditional Tags of woocomerce does not work anymore,cause this widget is loaded by ajax.
			 * Then we have to use javascript to create related conditions
			 *
			 */
			$success                     = "wc_bolt_minicart_checkout_$unique_btn_id.save_checkout(transaction, callback, 'shopping_cart' );";
			$wc_bolt_checkout            = "wc_bolt_minicart_checkout_$unique_btn_id";
			$bolt_review_obj             = "var wc_bolt_minicart_checkout_$unique_btn_id = new Bolt_Review(); wc_bolt_minicart_checkout_$unique_btn_id.init('');";
			$render_bolt_checkout_params = array(
				'checkout_type'                 => 'multistep',
				'bolt_response'                 => $bolt_response,
				'success'                       => $success,
				'exclude_additional_javascript' => true,
				'wc_bolt_checkout'              => $wc_bolt_checkout,
				'bolt_review_obj'               => $bolt_review_obj,
				'wrap_in_jquery_ready'          => false
			);
			$render_bolt_checkout        = $this->render_bolt_checkout( $render_bolt_checkout_params );
			echo "if(jQuery( '#payment-bolt-dynamic-script' ).length === 0){
                        $render_bolt_checkout
                    }
                    else{
                        jQuery( '#bolt-minicart ' ).hide();
                    }";
			echo htmlspecialchars_decode( $settings[ Bolt_Settings::SETTING_NAME_JAVASCRIPT_ADDITIONAL ], ENT_QUOTES );
		} catch ( \Exception $e ) {
			BugsnagHelper::notifyException( $e );
		} finally {
			echo '</script>
            </div>';
		}
	}

	/**
	 * Show the button on cart page.
	 *
	 * @since 1.0
	 */
	public function button_on_cart_page() {
		BugsnagHelper::initBugsnag();
		if ( ! wc_bolt_if_show_on_cart_page() ) {
			return;
		}
		try {
			$settings = wc_bolt()->get_settings();
			if ( $settings[ Bolt_Settings::SETTING_NAME_HIDE_DEFAULT_CHECKOUT_BUTTONS ] == 'yes' ) {
				remove_action( 'woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 20 );
			}
			$bolt_button_class = esc_attr( $settings[ Bolt_Settings::SETTING_NAME_SHOPPING_CART_BUTTON_CLASS ] );
			echo '<style>' . htmlspecialchars_decode( $settings[ Bolt_Settings::SETTING_NAME_ADDITIONAL_CSS ], ENT_QUOTES ) . '</style>
            <div class="bolt-quick-pay-btn" id="bolt-cartpage">
			' . $this->get_button_html( $bolt_button_class, BOLT_CART_ORDER_TYPE_CART ) . '
            <script id="payment-bolt-dynamic-script" type="text/javascript">';
			$bolt_response               = $this->get_cart_data_js( BOLT_CART_ORDER_TYPE_CART );
			$success                     = "wc_bolt_checkout.save_checkout(transaction, callback, 'shopping_cart' );";
			$bolt_review_obj             = "var wc_bolt_checkout = new Bolt_Review(); wc_bolt_checkout.init('form.woocommerce-cart-form');";
			$render_bolt_checkout_params = array(
				'checkout_type'   => 'multistep',
				'bolt_response'   => $bolt_response,
				'success'         => $success,
				'bolt_review_obj' => $bolt_review_obj
			);
			// Generate and print out the bolt js script.
			echo $this->render_bolt_checkout( $render_bolt_checkout_params );
		} catch ( \Exception $e ) {
			BugsnagHelper::notifyException( $e );
		} finally {
			echo '</script>
            </div>';
		}
	}

	/**
	 * Show the button on checkout page.
	 *
	 * @since  1.2.4
	 * @access public
	 */
	public function button_on_checkout_page( $bolt_gateway ) {
		BugsnagHelper::initBugsnag();
		if ( ! wc_bolt_if_show_on_checkout_page() ) {
			return;
		}
		try {
			$settings = wc_bolt()->get_settings();

			echo wpautop( wptexturize( $settings[ Bolt_Settings::SETTING_NAME_PAYMENT_METHOD_DESCRIPTION ] ) );
			$bolt_button_class = esc_attr( $settings[ Bolt_Settings::SETTING_NAME_PAYMENT_FIELD_BUTTON_CLASS ] );
			echo '<div class="bolt-quick-pay-btn" id="bolt-checkoutpage">';
			// Get button html.
			echo $this->get_button_html( $bolt_button_class, BOLT_CART_ORDER_TYPE_CHECKOUT );
			echo '<style>' . htmlspecialchars_decode( $settings[ Bolt_Settings::SETTING_NAME_ADDITIONAL_CSS ], ENT_QUOTES ) . '</style>
            <script type="text/javascript" id="payment-bolt-dynamic-script">';

			///////////////////////////////////////////////////////
			// If the customer pay from the "invoice for order" email,
			// then we should process in a separate type
			///////////////////////////////////////////////////////

			$parameters = (object) array(
				'pay_type'      => 'checkout',
				'checkout_type' => 'paymentonly',
				'form_name'     => 'form.woocommerce-checkout'
			);
			if ( wc_bolt_is_pay_exist_order() ) {
				$parameters->form_name = '#order_review';
				$parameters->pay_type  = 'orderinvoice';
			}

			// Allow extension to change paramters
			// For example, show "cart" button instead "checkout"
			$parameters = apply_filters( 'wc_bolt_parameters_on_checkout_page', $parameters );

			// Generate and print out the bolt js script.
			$bolt_response   = $this->get_cart_data_js( $parameters->pay_type );
			$json_cart       = $bolt_response['json_cart'];
			$success         = "jQuery('p_method_boltpay_reference').value = transaction.reference;
                            jQuery('p_method_boltpay_transaction_status').value = transaction.status;
                            wc_bolt_checkout.save_checkout(transaction, callback, 'checkout' );";
			$check_return    = "wc_bolt_checkout.beforePay($json_cart)";
			$bolt_review_obj = "var wc_bolt_checkout = new Bolt_Review(); wc_bolt_checkout.init('{$parameters->form_name}');";
			// In some contexts, the update_checkout_action could be triggered without any checkout field changes,
			// and if the related fragment content does not update, the Bolt checkout button will be missing from the payment methods HTML
			// (https://github.com/woocommerce/woocommerce/blob/33a74f5f04e60d0b2606245cede8fbc770d8c849/assets/js/frontend/checkout.js#L364).
			// So we create a variable which will be refreshed each time when generating Bolt checkout button HTML, in this way, the related fragment can be updated.
			$bolt_review_obj             .= "var wc_bolt_refresh_var = '" . microtime() . "';";
			$render_bolt_checkout_params = array(
				'checkout_type'   => $parameters->checkout_type,
				'bolt_response'   => $bolt_response,
				'success'         => $success,
				'check_return'    => $check_return,
				'bolt_review_obj' => $bolt_review_obj
			);
			echo $this->render_bolt_checkout( $render_bolt_checkout_params );
		} catch ( \Exception $e ) {
			BugsnagHelper::notifyException( $e );
		} finally {
			echo '</script>
            </div>';
		}
	}

	/**
	 * Show the Bolt PPC/Subscription button on product page.
	 *
	 * @since  2.4.0
	 * @access public
	 */
	public function show_bolt_button_on_product_page() {
		if ( ! wc_bolt_if_product_available() ) {
			return;
		}

		global $product;
		$product_id = $product->get_id();
		// If Bolt subscription is enabled for this product
		$is_enable_subscription = false;

		// If Bolt PPC is enabled for this product
		$is_enable_ppc = false;
		if ( wc_bolt_if_show_on_single_product_page( 'normal' ) ) {
			$is_enable_ppc = true;
		}

		if ( ! $is_enable_subscription && ! $is_enable_ppc ) {
			return;
		}

		//to prevent from updating the global product object, just to create another WC product object by same id.
		$dummy_product = wc_get_product( $product_id );
		$parent_name   = $dummy_product->get_name();

		if ( $dummy_product->is_type( 'simple' ) ) {
			$wc_product_is_purchasable = "true";
		} else {
			$wc_product_is_purchasable = "false";

			//TODO Add grouped product support
			if ( $dummy_product->is_type( 'variable' ) ) {
				$dummy_product = $this->get_variation_product( $dummy_product );
			}
		}

		$reference = $dummy_product->get_id();
		$price     = $dummy_product->get_price();
		$name      = $dummy_product->get_name();

		$settings                  = wc_bolt()->get_settings();
		$json_hints                = wc_bolt_cart_get_hint_data( true );
		$check                     = htmlspecialchars_decode( $settings[ Bolt_Settings::SETTING_NAME_JAVASCRIPT_CHECK ], ENT_QUOTES );
		$success                   = htmlspecialchars_decode( $settings[ Bolt_Settings::SETTING_NAME_JAVASCRIPT_SUCCESS ], ENT_QUOTES );
		$close                     = htmlspecialchars_decode( $settings[ Bolt_Settings::SETTING_NAME_JAVASCRIPT_CLOSE ], ENT_QUOTES );
		$additional_javascript     = htmlspecialchars_decode( $settings[ Bolt_Settings::SETTING_NAME_JAVASCRIPT_ADDITIONAL ], ENT_QUOTES );
		$oncheckoutstart           = htmlspecialchars_decode( $settings[ Bolt_Settings::SETTING_NAME_JAVASCRIPT_ONCHECKOUTSTART ], ENT_QUOTES );
		$onshippingdetailscomplete = htmlspecialchars_decode( $settings[ Bolt_Settings::SETTING_NAME_JAVASCRIPT_ONSHIPPINGDETAILSCOMPLETE ], ENT_QUOTES );
		$onshippingoptionscomplete = htmlspecialchars_decode( $settings[ Bolt_Settings::SETTING_NAME_JAVASCRIPT_ONSHIPPINGOPTIONSCOMPLETE ], ENT_QUOTES );
		$onpaymentsubmit           = htmlspecialchars_decode( $settings[ Bolt_Settings::SETTING_NAME_JAVASCRIPT_ONPAYMENTSUBMIT ], ENT_QUOTES );
		$onemailenter              = htmlspecialchars_decode( $settings[ Bolt_Settings::SETTING_NAME_JAVASCRIPT_ONEMAILENTER ], ENT_QUOTES );

		echo '<style>' . htmlspecialchars_decode( $settings[ Bolt_Settings::SETTING_NAME_ADDITIONAL_CSS ], ENT_QUOTES ), '</style>';

		if ( $is_enable_subscription ) {
			$subscription_button_class = isset( $settings[ Bolt_Settings::SETTING_NAME_SUBSCRIPTION_BUTTON_CLASS ] ) ? esc_attr( $settings[ Bolt_Settings::SETTING_NAME_SUBSCRIPTION_BUTTON_CLASS ] ) : '';
			$subscription_button_class = ( false !== strpos( $subscription_button_class, 'bolt-checkout-button-subscription' ) ) ? $subscription_button_class : $subscription_button_class . ' bolt-checkout-button-subscription';
			echo $this->get_button_html( $subscription_button_class, BOLT_CART_ORDER_TYPE_PPC );
		}

		if ( $is_enable_ppc ) {
			$ppc_button_class = esc_attr( $settings[ Bolt_Settings::SETTING_NAME_PPC_BUTTON_CLASS ] );
			$ppc_button_class = ( false !== strpos( $ppc_button_class, 'bolt-page-checkout-button' ) ) ? $ppc_button_class : $ppc_button_class . ' bolt-page-checkout-button';
			echo $this->get_button_html( $ppc_button_class, BOLT_CART_ORDER_TYPE_PPC );
		}
		$params = apply_filters( 'wc_bolt_filter_product_page_button_js_params', array(
			'product'                   => $dummy_product,
			'reference'                 => $reference,
			'price'                     => $price,
			'name'                      => $name,
			'parent_name'               => $parent_name,
			'additional_javascript'     => $additional_javascript,
			'wc_product_is_purchasable' => $wc_product_is_purchasable,
			'currency'                  => get_woocommerce_currency(),
			'currency_divider'          => get_currency_divider(),
			'is_enable_subscription'    => $is_enable_subscription,
			'is_enable_ppc'             => $is_enable_ppc,
		) );
		echo '<script>';
		echo $this->render( 'product_page_button.js.php', $params );
		if ( $is_enable_subscription ) {
			echo $this->render( 'product_page_subscription_button.js.php', apply_filters( 'wc_bolt_filter_product_page_subscription_button_js_params', array(
				'checkoutButtonClassName'   => 'bolt-checkout-button-subscription',
				'json_hints'                => $json_hints,
				'check'                     => $check,
				'success'                   => $success,
				'close'                     => $close,
				'oncheckoutstart'           => $oncheckoutstart,
				'onshippingdetailscomplete' => $onshippingdetailscomplete,
				'onshippingoptionscomplete' => $onshippingoptionscomplete,
				'onpaymentsubmit'           => $onpaymentsubmit,
				'onemailenter'              => $onemailenter,
				'currency'                  => get_woocommerce_currency(),
			), $params ) );
		}
		if ( $is_enable_ppc ) {
			echo $this->render( 'product_page_ppc_button.js.php', apply_filters( 'wc_bolt_filter_product_page_ppc_button_js_params', array(
				'checkoutButtonClassName'   => 'bolt-page-checkout-button',
				'json_hints'                => $json_hints,
				'check'                     => $check,
				'success'                   => $success,
				'close'                     => $close,
				'oncheckoutstart'           => $oncheckoutstart,
				'onshippingdetailscomplete' => $onshippingdetailscomplete,
				'onshippingoptionscomplete' => $onshippingoptionscomplete,
				'onpaymentsubmit'           => $onpaymentsubmit,
				'onemailenter'              => $onemailenter,
				'currency'                  => get_woocommerce_currency(),
			), $params ) );
		}
		echo '</script>';
	}

	/**
	 * Get variation of variable product.
	 *
	 * @since  2.4.0
	 * @access private
	 */
	private function get_variation_product( $variable_product ) {
		$attributes = $variable_product->get_default_attributes();
		if ( ! $attributes ) {
			$available_variations = $variable_product->get_available_variations();
			$attributes           = $available_variations[0]['attributes'];
		}
		if ( $attributes ) {
			$data_store   = \WC_Data_Store::load( 'product' );
			$variation_id = $data_store->find_matching_product_variation( $variable_product, $attributes );
			if ( $variation_id ) {
				$variation_product = wc_get_product( $variation_id );
			}
		}

		return isset( $variation_product ) ? $variation_product : $variable_product;
	}

	/**
	 * Retrieves the cart reference, and customer hints and constructs these parameters to be used
	 * by BoltCheckout
	 *
	 * @param string $type The type of checkout that this cart is being built.
	 * Possible values: checkout|cart|orderinvoice
	 *
	 * @return array  An array that contains the JSON cart and hints to be used by BoltCheckout
	 */
	public function get_cart_data_js( $type = BOLT_CART_ORDER_TYPE_CHECKOUT ) {

		BugsnagHelper::initBugsnag();
		ob_start();
		list( $order_details, $order_token ) = wc_bolt()->get_bolt_data_collector()->get_order_and_order_token( $type );
		@ob_get_clean();
		$order_reference = empty( $order_details ) ? '' : $order_details[ BOLT_CART ][ BOLT_CART_ORDER_REFERENCE ];

		$cart_data = array(
			'orderToken'     => $order_token,
			'orderReference' => $order_reference,
		);
		//this is for ajax checkout validation, when pay from the "invoice for order" email,
		//there is no need to validate, so we set up a sign
		if ( $type == BOLT_CART_ORDER_TYPE_ORDER_INVOICE ) {
			$cart_data[ BOLT_CART_ORDER_TYPE_ORDER_INVOICE ] = '1';
		}

		$json_cart = wp_json_encode( $cart_data );

		$json_hints = wc_bolt_cart_get_hint_data();

		$cart_availability = wc_bolt_cart_availability();

		if ( is_wp_error( $cart_availability ) ) {
			BugsnagHelper::notifyException(
				new \Exception( "Items in cart aren't available" ),
				array( 'message' => $cart_availability->error_message ),
				'info'
			);
		}

		return array(
			'json_cart'           => $json_cart,
			'json_hints'          => $json_hints,
			'bolt_on_email_enter' => $this->create_email_enter_event_callback( $order_details ),
			'cart_availability'   => $cart_availability,
		);
	}

	/**
	 * Create javascript callback for Email Enter Event
	 *
	 * @param mixed $order_details Cart data in an array if all required customer information is available, otherwise false
	 *
	 * @return string  Javascript callback
	 * @since  1.2.4
	 * @access public
	 *
	 */
	public function create_email_enter_event_callback( $order_details ) {
		$bolt_settings = wc_bolt()->get_settings();
		if ( 'no' !== $bolt_settings[ Bolt_Settings::SETTING_NAME_ENABLE_ABANDONDED_CART ] ) {
			// Default value
			$bolt_on_email_enter = 'var enable_bolt_email_enter = true; bolt_on_email_enter = function ( email, wc_bolt_checkout ) {};';
			$bolt_on_email_enter = apply_filters( 'bolt_email_enter_event_third_party_callback', $bolt_on_email_enter, $bolt_settings, $order_details );
		} else {
			$bolt_on_email_enter = 'var enable_bolt_email_enter = false;';
		}

		return apply_filters( 'bolt_email_enter_event_callback', $bolt_on_email_enter );
	}

	/**
	 * Bolt Payment Button HTML
	 *
	 * @param $classes
	 * @param $type string type of button
	 */
	public function get_button_html( $classes = '', $type = '' ) {
		$settings                  = wc_bolt()->get_settings();
		$bolt_primary_action_color = $settings[ Bolt_Settings::SETTING_NAME_BOLT_BUTTON_COLOR ]
			? '--bolt-primary-action-color:' . $settings[ Bolt_Settings::SETTING_NAME_BOLT_BUTTON_COLOR ] . ";"
			: '';

		if ( $type == BOLT_CART_ORDER_TYPE_CART || $type == BOLT_CART_ORDER_TYPE_CHECKOUT ) {
			$classes = 'bolt-checkout-button' . ' ' . $classes;
		}

		return '<div class="' . $classes . '" style="' . $bolt_primary_action_color . '"></div>';
	}

	/**
	 * Render bolt checkout
	 *
	 * @param array $render_bolt_checkout_params
	 *
	 * @return string return result of rendering
	 */
	public function render_bolt_checkout( $render_bolt_checkout_params ) {
		/**
		 * @param string $checkout_type 'productpage', 'paymentonly', 'multistep'
		 * @param object $bolt_response response of Bolt server
		 * @param string $success additional JS code for success
		 * @param bool $exclude_additional_javascript exclude or show settings['javascript_additional']
		 * @param string $check_callback js code check callback
		 * @param string $wc_bolt_checkout alternative name for 'wc_bolt_checkout'
		 * @param string $check_return alternative JS code for check_return
		 * @param string $bolt_review_obj javascript code to initiate Bolt_Review object
		 * @param bool $wrap_in_jquery_ready True if wrapping the bolt_cart js code into jQuery ready closure, false if not. Default to true
		 */
		$params = array_merge( array(
			'exclude_additional_javascript' => false,
			'check_callback'                => '',
			'wc_bolt_checkout'              => '',
			'check_return'                  => '',
			'wrap_in_jquery_ready'          => true
		), $render_bolt_checkout_params );

		$bolt_settings    = wc_bolt()->get_settings();
		$check            = htmlspecialchars_decode( $bolt_settings[ Bolt_Settings::SETTING_NAME_JAVASCRIPT_CHECK ], ENT_QUOTES );
		$success          = htmlspecialchars_decode( $bolt_settings[ Bolt_Settings::SETTING_NAME_JAVASCRIPT_SUCCESS ], ENT_QUOTES ) . $params['success'];
		$wc_bolt_checkout = $params['wc_bolt_checkout'] ?: 'wc_bolt_checkout';
		$login_check      = "";
		$onemailenter     = htmlspecialchars_decode( $bolt_settings[ Bolt_Settings::SETTING_NAME_JAVASCRIPT_ONEMAILENTER ], ENT_QUOTES );
		$onemailenter     .= ( 'paymentonly' != $params['checkout_type'] ) ? 'if (enable_bolt_email_enter && bolt_callbacks.email !== email) {
                            bolt_callbacks.email = email;
                            if (bolt_cart.orderReference) {
                                bolt_on_email_enter(email, ' . $wc_bolt_checkout . ');
                                ' . $wc_bolt_checkout . '.save_email(email, bolt_cart.orderReference);
                            }
                        }' : '';

		if ( 'paymentonly' <> $params['checkout_type'] ) {
			if ( bolt_compat()->check_is_registration_required() && ! is_user_logged_in() ) {
				$login_check = <<<JAVASCRIPT
                    data = {
                        "result" : "failure",
                        "messages": "<ul class='woocommerce-error'><li>{$bolt_settings[Bolt_Settings::SETTING_NAME_REQUIRED_LOGIN_MESSAGE]}</li></ul>"
                    };
                    display_notices(data);
                    return false;
JAVASCRIPT;
			}
		}

		// The filter `wc_bolt_cart_js_params` is for customization per merchant
		// or to update/insert specific parameters when integrate with 3rd-party plugins.
		// eg. the function `create_yith_ywgc_premium_refresh_cart_js` adds javascript to parameter `javascript_additional`
		// if `YITH WooCommerce Gift Cards Premium` is enabled
		return $this->render( "bolt_cart.js.php", apply_filters( 'wc_bolt_cart_js_params', array(
			'json_cart'                 => $params['bolt_response']['json_cart'],
			'json_hints'                => $params['bolt_response']['json_hints'],
			'cart_availability'         => $params['bolt_response']['cart_availability'] ? 'true' : 'false',
			'check'                     => $check,
			'check_return'              => $params['check_return'] ?: 'true',
			'success'                   => $success,
			'close'                     => htmlspecialchars_decode( $bolt_settings[ Bolt_Settings::SETTING_NAME_JAVASCRIPT_CLOSE ], ENT_QUOTES ),
			'login_check'               => $login_check,
			'check_callback'            => $params['check_callback'],
			'bolt_on_email_enter'       => ( 'multistep' == $params['checkout_type'] ) ? $params['bolt_response']['bolt_on_email_enter'] : '',
			'javascript_additional'     => $params['exclude_additional_javascript'] ? '' : htmlspecialchars_decode( $bolt_settings[ Bolt_Settings::SETTING_NAME_JAVASCRIPT_ADDITIONAL ], ENT_QUOTES ),
			'wc_bolt_checkout'          => $wc_bolt_checkout,
			'bolt_review_obj'           => $params['bolt_review_obj'],
			'wrap_in_jquery_ready'      => $params['wrap_in_jquery_ready'],
			'oncheckoutstart'           => htmlspecialchars_decode( $bolt_settings[ Bolt_Settings::SETTING_NAME_JAVASCRIPT_ONCHECKOUTSTART ], ENT_QUOTES ),
			'onshippingdetailscomplete' => htmlspecialchars_decode( $bolt_settings[ Bolt_Settings::SETTING_NAME_JAVASCRIPT_ONSHIPPINGDETAILSCOMPLETE ], ENT_QUOTES ),
			'onshippingoptionscomplete' => htmlspecialchars_decode( $bolt_settings[ Bolt_Settings::SETTING_NAME_JAVASCRIPT_ONSHIPPINGOPTIONSCOMPLETE ], ENT_QUOTES ),
			'onpaymentsubmit'           => htmlspecialchars_decode( $bolt_settings[ Bolt_Settings::SETTING_NAME_JAVASCRIPT_ONPAYMENTSUBMIT ], ENT_QUOTES ),
			'onemailenter'              => $onemailenter,
		), $params ) );
	}

	public function render( $template_name, array $parameters = array(), $render_output = false ) {
		foreach ( $parameters as $name_render => $value_render ) {
			${$name_render} = $value_render;
		}
		$template_path = apply_filters( 'wc_bolt_template_path', WC_BOLT_CHECKOUT_PLUGIN_DIR_VIEW . "/" . $template_name, $template_name );
		ob_start();
		include $template_path;
		$output = ob_get_contents();
		ob_end_clean();

		if ( $render_output ) {
			echo $output;
		} else {
			return $output;
		}
	}

	/**
	 * Return javascript to load Bolt account js.
	 *
	 * @since 2.15
	 */
	public function render_bolt_account_js() {
		$settings = wc_bolt()->get_settings();
		if ( ( $settings[ Bolt_Settings::SETTING_NAME_ENABLE_ORDER_MANAGEMENT ] === 'yes'
		       && Bolt_Feature_Switch::instance()->is_bolt_order_management_enabled() ) ||
		     ( $settings[ Bolt_Settings::SETTING_NAME_ENABLE_SSO ] === 'yes'
		       && Bolt_Feature_Switch::instance()->is_sso_enabled() )
		) {
			$base_url       = wc_bolt()->get_bolt_settings()->get_cdn_host();
			$account_js_url = $base_url . '/account.js';
			$bolt_key       = $settings[ Bolt_Settings::SETTING_NAME_PUBLISHABLE_KEY_MULTISTEP ];

			return '<script>' .
			       $render_bolt_account = $this->render( "bolt_account.js.php", apply_filters( 'wc_bolt_account_js_params', array(
					       'account_js_url' => $account_js_url,
					       'bolt_key'       => $bolt_key,
				       ) ) ) . '</script>';
		}

		return '';
	}

	/**
	 * Return HTML code for bolt account button.
	 *
	 * @since 2.15
	 */
	public function render_bolt_account() {
		$settings = wc_bolt()->get_settings();
		if ( $settings[ Bolt_Settings::SETTING_NAME_ENABLE_ORDER_MANAGEMENT ] === 'yes'
		     && Bolt_Feature_Switch::instance()->is_bolt_order_management_enabled() ) {
			return '<div class="bolt-account-login"></div>';
		}

		return '';
	}

	public function render_bolt_sso() {
		$settings = wc_bolt()->get_settings();
		if ( $settings[ Bolt_Settings::SETTING_NAME_ENABLE_SSO ] === 'yes'
		     && Bolt_Feature_Switch::instance()->is_sso_enabled() ) {
			if ( is_user_logged_in() ) {
				return '<div class="bolt-account-sso" data-logged-in="true"></div>';
			} else {
				return '<div class="bolt-account-sso" data-logged-in="false"></div>';
			};
		}

		return '';
	}

	/**
	 * Adds a shortcode [bolt_account] to load Bolt account js when needed.
	 *
	 * @since 2.15
	 */
	public function register_bolt_account_shortcode() {
		add_shortcode( 'bolt_account', array( $this, 'render_bolt_account' ) );
		add_shortcode( 'bolt_sso', array( $this, 'render_bolt_sso' ) );
	}

}

Bolt_HTML_Handler::instance();