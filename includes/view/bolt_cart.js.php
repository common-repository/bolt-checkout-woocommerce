var bolt_cart = <?= $json_cart;?>;
var bolt_hint = <?= $json_hints;?>;
var bolt_cart_availability = <?= $cart_availability;?>;
<?= $bolt_on_email_enter;?>
var bolt_callbacks;
<?php if ( $wrap_in_jquery_ready ) :?>
jQuery(document).ready(function () {
	<?php endif; ?>
    if (typeof Bolt_Review === 'undefined') {
        jQuery.ajax({
                type: 'POST',
                url: '<?= \WC_AJAX::get_endpoint( 'wc_bolt_record_frontend_error' ); ?>',
                data: {text: 'Bolt_Review is undefined', href: document.location.href, cart: bolt_cart}
            }
        );
    }
	<?= $bolt_review_obj; ?>
    bolt_callbacks = {
        onEmailEnter: function (email) {
            <?= $onemailenter;?>
        },
        close: function () {
            <?= $close;?>
            if (redirect_url) {
                window.location = redirect_url;
            }
        },
        success: function (transaction, callback) {
            <?= $success;?>
        },
        check: function () {
            <?= $check;?>
            <?= $login_check;?>
            <?= $check_callback;?>
            redirect_url = '';
            if (!bolt_cart_availability) {
                return false;
            }
            return <?= $check_return;?>;
        },
        onCheckoutStart: function () {
            <?= $oncheckoutstart;?>
        },
        onShippingDetailsComplete: function () {
            <?= $onshippingdetailscomplete;?>
        },
        onShippingOptionsComplete: function () {
            <?= $onshippingoptionscomplete;?>
        },
        onPaymentSubmit: function () {
            <?= $onpaymentsubmit;?>
        },
    };
    if (typeof BoltCheckout === 'undefined') {
        bolt_record_frontend_error('BoltCheckout is undefined');
    }
    BoltCheckout.configure(
        bolt_cart,
        bolt_hint,
        bolt_callbacks
    );
	<?php if ( $wrap_in_jquery_ready ) :?>
});
<?php endif; ?>
<?= $javascript_additional;?>
