<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Bolt Third-party addons support Functions
 *
 * Functions to support WooCommerce TM Extra Product Options.
 * Tested up to: 5.1
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */


/**
 * Add additional js to collect tm extra product options data.
 *
 *
 * @since 2.15.0
 * @access public
 *
 */
function add_tm_extra_product_options_product_page_button_js_params( $params ) {
	if ( class_exists( '\THEMECOMPLETE_Extra_Product_Options' ) && \THEMECOMPLETE_EPO()->can_load_scripts() ) {
		$params['additional_javascript'] = 'var bolt_epo_required = true;
                var epo_id = $cart_form.find(".tm-epo-counter").val();
                var epos = jQuery(\'.tc-extra-product-options[data-epo-id="\' + epo_id + \'"]\');
                if (epos.length > 0) {
                    bolt_epo_required = false;
                }
				jQuery(window).on("epo_options_visible", function () {
					collectTMCPFieldsData();
				});
				$cart_form.find(".tmcp-field,.tmcp-sub-fee-field,.tmcp-fee-field,.tm-quantity").on("tc_element_epo_rules change", function (a) {
					collectTMCPFieldsData();
				});
				function collectTMCPFieldsData() {
					// To collect the updated tmcp field.
					setTimeout(function () {
						bolt_epo_required = false;
						if (!jQuery("form.cart").tc_validate()) {
							return false;
						}
						var tmcp_price_element = jQuery("#tm-epo-totals .tm-final-totals .price.amount.final");
						if (tmcp_price_element.length > 0) {
							var current_total = tmcp_price_element.html();
							wc_bolt_items[0]["price"] = Math.round((Number(current_total.replace(/[^0-9.-]+/g, "")) / wc_bolt_items[0]["quantity"]) * ' . $params['currency_divider'] . ' ) / ' . $params['currency_divider'] . ';
						}
						wc_bolt_items[0]["properties"] = [{
							name: "form_post",
							value: $cart_form.serialize()
						}];
						setupProductPage();
						bolt_epo_required = true;
					}, 100);
				}' . $params['additional_javascript'];
	}

	return $params;
}

add_filter( 'wc_bolt_filter_product_page_button_js_params', '\BoltCheckout\add_tm_extra_product_options_product_page_button_js_params', 10, 1 );

/**
 * Collect tm extra product options data before updating Bolt cart items.
 *
 *
 * @since 2.15.0
 * @access public
 *
 */
function collect_tmcp_fields_data_before_ppc_variation_form_set_bolt_items() {
	if ( class_exists( '\THEMECOMPLETE_Extra_Product_Options' ) && \THEMECOMPLETE_EPO()->can_load_scripts() ) {
		echo 'collectTMCPFieldsData();';
	}
}

add_action( 'wc_bolt_before_ppc_variation_form_set_bolt_items', '\BoltCheckout\collect_tmcp_fields_data_before_ppc_variation_form_set_bolt_items', 10 );

/**
 * Add js to validate the fields of tm extra product options.
 *
 *
 * @since 2.15.0
 * @access public
 *
 */
function collect_tmcp_fields_data_before_ppc_check_callback() {
	if ( class_exists( '\THEMECOMPLETE_Extra_Product_Options' ) && \THEMECOMPLETE_EPO()->can_load_scripts() ) {
		echo 'if (!bolt_epo_required) {
                return false;
            }
			var epo_id = $cart_form.find(".tm-epo-counter").val();
            var epos = jQuery(\'.tc-extra-product-options[data-epo-id="\' + epo_id + \'"]\');
            if (epos.length > 0 && !$cart_form.tc_validate().form()) {
                return false;
            }';
	}
}

add_action( 'wc_bolt_before_ppc_check_callback', '\BoltCheckout\collect_tmcp_fields_data_before_ppc_check_callback', 10 );

/**
 * The Bolt plugin fails to decode value of transaction.shipping_option.value.signature, set it to empty to fix this issue.
 *
 *
 * @since 2.15.0
 * @access public
 *
 */
function add_tm_extra_product_options_product_page_ppc_button_js_params( $ppc_params, $params ) {
	if ( class_exists( '\THEMECOMPLETE_Extra_Product_Options' ) && \THEMECOMPLETE_EPO()->can_load_scripts() ) {
		$ppc_params['success'] = 'transaction.shipping_option.value.signature="";' . $ppc_params['success'];
	}

	return $ppc_params;
}

add_filter( 'wc_bolt_filter_product_page_ppc_button_js_params', '\BoltCheckout\add_tm_extra_product_options_product_page_ppc_button_js_params', 10, 2 );