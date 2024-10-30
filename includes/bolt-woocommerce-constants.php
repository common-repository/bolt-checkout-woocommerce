<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// String literals from Woocommerce

// Address elements
const WC_BILLING_ADDRESS_1  = 'billing_address_1';
const WC_BILLING_ADDRESS_2  = 'billing_address_2';
const WC_BILLING_FIRST_NAME = 'billing_first_name';
const WC_BILLING_LAST_NAME  = 'billing_last_name';
const WC_BILLING_CITY       = 'billing_city';
const WC_BILLING_STATE      = 'billing_state';
const WC_BILLING_POSTCODE   = 'billing_postcode';
const WC_BILLING_COUNTRY    = 'billing_country';
const WC_BILLING_PHONE      = 'billing_phone';
const WC_BILLING_EMAIL      = 'billing_email';
const WC_BILLING_COMPANY    = 'billing_company';

const WC_SHIPPING_ADDRESS_1  = 'shipping_address_1';
const WC_SHIPPING_ADDRESS_2  = 'shipping_address_2';
const WC_SHIPPING_FIRST_NAME = 'shipping_first_name';
const WC_SHIPPING_LAST_NAME  = 'shipping_last_name';
const WC_SHIPPING_CITY       = 'shipping_city';
const WC_SHIPPING_STATE      = 'shipping_state';
const WC_SHIPPING_POSTCODE   = 'shipping_postcode';
const WC_SHIPPING_COUNTRY    = 'shipping_country';
const WC_SHIPPING_COMPANY    = 'shipping_company';
const WC_SHIPPING_EMAIL      = 'shipping_email';
const WC_SHIPPING_PHONE      = 'shipping_phone';

const WC_SHIP_TO_DIFFERENT_ADDRESS = 'ship_to_different_address';
const WC_BILLING_PREFIX            = 'billing_';
const WC_SHIPPING_PREFIX           = 'shipping_';

// Order meta keys
const WC_ORDER_META_TRANSACTION_ID = '_transaction_id';
const WC_ORDER_META_PAYMENT_METHOD = '_payment_method';
const WC_ORDER_META_METHOD_TITLE   = '_payment_method_title';

// POST variables
const WC_ORDER_COMMENTS  = 'order_comments';
const WC_SHIPPING_METHOD = 'shipping_method';
const WC_PAYMENT_METHOD  = 'payment_method';
const WC_TERMS_FIELD     = 'terms-field';
const WC_TERMS           = 'terms';
const WC_WP_HTTP_REFERER = '_wp_http_referer';

// Order statuses
const WC_ORDER_STATUS_CANCELLED  = 'cancelled';
const WC_ORDER_STATUS_COMPLETED  = 'completed';
const WC_ORDER_STATUS_FAILED     = 'failed';
const WC_ORDER_STATUS_ON_HOLD    = 'on-hold';
const WC_ORDER_STATUS_PENDING    = 'pending';
const WC_ORDER_STATUS_PROCESSING = 'processing';
const WC_ORDER_STATUS_REFUNDED   = 'refunded';

// Notice type
const WC_NOTICE_TYPE_ERROR   = 'error';
const WC_NOTICE_TYPE_SUCCESS = 'success';

// Test log file name
const WC_TEST_LOG_FILE_NAME = 'test-log.log';
