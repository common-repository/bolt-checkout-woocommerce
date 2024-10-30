<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class to implement order.create API.
 *
 * @class   Bolt_Subscription
 * @version 1.2.8
 * @author  Bolt
 */
class Bolt_Subscription {

	/**
	 * Implement Bolt order.create API
	 *
	 * @param array $request_data The transaction data retrieved from the Bolt API endpoint.
	 *
	 * @return WP_REST_Response      Well-formed response sent to the Bolt Server
	 * @since  1.2.8
	 * @access public
	 *
	 */
	public function implement_order_create_api( $request_data ) {
		try {
			$bolt_order_reference = isset( $request_data->reference ) ? $request_data->reference : '';
			if ( empty( $bolt_order_reference ) ) {
				throw new \Exception( __( 'Incomplete transaction request data', 'woocommerce-bolt-payment-gateway' ) );
			}
			// Get the transaction details
			$api_response = wc_bolt()->get_bolt_data_collector()->handle_api_request( 'transactions/' . $bolt_order_reference );

			$billing_address  = $api_response->order->cart->billing_address;
			$shipping_address = isset( $api_response->order->cart->shipments ) ? $api_response->order->cart->shipments[0]->shipping_address : $billing_address;

			WC()->customer->set_props(
				array(
					WC_BILLING_FIRST_NAME => $billing_address->first_name,
					WC_BILLING_LAST_NAME  => $billing_address->last_name,
					WC_BILLING_COMPANY    => $billing_address->company,
					WC_BILLING_EMAIL      => $billing_address->email ?: $billing_address->email_address,
					WC_BILLING_PHONE      => $billing_address->phone ?: $billing_address->phone_number,
					WC_BILLING_ADDRESS_1  => $billing_address->street_address1,
					WC_BILLING_ADDRESS_2  => $billing_address->street_address2,
					WC_BILLING_CITY       => $billing_city = $billing_address->locality,
					WC_BILLING_COUNTRY    => $billing_country_code = bolt_addr_helper()->verify_country_code( $billing_address->country_code, $billing_address->region ),
					WC_BILLING_STATE      => $billing_region = bolt_addr_helper()->get_region_code_without_encoding( $billing_country_code, $billing_address->region ?: ( bolt_addr_helper()->check_if_address_field_required( WC_BILLING_STATE, $billing_country_code, WC_BILLING_PREFIX ) ? $billing_city : '' ) ),
					WC_BILLING_POSTCODE   => $billing_post_code = $billing_address->postal_code,
				)
			);

			WC()->customer->set_props(
				array(
					WC_SHIPPING_FIRST_NAME => $shipping_address->first_name,
					WC_SHIPPING_LAST_NAME  => $shipping_address->last_name,
					WC_SHIPPING_ADDRESS_1  => $shipping_address->street_address1,
					WC_SHIPPING_ADDRESS_2  => $shipping_address->street_address2,
					WC_SHIPPING_CITY       => $shipping_city = $shipping_address->locality,
					WC_SHIPPING_COUNTRY    => $shipping_country_code = bolt_addr_helper()->verify_country_code( $shipping_address->country_code, $shipping_address->region ),
					WC_SHIPPING_STATE      => $shipping_region = bolt_addr_helper()->get_region_code_without_encoding( $shipping_country_code, $shipping_address->region ?: ( bolt_addr_helper()->check_if_address_field_required( WC_SHIPPING_STATE, $shipping_country_code, WC_SHIPPING_PREFIX ) ? $shipping_city : '' ) ),
					WC_SHIPPING_POSTCODE   => $shipping_post_code = $shipping_address->postal_code,
				)
			);
			WC()->customer->save();

			// TODO : if need to login a customer

			foreach ( $api_response->order->cart->items as $item ) {
				$product_id       = intval( $item->reference );
				$product_quantity = intval( $item->quantity );
				// TODO : try to response with bolt defined failure code
				if ( ! WC()->cart->add_to_cart( $product_id, $product_quantity ) ) {
					if ( $notices = wc_get_notices( WC_NOTICE_TYPE_ERROR ) ) {
						$error_msg = '';
						foreach ( $notices as $notice ) {
							// WooCommerce notice has different structures in different versions
							$error_msg .= wc_kses_notice( get_wc_notice_message( $notice ) );
						}
						throw new \Exception( $error_msg );
					} else {
						throw new \Exception( sprintf( __( 'Fail to add %s to the cart.', 'woocommerce-bolt-payment-gateway' ), wp_kses_post( $item->name ) ) );
					}
				}
			}

			// TODO : how to add fee, current the transaction detail does not have field for fee

			if ( WC()->cart->is_empty() ) {
				throw new \Exception( __( 'Empty cart', 'woocommerce-bolt-payment-gateway' ) );
			}

			// TODO : if need to apply coupon

			if ( isset( $api_response->order->cart->shipments ) ) {
				wc_bolt_set_chosen_shipping_method_for_first_package( $api_response->order->cart->shipments[0]->reference );
				$calculated_shipping_packages = WC()->shipping->calculate_shipping( WC()->cart->get_shipping_packages() );
				$method_counts                = array();
				$previous_shipping_methods    = array();
				foreach ( $calculated_shipping_packages as $key => $package ) {
					$method_counts[ $key ]             = count( $package['rates'] );
					$previous_shipping_methods[ $key ] = array_keys( $package['rates'] );
				}
				WC()->session->set( 'shipping_method_counts', $method_counts );
				WC()->session->set( 'previous_shipping_methods', $previous_shipping_methods );
			}

			$_POST['orphaned_transaction'] = '1';

			// Create bolt cart session and process bolt checkout
			$order_session_data                         = wc_bolt()->get_bolt_data_collector()->build_order( BOLT_CART_ORDER_TYPE_CART );
			$api_response->order->cart->order_reference = $order_session_data[ BOLT_CART ][ BOLT_CART_ORDER_REFERENCE ];

			return wc_bolt()->get_bolt_checkout()->save_order( $api_response );

		} catch ( \Exception $e ) {
			return Bolt_HTTP_Handler::prepare_http_response(
				array(
					BOLT_FIELD_NAME_STATUS => BOLT_STATUS_FAILURE,
					BOLT_FIELD_NAME_ERROR  => array(
						// TODO : if the exception is thrown out by woocommerce original functions or 3rd-party addon,
						// it is hard to define the failure code, so we need a general code for that
						BOLT_FIELD_NAME_HTTP_CODE     => E_BOLT_SUBSCRIPTION_GENERAL_ERROR,
						BOLT_FIELD_NAME_ERROR_MESSAGE => $e->getMessage(),
					),
				),
				HTTP_STATUS_UNPROCESSABLE,
				array( BOLT_HEADER_CACHED_VALUE => false )
			);
		} finally {
			wc_clear_notices();
		}
	}

}
