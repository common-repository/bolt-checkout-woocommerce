/**
 * Review Payment before make payment on merchant account
 * @constructor
 */
var redirect_url = "";
var bolt_order_id = "";
//For none-html5-localstorage-support browser, this is a lock for single pay bolt button
var isRequestInFlight = false; // false->unlock true->lock
//Whether the current browser support html5 local storage
var $supports_html5_storage = false;

var Bolt_Review = function () {

    // Bolt checkout form fields.
    var bolt_checkout_form;

    // Bolt Initialization.
    this.init = function ( form ) {

        bolt_checkout_form = form;
        
        /* Storage Handling */
        $supports_html5_storage = true;
    
        try {
            $supports_html5_storage = ( 'sessionStorage' in window && window.sessionStorage !== null );
            window.sessionStorage.setItem( 'wc_bolt', 'test' );
            window.sessionStorage.removeItem( 'wc_bolt' );
            window.localStorage.setItem( 'wc_bolt', 'test' );
            window.localStorage.removeItem( 'wc_bolt' );
        } catch( err ) {
            $supports_html5_storage = false;
        }       

        //cart update event.
        jQuery(document).on('updated_cart_totals', function () {
            BoltCheckout.configure(new Promise(function (resolve, reject) {
                    jQuery.ajax({
                        type: 'POST',
                        async: false,
                        cache: false,
                        url: bolt_get_ajax_url('get_bolt_cart'),
                        success: function (data) {
                            if (data.result == 'success') {
                                window.eval(data.bolt_on_email_enter);
                                bolt_cart_availability = data.cart_availability;
                                bolt_cart = JSON.parse(data.json_cart);
                                resolve(bolt_cart);
                            } else {
                                bolt_record_frontend_error( 'update cart totals does not return success ', {ajax_answer:data} );
                                reject();
                            }
                        },
                        error: function (jqXHR, textStatus, errorThrown) {
                            bolt_record_frontend_error( 'update cart totals error ' + textStatus );
                            reject();
                        }
                    })
                })
                .catch( function(e) { window.location = window.location.href; } ),
                bolt_hint,
                bolt_callbacks);
        });
        
        // If the current page has checkout form.
        if ( bolt_checkout_form && jQuery( bolt_checkout_form ).length > 0 ) {

            // When switch the payment method on checkout .
            jQuery( bolt_checkout_form ).on( 'change', '.wc_payment_methods input[name="payment_method"], .payment_methods input[name="payment_method"]', onChangePaymentGateway );

            // From WC 3.4.3, it prevents further propagation of the payment method change event when refresh the checkout form by ajax.
            // So we have to depend on another event `updated_checkout` which is triggered at the end of ajax call. 
            jQuery( document.body ).on( 'updated_checkout', onChangePaymentGateway );

            onChangePaymentGateway();
        }
    };

    /**
     * Called when selected payment gateway in checkout page is changed.
     *
     * @param e event for button.
     */
    onChangePaymentGateway = function ( e ) {

        // If the bolt payment gateway was chosen.
        var $place_order = jQuery( '#place_order' );
        var $bolt_checkoutpage = jQuery('#bolt-checkoutpage');

        if ( jQuery( '#payment_method_wc-bolt-payment-gateway' ).is( ':checked' ) ) {
            // Hide the place order button.
            if ( $place_order.is(":visible") || $bolt_checkoutpage.is(":hidden") ) {
                $place_order.hide().parent().append(jQuery('#bolt-checkoutpage'));
                jQuery('#bolt-checkoutpage').show();
            }
        } else {
            // Show.
            if ( $place_order.is(":hidden") || $bolt_checkoutpage.is(":visible") ) {
                $place_order.show();
                jQuery("#bolt-checkoutpage").hide();
            }
        }
    };

    // Once the payment has been done on the bolt this method will be fired.
    this.save_checkout = function ( transaction, callback, type ) {

        var params = [
            'transaction_details=' + encodeURIComponent(JSON.stringify( transaction )),
            type + '=1'
        ];
        if(bolt_checkout_form && jQuery( bolt_checkout_form ).length>0){
            params.unshift(jQuery( bolt_checkout_form ).serialize());
        }
        var cart_data = params.join("&");

        jQuery.ajax( {
            type: 'POST',
            url: bolt_get_ajax_url( 'create_order' ),
            data: cart_data,
            success: function ( data ) {
                if ( data.result != 'success' ) {
                    jQuery('#bolt-modal-background').remove();
                    jQuery('html').removeClass('bolt_modal_active');
                    jQuery('body').css('overflow', 'auto');
                    display_notices(data);
                } else {
                    if(typeof data.order_id !== 'undefined'){
                        bolt_order_id = data.order_id
                    }
                    if(typeof data.redirect_url !== 'undefined' && data.redirect_url != ''){
                        redirect_url = data.redirect_url;
                    }
                    else if(typeof data.redirect !== 'undefined' && data.redirect != ''){
                        // If the checkout process without payment, the return of ajax only has redirect variable
                        redirect_url = data.redirect;
                    }
                    else{
                        bolt_record_frontend_error( 'Have no redirect on save checkout answer' );
                        // Make sure there is always an alternative for redirect url after successfully save woocommerce order 
                        redirect_url = wc_bolt_checkout_config.default_redirect_url;
                    }

                    callback();
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                bolt_record_frontend_error( 'save checkout error',{cart_data: cart_data, textStatus: textStatus } );
            }
        } );
    };

    // Update the email in the cart session
    this.save_email = function ( email, order_reference ) {
        if (validateEmail(email)) {
            jQuery.ajax( {
                type: 'POST',
                url: bolt_get_ajax_url( 'save_email' ),
                data: 'email=' + email + '&order_reference=' + order_reference,
                success: function ( data ) {

                },
                error: function (jqXHR, textStatus, errorThrown) {
                    bolt_record_frontend_error( 'save email error' + textStatus );
                }
            } );
        }
    };

    // Check if bolt payment gateway is selected.
    isBoltPluginChosen = function () {
        return jQuery( '#payment_method_wc-bolt-payment-gateway' ).is( ':checked' );
    };

    // Create order before paying money.
    this.beforePay = function ( order_data ) {

        if(order_data.orderinvoice != undefined){
            // for payment from "invoice for order" email
            var cart_data = 'bolt_order_invoice=1&bolt_order_token=' + order_data.orderToken + '&bolt_order_reference=' + order_data.orderReference;
        }
        else{
            var cart_data = jQuery( bolt_checkout_form ).serialize() + '&bolt_order_token=' + order_data.orderToken + '&bolt_order_reference=' + order_data.orderReference;
        }
        
        var checkoutform = bolt_checkout_form;
        var is_validated = false;

        jQuery.ajax( {
            type: 'POST',
            async: false,
            cache: false,
            url: bolt_get_ajax_url( 'checkout_validation' ),
            data: cart_data,
            success: function ( data ) {
                if ( data.result != 'success' ) {
                    display_notices(data);
                } else {
                    is_validated = true;
                }
            },
            error: function (jqXHR, textStatus, errorThrown) {
                bolt_record_frontend_error( 'Before pay error ' + textStatus, {cart_data:cart_data} );
            }
        } );

        return is_validated;

    };

    if ( typeof wc_checkout_params === 'undefined' || wc_checkout_params.is_checkout === '0' || wc_bolt_checkout_config.is_order_pay_page === '1' )
        return false;
    var updateTimer, dirtyInput = false, xhr;

    function update_order_review_table( billing_first_name, billing_last_name, billing_phone, billing_email ) {
        jQuery( '#order_methods, #order_review' ).block( {
            message: null,
            overlayCSS: {
                background: '#fff',
                backgroundSize: '16px 16px',
                opacity: 0.6
            }
        } );

        var data = {
            action: 'woocommerce_update_order_review',
            security: wc_checkout_params.update_order_review_nonce,
            billing_first_name: billing_first_name,
            billing_last_name: billing_last_name,
            billing_phone: billing_phone,
            billing_email: billing_email,
            post_data: jQuery( 'form.checkout' ).serialize()
        };

        jQuery.ajax( {
            type: 'POST',
            url: wc_checkout_params.ajax_url,
            data: data,
            success: function ( response ) {
                var order_output = jQuery( response );
                try {
                    jQuery( '#order_review' ).html( response[ 'fragments' ][ '.woocommerce-checkout-review-order-table' ] + response[ 'fragments' ][ '.woocommerce-checkout-payment' ] );
                    jQuery( 'body' ).trigger( 'update_checkout' );
                } catch (e) {
                }
            },
            error: function ( code ) {
                console.log( 'update_order_review_table ERROR' );
            }
        } );
    }

    function update_order_review_table_shipping( shipping_first_name, shipping_last_name, shipping_phone, shipping_email ) {
        if ( xhr ) xhr.abort();
        jQuery( '#order_methods, #order_review' ).block( {
            message: null,
            overlayCSS: {
                background: '#fff',
                backgroundSize: '16px 16px',
                opacity: 0.6
            }
        } );

        var data = {
            action: 'woocommerce_update_order_review',
            security: wc_checkout_params.update_order_review_nonce,
            shipping_first_name: shipping_first_name,
            shipping_last_name: shipping_last_name,
            shipping_phone: shipping_phone,
            shipping_email: shipping_email,
            post_data: jQuery( 'form.checkout' ).serialize()
        };

        xhr = jQuery.ajax( {
            type: 'POST',
            url: wc_checkout_params.ajax_url,
            data: data,
            success: function ( response ) {
                var order_output = jQuery( response );
                jQuery( '#order_review' ).html( response[ 'fragments' ][ '.woocommerce-checkout-review-order-table' ] + response[ 'fragments' ][ '.woocommerce-checkout-payment' ] );
                jQuery( 'body' ).trigger( 'update_checkout' );

            },
            error: function ( code ) {
                console.log( 'update_order_review_table_shipping ERROR' );
            }
        } );
    }

    // Get the input box
    var billing_first_name = document.getElementById( 'billing_first_name' );

    // Init a timeout variable to be used below
    var billing_first_name_timeout = null;

    // Listen for keystroke events
    billing_first_name.onkeyup = function ( e ) {

        // Clear the timeout if it has already been set.
        // This will prevent the previous task from executing
        // if it has been less than <MILLISECONDS>
        clearTimeout( billing_first_name_timeout );

        // Make a new timeout set to go off in 800ms
        billing_first_name_timeout = setTimeout( function () {
            update_order_review_table( jQuery( '#billing_first_name' ).val(), jQuery( '#billing_last_name' ).val(), jQuery( '#billing_phone' ).val(), jQuery( '#billing_email' ).val() );
        }, 500 );
    };

    // Get the input box
    var billing_last_name = document.getElementById( 'billing_last_name' );

    // Init a timeout variable to be used below
    var billing_last_name_timeout = null;

    // Listen for keystroke events
    billing_last_name.onkeyup = function ( e ) {

        // Clear the timeout if it has already been set.
        // This will prevent the previous task from executing
        // if it has been less than <MILLISECONDS>
        clearTimeout( billing_last_name_timeout );

        // Make a new timeout set to go off in 800ms
        billing_last_name_timeout = setTimeout( function () {
            update_order_review_table( jQuery( '#billing_first_name' ).val(), jQuery( '#billing_last_name' ).val(), jQuery( '#billing_phone' ).val(), jQuery( '#billing_email' ).val() );
        }, 500 );
    };

    // Get the input box
    var billing_phone = document.getElementById( 'billing_phone' );

    // Init a timeout variable to be used below
    var billing_phone_timeout = null;

    // Listen for keystroke events
    billing_phone.onkeyup = function ( e ) {

        // Clear the timeout if it has already been set.
        // This will prevent the previous task from executing
        // if it has been less than <MILLISECONDS>
        clearTimeout( billing_phone_timeout );

        // Make a new timeout set to go off in 800ms
        billing_phone_timeout = setTimeout( function () {
            update_order_review_table( jQuery( '#billing_first_name' ).val(), jQuery( '#billing_last_name' ).val(), jQuery( '#billing_phone' ).val(), jQuery( '#billing_email' ).val() );
        }, 500 );
    };

    // Get the input box
    var billing_email = document.getElementById( 'billing_email' );

    // Init a timeout variable to be used below
    var billing_email_timeout = null;

    // Listen for keystroke events
    billing_email.onkeyup = function ( e ) {

        // Clear the timeout if it has already been set.
        // This will prevent the previous task from executing
        // if it has been less than <MILLISECONDS>
        clearTimeout( billing_email_timeout );

        // Make a new timeout set to go off in 800ms
        billing_email_timeout = setTimeout( function () {
            update_order_review_table( jQuery( '#billing_first_name' ).val(), jQuery( '#billing_last_name' ).val(), jQuery( '#billing_phone' ).val(), jQuery( '#billing_email' ).val() );
        }, 500 );
    };

    // Get the input box
    var shipping_first_name = document.getElementById( 'shipping_first_name' );
    if ( typeof(shipping_first_name) != 'undefined' && shipping_first_name != null ) {
        // Init a timeout variable to be used below
        var shipping_first_name_timeout = null;

        // Listen for keystroke events
        shipping_first_name.onkeyup = function ( e ) {

            // Clear the timeout if it has already been set.
            // This will prevent the previous task from executing
            // if it has been less than <MILLISECONDS>
            clearTimeout( shipping_first_name_timeout );

            // Make a new timeout set to go off in 800ms
            shipping_first_name_timeout = setTimeout( function () {
                update_order_review_table_shipping( jQuery( '#shipping_first_name' ).val(), jQuery( '#shipping_last_name' ).val(), jQuery( '#shipping_phone' ).val(), jQuery( '#shipping_email' ).val() );
            }, 500 );
        };
    }

    // Get the input box
    var shipping_last_name = document.getElementById( 'shipping_last_name' );
    if ( typeof(shipping_last_name) != 'undefined' && shipping_last_name != null ) {
        // Init a timeout variable to be used below
        var shipping_last_name_timeout = null;

        // Listen for keystroke events
        shipping_last_name.onkeyup = function ( e ) {

            // Clear the timeout if it has already been set.
            // This will prevent the previous task from executing
            // if it has been less than <MILLISECONDS>
            clearTimeout( shipping_last_name_timeout );

            // Make a new timeout set to go off in 800ms
            shipping_last_name_timeout = setTimeout( function () {
                update_order_review_table_shipping( jQuery( '#shipping_first_name' ).val(), jQuery( '#shipping_last_name' ).val(), jQuery( '#shipping_phone' ).val(), jQuery( '#shipping_email' ).val() );
            }, 500 );
        };
    }

    // Get the input box
    var shipping_phone = document.getElementById( 'shipping_phone' );
    if ( typeof(shipping_phone) != 'undefined' && shipping_phone != null ) {
        // Init a timeout variable to be used below
        var shipping_phone_timeout = null;

        // Listen for keystroke events
        shipping_phone.onkeyup = function ( e ) {

            // Clear the timeout if it has already been set.
            // This will prevent the previous task from executing
            // if it has been less than <MILLISECONDS>
            clearTimeout( shipping_phone_timeout );

            // Make a new timeout set to go off in 800ms
            shipping_phone_timeout = setTimeout( function () {
                update_order_review_table_shipping( jQuery( '#shipping_first_name' ).val(), jQuery( '#shipping_last_name' ).val(), jQuery( '#shipping_phone' ).val(), jQuery( '#shipping_email' ).val() );
            }, 500 );
        };
    }

    // Get the input box
    var shipping_email = document.getElementById( 'shipping_email' );
    if ( typeof(shipping_email) != 'undefined' && shipping_email != null ) {
        // Init a timeout variable to be used below
        var shipping_email_timeout = null;

        // Listen for keystroke events
        shipping_email.onkeyup = function ( e ) {

            // Clear the timeout if it has already been set.
            // This will prevent the previous task from executing
            // if it has been less than <MILLISECONDS>
            clearTimeout( shipping_email_timeout );

            // Make a new timeout set to go off in 800ms
            shipping_email_timeout = setTimeout( function () {
                update_order_review_table_shipping( jQuery( '#shipping_first_name' ).val(), jQuery( '#shipping_last_name' ).val(), jQuery( '#shipping_phone' ).val(), jQuery( '#shipping_email' ).val() );
            }, 500 );
        };
    }
    
    function validateEmail(email) {
        var re = /^(([^<>()[\]\\.,;:\s@\"]+(\.[^<>()[\]\\.,;:\s@\"]+)*)|(\".+\"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
        return re.test(email);
    }

};

/**
 * Display all notices sent by WooCommerce via an ajax call
 *
 * @param data  the response sent back from WooCommerce
 */
function display_notices(data) {
    var $header = jQuery( wc_bolt_checkout_config.display_notices_selector ).length ? jQuery( wc_bolt_checkout_config.display_notices_selector ) : jQuery('.entry-summary');
    if (!$header.length) {
        $header = jQuery('body');
    }
    // Remove notices from all sources
    jQuery( '.woocommerce-error' ).remove();

    // Add new errors returned by this event
    if ( data.messages ) {
        $header.prepend( '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-updateOrderReview">' + data.messages + '</div>' );
    } else {
        $header.prepend(data);
    }

    // Scroll to top
    jQuery( 'html, body' ).animate( {
        scrollTop: ( $header.offset().top - 100 )
    }, 1000 );
}

// Get ajax endpoint URL.
function bolt_get_ajax_url ( endpoint ) {
    return wc_bolt_checkout_config.ajax_url
        .toString()
        .replace( '%%wc_endpoint%%', 'wc_bolt_' + endpoint );
};

function get_ajax_url ( endpoint ) {
    return bolt_get_ajax_url(endpoint);
};

function bolt_record_frontend_error( text, data ) {
    if (data === undefined) data = {};
    data.text = text;
    data.href = document.location.href;
    if (typeof bolt_cart !== 'undefined') {
        data.cart = bolt_cart;
    }

    jQuery.ajax(
        {
            type: 'POST',
            url: bolt_get_ajax_url( 'record_frontend_error' ),
            data: data
        }
    );
};

