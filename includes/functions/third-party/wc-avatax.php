<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bolt Third-party addons support Functions
 *
 * Functions to support WooCommerce AvaTax.
 * Tested up to: 1.10.3
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */


/**
 * By default, WC Avatax plugin does not calculate the taxes for rest api,
 * this function is to enable the calculation for shipping&tax endpoint if WC session has related flag.
 *
 *
 * @param bool $needs_calculation Whether the cart needs new taxes calculated.
 *
 * @since 2.14.0
 * @access public
 *
 */
function enable_avatax_calculation_for_shipping_tax_api( $needs_calculation ) {
	if ( class_exists( '\WC_AvaTax' )
	     && wc_bolt_is_bolt_rest_api_request()
	     && is_object( WC()->session ) && is_callable( array( WC()->session, 'get' ) )
	     && \wc_avatax()->get_tax_handler()->is_available()
	     && WC()->session->get( 'bolt_enable_avatax_calculation', null ) === true ) {
		$needs_calculation = true;
	}

	return $needs_calculation;
}

add_filter( 'wc_avatax_cart_needs_calculation', '\BoltCheckout\enable_avatax_calculation_for_shipping_tax_api', 999, 1 );

/**
 * By default, WC Avatax plugin does not calculate the taxes for rest api,
 * this function is for adding a flag into the WC session, this flag indicates the cart needs taxes calculated.
 *
 * @since 2.14.0
 * @access public
 *
 */
function enable_avatax_calculation_for_shipping_tax_api_set_session( $bolt_order ) {
	if ( class_exists( '\WC_AvaTax' ) && \wc_avatax()->get_tax_handler()->is_available() ) {
		WC()->session->set( 'bolt_enable_avatax_calculation', true );
	}

	return $bolt_order;
}

add_filter( 'wc_bolt_shipping_validation', '\BoltCheckout\enable_avatax_calculation_for_shipping_tax_api_set_session', 10, 1 );

/**
 * After WC Avatax plugin calculates the taxes for shipping&tax endpoint,
 * this function is for removing the flag from the WC session.
 *
 * @since 2.14.0
 * @access public
 *
 */
function enable_avatax_calculation_for_shipping_tax_api_unset_session( $shipping_options, $bolt_order ) {
	if ( class_exists( '\WC_AvaTax' ) && \wc_avatax()->get_tax_handler()->is_available() ) {
		WC()->session->set( 'bolt_enable_avatax_calculation', null );
	}

	return $shipping_options;
}

add_filter( 'wc_bolt_after_load_shipping_options', '\BoltCheckout\enable_avatax_calculation_for_shipping_tax_api_unset_session', 10, 2 );

/**
 * WC may cache taxes on the cart page,then in the Bolt modal, we need to clear those cache before calculating cart taxes for shipping&tax endpoint.
 *
 * @since 2.14.0
 * @access public
 *
 */
function remove_avatax_cache_for_shipping_tax_api( $shipping_methods ) {
	if ( class_exists( '\WC_AvaTax' ) && \wc_avatax()->get_tax_handler()->is_available() ) {
		WC()->session->set( 'bolt_enable_avatax_calculation', true );

		$shipping_tax_class = get_option( 'woocommerce_shipping_tax_class' );

		if ( 'inherit' !== $shipping_tax_class ) {
			$tax_class = $shipping_tax_class;
		}

		$location = \WC_Tax::get_tax_location( $tax_class, null );

		if ( 4 === count( $location ) ) {
			list( $country, $state, $postcode, $city ) = $location;
			$postcode = wc_normalize_postcode( wc_clean( $postcode ) );

			if ( $country ) {
				$cache_key = \WC_Cache_Helper::get_cache_prefix( 'taxes' ) . 'wc_tax_rates_' . md5( sprintf( '%s+%s+%s+%s+%s', $country, $state, $city, $postcode, $tax_class ) );
				wp_cache_set( $cache_key, false, 'taxes' );
			}
		}
	}
}

add_action( 'wc_bolt_before_calculate_shipping_tax', '\BoltCheckout\remove_avatax_cache_for_shipping_tax_api', 10, 1 );

/**
 * By default, WC Avatax plugin does not calculate the taxes for rest api,
 * this function is for adding a flag into the WC session, this flag indicates the cart needs taxes calculated.
 *
 * @since 2.14.0
 * @access public
 *
 */
