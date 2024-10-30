<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bolt Third-party addons support Functions
 *
 * Functions to support WooCommerce Smart Coupons.
 * Tested up to: 8.6.0
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */


/**
 * Currently every time when $order->calculate_totals() is called after the order creation,
 * the store credit of `WooCommerce Smart Coupons` would be excluded from the order total calculation.
 * Then this function recalculate the order total with the store credit discount
 * if there is price difference between order total_discount and order discount_total.
 *
 *
 * @param bool $and_taxes Calc taxes if true.
 * @param WC_Order $order Order object of the newly created order
 *
 * @since 2.0.0
 * @access public
 *
 */
function recalculate_order_total_with_smart_coupons_store_credit_discount( $and_taxes, $order = null ) {
	if ( class_exists( '\WC_Smart_Coupons' ) ) {
		if ( empty( $order ) ) {
			return;
		}

		// Gets the total discount amount which would always include the correct discount.
		$total_discount = $order->get_total_discount();
		$total_discount = abs( convert_monetary_value_to_bolt_format( $total_discount ) );
		// Get prop discount_total which may exclude the store credit of `WooCommerce Smart Coupons` after order calculation.
		$discount_total = $order->get_discount_total();
		$discount_total = abs( convert_monetary_value_to_bolt_format( $discount_total ) );
		if ( $total_discount === $discount_total ) {
			return;
		}

		$total = $order->get_total();

		$coupons = ( is_object( $order ) && is_callable( array(
				$order,
				'get_items'
			) ) ) ? $order->get_items( 'coupon' ) : array();

		if ( ! empty( $coupons ) ) {
			$applied_discount_smart_coupon = 0;
			foreach ( $coupons as $coupon ) {
				$code = ( is_object( $coupon ) && is_callable( array(
						$coupon,
						'get_code'
					) ) ) ? $coupon->get_code() : '';
				if ( empty( $code ) ) {
					continue;
				}
				$_coupon       = new \WC_Coupon( $code );
				$discount_type = ( is_object( $_coupon ) && is_callable( array(
						$_coupon,
						'get_discount_type'
					) ) ) ? $_coupon->get_discount_type() : '';
				if ( ! empty( $discount_type ) && 'smart_coupon' == $discount_type ) {
					$discount                      = ( is_object( $coupon ) && is_callable( array(
							$coupon,
							'get_discount'
						) ) ) ? $coupon->get_discount() : 0;
					$applied_discount              = min( $total, $discount );
					$applied_discount_smart_coupon += $applied_discount;
				}
			}
			if ( ( $total_discount - $discount_total ) >= abs( convert_monetary_value_to_bolt_format( $applied_discount_smart_coupon ) ) ) {
				$total -= $applied_discount_smart_coupon;
				$order->set_total( $total );
				$order->save( $total );
			}
		}
	}
}

add_action( 'woocommerce_order_after_calculate_totals', '\BoltCheckout\recalculate_order_total_with_smart_coupons_store_credit_discount', 999, 2 );

/**
 * In the pre-auth order creation process, the Bolt plugin would call WC()->cart->calculate_totals() for
 * several times, but WooCommerce Smart Coupons set a limitation on calculation of its store credit that
 * only can run twice during the process. Then we have to reset the count to make discount calculation correct.
 *
 *
 * @since 2.0.2
 * @access public
 *
 */
function reset_count_after_calculate_totals_with_smart_coupons() {
	if ( isset( $_SERVER['REQUEST_URI'] ) && false !== strpos( $_SERVER['REQUEST_URI'], '/bolt/create-order' ) ) {
		global $wp_actions;
		-- $wp_actions['smart_coupons_after_calculate_totals'];
	}
}

add_action( 'smart_coupons_after_calculate_totals', '\BoltCheckout\reset_count_after_calculate_totals_with_smart_coupons' );

/**
 * The tax amount or shipping amount may update during Bolt checkout, to cover these fees, the Bolt plugin
 * send all the available credits of smart store credit to the bolt server.
 *
 *
 * @since 2.0.2
 * @access public
 *
 */
