<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bolt Third-party addons support
 *
 * Class to support WooCommerce Conditional Shipping and Payments (CSP)
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */

/**
 * Set $include_data to true, so that CSP can evaluate all restriction rules and parse all matching indexes for Bolt.
 *
 *
 * @since 2.14.0
 * @access public
 *
 */
function bolt_csp_set_include_data_run_payment_restriction( $include_data, $restriction, $payload, $args ) {
	if ( isset( $_POST['bolt_csp'] ) ) {
		return true;
	}

	return $include_data;
}

add_filter( 'woocommerce_csp_rule_map_include_restriction_data', 'BoltCheckout\bolt_csp_set_include_data_run_payment_restriction', 10, 4 );

/**
 * Make CSP evaluate all restriction rules and parse all matching indexes for Bolt.
 *
 *
 * @since 2.14.0
 * @access public
 *
 */
function bolt_csp_check_payment_restriction( $flag, $gateways ) {
	if ( class_exists( 'WC_CSP_Restrict_Payment_Gateways' ) ) {
		$restriction = \WC_CSP()->restrictions->get_restriction( 'payment_gateways' );
		if ( $restriction ) {
			if ( empty( $gateways ) ) {
				$gateways = array(
					BOLT_GATEWAY_NAME => (object) array(
						'id' => BOLT_GATEWAY_NAME
					)
				);
			}

			$_POST['bolt_csp'] = true;
			$gateways          = $restriction->exclude_payment_gateways( $gateways, true );
			unset( $_POST['bolt_csp'] );
			// If the guest is not in the WC native checkout page, CSP would load default billing county and state for validation,
			// it may remove Bolt from available payment gateways by mistake, so we remove the default values before validation,
			// then after vailidation, the related fields need to be restored.
			if ( ! is_user_logged_in() && ! is_checkout()
			     && isset( $_POST['bolt_csp_default_country'] ) && isset( $_POST['bolt_csp_default_state'] ) ) {
				// Restore default billing country and state
				WC()->customer->set_billing_country( $_POST['bolt_csp_default_country'] );
				WC()->customer->set_billing_state( $_POST['bolt_csp_default_state'] );
				WC()->customer->save();
				unset( $_POST['bolt_csp_default_country'] );
				unset( $_POST['bolt_csp_default_state'] );
			}
			if ( ! isset( $gateways[ BOLT_GATEWAY_NAME ] ) ) {
				// The mini-cart widget does not update in some contexts,
				// so we need to hide the Bolt checkout button from mini-cart widget if there is any restriction on Bolt.
				echo '<style>#bolt-minicart{display:none !important;}</style>';

				return false;
			}
		}
	}

	return $flag;
}

/**
 * If the guest is not in the WC native checkout page, CSP would load default billing county and state for validation,
 * it may remove Bolt from available payment gateways by mistake, so we remove the default values before validation.
 *
 *
 * @since 2.14.0
 * @access public
 *
 */
function bolt_csp_check_payment_restriction_non_checkout_page( $flag, $gateways ) {
	if ( ! is_user_logged_in() ) {
		$_POST['bolt_csp_default_country'] = WC()->customer->get_billing_country();
		$_POST['bolt_csp_default_state']   = WC()->customer->get_billing_state();
		WC()->customer->set_billing_country( '' );
		WC()->customer->set_billing_state( '' );
		WC()->customer->save();
	}

	return bolt_csp_check_payment_restriction( $flag, $gateways );
}

add_filter( 'wc_bolt_pre_is_show_on_checkout_page', 'BoltCheckout\bolt_csp_check_payment_restriction', 10, 2 );
add_filter( 'wc_bolt_pre_is_show_on_cart_page', 'BoltCheckout\bolt_csp_check_payment_restriction_non_checkout_page', 10, 2 );
add_filter( 'wc_bolt_pre_is_show_on_mini_cart', 'BoltCheckout\bolt_csp_check_payment_restriction_non_checkout_page', 10, 2 );
add_filter( 'wc_bolt_pre_is_show_on_single_product_page', 'BoltCheckout\bolt_csp_check_payment_restriction_non_checkout_page', 10, 2 );