function reset_avatax_taxes_for_shipping_tax_api_set_session( $shipping_methods ) {
	if ( class_exists( '\WC_AvaTax' ) && \wc_avatax()->get_tax_handler()->is_available() ) {
		WC()->session->set( 'bolt_reset_avatax_calculation', true );
	}
}

add_action( 'wc_bolt_before_calculate_shipping_tax', '\BoltCheckout\reset_avatax_taxes_for_shipping_tax_api_set_session', 10, 1 );

/**
 * After WC Avatax plugin calculates the taxes for shipping&tax endpoint,
 * this function is for removing the flag from the WC session.
 *
 * @since 2.14.0
 * @access public
 *
 */
function reset_avatax_taxes_for_shipping_tax_api_unset_session( $tax_calculation, $shipping_methods ) {
	if ( class_exists( '\WC_AvaTax' ) && \wc_avatax()->get_tax_handler()->is_available() ) {
		WC()->session->set( 'bolt_reset_avatax_calculation', null );
	}
}

add_action( 'wc_bolt_after_calculate_shipping_tax', '\BoltCheckout\reset_avatax_taxes_for_shipping_tax_api_unset_session', 10, 2 );

/**
 * By default, WC Avatax plugin does not calculate the taxes for rest api,
 * this function is to simulate a transaction on checkout page,
 * so WC Avatax plugin can replace WooCommerce core tax rates with those estimated taxes.
 *
 */
function bolt_reset_tax_cache_checkout( $flag ) {
	if ( class_exists( '\WC_AvaTax' )
	     && wc_bolt_is_bolt_rest_api_request()
	     && \wc_avatax()->get_tax_handler()->is_available()
	     && WC()->session
	     && WC()->session->get( 'bolt_reset_avatax_calculation', null ) === true ) {
		return true;
	}

	return $flag;
}

add_filter( 'woocommerce_is_checkout', '\BoltCheckout\bolt_reset_tax_cache_checkout', 999, 1 );

/**
 * Before Avatax plugin sets the shipping tax data for a cart, it needs shipping packages calculated.
 * This function is to fix the compatibility issue.
 *
 */
function bolt_calculate_shipping_before_update_session_for_avatax( $posted_data, $shipping_methods ) {
	if ( ! isset( $_POST['in_bolt_checkout'] )
	     || ! class_exists( '\WC_AvaTax' )
	     || ! \wc_avatax()->get_tax_handler()->is_available() ) {
		return;
	}

	WC()->cart->calculate_shipping();
}

add_action( 'wc_bolt_before_update_session_in_checkout', '\BoltCheckout\bolt_calculate_shipping_before_update_session_for_avatax', 10, 2 );

/**
 * Before Avatax plugin sets the shipping tax data for a cart, it needs shipping packages calculated.
 * This function is to fix the compatibility issue.
 *
 */
function bolt_calculate_tax_per_each_option_for_avatax( $flag ) {
	if ( class_exists( '\WC_AvaTax' ) && \wc_avatax()->get_tax_handler()->is_available() ) {
		return true;
	}

	return $flag;
}

add_filter( 'wc_bolt_calculate_tax_per_each_option', '\BoltCheckout\bolt_calculate_tax_per_each_option_for_avatax', 20, 1 );

/**
 * For each api request of avalara service (https://rest.avatax.com/api/v2/transactions/create) it contains a field "customerCode",
 * if its value is an email and this email belongs to a tax-exempt customer, the tax amount in the api response is zero.
 * Consequently, the tax amount of cart/order changes to zero as well.
 * To get the correct tax amount in Bolt shipping&tax endpoint, we need to save the email to $_POST.
 *
 */
function add_billing_email_for_avatax_in_shippingtax_endpoint( $shipping_options, $bolt_order, $error_handler ) {
	if ( class_exists( '\WC_AvaTax' ) && wc_avatax()->get_tax_handler()->is_available() ) {
		$_POST['billing_email'] = $bolt_order->cart->billing_address->email ?: $bolt_order->shipping_address->email;
	}

	return $shipping_options;
}

