<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bolt Third-party addons support Functions
 *
 * Functions to support WooCommerce Extra Shipping Options.
 * Tested up to: 1.2.4
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */

/**
 * Check whether the WooCommerce Extra Shipping Options is enabled.
 *
 *
 * @since 2.14.0
 * @access public
 *
 */
function check_if_woocommerce_extra_shipping_options_enabled() {
	return ( class_exists( 'WooCommerce_Extra_Shipping_Options' ) && 'yes' === get_option( 'enable_woocommerce_extra_shipping_options', 'no' ) ) ? true : false;
}

/**
 * To adapt the extra shipping options addon, add each choice as a single shipping option with original shipping option.
 *
 *
 * @since 2.14.0
 * @access public
 *
 */
function add_extra_shipping_options( $shipping_options, $bolt_order ) {
	if ( ! check_if_woocommerce_extra_shipping_options_enabled() ) {
		return $shipping_options;
	}

	$new_shipping_options = array();

	if ( WC()->cart->needs_shipping() ) {
		if ( ! empty( $shipping_options ) ) {
			foreach ( $shipping_options as $shipping_option ) {
				$new_shipping_options[]  = $shipping_option;
				$chosen_shipping_methods = array( '0' => $shipping_option->reference );
				WC()->session->set( 'chosen_shipping_methods', $chosen_shipping_methods );

				// Get available extra shipping options for chosen shipping method
				$packages               = WC()->shipping->get_packages();
				$extra_shipping_options = \weso_get_shipping_option_posts();

				$extra_shipping_option_groups = array();
				foreach ( $packages as $package_index => $package ) {
					foreach ( $extra_shipping_options as $shipping_option_post ) {
						// Only retrieve extra shipping options that match the conditions.
						$condition_groups = get_post_meta( $shipping_option_post->ID, '_shipping_option_conditions', true );
						if ( \wpc_match_conditions( $condition_groups, array(
							'context'       => 'weso',
							'package'       => $package,
							'package_index' => $package_index
						) ) ) {
							$choices_group_cost      = 0;
							$choices_group_tax       = 0;
							$choices_group_reference = '';
							$choices_group_name      = '';
							foreach ( \weso_get_shipping_options( $shipping_option_post->ID ) as $key => $weso_shipping_option ) {
								if ( $option = new \WESO_Shipping_Option( $shipping_option_post->ID, $weso_shipping_option['option_id'], $package_index ) ) {
									$option_id               = $option->get_field()->shipping_option_args['option_id'];
									$shipping_option_post_id = absint( $option->get_field()->shipping_option->post_id );
									$choices                 = $option->get_field()->get_choices();

									foreach ( $choices as $k => $choice ) {
										$choice_cost = $option->get_field()->get_choice_total_cost( $choice );
										$choice_cost = (int) round( $choice_cost * 100 );
										$eso_tax     = 0;
										if ( $choice_cost > 0 ) {
											if ( $option->get_field()->shipping_option->taxable() ) {
												$eso_tax = $option->get_field()->get_choice_tax( $choice );
												$eso_tax = (int) round( $eso_tax * 100 );
											}
											$reference_current       = $shipping_option_post_id . '+' . $option_id . '+' . $choice['key'] . '+' . $choice_cost . '+' . $eso_tax;
											$choices_group_cost      += $choice_cost;
											$choices_group_tax       += $eso_tax;
											$choices_group_reference .= '#' . $shipping_option_post_id . '+' . $option_id . '+' . $choice['key'] . '+' . $choice_cost . '+' . $eso_tax;
											$choices_group_name      .= ' + ' . $choice['name'];

											$extra_shipping_option_groups[ $reference_current ] = array(
												'cost'      => $choice_cost,
												'tax'       => $eso_tax,
												'reference' => $reference_current,
												'name'      => $choice['name'],
											);
										}
									}
								}
							}
						}
					}
				}

				// Loop all the available extra shipping options and combine them with each other
				// eg.
				// Original shipping options : A / B
				// Extra shipping options : a / b / c (they are available for all the original shipping options)
				// Results :
				// A, A+a, A+b, A+c, A+a+b, A+a+c, A+b+c, A+a+b+c
				// B, B+a, B+b, B+c, B+a+b, B+a+c, B+b+c, B+a+b+c
				$composed_shipping_options = array();
				foreach ( $extra_shipping_option_groups as $reference_current => $extra_shipping_option_data ) {
					$composed_shipping_options[ $reference_current ] = array(
						'service'    => $shipping_option->service . ' + ' . $extra_shipping_option_data['name'],
						'cost'       => $shipping_option->cost + $extra_shipping_option_data['cost'],
						'reference'  => $shipping_option->reference . '#' . $reference_current,
						'tax_amount' => $shipping_option->tax_amount + $extra_shipping_option_data['tax'],
					);

					$temp_choices_options = $composed_shipping_options;
					foreach ( $temp_choices_options as $key => $choices_option ) {
						if ( $reference_current != $key ) {
							$new_reference                               = $key . '#' . $reference_current;
							$composed_shipping_options[ $new_reference ] = array(
								'service'    => $choices_option['service'] . ' + ' . $extra_shipping_option_data['name'],
								'cost'       => $choices_option['cost'] + $extra_shipping_option_data['cost'],
								'reference'  => $choices_option['reference'] . '#' . $reference_current,
								'tax_amount' => $choices_option['tax_amount'] + $extra_shipping_option_data['tax'],
							);
						}
					}
				}
				foreach ( $composed_shipping_options as $choices_option ) {
					$shipping_options[] = (object) $choices_option;
				}
			}
		}
	}

	return $shipping_options;
}

