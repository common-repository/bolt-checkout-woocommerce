<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bolt Third-party addons support
 *
 * Class to support Woocommerce Google Analytics Pro
 *
 * Restrictions: only checkout on the cart page.
 * No support for Product page checkout / subscription / payment only
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */
class WC_Google_Analytics_Pro {

	const GA_COOKIE_NAME = '_ga';

	public function __construct() {
		if ( ! self::is_enabled() ) {
			return;
		}
		BugsnagHelper::initBugsnag();
		// Add filter for save GA cookie in our session table
		add_filter(
			'wc_bolt_update_cart_session',
			array( $this, 'update_cart_session' ), 10, 3
		);
		// Add action for restore GA cookie from our session table
		add_action(
			'wc_bolt_after_set_cart_by_bolt_reference',
			array( $this, 'after_set_cart_by_bolt_reference' ), 10, 2
		);
		// Add action for add Ajax call when we start checkout
		add_action(
			'wc_bolt_cart_js_params',
			array( $this, 'add_ajax_call_when_start_checkout' ), 10, 2
		);
		// Add action for record step 1 of checkout funnel when we open checkout modal
		add_action(
			'wc_ajax_wc_bolt_start_checkout',
			array( $this, 'record_event_on_start_checkout' )
		);
		// Add action for record step 2 of checkout funnel when user provides email
		// Need to have a priority less than 5 because of action with priority 10 send an answer and call exit()
		add_action(
			'wc_ajax_wc_bolt_save_email',
			array( $this, 'record_event_on_save_email' ), 5
		);
		// Add action for record step 3 of checkout funnel when we calculate shipping options
		add_filter(
			'wc_bolt_after_load_shipping_options',
			array( $this, 'after_load_shipping_options' ), 10, 2
		);
		// Add action for fix GA event options
		add_filter(
			'wc_google_analytics_pro_api_ec_checkout_option_parameters',
			array( $this, 'ec_checkout_option_parameters' )
		);
		// Add action to enable email enter event to record step 2 of checkout funnel
		add_filter(
			'bolt_email_enter_event_callback',
			array( $this, 'enable_email_enter_event_for_step_2' ), 10, 1
		);
		// Add action for record 'add_shipping_info' event, this is only valid for GA 4
		add_action(
			'wc_bolt_before_update_session_in_checkout',
			array( $this, 'record_add_shipping_info_ga4' ), 10, 2
		);
		// Add action for record 'add_payment_info' event, this is only valid for GA 4
		add_action(
			'wc_bolt_before_update_session_in_checkout',
			array( $this, 'record_add_payment_info_ga4' ), 20, 2
		);
	}

	/**
	 * Return true if we should support the plugin
	 */
	public static function is_enabled() {
		return apply_filters( 'bolt_woocommerce_is_wc_google_analytics_pro_enabled', function_exists( 'wc_google_analytics_pro' ) );
	}

	/**
	 * Save GA cookies on our session table
	 *
	 * @param array $data data should be saved in our session table
	 * @param $type not used
	 * @param $order_id not used
	 *
	 * @return array
	 *
	 * @since 2.2.0
	 */
	public function update_cart_session( $data, $type, $order_id ) {
		if ( isset( $_COOKIE[ SELF::GA_COOKIE_NAME ] ) ) {
			$data[ BOLT_FIELD_NAME_ADDITIONAL ][ SELF::GA_COOKIE_NAME ] = $_COOKIE[ SELF::GA_COOKIE_NAME ];
		}

		return $data;
	}

	/**
	 * Restore GA cookies from session data
	 *
	 * @param string $reference order reference, not used
	 * @param array $original_session_data session data
	 *
	 * @since 2.2.0
	 */
	public function after_set_cart_by_bolt_reference( $reference, $original_session_data ) {
		if ( isset( $original_session_data[ BOLT_FIELD_NAME_ADDITIONAL ][ SELF::GA_COOKIE_NAME ] ) ) {
			$_COOKIE[ SELF::GA_COOKIE_NAME ] = $original_session_data[ BOLT_FIELD_NAME_ADDITIONAL ][ SELF::GA_COOKIE_NAME ];
		}
	}

	/**
	 * Add Ajax call when we start checkout
	 *
	 * @param array $template_params tempate parameters
	 * @param array $render_bolt_checkout_params not used
	 *
	 * @return array
	 *
	 * @since 2.2.0
	 */
	public function add_ajax_call_when_start_checkout( $template_params, $render_bolt_checkout_params ) {
		$template_params['check'] .= "jQuery.ajax({ type: 'POST', url: get_ajax_url('start_checkout') });";

		return $template_params;
	}


