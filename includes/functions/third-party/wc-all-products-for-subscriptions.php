<?php
/**
 * WooCommerce Bolt Third-party addons support
 *
 * Class to support the WooCommerce All Products For Subscriptions plugin
 *
 * @package Woocommerce_Bolt_Checkout/Functions
 * @version 1.0.0
 */

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if (!class_exists(\WCS_ATT::class)) {
    return;
}

/**
 * Add additional javascript to toggle bolt checkout button visibility when selecting a subscription plan on the product page
 *
 * @param $params
 * @return mixed
 */
function wc_bolt_toggle_button_visibility_on_checkout_page($params) {
    $params['additional_javascript'] .= "
        var subscriptionOption = jQuery('.wcsatt-options-product').find('input[type=radio]');
        if (typeof subscriptionOption !== 'undefined') {
            if (subscriptionOption.val() != 0) {
                jQuery('.bolt-page-checkout-button').hide();
            }
            subscriptionOption.on('change', function () {
                if (jQuery(this).val() != 0) {
                    jQuery('.bolt-page-checkout-button').hide();
                } else {
                    jQuery('.bolt-page-checkout-button').show();
                }
            });
        }
    ";
    return $params;
}

add_filter('wc_bolt_filter_product_page_button_js_params', '\BoltCheckout\wc_bolt_toggle_button_visibility_on_checkout_page', 10, 1);