function update_cart_discounts_with_smart_coupons( $bolt_cart ) {
	if ( class_exists( '\WC_Smart_Coupons' ) && isset( $bolt_cart[ BOLT_CART_DISCOUNTS ] ) && ! empty( $bolt_cart[ BOLT_CART_DISCOUNTS ] ) ) {
		if ( 'yes' === get_option( 'woocommerce_smart_coupon_apply_before_tax', 'no' ) ) {
			return $bolt_cart;
		}
		$new_bolt_cart_discounts = array();
		foreach ( $bolt_cart[ BOLT_CART_DISCOUNTS ] as $discount ) {
			$coupon_code   = $discount[ BOLT_CART_DISCOUNT_REFERENCE ];
			$coupon        = new \WC_Coupon( $coupon_code );
			$discount_type = ( is_object( $coupon ) && is_callable( array(
					$coupon,
					'get_discount_type'
				) ) ) ? $coupon->get_discount_type() : '';
			if ( 'smart_coupon' === $discount_type ) {
				$new_bolt_cart_discounts[] = array(
					BOLT_CART_DISCOUNT_AMOUNT      => convert_monetary_value_to_bolt_format( $coupon->get_amount() ),
					BOLT_CART_DISCOUNT_DESCRIPTION => $discount[ BOLT_CART_DISCOUNT_DESCRIPTION ],
					BOLT_CART_DISCOUNT_REFERENCE   => (string) $coupon_code,
					BOLT_CART_DISCOUNT_CATEGORY    => BOLT_DISCOUNT_CATEGORY_COUPON
				);
			} else {
				$new_bolt_cart_discounts[] = $discount;
			}
		}
		$bolt_cart[ BOLT_CART_DISCOUNTS ] = $new_bolt_cart_discounts;
	}

	return $bolt_cart;
}

add_action( 'wc_bolt_order_creation_cart_data', '\BoltCheckout\update_cart_discounts_with_smart_coupons' );

/**
 * Smart Coupon Store Credit does not add discount amount to the discount_total of WC cart,
 * this function is to fix this issue.
 *
 *
 * @since 2.6.0
 * @access public
 *
 */
function apply_smart_coupon_credit_to_cart( $cart_discount_total, $bolt_transaction, $error_handler ) {
	if ( class_exists( '\WC_Smart_Coupons' ) ) {
		$smart_coupon_apply_before_tax = ( 'yes' === get_option( 'woocommerce_smart_coupon_apply_before_tax', 'no' ) );
		if ( Bolt_Feature_Switch::instance()->is_native_cart_session_enabled() && $smart_coupon_apply_before_tax ) {
			return $cart_discount_total;
		}
		$applied_coupons          = is_callable( array(
			WC()->cart,
			'get_coupon_discount_totals'
		) ) ? WC()->cart->get_coupon_discount_totals() : array();
		$smart_coupon_credit_used = isset( WC()->cart->smart_coupon_credit_used ) ? WC()->cart->smart_coupon_credit_used : array();
		if ( ! empty( $applied_coupons ) ) {
			foreach ( $applied_coupons as $code => $discount_amount ) {
				if ( ! array_key_exists( $code, $smart_coupon_credit_used ) ) {
					continue;
				}
				if ( $smart_coupon_apply_before_tax ) {
					calculate_smart_store_credit_before_tax();
				} else {
					$coupon = new \WC_Coupon( $code );
					// From Smart Coupon v7.8.0, the cart already has store credits of smart coupon applied, so we just need to apply the rest of available credits.
					if ( is_sc_gte_78() ) {
						$cart_discount_total += abs( convert_monetary_value_to_bolt_format( $coupon->get_amount() ) ) - abs( convert_monetary_value_to_bolt_format( $smart_coupon_credit_used[ $code ] ) );
					} else {
						$cart_discount_total += abs( convert_monetary_value_to_bolt_format( $coupon->get_amount() ) );
					}
				}

			}
		}
	}

	return $cart_discount_total;

}

add_filter( 'wc_bolt_cart_discount_total', '\BoltCheckout\apply_smart_coupon_credit_to_cart', 10, 3 );


/**
 * Create WC()->cart->smart_coupon_credit_used when restoring Bolt cart.
 *
 *
 * @since 2.6.0
 * @access public
 *
 */
function set_smart_coupon_credit_total_credit_used( $reference, $original_session_data ) {
	if ( class_exists( '\WC_Smart_Coupons' ) && 'yes' === get_option( 'woocommerce_smart_coupon_apply_before_tax', 'no' ) ) {
		if ( ! class_exists( '\WC_SC_Apply_Before_Tax' ) ) {
			$file = trailingslashit( WP_PLUGIN_DIR . '/' . WC_SC_PLUGIN_DIRNAME ) . 'includes/class-wc-sc-apply-before-tax.php';
			if ( ! file_exists( $file ) ) {
				return;
			} else {
				include_once $file;
			}
		}
		calculate_smart_store_credit_before_tax();
	}
}

