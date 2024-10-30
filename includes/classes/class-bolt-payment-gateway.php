<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bolt Payment Gateway.
 *
 * Provides a Bolt Payment Gateway.
 *
 * @class   Bolt_Payment_Gateway
 * @extends WC_Payment_Gateway
 * @version 1.0
 * @author  Bolt
 */

/**
 * Bolt_Payment_Gateway Class.
 */
class Bolt_Payment_Gateway extends \WC_Payment_Gateway {

	/**
	 * Sandbox mode
	 *
	 * @since 1.0
	 * @var bool
	 */
	protected $sandbox_mode = true;

	/**
	 * Features which this gateway supports
	 * @inheritdoc
	 */
	public $supports = array( 'products', 'refunds' );

	/**
	 * Error text receieved from woocommerce
	 *
	 * @since 1.0
	 * @var string
	 */
	protected $error_message;


	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {

		$this->id                 = BOLT_GATEWAY_NAME;
		$this->method_title       = __( 'Bolt Checkout', 'bolt-checkout-woocommerce' );
		$this->method_description = __( 'Add the Bolt Checkout experience to your WooCommerce site. For more information and to obtain merchant credentials, visit <a href="https://bolt.com/">bolt.com</a>', 'bolt-checkout-woocommerce' );
		$this->has_fields         = true;

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define or set the default value for the gateway options setting page.
		// Since Bolt_Payment_Gateway extends abstract WC_Payment_Gateway,
		// the constructor should implement these properties for WooCommerce use.
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		$this->enabled = ( Bolt_Settings::VALUE_YES == $this->enabled && Bolt_Feature_Switch::instance()->is_bolt_enabled() ) ? Bolt_Settings::VALUE_YES : Bolt_Settings::VALUE_NO;

		// Save the gateway options.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
			$this,
			'process_admin_options',
		) );
	}

	/**
	 * Initiate Form Fields.
	 *
	 * @since 1.0
	 */
	public function init_form_fields() {

		/**
		 * Settings for Bolt Checkout plugin.
		 */
		$this->form_fields = wc_bolt()->get_bolt_settings()->get_form_fields();
	}

	/**
	 * Processes and saves options.
	 * If there is an error thrown, will continue to save and validate fields, but will leave the erroring field out.
	 *
	 * @return bool was anything saved?
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();

		if ( ! empty( $this->get_option( Bolt_Settings::SETTING_NAME_MERCHANT_KEY, '' ) )
		     && empty( get_option( Bolt_Feature_Switch::OPTION_NAME, array() ) ) ) {
			WC_Bolt_Install::update_feature_switches();
		}

		return $saved;
	}

	/**
	 * Validates the primary Bolt color field.  The current validation only checks if it appears
	 * to be in hex.  We can easily add rgb, rgba, hsl, and hsla, and color name validation.
	 *
	 * @param $settings_field_key
	 * @param $color string
	 *
	 * @return string
	 */
	public function validate_bolt_button_color_field( $settings_field_key, $color ) {

		if ( empty( $color ) ) {
			return '';
		}

		if ( ( strpos( $color, '#' ) === 0 ) ) {
			# we've identified a value that appears to be a hex code
			if ( $this->bolt_sanitize_hex_color( $color ) ) {
				return $color;
			} else {
				# The hex color was invalid, so add an error to display and save the previous value

				$bolt_button_color_was_added = wp_cache_get( 'bolt_button_color_was_added', 'bolt-checkout-woocommerce' );

				if ( ! $bolt_button_color_was_added ) {
					$title = $this->form_fields[ Bolt_Settings::SETTING_NAME_BOLT_BUTTON_COLOR ][ Bolt_Settings::KEY_TITLE ];
					\WC_Admin_Settings::add_error( sprintf( __( '[%1$s][%2$s]: Invalid hex color value or format. Only 6 and 8 digit hex values are supported.  Valid examples: #fa2de0, #43EB01, #5e703e80', "bolt-checkout-woocommerce" ), $title, $color ) );
					wp_cache_set( 'bolt_button_color_was_added', true, 'bolt-checkout-woocommerce' );
				}

				return $this->get_option( 'bolt_button_color' );
			}

		} else {
			return $this->validate_bolt_button_color_field( $settings_field_key, "#" . $color );
		}

	}

	/**
	 * Process Payment
	 *
	 * @return array that is returned as json to the browser that contains the url for order creation success
	 * @var int $order_id The id of the order that was created
	 *
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		WC()->cart->empty_cart();

		# Here we allow for a merchant hook prior to showing the success page
		do_action( 'wc_bolt_process_payment', $order );

		return array(
			'order_id'     => (string) $order_id,
			'result'       => 'success',
			'redirect_url' => $order->get_checkout_order_received_url(),
			// This is for redirection in bolt woocommerce plugin
			'redirect'     => $order->get_checkout_order_received_url()
			// This is for redirection in woocommerce
		);
	}

	/**
	 * Create form on front-end checkout fields.
	 *
	 * @since 1.0
	 */
	public function payment_fields() {
		do_action( 'bolt_payment_checkout', $this );
	}

	/**
	 * Get_icon function.
	 *
	 * @access public
	 * @return string
	 */
	public function get_icon() {
		$ext   = version_compare( WC()->version, '2.6', '>=' ) ? '.svg' : '.png';
		$style = version_compare( WC()->version, '2.6', '>=' ) ? 'style="margin-left: 0.3em"' : '';

		$icon = '<img src="' . \WC_HTTPS::force_https_url( plugins_url( '../../assets/images/visa' . $ext, __FILE__ ) ) . '" alt="Visa" width="32" ' . $style . ' />';
		$icon .= '<img src="' . \WC_HTTPS::force_https_url( plugins_url( '../../assets/images/mastercard' . $ext, __FILE__ ) ) . '" alt="Mastercard" width="32" ' . $style . ' />';
		$icon .= '<img src="' . \WC_HTTPS::force_https_url( plugins_url( '../../assets/images/amex' . $ext, __FILE__ ) ) . '" alt="Amex" width="32" ' . $style . ' />';
		$icon .= '<img src="' . \WC_HTTPS::force_https_url( plugins_url( '../../assets/images/discover' . $ext, __FILE__ ) ) . '" alt="Discover" width="32" ' . $style . ' />';
		$icon .= '<img src="' . \WC_HTTPS::force_https_url( plugins_url( '../../assets/images/jcb' . $ext, __FILE__ ) ) . '" alt="JCB" width="32" ' . $style . ' />';
		$icon .= '<img src="' . \WC_HTTPS::force_https_url( plugins_url( '../../assets/images/diners' . $ext, __FILE__ ) ) . '" alt="Diners" width="32" ' . $style . ' />';


		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}

	/**
	 * Process a refund if supported.
	 *
	 * @param int $order_id
	 * @param float $amount
	 * @param string $reason
	 *
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$response = false;
		try {
			$order           = wc_get_order( $order_id );
			$refund_response = $this->refund_transaction( $order, $amount, $reason );

			if ( isset( $refund_response ) ) {
				if ( BOLT_TRANSACTION_STATUS_COMPLETED === $refund_response->status ) {
					$order->add_order_note( sprintf( __( 'Refunded %1$s via Bolt - Refund ID: %2$s with reference ID %3$s.', 'woocommerce-bolt-payment-gateway' ), wc_price( $amount ), $refund_response->id, $refund_response->reference ) );
					$response = true;
				} elseif ( BOLT_TRANSACTION_STATUS_CREATED === $refund_response->status ) {
					// The Bolt server could create refund transaction with created status,
					// then the plugin just add order note,
					// and save the transaction id into order meta to avoid duplicate refund when processing Bolt api hooks.
					$created_refund = wc_bolt_get_order_meta( $order, BOLT_ORDER_META_REFUND_CREATED, true );
					if ( empty( $created_refund ) ) {
						$created_refund = array();
					}
					if ( ! isset( $created_refund[ $refund_response->id ] ) ) {
						$created_refund[ $refund_response->id ] = $refund_response->type;
						wc_bolt_update_order_meta( $order, BOLT_ORDER_META_REFUND_CREATED, $created_refund );
						$order->add_order_note( sprintf( __( 'Refunded %1$s via Bolt is in progress. See actual refund status in Bolt merchant dashboard. - Refund ID: %2$s with reference ID %3$s.', 'woocommerce-bolt-payment-gateway' ), wc_price( $amount ), $refund_response->id, $refund_response->reference ) );
					}
					$response = true;
				} elseif ( isset( $refund_response->errors ) ) {
					$errors = $refund_response->errors;
					if ( isset( $errors ) ) {
						foreach ( $errors as $error ) {
							$order->add_order_note( sprintf( __( 'Refund via Bolt failed because of %1$s ', 'woocommerce-bolt-payment-gateway' ), $error->message ) );
						}
						// Handle Error message.
						$response = new \WP_Error( 'bolt_refund_error', __( 'Refund via Bolt failed.', 'woocommerce-bolt-payment-gateway' ) );
					}
				}
			} else {
				$response = new \WP_Error( 'bolt_refund_error', __( 'Refund via Bolt failed.', 'woocommerce-bolt-payment-gateway' ) );
			}
		} catch ( BOLT_REST_Exception $e ) {
			BugsnagHelper::notifyException( $e );
			$response = new \WP_Error(
				'bolt_refund_error',
				sprintf(
					__( 'There was a problem initiating a refund: %1$s', 'woocommerce-bolt-payment-gateway' ),
					$e->getErrorData()['message']
				)
			);
		} catch ( \Exception $e ) {
			BugsnagHelper::notifyException( $e );
			$response = new \WP_Error(
				'bolt_refund_error',
				sprintf(
					__( 'There was a problem initiating a refund: %1$s', 'woocommerce-bolt-payment-gateway' ),
					$e->getMessage()
				)
			);
		} finally {
			return $response;
		}
	}


	/**
	 * Refund an order via Bolt.
	 *
	 * @param WC_Order $order
	 * @param float $amount
	 * @param string $reason
	 *
	 * @return array $refund_response
	 * @throws
	 */
	public function refund_transaction( $order, $amount = null, $reason = '' ) {
		$transaction_id = wc_bolt_get_order_meta( $order, BOLT_ORDER_META_TRANSACTION_ID, true );
		if ( ! $transaction_id ) {
			BugsnagHelper::notifyException(
				new \Exception( 'bolt_transaction_id is empty in refund' ),
				array( 'order_id' => $order->get_id(), 'order transaction' => $order->get_transaction_id() )
			);

			// return error without calling server
			return (object) array(
				'status' => 'error',
				'errors' => array( (object) ( array( 'message' => 'Bolt transaction id is empty' ) ) )
			);
		}
		$refund_response = wc_bolt()->get_bolt_data_collector()->handle_api_request(
			'transactions/credit',
			array(
				BOLT_FIELD_NAME_TRANSACTION_ID         => (string) $transaction_id,
				BOLT_FIELD_NAME_AMOUNT                 => convert_monetary_value_to_bolt_format( $amount ),
				BOLT_FIELD_NAME_CURRENCY               => $order->get_currency(),
				BOLT_FIELD_NAME_SKIP_HOOK_NOTIFICATION => true,
			)
		);

		return $refund_response;
	}

	/**
	 * Can the order be refunded via Bolt?
	 *
	 * @param WC_Order $order
	 *
	 * @return bool
	 */
	public function can_refund_order( $order ) {
		return $order && $order->get_transaction_id();
	}


	/**
	 * Generate Javascript HTML.
	 *
	 * @param mixed $key
	 * @param mixed $data
	 *
	 * @return string
	 * @since  1.6
	 */
	public function generate_javascript_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$defaults  = array(
			Bolt_Settings::KEY_TITLE             => '',
			Bolt_Settings::KEY_DISABLED          => false,
			Bolt_Settings::KEY_CLASS             => '',
			Bolt_Settings::KEY_CSS               => '',
			Bolt_Settings::KEY_PLACEHOLDER       => '',
			Bolt_Settings::KEY_TYPE              => Bolt_Settings::TYPE_TEXT,
			Bolt_Settings::KEY_DESC_TIP          => false,
			Bolt_Settings::KEY_DESCRIPTION       => '',
			Bolt_Settings::KEY_CUSTOM_ATTRIBUTES => array(),
		);

		$data = wp_parse_args( $data, $defaults );

		ob_start();
		?>
        <tr valign="top">
            <th scope="row" class="titledesc">
				<?php echo $this->get_tooltip_html( $data ); ?>
                <label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data[ Bolt_Settings::KEY_TITLE ] ); ?></label>
            </th>
            <td class="forminp js">
                <fieldset>
                    <legend class="screen-reader-text">
                        <span><?php echo wp_kses_post( $data[ Bolt_Settings::KEY_TITLE ] ); ?></span>
                    </legend>
                    <textarea rows="3" cols="20"
                              class="input-text wide-input <?php echo esc_attr( $data[ Bolt_Settings::KEY_CLASS ] ); ?>"
                              type="<?php echo esc_attr( $data[ Bolt_Settings::KEY_TYPE ] ); ?>"
                              name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>"
                              style="<?php echo esc_attr( $data['css'] ); ?>"
                              placeholder="<?php echo esc_attr( $data[ Bolt_Settings::KEY_PLACEHOLDER ] ); ?>" <?php disabled( $data[ Bolt_Settings::KEY_DISABLED ], true ); ?> <?php echo $this->get_custom_attribute_html( $data ); ?>><?php echo( $this->get_option( $key ) ); ?></textarea>
					<?php echo $this->get_description_html( $data ); ?>
                </fieldset>
            </td>
        </tr>
		<?php

		return ob_get_clean();
	}

	/**
	 * Sanitizes a hex color.
	 *
	 * Returns either '', 6 or 8 digit hex color (with #).
	 *
	 * @param string $color Presumed hex value that should start with a #
	 *
	 * @return string           The unchanged hex color if valid, otherwise and empty string
	 */
	private function bolt_sanitize_hex_color( $color ) {
		// 6 or 8 hex digits.
		if ( preg_match( '/^#(([A-Fa-f0-9]{6})|([A-Fa-f0-9]{8}))$/', $color ) ) {
			return $color;
		}

		return '';
	}

	/**
	 * Output the admin options table.
	 * Override parent method so that we can conditionally show or hide specific option fields
	 *
	 * @since 1.2.4
	 * @access public
	 *
	 */
	public function admin_options() {
		parent::admin_options();
		?>
        <table class="form-table">
            <script type="text/javascript">
                jQuery('#woocommerce_wc-bolt-payment-gateway_enable_abandonded_cart').on('change', function () {
                    var abandonded_cart_key_row = jQuery('#woocommerce_wc-bolt-payment-gateway_abandonded_cart_key').closest('tr');
                    if (jQuery(this).val() == 'no') {
                        abandonded_cart_key_row.hide();
                    } else {
                        abandonded_cart_key_row.show();
                    }
                }).change();
            </script>
        </table>
		<?php
	}
}
