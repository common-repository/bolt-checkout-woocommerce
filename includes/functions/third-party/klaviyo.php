<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bolt Third-party addons support Functions
 *
 * Functions to support Klaviyo plugin.
 * Tested up to: 3.0.3
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */


/**
 * By default, the route widget is only loaded on the checkout page,
 * for Bolt checkout, we add this widget to the cart page, before the Bolt button.
 *
 * @since 2.15.0
 * @access public
 *
 */
function add_klaviyo_callback_to_email_enter_event( $bolt_on_email_enter, $bolt_settings, $order_details ) {
	if ( class_exists( 'WooCommerceKlaviyo' ) && $bolt_settings[ Bolt_Settings::SETTING_NAME_ENABLE_ABANDONDED_CART ] == 'klaviyo' ) {
		$event_data = '';
		if ( $order_details ) {
			$currency_divider = get_currency_divider();
			$event_data       = array(
				'$service'       => 'woocommerce',
				'CurrencySymbol' => get_woocommerce_currency_symbol(),
				'Currency'       => get_woocommerce_currency(),
				'$value'         => $order_details[ BOLT_CART ][ BOLT_CART_TOTAL_AMOUNT ] / $currency_divider,
				'Categories'     => '',
				'$extra'         => array(
					'Items'         => array(),
					'SubTotal'      => $order_details[ BOLT_CART ][ BOLT_CART_TOTAL_AMOUNT ] / $currency_divider,
					'ShippingTotal' => 0,
					'TaxTotal'      => 0,
					'GrandTotal'    => $order_details[ BOLT_CART ][ BOLT_CART_TOTAL_AMOUNT ] / $currency_divider,
				),
			);
			foreach ( $order_details[ BOLT_CART ][ BOLT_CART_ITEMS ] as $item ) {
				$event_data['$extra']['Items'][] = array(
					'Quantity'     => $item[ BOLT_CART_ITEM_QUANTITY ],
					'ProductID'    => '',
					'VariantID'    => '',
					'name'         => $item[ BOLT_CART_ITEM_NAME ],
					'URL'          => '',
					'Images'       => array(
						array(
							'URL' => isset( $item[ BOLT_CART_ITEM_IMAGE_URL ] ) ? $item[ BOLT_CART_ITEM_IMAGE_URL ] : wc_placeholder_img_src(),
						),
					),
					'Categories'   => '',
					'Variation'    => '',
					'SubTotal'     => $item[ BOLT_CART_ITEM_TOTAL_AMOUNT ] / $currency_divider,
					'Total'        => $item[ BOLT_CART_ITEM_TOTAL_AMOUNT ] / $currency_divider,
					'LineTotal'    => $item[ BOLT_CART_ITEM_TOTAL_AMOUNT ] / $currency_divider,
					'Tax'          => 0,
					'TotalWithTax' => $item[ BOLT_CART_ITEM_TOTAL_AMOUNT ] / $currency_divider,

				);
			}
			$event_data = json_encode( $event_data );
		}
		$abandonded_cart_service_callback = 'var event_object = {
												"token": "' . $bolt_settings[ Bolt_Settings::SETTING_NAME_ABANDONDED_CART_KEY ] . '",
												"event": "$started_checkout",
												"customer_properties": {
												  "$email": email
												},
												"properties": ' . $event_data . '
											};
											data_param = btoa(unescape(encodeURIComponent(JSON.stringify(event_object))));
											jQuery.get("https://a.klaviyo.com/api/track?data=" + data_param);
											';
		$bolt_on_email_enter              = 'var enable_bolt_email_enter = true;
		                        bolt_on_email_enter = function ( email, wc_bolt_checkout ) {
									' . $abandonded_cart_service_callback . '
								};';
	}

	return $bolt_on_email_enter;
}

add_filter( 'bolt_email_enter_event_third_party_callback', '\BoltCheckout\add_klaviyo_callback_to_email_enter_event', 10, 3 );