add_action( 'wc_bolt_after_set_cart_by_bolt_reference', '\BoltCheckout\set_smart_coupon_credit_total_credit_used', 10, 2 );

/**
 * Create WC()->cart->smart_coupon_credit_used when restoring Bolt cart from WooCommerce native cart session.
 *
 *
 * @since 2.15.0
 * @access public
 *
 */
function set_smart_coupon_credit_total_credit_used_before_restore_native_session( $customer_id, $reference ) {
	if ( class_exists( '\WC_Smart_Coupons' ) ) {
		if ( 'yes' !== get_option( 'woocommerce_smart_coupon_apply_before_tax', 'no' ) ) {
			return;
		}
		if ( ! class_exists( '\WC_SC_Apply_Before_Tax' ) ) {
			$file = trailingslashit( WP_PLUGIN_DIR . '/' . WC_SC_PLUGIN_DIRNAME ) . 'includes/class-wc-sc-apply-before-tax.php';
			if ( ! file_exists( $file ) ) {
				return;
			} else {
				include_once $file;
			}
		}
		calculate_smart_store_credit_before_tax();
	}
}

add_action( 'wc_bolt_after_load_cart_from_native_wc_session', 'BoltCheckout\set_smart_coupon_credit_total_credit_used_before_restore_native_session', 10, 2 );

/**
 * When applying the store credit of smart coupons on WooC native checkout page, the function WC_Smart_Coupons\smart_coupons_discounted_totals
 * would check if the store credit is already in use, and if so, it does not calculate the discount for store credit, this causes the
 * cart total mismatch between cart provided amount and computed amount in Bolt cart. So we need to reset store credit used data for re-calculation.
 *
 * @since 2.13.0
 * @access public
 *
 */
function reset_smart_coupon_store_credit_for_payment_only_on_checkout_page( $type, $order_id ) {
	if ( class_exists( '\WC_Smart_Coupons' )
	     && ( $type === BOLT_CART_ORDER_TYPE_CHECKOUT || ( is_sc_gte_78() && $type === BOLT_CART_ORDER_TYPE_CART ) ) ) {
		unset( WC()->cart->smart_coupon_credit_used );
	}
}

add_action( 'wc_bolt_before_build_cart', '\BoltCheckout\reset_smart_coupon_store_credit_for_payment_only_on_checkout_page', 10, 2 );

/**
 * Add Smart Store Credit to cart.
 *
 * @since 2.15.0
 * @access public
 *
 */
function add_smart_store_credit_to_cart( $the_coupon, $code ) {
	if ( ! empty( $the_coupon )
	     || empty( $code )
	     || ! class_exists( '\WC_Smart_Coupons' ) ) {
		return $the_coupon;
	}
	$code            = sanitize_text_field( $code );
	$applied_coupons = is_callable( array(
		WC()->cart,
		'get_applied_coupons'
	) ) ? WC()->cart->get_applied_coupons() : array();
	if ( ! empty( $applied_coupons ) ) {
		foreach ( $applied_coupons as $applied_coupon_code ) {
			if ( $applied_coupon_code === $code ) {
				return $the_coupon;
			}
		}
	}
	$smart_coupon_credit_used = isset( WC()->cart->smart_coupon_credit_used ) ? WC()->cart->smart_coupon_credit_used : array();
	if ( array_key_exists( $code, $smart_coupon_credit_used ) ) {
		return $the_coupon;
	}
	$coupon        = new \WC_Coupon( $code );
	$discount_type = ( is_object( $coupon ) && is_callable( array(
			$coupon,
			'get_discount_type'
		) ) ) ? $coupon->get_discount_type() : '';
	if ( 'smart_coupon' === $discount_type ) {
		// Validate the coupon based on payment method
		WC()->session->set( 'chosen_payment_method', BOLT_GATEWAY_NAME );
		// Validate the coupon based on payment method
		if ( check_smart_coupon_location_validation_enabled( $coupon ) ) {
			$bolt_smart_coupon_address = WC()->session->get( 'bolt_smart_coupon_address', true );
			if ( ! empty( $bolt_smart_coupon_address ) ) {
				WC()->customer->set_location( $bolt_smart_coupon_address[ WC_SHIPPING_COUNTRY ], $bolt_smart_coupon_address[ WC_SHIPPING_STATE ], $bolt_smart_coupon_address[ WC_SHIPPING_POSTCODE ], $bolt_smart_coupon_address[ WC_SHIPPING_CITY ] );
				WC()->customer->set_shipping_address( $bolt_smart_coupon_address[ WC_SHIPPING_ADDRESS_1 ] );
				WC()->customer->save();
			}
		}
		WC()->cart->add_discount( $code );
		if ( 'yes' === get_option( 'woocommerce_smart_coupon_apply_before_tax', 'no' ) ) {
			calculate_smart_store_credit_before_tax();
		}
	}

	return $the_coupon;
}