/**
 * After restoring cart data from Bolt session, we need to run CSP validation.
 * 1. Check if there is any restriction rule on Shipping Countries & States
 * 2. Check if there is any restriction rule on Payment Gateways
 *
 *
 * @since 2.14.0
 * @access public
 *
 */
function bolt_csp_run_restriction_after_load_cart_session( $reference, $original_session_data ) {
	try {
		if ( class_exists( 'WC_Conditional_Shipping_Payments' ) && isset( $_POST[ BOLT_PREFIX_SHIPPING_AND_TAX . $reference ] ) ) {
			$bolt_order   = $_POST[ BOLT_PREFIX_SHIPPING_AND_TAX . $reference ];
			$country_code = bolt_addr_helper()->verify_country_code( $bolt_order->shipping_address->country_code, $bolt_order->shipping_address->region );
			$post_code    = $bolt_order->shipping_address->postal_code ?: '';
			$region       = bolt_addr_helper()->get_region_code( $country_code, $bolt_order->shipping_address->region ?: ( bolt_addr_helper()->check_if_address_field_required( 'shipping_state', $country_code, 'shipping_' ) ? $bolt_order->shipping_address->locality : '' ) );
			$city         = $bolt_order->shipping_address->locality ?: '';
			$email        = $bolt_order->shipping_address->email ?: $bolt_order->cart->billing_address->email;

			WC()->customer->set_billing_email( $email );
			WC()->customer->set_location( $country_code, $region, $post_code, $city );
			WC()->customer->save();

			$restriction = \WC_CSP()->restrictions->get_restriction( 'shipping_countries' );
			if ( $restriction ) {
				WC()->cart->calculate_shipping();

				$woocommerce_enable_shipping_calc = get_option( 'woocommerce_enable_shipping_calc' );
				update_option( 'woocommerce_enable_shipping_calc', 'yes' );

				$result = $restriction->validate_cart();

				if ( $result->has_messages() ) {
					foreach ( $result->get_messages() as $message ) {
						$error_msg .= wp_kses_post( $message['text'] );
					}

					throw new \Exception( html_entity_decode( $error_msg ) );
				}
			}

			$restriction = \WC_CSP()->restrictions->get_restriction( 'payment_gateways' );
			if ( $restriction ) {
				$gateways = array(
					BOLT_GATEWAY_NAME => (object) array(
						'id' => BOLT_GATEWAY_NAME
					)
				);

				// Validate customer by email
				$_POST['billing_email'] = $email;

				$_POST['bolt_csp'] = true;
				$gateways          = $restriction->exclude_payment_gateways( $gateways, true );
				unset( $_POST['bolt_csp'] );

				if ( ! isset( $gateways[ BOLT_GATEWAY_NAME ] ) ) {
					$order_payment_title = wc_bolt()->get_settings()[ Bolt_Settings::SETTING_NAME_PAYMENT_METHOD_TITLE ];
					throw new \Exception( $order_payment_title . ' is unavailable for this cart.' );
				}
			}
		}
	} catch ( Exception $e ) {
		throw $e;
	}
}

add_action( 'wc_bolt_after_set_cart_by_bolt_reference', 'BoltCheckout\bolt_csp_run_restriction_after_load_cart_session', 10, 2 );

/**
 * After restoring cart data from WooCommerce native session, we need to run CSP validation.
 * 1. Check if there is any restriction rule on Shipping Countries & States
 * 2. Check if there is any restriction rule on Payment Gateways
 *
 * @since 2.15.0
 * @access public
 *
 */