add_filter( 'wc_bolt_after_load_shipping_options', '\BoltCheckout\add_extra_shipping_options', 9, 2 );

/**
 * Restore the selected extra shipping options when set cart by Bolt reference.
 *
 * @since 2.14.0
 * @access public
 *
 */
function restore_extra_shipping_option_after_set_cart_by_bolt_reference( $reference, $original_session_data ) {
	if ( ! check_if_woocommerce_extra_shipping_options_enabled() ) {
		return;
	}

	restore_extra_shipping_option();
}

add_action( 'wc_bolt_after_set_cart_by_bolt_reference', '\BoltCheckout\restore_extra_shipping_option_after_set_cart_by_bolt_reference', 10, 2 );

/**
 * Restore the selected extra shipping options after loading cart from WooCommerce native session.
 *
 * @since 2.15.0
 * @access public
 *
 */
function restore_extra_shipping_option_after_load_native_wc_session( $customer_id, $reference ) {
	if ( ! check_if_woocommerce_extra_shipping_options_enabled() ) {
		return;
	}

	restore_extra_shipping_option();
}

add_action( 'wc_bolt_after_load_cart_from_native_wc_session', 'BoltCheckout\restore_extra_shipping_option_after_load_native_wc_session', 10, 2 );

/**
 * Restore the selected extra shipping options when checking cart items.
 *
 * @since 2.14.0
 * @access public
 *
 */
function restore_extra_shipping_option_check_cart_items() {
	if ( ! check_if_woocommerce_extra_shipping_options_enabled() ) {
		return;
	}

	restore_extra_shipping_option();
}

add_action( 'woocommerce_check_cart_items', '\BoltCheckout\restore_extra_shipping_option_check_cart_items', 9 );

/**
 * Restore the selected extra shipping options before calculating cart totals.
 *
 * @since 2.14.0
 * @access public
 *
 */
function restore_extra_shipping_option_before_calculate_totals( $cart ) {
	if ( ! check_if_woocommerce_extra_shipping_options_enabled() ) {
		return;
	}

	restore_extra_shipping_option();
}

add_action( 'woocommerce_before_calculate_totals', '\BoltCheckout\restore_extra_shipping_option_before_calculate_totals', 9, 1 );

/**
 * Set up the selected extra shipping options from the api request.
 *
 * @since 2.14.0
 * @access public
 *
 */
