<?php

namespace BoltCheckout;

if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists(\xoo_wsc::class)) {
	return;
}

add_action('xoo_wsc_after_footer', [\BoltCheckout\Bolt_HTML_Handler::instance(), 'button_on_minicart']);

function boltpay_hide_xoo_wsc_checkout_button()
{
	if (!wc_bolt_if_show_on_mini_cart()) {
		return;
	}

	echo <<<HTML
<style type="text/css">
.xoo-wsc-footer-b a.button.xoo-wsc-chkt {
	display: none;
}
</style>
HTML;
}

add_action('xoo_wsc_after_footer', '\BoltCheckout\boltpay_hide_xoo_wsc_checkout_button');