add_filter( 'wc_bolt_add_third_party_discounts_to_cart', '\BoltCheckout\add_smart_store_credit_to_cart', 10, 2 );

/**
 * Add Smart Store Credit from discount hook.
 *
 * @since 2.15.0
 * @access public
 *
 */
function add_smart_store_credit_from_discount_hook( $discount_info, $code ) {
	if ( empty( $code )
	     || ! class_exists( '\WC_Smart_Coupons' )
	     || Bolt_Feature_Switch::instance()->is_native_cart_session_enabled() ) {
		return $discount_info;
	}
	$code                          = sanitize_text_field( $code );
	$smart_coupon_apply_before_tax = ( 'yes' === get_option( 'woocommerce_smart_coupon_apply_before_tax', 'no' ) );
	$existing_coupon               = false;
	if ( ! $smart_coupon_apply_before_tax ) {
		$applied_coupons = is_callable( array(
			WC()->cart,
			'get_applied_coupons'
		) ) ? WC()->cart->get_applied_coupons() : array();
		if ( ! empty( $applied_coupons ) ) {
			foreach ( $applied_coupons as $applied_coupon_code ) {
				if ( $applied_coupon_code === $code ) {
					if ( ! empty( $discount_info ) ) {
						return $discount_info;
					} else {
						$existing_coupon = true;
					}
				}
			}
		}
	}
	$coupon        = new \WC_Coupon( $code );
	$discount_type = ( is_object( $coupon ) && is_callable( array(
			$coupon,
			'get_discount_type'
		) ) ) ? $coupon->get_discount_type() : '';
	if ( 'smart_coupon' === $discount_type ) {
		$smart_coupon_credit_used = isset( WC()->cart->smart_coupon_credit_used ) ? WC()->cart->smart_coupon_credit_used : array();
		if ( ! array_key_exists( $code, $smart_coupon_credit_used ) ) {
			// For this case, WC()->cart->get_applied_coupons() has code of smart coupon but WC()->cart->smart_coupon_credit_used is empty,
			// so we have to remove the coupon and re-add it.
			if ( $existing_coupon ) {
				WC()->cart->remove_coupon( $code );
			}
			if ( $smart_coupon_apply_before_tax || $existing_coupon ) {
				// Validate the coupon based on payment method
				WC()->session->set( 'chosen_payment_method', BOLT_GATEWAY_NAME );
				// Validate the coupon based on payment method
				if ( check_smart_coupon_location_validation_enabled( $coupon ) ) {
					$bolt_smart_coupon_address = WC()->session->get( 'bolt_smart_coupon_address', true );
					if ( ! empty( $bolt_smart_coupon_address ) ) {
						WC()->customer->set_location( $bolt_smart_coupon_address[ WC_SHIPPING_COUNTRY ], $bolt_smart_coupon_address[ WC_SHIPPING_STATE ], $bolt_smart_coupon_address[ WC_SHIPPING_POSTCODE ], $bolt_smart_coupon_address[ WC_SHIPPING_CITY ] );
						WC()->customer->set_shipping_address( $bolt_smart_coupon_address[ WC_SHIPPING_ADDRESS_1 ] );
						WC()->customer->save();
					}
				}
				WC()->cart->add_discount( $code );
			}
		}
		if ( $smart_coupon_apply_before_tax ) {
			calculate_smart_store_credit_before_tax();
		}
		$discount_info = array(
			'discount_code'             => $code,
			'discount_type'             => 'fixed_amount',
			BOLT_CART_DISCOUNT_CATEGORY => BOLT_DISCOUNT_CATEGORY_GIFTCARD
		);
	}

	return $discount_info;
}


add_filter( 'wc_bolt_add_third_party_discounts_to_cart_from_discount_hook', '\BoltCheckout\add_smart_store_credit_from_discount_hook', 10, 2 );

/**
 * Apply store credit before tax calculation.
 *
 * @since 2.15.0
 * @access public
 *
 */
function calculate_smart_store_credit_before_tax() {
	$sc_apply_before_tax = \WC_SC_Apply_Before_Tax::get_instance();
	$sc_apply_before_tax->cart_calculate_discount_amount();
	$sc_apply_before_tax->cart_set_total_credit_used();
}