function restore_extra_shipping_option() {
	$api_response = Bolt_Checkout::get_bolt_transaction();

	// When implement create-order endpoint and call set_cart_by_bolt_reference_return_all_error function,
	// BoltCheckout\Bolt_Checkout::get_bolt_transaction would return null at that time.
	if ( empty( $api_response ) && false !== strpos( $_SERVER['REQUEST_URI'], '/bolt/create-order' ) ) {
		$get_data     = file_get_contents( 'php://input' );
		$api_response = json_decode( $get_data );
	}

	if ( $api_response && isset( $api_response->order->cart->shipments ) && ! empty( $api_response->order->cart->shipments ) ) {
		$shipping_method_reference = $api_response->order->cart->shipments[0]->reference;

		if ( strpos( $shipping_method_reference, '#' ) !== false ) {
			$shipping_method_reference_array = explode( '#', $shipping_method_reference );

			$selected_shipping_method_reference = $shipping_method_reference_array[0];

			WC()->session->set( 'chosen_shipping_methods', array( $selected_shipping_method_reference ) );
			$calculated_shipping_packages = WC()->shipping->calculate_shipping( WC()->cart->get_shipping_packages() );
			$method_counts                = array();
			$previous_shipping_methods    = array();
			foreach ( $calculated_shipping_packages as $key => $package ) {
				$method_counts[ $key ]             = count( $package['rates'] );
				$previous_shipping_methods[ $key ] = array_keys( $package['rates'] );
			}
			WC()->session->set( 'shipping_method_counts', $method_counts );
			WC()->session->set( 'previous_shipping_methods', $previous_shipping_methods );

			$extra_shipping_options_cost       = 0;
			$extra_shipping_options_tax        = 0;
			$extra_shipping_option_groups      = array();
			$selected_shipping_option_post_ids = array();
			foreach ( $shipping_method_reference_array as $k => $shipping_method_reference_options_array ) {
				if ( $k == 0 ) {
					continue;
				}
				$shipping_method_reference_suboptions_array = explode( '+', $shipping_method_reference_options_array );

				$selected_shipping_option_post_id    = $shipping_method_reference_suboptions_array[0];
				$selected_shipping_option_id         = $shipping_method_reference_suboptions_array[1];
				$selected_shipping_choice_key        = $shipping_method_reference_suboptions_array[2];
				$selected_shipping_extra_option_cost = $shipping_method_reference_suboptions_array[3];
				$selected_shipping_extra_option_tax  = $shipping_method_reference_suboptions_array[4];

				$selected_shipping_option_post_ids[] = $selected_shipping_option_post_id;
				if ( ! isset( $extra_shipping_option_groups[ $selected_shipping_option_post_id ] ) ) {
					$extra_shipping_option_groups[ $selected_shipping_option_post_id ] = array();
				}
				if ( ! isset( $extra_shipping_option_groups[ $selected_shipping_option_post_id ][ $selected_shipping_option_id ] ) ) {
					$extra_shipping_option_groups[ $selected_shipping_option_post_id ][ $selected_shipping_option_id ]      = array();
					$extra_shipping_option_groups[ $selected_shipping_option_post_id ][ $selected_shipping_option_id ]["0"] = "";
				}
				$extra_shipping_option_groups[ $selected_shipping_option_post_id ][ $selected_shipping_option_id ][ $selected_shipping_choice_key ] = "yes";
				$extra_shipping_options_cost                                                                                                        += (int) $selected_shipping_extra_option_cost;
				$extra_shipping_options_tax                                                                                                         += (int) $selected_shipping_extra_option_tax;
			}
			$extra_shipping_option_data = array(
				'0' => $extra_shipping_option_groups
			);

			WC()->session->set( 'extra_shipping_options', $extra_shipping_option_data );

			$_POST['extra_shipping_option'] = $extra_shipping_option_data;

			$shipping_method_label_array = explode( '+', $api_response->order->cart->shipments[0]->service );

			$api_response->order->cart->shipments[0]->reference          = $selected_shipping_method_reference;
			$api_response->order->cart->shipments[0]->service            = trim( $shipping_method_label_array[0] );
			$api_response->order->cart->shipments[0]->cost->amount       = (int) $api_response->order->cart->shipments[0]->cost->amount - $extra_shipping_options_cost;
			$api_response->order->cart->shipments[0]->tax_amount->amount = (int) $api_response->order->cart->shipments[0]->tax_amount->amount - $extra_shipping_options_tax;

			// in WooCommerce Extra Shipping Options 1.2.1, its function get the selected extra shipping options from wp cache instead
			$posts            = new \WP_Query( array(
				'posts_per_page' => 10,
				'post_type'      => 'shipping_option',
				'post_status'    => 'publish',
				'orderby'        => 'menu_order',
				'order'          => 'ASC',
				'post__in'       => $selected_shipping_option_post_ids,
			) );
			$shipping_options = $posts->posts;
			wp_cache_set( 'extra-shipping-options', $shipping_options, 'weso' );

			WC()->session->set( 'bolt_extra_shipping_options', $shipping_options );
			// save the calculated extra shipping option fee
			WC()->session->set( 'bolt_extra_shipping_options_cost', $extra_shipping_options_cost );
		} else { // if the selected shipping method does not include any extra shipping option
			WC()->session->set( 'chosen_shipping_methods', array( $shipping_method_reference ) );
			$bolt_extra_shipping_options = WC()->session->get( 'bolt_extra_shipping_options' );
			if ( ! empty( $bolt_extra_shipping_options ) ) {
				wp_cache_set( 'extra-shipping-options', $bolt_extra_shipping_options, 'weso' );
			} else {
				wp_cache_set( 'extra-shipping-options', array(), 'weso' );
			}
		}

	}
}

