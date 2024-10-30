<?php
/**
 * @package Woocommerce_Bolt_Checkout/Class
 */

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bolt_Shipping_API_Handler.
 *
 * @since
 */
class Bolt_Shipping_API_Handler {

	use Bolt_Controller;
	use Bolt_Shipping_Tax;

	const SHIPPING_COUPON_ERR_MAPPING = array(
		'default' => E_BOLT_SHIPPING_CUSTOM_ERROR,
	);

	public function __construct() {
		$this->error_handler = new Bolt_Error_Handler( BOLT_ENDPOINT_ID_SHIPPING_TAX );
		$this->route         = 'shipping';

		if ( WC_BOLT_WP_REST_API_ADDON ) {
			add_filter( 'json_endpoints', array( $this, 'register_wp_rest_api_route' ) );
		} else {
			add_action( 'rest_api_init', array( $this, 'register_endpoint' ) );
		}
	}

	/**
	 * Function for Bolt shipping endpoint.
	 *
	 * @return WP_REST_Response array   Well-formed response sent to the Bolt Server
	 *
	 */
	public function handle_endpoint() {
		wc_bolt()->get_metrics_client()->save_start_time();
		BugsnagHelper::initBugsnag();

		try {
			$this->presetup();

			$this->retrive_active_cart_session_by_order_reference( $this->decode_request_payload );

			$cache_session_key = $this->calculate_cache_session_key( BOLT_PREFIX_SHIPPING . $this->decode_request_payload->cart->order_reference, $this->request_payload );
			if ( ! $this->check_cart_have_changed( $this->decode_request_payload->cart->order_reference ) && apply_filters( 'wc_bolt_if_enable_shipping_cache', true ) ) {
				if ( ( $cached_estimate = $this->get_cached_estimate( $cache_session_key ) ) ) {
					return Bolt_HTTP_Handler::prepare_http_response(
						json_decode( $cached_estimate ),
						HTTP_STATUS_OK,
						array( BOLT_HEADER_CACHED_VALUE => true )
					);
				}
			}

			$this->set_if_apply_pay( $this->decode_request_payload );

			$formatted_shipping_address = $this->format_shipping_address( $this->decode_request_payload->shipping_address );

			$this->validate_shipping_address( $this->decode_request_payload, $formatted_shipping_address );

			$this->set_customer_shipping_address( $formatted_shipping_address );
			do_action( 'wc_bolt_shippingtax_after_set_customer_shipping_address', $formatted_shipping_address, $this->decode_request_payload->cart->order_reference );

			$shipping_options = $this->get_shipping_options( $this->decode_request_payload );

			if ( $this->error_handler->has_error() ) {
				wc_bolt()->get_metrics_client()->process_metric( BOLT_METRIC_NAME_SHIP, BOLT_STATUS_FAILURE );

				return $this->error_handler->build_error();
			}

			############################################################
			# The coupon validation result is based on error notice, so
			# before validating the applied coupons, first check whether
			# there is already any error notice in case of other errors.
			# In that way the response would be more appropriate.
			############################################################
			$this->check_error_notices();

			$this->validate_discounts( $this->decode_request_payload, self::SHIPPING_COUPON_ERR_MAPPING );

			if ( $this->error_handler->has_error() ) {
				wc_bolt()->get_metrics_client()->process_metric( BOLT_METRIC_NAME_SHIP, BOLT_STATUS_FAILURE );

				return $this->error_handler->build_error();
			}

			$shipping_result = (object) array(
				BOLT_FIELD_NAME_SHIPPING_OPTIONS => $shipping_options,
			);

			wc_bolt()->get_metrics_client()->process_metric( BOLT_METRIC_NAME_SHIP, BOLT_STATUS_SUCCESS );

			if ( apply_filters( 'wc_bolt_if_enable_shipping_cache', true ) ) {
				$this->set_cached_estimate( $cache_session_key, json_encode( $shipping_result ) );
			}

			Bolt_HTTP_Handler::clean_buffers( true );

			return Bolt_HTTP_Handler::prepare_http_response(
				$shipping_result,
				HTTP_STATUS_OK,
				array( BOLT_HEADER_CACHED_VALUE => false )
			);

		} catch ( BOLT_REST_Exception $e ) {
			wc_bolt()->get_metrics_client()->process_metric( BOLT_METRIC_NAME_SHIP, BOLT_STATUS_FAILURE );
			$this->error_handler->handle_error( $e->getErrorCode(), (object) array( BOLT_ERR_REASON => $e->getMessage() ) );

			return $this->error_handler->build_error();
		} catch ( \Exception $e ) {
			wc_bolt()->get_metrics_client()->process_metric( BOLT_METRIC_NAME_SHIP, BOLT_STATUS_FAILURE );
			$this->error_handler->handle_error( E_BOLT_SHIPPING_GENERAL_ERROR, (object) array( BOLT_ERR_REASON => $e->getMessage() ) );

			return $this->error_handler->build_error();

		} finally {
			# If the user isn't logged in then we created a new session, that will no longer be used
			# Need to destroy it not to add an extra entry to the session table
			if ( ! Bolt_Feature_Switch::instance()->is_native_cart_session_enabled() && ! is_user_logged_in() ) {
				@ WC()->session->destroy_session();
			}
		}
	}

	private function get_shipping_options( $decode_request_payload ) {
		$shipping_options = apply_filters( 'wc_bolt_before_load_shipping_options', array(), $decode_request_payload, $this->error_handler );
		if ( $this->error_handler->has_error() ) {
			return false;
		}

		if ( WC()->cart->needs_shipping() ) {
			$shipping_methods = wc_bolt_get_shipping_methods_for_first_package();

			if ( ! empty( $shipping_methods ) ) {
				foreach ( $shipping_methods["rates"] as $method_key => $shipping_method ) {
					$shipping_options[] = apply_filters( 'wc_bolt_loading_shipping_option', (object) array(
						BOLT_CART_SHIPMENT_SERVICE   => html_entity_decode( $shipping_method->get_label() ),
						BOLT_CART_SHIPMENT_COST      => convert_monetary_value_to_bolt_format( $shipping_method->get_cost() ),
						BOLT_CART_SHIPMENT_REFERENCE => (string) $method_key,
					), $method_key, $shipping_method, $decode_request_payload );
				}
			}
		} else {
			$shipping_options[] = (object) array(
				BOLT_CART_SHIPMENT_SERVICE   => 'No shipping required',
				BOLT_CART_SHIPMENT_REFERENCE => 'no_shipping_required',
				BOLT_CART_SHIPMENT_COST      => 0,
			);
		}

		// Extensions can do customization per merchant in this filter hook
		$shipping_options = apply_filters( 'wc_bolt_after_load_shipping_options', $shipping_options, $decode_request_payload );

		###################################
		# There were no shipping options
		# found for the provided location.
		# The Bolt modal will reflect this,
		# but still alert Bugsnag
		###################################
		if ( empty( $shipping_options ) ) {
			BugsnagHelper::notifyException( new \Exception( "No shipping options were found." ), array(), 'info' );
		}

		return $shipping_options;
	}

}

new Bolt_Shipping_API_Handler();