/**
 * Save shipping address in the session, and the smart coupon plugin could vailidate coupon by location.
 *
 * @since 2.19.0
 * @access public
 *
 */
function save_customer_shipping_address_for_smart_coupons( $shipping_address, $order_reference ) {
	if ( class_exists( '\WC_Smart_Coupons' ) ) {
		$original_session_data                              = wc_bolt_data()->get_session( BOLT_PREFIX_SESSION_DATA . $order_reference );
		$original_session_data['bolt_smart_coupon_address'] = $shipping_address;
		wc_bolt_data()->update_session( BOLT_PREFIX_SESSION_DATA . $order_reference, $original_session_data );
	}
}

add_action( 'wc_bolt_shippingtax_after_set_customer_shipping_address', '\BoltCheckout\save_customer_shipping_address_for_smart_coupons', 99, 2 );

/**
 * Check if the smart coupon has location validation enabled
 *
 * @since 2.19.0
 * @access public
 *
 */
function check_smart_coupon_location_validation_enabled( $coupon ) {
	$coupon_id           = $coupon->get_id();
	$locations_lookup_in = get_post_meta( $coupon_id, 'sa_cbl_locations_lookup_in', true );
	if ( empty( $locations_lookup_in ) || empty( $locations_lookup_in['address'] ) ) {
		return false;
	}
	$locations = get_post_meta( $coupon_id, 'sa_cbl_' . $locations_lookup_in['address'] . '_locations', true );
	if ( ! empty( $locations ) && is_array( $locations ) && ! empty( $locations['additional_locations'] ) && is_array( $locations['additional_locations'] ) && array_key_exists( 'additional_locations', $locations ) ) {
		return true;
	}

	return false;
}

/**
 * To set customer location for performing address validation by smart coupon.
 *
 * @since 2.19.0
 * @access public
 *
 */
function set_shipping_address_smart_coupon_validation_before_is_valid( $flag, $coupon, $wc_discount ) {
	if ( ! $flag
	     || empty( $coupon )
	     || ! class_exists( '\WC_Smart_Coupons' )
	     || Bolt_Feature_Switch::instance()->is_native_cart_session_enabled()
	     || ! check_smart_coupon_location_validation_enabled( $coupon ) ) {
		return $flag;
	}
	$bolt_smart_coupon_address = WC()->session->get( 'bolt_smart_coupon_address', true );
	if ( ! empty( $bolt_smart_coupon_address ) ) {
		WC()->customer->set_location( $bolt_smart_coupon_address[ WC_SHIPPING_COUNTRY ], $bolt_smart_coupon_address[ WC_SHIPPING_STATE ], $bolt_smart_coupon_address[ WC_SHIPPING_POSTCODE ], $bolt_smart_coupon_address[ WC_SHIPPING_CITY ] );
		WC()->customer->set_shipping_address( $bolt_smart_coupon_address[ WC_SHIPPING_ADDRESS_1 ] );
		WC()->customer->save();
	}

	return $flag;
}

add_filter( 'woocommerce_coupon_is_valid', '\BoltCheckout\set_shipping_address_smart_coupon_validation_before_is_valid', 10, 3 );

/**
 * Bypass shipping total comparison if cart has smart coupon applied.
 *
 * @since 2.19.0
 * @access public
 *
 */
function fix_wc_smart_coupon_shipping_difference( $shipping_total, $bolt_transaction, $error_handler ) {
	if ( ! class_exists( "WC_Smart_Coupons" ) ) {
		return $shipping_total;
	}
	$applied_coupons = WC()->cart->get_applied_coupons();
	foreach ( $applied_coupons as $coupon_code ) {
		$coupon        = new \WC_Coupon( $coupon_code );
		$discount_type = ( is_object( $coupon ) && is_callable( array(
				$coupon,
				'get_discount_type'
			) ) ) ? $coupon->get_discount_type() : '';
		if ( 'smart_coupon' === $discount_type ) {
			return $bolt_transaction->order->cart->shipping_amount->amount;
		}
	}

	return $shipping_total;
}

add_filter( 'wc_bolt_cart_shipping_total', '\BoltCheckout\fix_wc_smart_coupon_shipping_difference', 10, 3 );

/**
 * Function to check if Smart Coupon plugin version is Greater Than And Equal To 7.8.0
 *
 * @since 2.19.0
 * @access public
 *
 */
function is_sc_gte_78() {
	$plugin_data = \WC_Smart_Coupons::get_smart_coupons_plugin_data();
	$version     = isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : false;

	return version_compare( $version, '7.8.0', '>=' );
}