	/**
	 * Record step 1 of checkout funnel when we open checkout modal
	 *
	 * @since 2.2.0
	 */
	public function record_event_on_start_checkout() {
		try {
			if ( version_compare( wc_google_analytics_pro()::VERSION, '2.0.0', '>=' ) ) {
				$properties = [
					'category' => 'Checkout',
					'currency' => get_woocommerce_currency(),
					'value'    => WC()->cart->get_total( 'edit' ),
					'coupon'   => implode( ', ', WC()->cart->get_applied_coupons() ),
					'items'    => array_values( array_map( static function ( $item ) {
						return ( new \SkyVerge\WooCommerce\Google_Analytics_Pro\Tracking\Adapters\Cart_Item_Event_Data_Adapter( $item ) )->convert_from_source();
					}, WC()->cart->get_cart() ) ),
				];
				$this->record_via_api_ga4( $properties, 'begin_checkout' );
			} else {
				$properties = array(
					'eventCategory'  => 'Checkout',
					'eventLabel'     => is_user_logged_in() ? __( 'Registered User', 'woocommerce-google-analytics-pro' ) : __( 'Guest', 'woocommerce-google-analytics-pro' ),
					'nonInteraction' => true,
				);
				$ec         = array(
					'checkout_option' => array(
						'step'   => 1,
						'option' => BOLT_PLUGIN_NAME
					)
				);
				wc_google_analytics_pro()->get_integration()->api_record_event( 'started checkout', $properties, $ec );
			}
		} catch ( \Exception $e ) {
			BugsnagHelper::notifyException( $e );
		}
	}

	/**
	 * Record step 2 of checkout funnel when we user provides email
	 *
	 * @since 2.2.0
	 */
	public function record_event_on_save_email() {
		try {
			if ( version_compare( wc_google_analytics_pro()::VERSION, '2.0.0', '>=' ) ) {
				$properties = [
					'category' => 'Checkout',
				];
				$this->record_via_api_ga4( $properties, 'provide_billing_email' );
			} else {
				$properties = array(
					'eventCategory'  => 'Checkout',
					'nonInteraction' => true,
				);
				$ec         = array( 'checkout_option' => array( 'step' => 2, 'option' => BOLT_PLUGIN_NAME ) );
				wc_google_analytics_pro()->get_integration()->api_record_event( 'provided billing email', $properties, $ec );
			}
		} catch ( \Exception $e ) {
			BugsnagHelper::notifyException( $e );
		}
	}

	/**
	 * Record step 3 of checkout funnel when we calculate shipping options
	 * We use action wc_bolt_after_load_shipping_options for that so function should get $shipping_options
	 * and return it without changes
	 *
	 * @param array $shipping_options
	 * @param $bolt_order
	 *
	 * @return array - unchanged shipping options
	 *
	 * @since 2.2.0
	 */
	public function after_load_shipping_options( $shipping_options, $bolt_order ) {
		try {
			if ( version_compare( wc_google_analytics_pro()::VERSION, '2.0.0', '<' ) ) {
				$properties = array(
					'eventCategory' => 'Checkout',
					'eventLabel'    => wc_bolt()->get_settings()[ Bolt_Settings::SETTING_NAME_PAYMENT_METHOD_TITLE ]
				);
				$ec         = array(
					'checkout_option' => array(
						'step'   => 3,
						'option' => BOLT_PLUGIN_NAME
					)
				);
				wc_google_analytics_pro()->get_integration()->api_record_event( 'selected payment method', $properties, $ec );
			}
		} catch ( \Exception $e ) {
			BugsnagHelper::notifyException( $e );
		}

		return $shipping_options;
	}

	/**
	 * Fix GA event options
	 *
	 * We need to use product action 'checkout,' but the plugin can use it only after WooC order creation
	 * So we use the product action 'checkout option' and change it to 'checkout' before the plugin sends it.
	 *
	 * @param array $params
	 *
	 * @return array
	 * @since 2.2.0
	 */
	public function ec_checkout_option_parameters( $params ) {
		if ( $params['col'] == BOLT_PLUGIN_NAME && $params['pa'] == 'checkout_option' ) {
			$params['pa']  = 'checkout';
			$params['col'] = '';
		}

		return $params;
	}

	/**
	 * Enable email enter event to record step 2 of checkout funnel
	 *
	 * @param string $bolt_on_email_enter
	 *
	 * @since 2.19.0
	 */
	public function enable_email_enter_event_for_step_2( $bolt_on_email_enter ) {
		if ( $bolt_on_email_enter && strpos( $bolt_on_email_enter, 'enable_bolt_email_enter = false' ) !== false ) {
			$bolt_on_email_enter = 'var enable_bolt_email_enter = true; bolt_on_email_enter = function ( email, wc_bolt_checkout ) {};';
		}

		return $bolt_on_email_enter;
	}

