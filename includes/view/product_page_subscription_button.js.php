var wc_bolt_subscription_items_json = "";
function setupBoltSubscription() {
    //Woocommerce can fire show_variation event few times.
    //So we need to prevent duplicate call for configureSubscription with same argument.
    if (JSON.stringify(wc_bolt_items) == wc_bolt_subscription_items_json) {
        return 0;
    }
    wc_bolt_subscription_items_json = JSON.stringify(wc_bolt_items);

    var bolt_cart_hints = <?= $json_hints; ?>;
    var bolt_cart = {
        currency: "<?= $currency; ?>",
        items: wc_bolt_items,
    };
    var bolt_callbacks = {
        close: function () {
            <?= $close; ?>
            if (redirect_url) {
                window.location = redirect_url;
            }
        },
        success: function (transaction, callback) {
            <?= $success; ?>
            jQuery('p_method_boltpay_reference').value = transaction.reference;
            jQuery('p_method_boltpay_transaction_status').value = transaction.status;
            // WooCommerce would try to add item to cart on wp_loaded if $_REQUEST['add-to-cart'] has valid value.
            // Then it would result in wp_verify_nonce failure due to the changes in cart session,
            // so we have to eliminate this field before saving order.
            if (jQuery('input[name=add-to-cart]').length > 0) {
                jQuery('input[name=add-to-cart]').val(0);
            }
            wc_bolt_checkout.save_checkout(transaction, callback, 'product_page');
        },
        check: function () {
            if ((wc_bolt_max_qty !== "") && (wc_bolt_max_qty < wc_bolt_items[0].quantity)) {
                if (wc_bolt_max_qty > 0) {
                    window.alert('Sorry, you want to buy ' + wc_bolt_items[0].quantity + ' items, but we have only ' + wc_bolt_max_qty + ' on stock');
                } else {
                    window.alert('Sorry, this product is unavailable. Please choose a different combination.');
                }
                return false;
            }
            if (!wc_product_is_purchasable) {
                window.alert('Please select some product options before starting checkout.');
                return false;
            }
			<?php
			// support the addon `WooCommerce TM Extra Product Options`
			if ( defined( 'TM_EPO_PLUGIN_SECURITY' ) ){
			?>
            // validate tm extra product options
            var epo_id = $cart_form.find('.tm-epo-counter').val();
            var epos = jQuery('.tc-extra-product-options[data-epo-id="' + epo_id + '"]');
            if (epos.length > 0 && !$cart_form.tc_validate().form()) {
                return false;
            }
			<?php
			}
			?>
			<?= $check; ?>
            redirect_url = '';
            return true;
        },
        onAddToCart: function () {
            jQuery('.single_add_to_cart_button').click();
        },
        onEmailEnter: function (email) {
            <?= $onemailenter;?>
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
    BoltCheckout.configureSubscription(bolt_cart, bolt_cart_hints, bolt_callbacks, {
        checkoutButtonClassName: "<?= $checkoutButtonClassName ?>",
        subscriptionRenderType: 'component'
    });
}