function bolt_csp_run_restriction_after_load_native_wc_session( $customer_id, $reference ) {
	try {
		if ( class_exists( 'WC_Conditional_Shipping_Payments' ) && isset( $_POST[ BOLT_PREFIX_SHIPPING_AND_TAX . $reference ] ) ) {
			$bolt_order   = $_POST[ BOLT_PREFIX_SHIPPING_AND_TAX . $reference ];
			$country_code = bolt_addr_helper()->verify_country_code( $bolt_order->shipping_address->country_code, $bolt_order->shipping_address->region );
			$post_code    = $bolt_order->shipping_address->postal_code ?: '';
			$region       = bolt_addr_helper()->get_region_code( $country_code, $bolt_order->shipping_address->region ?: ( bolt_addr_helper()->check_if_address_field_required( 'shipping_state', $country_code, 'shipping_' ) ? $bolt_order->shipping_address->locality : '' ) );
			$city         = $bolt_order->shipping_address->locality ?: '';
			$email        = $bolt_order->shipping_address->email ?: $bolt_order->cart->billing_address->email;
			WC()->customer->set_billing_email( $email );
			WC()->customer->set_location( $country_code, $region, $post_code, $city );
			WC()->customer->save();
			$restriction = \WC_CSP()->restrictions->get_restriction( 'shipping_countries' );
			if ( $restriction ) {
				$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
				$method_counts  = WC()->session->get( 'shipping_method_counts' );
				WC()->cart->calculate_shipping();
				WC()->session->set( 'chosen_shipping_methods', $chosen_methods );
				WC()->session->set( 'shipping_method_counts', $method_counts );
				$woocommerce_enable_shipping_calc = get_option( 'woocommerce_enable_shipping_calc' );
				update_option( 'woocommerce_enable_shipping_calc', 'yes' );
				$result = $restriction->validate_cart();
				update_option( 'woocommerce_enable_shipping_calc', $woocommerce_enable_shipping_calc );
				if ( $result->has_messages() ) {
					foreach ( $result->get_messages() as $message ) {
						$error_msg .= wp_kses_post( $message['text'] );
					}

					throw new \Exception( html_entity_decode( $error_msg ) );
				}
			}
			$restriction = \WC_CSP()->restrictions->get_restriction( 'payment_gateways' );
			if ( $restriction ) {
				$gateways = array(
					BOLT_GATEWAY_NAME => (object) array(
						'id' => BOLT_GATEWAY_NAME
					)
				);
				// Validate customer by email
				$_POST['billing_email'] = $email;
				$_POST['bolt_csp']      = true;
				$gateways               = $restriction->exclude_payment_gateways( $gateways, true );
				unset( $_POST['bolt_csp'] );
				if ( ! isset( $gateways[ BOLT_GATEWAY_NAME ] ) ) {
					$order_payment_title = wc_bolt()->get_settings()[ Bolt_Settings::SETTING_NAME_PAYMENT_METHOD_TITLE ];
					throw new \Exception( $order_payment_title . ' is unavailable for this cart.' );
				}
			}
		}
	} catch ( Exception $e ) {
		throw $e;
	}
}

add_action( 'wc_bolt_after_load_cart_from_native_wc_session', 'BoltCheckout\bolt_csp_run_restriction_after_load_native_wc_session', 10, 2 );

/**
 * Exclude shipping options if they are unavailable for Bolt under CSP conditions.
 *
 *
 * @since 2.14.0
 * @access public
 *
 */
function bolt_csp_check_payment_restriction_after_load_shipping_options( $shipping_options, $bolt_order ) {
	if ( empty( $shipping_options ) ) {
		return $shipping_options;
	}

	try {
		if ( class_exists( 'WC_Conditional_Shipping_Payments' ) ) {
			$restriction = \WC_CSP()->restrictions->get_restriction( 'payment_gateways' );
			if ( $restriction ) {
				$_POST['bolt_csp']    = true;
				$new_shipping_options = array();
				foreach ( $shipping_options as $shipping_option ) {
					wc_bolt_set_chosen_shipping_method_for_first_package( $shipping_option->reference );
					$gateways = array(
						BOLT_GATEWAY_NAME => (object) array(
							'id' => BOLT_GATEWAY_NAME
						)
					);
					$gateways = $restriction->exclude_payment_gateways( $gateways, true );
					if ( isset( $gateways[ BOLT_GATEWAY_NAME ] ) ) {
						$new_shipping_options[] = $shipping_option;
					}

				}
				unset( $_POST['bolt_csp'] );
				$shipping_options = $new_shipping_options;
			}
		}
	} catch ( Exception $e ) {
		throw $e;
	}

	return $shipping_options;
}

add_filter( 'wc_bolt_after_load_shipping_options', 'BoltCheckout\bolt_csp_check_payment_restriction_after_load_shipping_options', 999, 2 );