	/**
	 * Records the GA 4 event 'add_shipping_info' event via API.
	 *
	 * @param array $posted_data
	 * @param array $shipping_methods
	 *
	 * @since 2.19.0
	 *
	 */
	public function record_add_shipping_info_ga4( $posted_data, $shipping_methods ) {
		try {
			if ( version_compare( wc_google_analytics_pro()::VERSION, '2.0.0', '>=' ) && $shipping_methods ) {
				$bolt_transaction = Bolt_Checkout::get_bolt_transaction();
				$shipping_method  = ! empty( $bolt_transaction->order->cart->shipments ) ? $bolt_transaction->order->cart->shipments[0]->service : '';
				$properties       = array_merge(
					[
						'category'      => 'Checkout',
						'shipping_tier' => html_entity_decode( wp_strip_all_tags( $shipping_method ) ),
					],
					( new \SkyVerge\WooCommerce\Google_Analytics_Pro\Tracking\Adapters\Cart_Event_Data_Adapter( WC()->cart ) )->convert_from_source(),
			);
				$this->record_via_api_ga4( $properties, 'add_shipping_info' );
			}
		} catch ( \Exception $e ) {
			BugsnagHelper::notifyException( $e );
		}
	}

	/**
	 * Records the GA 4 event 'add_payment_info' event via API.
	 *
	 * @param array $posted_data
	 * @param array $shipping_methods
	 *
	 * @since 2.19.0
	 *
	 */
	public function record_add_payment_info_ga4( $posted_data, $shipping_methods ) {
		try {
			if ( version_compare( wc_google_analytics_pro()::VERSION, '2.0.0', '>=' ) ) {
				$method     = WC()->shipping()->get_shipping_methods()[ $shipping_methods[0] ] ?? null;
				$properties = array_merge(
					[
						'category'     => 'Checkout',
						'payment_type' => html_entity_decode( wp_strip_all_tags( wc_bolt()->get_settings()[ Bolt_Settings::SETTING_NAME_PAYMENT_METHOD_TITLE ] ) ),
					],
					( new \SkyVerge\WooCommerce\Google_Analytics_Pro\Tracking\Adapters\Cart_Event_Data_Adapter( WC()->cart ) )->convert_from_source(),
			);
				$this->record_via_api_ga4( $properties, 'add_payment_info' );
			}
		} catch ( \Exception $e ) {
			BugsnagHelper::notifyException( $e );
		}
	}

	/**
	 * Records the event via API for GA 4 events.
	 *
	 * @param array $properties event properties
	 * @param string $event_name event name
	 *
	 * @since 2.19.0
	 *
	 */
	public function record_via_api_ga4( $properties, $event_name ) {
		try {
			$user_id = \SkyVerge\WooCommerce\Google_Analytics_Pro\Helpers\Identity_Helper::get_uid();
			$data    = [
				'client_id' => (string) ( \SkyVerge\WooCommerce\Google_Analytics_Pro\Helpers\Identity_Helper::get_cid() ),
				'events'    => [
					[
						'name'   => $event_name,
						'params' => $properties,
					]
				]
			];
			if ( $user_id ) {
				if ( \SkyVerge\WooCommerce\Google_Analytics_Pro\Tracking::is_user_id_tracking_enabled() ) {
					$data = $this->bolt_ga_array_insert_after( $data, 'client_id', [ 'user_id' => (string) $user_id ] );
				}

				$data['user_properties']['role']['value'] = implode( ', ', get_userdata( $user_id )->roles );
			}
			wc_google_analytics_pro()->get_api_client_instance()->get_measurement_protocol_api()->collect( $data );
		} catch ( \Exception $e ) {
			BugsnagHelper::notifyException( $e );
		}
	}

	/**
	 * This function is copied from SkyVerge\WooCommerce\PluginFramework\v5_11_0\SV_WC_Helper::array_insert_after
	 * Insert the given element after the given key in the array
	 *
	 * Sample usage:
	 *
	 * given
	 *
	 * array( 'item_1' => 'foo', 'item_2' => 'bar' )
	 *
	 * array_insert_after( $array, 'item_1', array( 'item_1.5' => 'w00t' ) )
	 *
	 * becomes
	 *
	 * array( 'item_1' => 'foo', 'item_1.5' => 'w00t', 'item_2' => 'bar' )
	 *
	 * @param array $array array to insert the given element into
	 * @param string $insert_key key to insert given element after
	 * @param array $element element to insert into array
	 *
	 * @return array
	 * @since 2.19.0
	 */
	public function bolt_ga_array_insert_after( $array, $insert_key, $element ) {
		$new_array = array();
		foreach ( $array as $key => $value ) {
			$new_array[ $key ] = $value;
			if ( $insert_key == $key ) {
				foreach ( $element as $k => $v ) {
					$new_array[ $k ] = $v;
				}
			}
		}

		return $new_array;
	}
}

new WC_Google_Analytics_Pro();