add_filter( 'wc_bolt_before_load_shipping_options', '\BoltCheckout\add_billing_email_for_avatax_in_shippingtax_endpoint', 10, 3 );

/**
 * By default, WC Avatax plugin does not calculate the taxes for rest api,
 * this function is for adding a flag into the WC session, this flag indicates the cart needs taxes calculated.
 * Also set tax-exempt customer info to POST data.
 *
 * @since 2.16.0
 * @access public
 *
 */
function add_billing_email_for_avatax_in_tax_endpoint( $decode_request_payload, $error_handler ) {
	if ( class_exists( '\WC_AvaTax' ) && wc_avatax()->get_tax_handler()->is_available() ) {
		$_POST['billing_email'] = $decode_request_payload->cart->billing_address->email ?: $decode_request_payload->shipping_address->email;
		WC()->session->set( 'bolt_reset_avatax_calculation', true );
		// Fix the compatibility issue between WC Store Credit and WC Avatax,
		// even the cart does not have any store credit applied, the WC Store Credit plugin still calculates shipping tax incorrectly,
		// and it causes tax amount mismatch for Bolt checkout. So we have to remove the related hook.
		if ( ! empty( $_SERVER['REQUEST_URI'] )
		     && false !== strpos( $_SERVER['REQUEST_URI'], '/bolt/tax' )
		     && class_exists( "\WC_Store_Credit_Cart" ) ) {
			$applied_coupons             = WC()->cart->get_applied_coupons();
			$has_applied_wc_store_credit = false;
			foreach ( $applied_coupons as $coupon_code ) {
				if ( wc_is_store_credit_coupon( $coupon_code ) ) {
					$has_applied_wc_store_credit = true;
					break;
				}
			}
			if ( ! $has_applied_wc_store_credit ) {
				global $wp_filter;
				$remove_hooks = array();
				if ( isset( $wp_filter['woocommerce_after_calculate_totals'] ) ) {
					foreach ( $wp_filter['woocommerce_after_calculate_totals'] as $pri => $funcs ) {
						foreach ( $funcs as $name => $content ) {
							if ( isset( $content['function'][0] ) && is_a( $content['function'][0], 'WC_Store_Credit_Cart' ) ) {
								$remove_hooks[ $name ] = $pri;
							}
						}
					}
				}
				if ( ! empty( $remove_hooks ) ) {
					foreach ( $remove_hooks as $remove_hook_name => $remove_hook_pri ) {
						remove_action( 'woocommerce_after_calculate_totals', $remove_hook_name, $remove_hook_pri );
					}
				}
			}
		}
	}

	return $shipping_options;
}

add_filter( 'wc_bolt_before_calculate_tax', '\BoltCheckout\add_billing_email_for_avatax_in_tax_endpoint', 10, 2 );

/**
 * After WC Avatax plugin calculates the taxes for shipping&tax endpoint,
 * this function is for removing the flag from the WC session.
 * Also fix the compatibility issue with WooCommerce Store Credit plugin for Bolt split shipping&tax endpoints.
 *
 * @since 2.16.0
 * @access public
 *
 */
function bolt_avatax_unset_is_checkout( $taxes, $decode_request_payload ) {
	if ( class_exists( '\WC_AvaTax' ) && \wc_avatax()->get_tax_handler()->is_available() ) {
		WC()->session->set( 'bolt_reset_avatax_calculation', null );
		// Fix the compatibility issue with WooCommerce Store Credit plugin for Bolt split shipping&tax endpoints.
		if ( ! empty( $taxes ) && ! empty( $_SERVER['REQUEST_URI'] )
		     && false !== strpos( $_SERVER['REQUEST_URI'], '/bolt/tax' )
		     && class_exists( "\WC_Store_Credit_Cart" ) ) {
			$applied_coupons = WC()->cart->get_applied_coupons();
			foreach ( $applied_coupons as $coupon_code ) {
				if ( wc_is_store_credit_coupon( $coupon_code ) ) {
					$taxes['subtotal_tax'] = convert_monetary_value_to_bolt_format( WC()->cart->get_taxes_total() - WC()->cart->get_shipping_tax() );
					break;
				}
			}
		}
	}

	return $taxes;
}

add_filter( 'wc_bolt_tax_calculation', '\BoltCheckout\bolt_avatax_unset_is_checkout', 10, 2 );