/**
 * Correct the cart shipping total by saved extra shipping option fee.
 *
 * @since 2.14.0
 * @access public
 *
 */
function bolt_correct_cart_total_when_calculate_totals( $cart ) {
	if ( ! check_if_woocommerce_extra_shipping_options_enabled() ) {
		return;
	}

	$extra_shipping_options_cost = WC()->session->get( 'bolt_extra_shipping_options_cost' );
	if ( ! empty( $extra_shipping_options_cost ) ) {
		$cart->set_shipping_total( $cart->get_shipping_total() + ( $extra_shipping_options_cost / 100 ) );
	}
}

add_action( 'woocommerce_calculate_totals', '\BoltCheckout\bolt_correct_cart_total_when_calculate_totals', 999 );

/**
 * Correct the cart total by saved extra shipping option fee.
 *
 * @since 2.14.0
 * @access public
 *
 */
function bolt_correct_cart_total_with_calculated_total( $total, $cart ) {
	$extra_shipping_options_cost = WC()->session->get( 'bolt_extra_shipping_options_cost' );

	if ( check_if_woocommerce_extra_shipping_options_enabled() && ! empty( $extra_shipping_options_cost ) ) {
		return $total - weso_get_extra_shipping_option_cost() + ( $extra_shipping_options_cost / 100 );
	}

	return $total;
}

add_filter( 'woocommerce_calculated_total', '\BoltCheckout\bolt_correct_cart_total_with_calculated_total', 999, 2 );

/**
 * Due to the special logic of extra shipping options addon, we have to apply the extra amount.
 *
 * @since 2.14.0
 * @access public
 *
 */
function apply_extra_shipping_cost_after_calculate_totals( $and_taxes, $order ) {
	if ( ! check_if_woocommerce_extra_shipping_options_enabled() ) {
		return;
	}

	$bolt_transaction_reference_id = wc_bolt_get_order_meta( $order, 'bolt_transaction_reference_id', true );
	$bolt_transaction              = wc_bolt()->bolt_api_manager()->api_request( 'transactions/' . $bolt_transaction_reference_id );
	$original_wc_total             = $order->get_total();
	$price_difference              = (int) ( $bolt_transaction->amount->amount - round( $original_wc_total * 100 ) );
	$extra_shipping_options        = $order->get_items( 'shipping_option' );

	if ( ! empty( $extra_shipping_options ) ) {
		$shipping_option_cost = 0;
		$shipping_option_tax  = 0;
		foreach ( $extra_shipping_options as $item_id => $item ) {
			$shipping_option_cost += $item['cost'];
			$shipping_option_tax  += $item['tax_amount'];
		}

		if ( $price_difference >= (int) round( $shipping_option_cost * 100 ) ) {
			$order->set_cart_tax( $order->get_cart_tax() + $shipping_option_tax );
			$order->set_total( $order->get_total() + $shipping_option_cost );
			$order->save();
		}
	}
}

add_action( 'woocommerce_order_after_calculate_totals', '\BoltCheckout\apply_extra_shipping_cost_after_calculate_totals', 99, 2 );