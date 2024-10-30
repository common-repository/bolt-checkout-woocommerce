<?php
/**
 * @package Woocommerce_Bolt_Checkout/Class
 */

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bolt_Tax_API_Handler.
 *
 * @since
 */
class Bolt_Tax_API_Handler {

	use Bolt_Controller;
	use Bolt_Shipping_Tax;

	const TAX_COUPON_ERR_MAPPING = array(
		'default' => E_BOLT_SHIPPING_GENERAL_ERROR,
	);

	public function __construct() {
		$this->error_handler = new Bolt_Error_Handler( BOLT_ENDPOINT_ID_SHIPPING_TAX ); // TODO
		$this->route         = 'tax';

		if ( WC_BOLT_WP_REST_API_ADDON ) {
			add_filter( 'json_endpoints', array( $this, 'register_wp_rest_api_route' ) );
		} else {
			add_action( 'rest_api_init', array( $this, 'register_endpoint' ) );
		}
	}

	/**
	 * Function for Bolt tax endpoint.
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

			$cache_session_key = $this->calculate_cache_session_key( BOLT_PREFIX_TAX . $this->decode_request_payload->cart->order_reference, $this->request_payload );
			if ( ! $this->check_cart_have_changed( $this->decode_request_payload->cart->order_reference ) && apply_filters( 'wc_bolt_if_enable_tax_cache', true ) ) {
				if ( $cached_estimate = $this->get_cached_estimate( $cache_session_key ) ) {
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

			$taxes_data = $this->get_taxes( $this->decode_request_payload );

			if ( $this->error_handler->has_error() ) {
				wc_bolt()->get_metrics_client()->process_metric( BOLT_METRIC_NAME_TAX, BOLT_STATUS_FAILURE );

				return $this->error_handler->build_error();
			}

			############################################################
			# The coupon validation result is based on error notice, so
			# before validating the applied coupons, first check whether
			# there is already any error notice in case of other errors.
			# In that way the response would be more appropriate.
			############################################################
			$this->check_error_notices();

			$this->validate_discounts( $this->decode_request_payload, self::TAX_COUPON_ERR_MAPPING );

			if ( $this->error_handler->has_error() ) {
				wc_bolt()->get_metrics_client()->process_metric( BOLT_METRIC_NAME_TAX, BOLT_STATUS_FAILURE );

				return $this->error_handler->build_error();
			}

			$tax_result = (object) array(
				'tax_result'      => (object) array(
					'subtotal_amount' => $taxes_data['subtotal_tax']
				),
				'shipping_option' => (object) array(
					BOLT_CART_SHIPMENT_SERVICE    => $this->decode_request_payload->shipping_option->service,
					BOLT_CART_SHIPMENT_COST       => $this->decode_request_payload->shipping_option->cost,
					BOLT_CART_SHIPMENT_REFERENCE  => $this->decode_request_payload->shipping_option->reference,
					BOLT_CART_SHIPMENT_TAX_AMOUNT => $taxes_data['shipping_tax']
				)
			);

			// Extensions can do customization per merchant in this filter hook
			$tax_result = apply_filters( 'wc_bolt_after_load_tax', $tax_result, $this->decode_request_payload );

			wc_bolt()->get_metrics_client()->process_metric( BOLT_METRIC_NAME_TAX, BOLT_STATUS_SUCCESS );

			if ( apply_filters( 'wc_bolt_if_enable_tax_cache', true ) ) {
				$this->set_cached_estimate( $cache_session_key, json_encode( $tax_result ) );
			}
			// So we can check if separate shipping&tax feature is enabled during order creation.
			$split_tax_enable_key = BOLT_PREFIX_TAX . $this->decode_request_payload->cart->order_reference . '_split_tax';
			wc_bolt_data()->update_session( $split_tax_enable_key, array( 'enable' => true ) );

			Bolt_HTTP_Handler::clean_buffers( true );

			return Bolt_HTTP_Handler::prepare_http_response(
				$tax_result,
				HTTP_STATUS_OK,
				array( BOLT_HEADER_CACHED_VALUE => false )
			);

		} catch ( BOLT_REST_Exception $e ) {
			wc_bolt()->get_metrics_client()->process_metric( BOLT_METRIC_NAME_TAX, BOLT_STATUS_FAILURE );
			$this->error_handler->handle_error( $e->getErrorCode(), (object) array( BOLT_ERR_REASON => $e->getMessage() ) );

			return $this->error_handler->build_error();
		} catch ( \Exception $e ) {
			wc_bolt()->get_metrics_client()->process_metric( BOLT_METRIC_NAME_TAX, BOLT_STATUS_FAILURE );
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

	private function get_taxes( $decode_request_payload ) {
		do_action( 'wc_bolt_before_calculate_tax', $decode_request_payload, $this->error_handler );
		if ( $this->error_handler->has_error() ) {
			return false;
		}

		wc_bolt_set_chosen_shipping_method_for_first_package( $decode_request_payload->shipping_option->reference );
		Bolt_woocommerce_cart_calculation::calculate();
		$cart_shipping_tax = WC()->cart->get_shipping_tax();
		$cart_subtotal_tax = WC()->cart->get_total_tax() - $cart_shipping_tax;

		$taxes = array(
			'subtotal_tax' => convert_monetary_value_to_bolt_format( $cart_subtotal_tax ),
			'shipping_tax' => convert_monetary_value_to_bolt_format( $cart_shipping_tax ),
		);

		return apply_filters( 'wc_bolt_tax_calculation', $taxes, $decode_request_payload );
	}

}

new Bolt_Tax_API_Handler();