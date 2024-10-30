<?php

namespace BoltCheckout;

/**
 * The public-facing functionality of the plugin.
 *
 * @link       http://bolt.com
 * @since      1.0.0
 *
 * @package    Woocommerce_Bolt_Checkout
 * @subpackage Woocommerce_Bolt_Checkout/public
 */

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Woocommerce_Bolt_Checkout
 * @subpackage Woocommerce_Bolt_Checkout/public
 * @author     Bolt
 */
class Bolt_Payment_Gateway_Public {

	// TODO: delete this constants when we remove them from extensions
	const KEY_PUBLISHABLE_KEY_PAYMENTONLY = 'processing_key';
	const KEY_PUBLISHABLE_KEY_MULTISTEP = 'quick_payment_secret_key';

	/**
	 * Get the gateway settings.
	 *
	 * @since 1.0
	 * @var $gateway_settings
	 */
	private $gateway_settings;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {

		$this->gateway_settings = wc_bolt()->get_settings();

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

	}

	/**
	 * Register the JavaScript for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {
		// Add track.js and other js resources.
		add_action( 'wp_head', array( $this, 'add_resources' ) );
	}

	/**
	 * Add the track.js on the all of the pages.
	 *
	 * @since 1.0
	 */
	public function add_resources() {
		// Get the connect js url.
		$base_url       = wc_bolt()->get_bolt_settings()->get_cdn_host();
		$connect_js_url = $base_url . '/connect.js';
		$track_js_url   = $base_url . '/track.js';

		echo wc_bolt()->get_html_handler()->render_bolt_account_js();

		echo '<script type="text/javascript" id="bolt-track"
                data-publishable-key="' . $this->get_publishable_key() . '"
                src="' . $track_js_url . '">
        </script>';

		if ( wc_bolt_is_eliminated_from_current_page() ) {
			return;
		}

		echo '<script type="text/javascript" id="bolt-connect"
                data-publishable-key="' . $this->get_publishable_key() . '"
                src="' . $connect_js_url . '" data-shopping-cart-id="WooCommerce">
        </script>

        <script type="text/javascript">
            var allow_payment = false;
            jQuery(document).on("updated_checkout", function () {
                if (jQuery("form.woocommerce-checkout").find(".woocommerce-invalid-required-field").length == 0) {
                    allow_payment = true;
                } else {
                    allow_payment = false;
                }
            });
        </script>';
	}

	private function get_publishable_key() {
		global $wp;

		$multistep_key        = $this->gateway_settings[ Bolt_Settings::SETTING_NAME_PUBLISHABLE_KEY_MULTISTEP ];
		$paymentonly_key      = $this->gateway_settings[ Bolt_Settings::SETTING_NAME_PUBLISHABLE_KEY_PAYMENTONLY ];
		$backoffice_order_key = $this->gateway_settings[ Bolt_Settings::SETTING_NAME_PUBLISHABLE_KEY_BACKOFFICE ];

		if ( wc_bolt_is_checkout_page() ) {
			$backoffice_order = false;
			if ( ! empty( $wp->query_vars['order-pay'] ) ) {
				$order_id = absint( $wp->query_vars['order-pay'] );
				$order    = wc_get_order( $order_id );
				if ( $order && 'admin' === $order->get_created_via() ) {
					$backoffice_order = true;
				}
			}
			if ( $backoffice_order ) {
				// We load the key from backoffice division for shop manager,
				// otherwise we use the key from payment-only division.
				if ( current_user_can( 'manage_woocommerce' ) ) {
					return esc_attr( $backoffice_order_key );
				} else {
					return esc_attr( $paymentonly_key );
				}
			} else {
				return esc_attr( $paymentonly_key );
			}
		}

		return esc_attr( $multistep_key );
	}
}
