<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( \Objectiv\Plugins\Checkout\Main::class ) ) {
	return;
}

add_action( 'cfw_after_side_cart_proceed_to_checkout_button', [
	\BoltCheckout\Bolt_HTML_Handler::instance(),
	'button_on_minicart'
] );

function boltpay_hide_cfw_side_cart_checkout_button() {
	if ( ! wc_bolt_if_show_on_mini_cart() ) {
		return;
	}

	echo <<<HTML
<style type="text/css">
#cfw-side-cart .cfw-side-cart-checkout-btn {
	display: none;
}
#cfw-side-cart .wc-proceed-to-checkout #bolt-minicart {
    text-align: center;
}
</style>
HTML;
}

add_action( 'cfw_after_side_cart_proceed_to_checkout_button', '\BoltCheckout\boltpay_hide_cfw_side_cart_checkout_button' );

function boltpay_hook_ajax_call_cfw_side_cart() {
	echo <<<HTML
<script>
jQuery(document).ajaxSend(function(event, xhr, settings) {
    if (jQuery('#bolt-minicart').length > 0 && settings.url.indexOf('wc-ajax=update_side_cart') !== -1) {
        jQuery('.wc-proceed-to-checkout').block( {
            message: null,
            overlayCSS: {
				background: '#fff',
                opacity: 0.5
            }
        } );
    }
});
</script>
HTML;
}

add_action( 'wp_footer', '\BoltCheckout\boltpay_hook_ajax_call_cfw_side_cart', 10001 );