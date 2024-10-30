<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// String literals from Bolt server

// Hook types
const BOLT_HOOK_TYPE_AUTH                  = 'auth';
const BOLT_HOOK_TYPE_CAPTURE               = 'capture';
const BOLT_HOOK_TYPE_CART_CREATE           = 'cart.create';
const BOLT_HOOK_TYPE_CREDIT                = 'credit';
const BOLT_HOOK_TYPE_DISCOUNTS_CODE_APPLY  = 'discounts.code.apply';
const BOLT_HOOK_TYPE_FAILED_PAYMENT        = 'failed_payment';
const BOLT_HOOK_TYPE_PAYMENT               = 'payment';
const BOLT_HOOK_TYPE_ORDER_CREATE          = 'order.create';
const BOLT_HOOK_TYPE_REJECTED_IRREVERSIBLE = 'rejected_irreversible';
const BOLT_HOOK_TYPE_REJECTED_REVERSIBLE   = 'rejected_reversible';
const BOLT_HOOK_TYPE_VOID                  = 'void';
const BOLT_HOOK_TYPE_PENDING               = 'pending';

// Transaction status refer to https://docs.bolt.com/docs/hook-integration-1#section-transaction-statuses
const BOLT_TRANSACTION_STATUS_PENDING      = 'pending';
const BOLT_TRANSACTION_STATUS_COMPLETED    = 'completed';
const BOLT_TRANSACTION_STATUS_CANCELLED    = 'cancelled';
const BOLT_TRANSACTION_STATUS_FAILED       = 'failed';
const BOLT_TRANSACTION_STATUS_AUTHORIZED   = 'authorized';
const BOLT_TRANSACTION_STATUS_IRREVERSIBLE = 'rejected_irreversible';
const BOLT_TRANSACTION_STATUS_REVERSIBLE   = 'rejected_reversible';
const BOLT_TRANSACTION_STATUS_IN_PROGRESS  = 'in_progress';
const BOLT_TRANSACTION_STATUS_CREATED      = 'created';

// Request fields
const BOLT_FIELD_NAME_ADDITIONAL             = 'additional';
const BOLT_FIELD_NAME_AMOUNT                 = 'amount';
const BOLT_FIELD_NAME_BODY                   = 'body';
const BOLT_FIELD_NAME_CURRENCY               = 'currency';
const BOLT_FIELD_NAME_DECISION               = 'decision';
const BOLT_FIELD_NAME_ERROR                  = 'error';
const BOLT_FIELD_NAME_ERRORS                 = 'errors';
const BOLT_FIELD_NAME_ERROR_CODE             = 'code';
const BOLT_FIELD_NAME_ERROR_DATA             = 'data';
const BOLT_FIELD_NAME_ERROR_MESSAGE          = 'message';
const BOLT_FIELD_NAME_HEADERS                = 'headers';
const BOLT_FIELD_NAME_HTTP_CODE              = 'code';
const BOLT_FIELD_NAME_ORDER                  = 'order';
const BOLT_FIELD_NAME_ORDER_CREATE           = 'order_create';
const BOLT_FIELD_NAME_ORDER_RECEIVED_URL     = 'order_received_url';
const BOLT_FIELD_NAME_SKIP_HOOK_NOTIFICATION = 'skip_hook_notification';
const BOLT_FIELD_NAME_STATUS                 = 'status';
const BOLT_FIELD_NAME_SHIPPING_OPTIONS       = 'shipping_options';
const BOLT_FIELD_NAME_TAX_RESULT             = 'tax_result';
const BOLT_FIELD_NAME_TOTAL                  = 'total';
const BOLT_FIELD_NAME_TRANSACTION_ID         = 'transaction_id';

const BOLT_STATUS_FAILURE = 'failure';
const BOLT_STATUS_SUCCESS = 'success';

// Address elements
const BOLT_STREET_ADDRESS1 = 'street_address1';
const BOLT_STREET_ADDRESS2 = 'street_address2';
const BOLT_STREET_ADDRESS3 = 'street_address3';
const BOLT_STREET_ADDRESS4 = 'street_address4';
const BOLT_FIRST_NAME      = 'first_name';
const BOLT_LAST_NAME       = 'last_name';
const BOLT_LOCALITY        = 'locality';
const BOLT_REGION          = 'region';
const BOLT_POSTAL_CODE     = 'postal_code';
const BOLT_COUNTRY_CODE    = 'country_code';
const BOLT_COUNTRY         = 'country';
const BOLT_PHONE_NUM       = 'phone_number';
const BOLT_PHONE           = 'phone';
const BOLT_EMAIL_ADDR      = 'email_address';
const BOLT_EMAIL           = 'email';
const BOLT_COMPANY         = 'company';

// Hint address elements
const BOLT_HINT_FIRST_NAME      = 'firstName';
const BOLT_HINT_LAST_NAME       = 'lastName';
const BOLT_HINT_EMAIL           = 'email';
const BOLT_HINT_PHONE           = 'phone';
const BOLT_HINT_STREET_ADDRESS1 = 'addressLine1';
const BOLT_HINT_STREET_ADDRESS2 = 'addressLine2';
const BOLT_HINT_LOCALITY        = 'city';
const BOLT_HINT_COUNTRY         = 'country';
const BOLT_HINT_REGION          = 'state';
const BOLT_HINT_POSTAL_CODE     = 'zip';

const BOLT_HINT_PREFILL = 'prefill';

// Order meta keys
const BOLT_ORDER_META_CHECKBOXES               = 'bolt_checkboxes';
const BOLT_ORDER_META_CUSTOM_FIELDS            = 'bolt_custom_fields';
const BOLT_ORDER_META_TRANSACTION_ID           = 'bolt_transaction_id';
const BOLT_ORDER_META_TRANSACTION_REFERENCE_ID = 'bolt_transaction_reference_id';
const BOLT_ORDER_META_TRANSACTION_ORDER        = 'bolt_transaction_order';
const BOLT_ORDER_META_TRANSACTION_DISPLAY_ID   = 'bolt_transaction_display_id';
const BOLT_ORDER_META_REFUND_CREATED           = 'bolt_refund_created';

// Session data prefixes
const BOLT_PREFIX_SHIPPING_AND_TAX    = 'shipping_and_tax_';
const BOLT_PREFIX_SHIPPING            = 'shipping_';
const BOLT_PREFIX_TAX                 = 'tax_';
const BOLT_PREFIX_SESSION_DATA        = 'session_data_';
const BOLT_PREFIX_SESSION_POSTED_DATA = 'session_posteddata_';

// Prefix for order reference
const BOLT_PREFIX_ORDER_REFERENCE        = 'BLT';
const BOLT_PREFIX_ORDER_REFERENCE_LENGTH = 3;

// Bolt HTTP headers
const BOLT_HEADER_CACHED_VALUE      = 'X-Bolt-Cached-Value';
const BOLT_HEADER_HMAC              = 'HTTP_X_BOLT_HMAC_SHA256';
const BOLT_HEADER_SOURCE_NAME_VALUE = 'woo_commerce';

// Order status
const WC_ORDER_STATUS_BOLT_REJECT = 'bolt-reject';

// Bolt cart elements
const BOLT_CART_BILLING_ADDRESS          = 'billing_address';
const BOLT_CART                          = 'cart';
const BOLT_CART_CURRENCY                 = 'currency';
const BOLT_CART_DISCOUNTS                = 'discounts';
const BOLT_CART_DISCOUNT_AMOUNT          = 'amount';
const BOLT_CART_DISCOUNT_DESCRIPTION     = 'description';
const BOLT_CART_DISCOUNT_CATEGORY        = 'discount_category';
const BOLT_CART_DISCOUNT_REFERENCE       = 'reference';
const BOLT_CART_DISPLAY_ID               = 'display_id';
const BOLT_CART_ITEMS                    = 'items';
const BOLT_CART_ITEM_DESCRIPTION         = 'description';
const BOLT_CART_ITEM_IMAGE_URL           = 'image_url';
const BOLT_CART_ITEM_NAME                = 'name';
const BOLT_CART_ITEM_PROPERTIES          = 'properties';
const BOLT_CART_ITEM_QUANTITY            = 'quantity';
const BOLT_CART_ITEM_REFERENCE           = 'reference';
const BOLT_CART_ITEM_SKU                 = 'sku';
const BOLT_CART_ITEM_TOTAL_AMOUNT        = 'total_amount';
const BOLT_CART_ITEM_TYPE                = 'type';
const BOLT_CART_ITEM_TYPE_DIGITAL        = 'digital';
const BOLT_CART_ITEM_TYPE_PHYSICAL       = 'physical';
const BOLT_CART_ITEM_UNIT_PRICE          = 'unit_price';
const BOLT_CART_ORDER_REFERENCE          = 'order_reference';
const BOLT_CART_ORDER_TOKEN              = 'order_token';
const BOLT_CART_ORDER_TYPE_CART          = 'cart';
const BOLT_CART_ORDER_TYPE_CHECKOUT      = 'checkout';
const BOLT_CART_ORDER_TYPE_ORDER_INVOICE = 'orderinvoice';
const BOLT_CART_ORDER_TYPE_PPC           = 'product_page_checkout';
const BOLT_CART_SHIPMENTS                = 'shipments';
const BOLT_CART_SHIPMENT_CARRIER         = 'carrier';
const BOLT_CART_SHIPMENT_COST            = 'cost';
const BOLT_CART_SHIPMENT_REFERENCE       = 'reference';
const BOLT_CART_SHIPMENT_SERVICE         = 'service';
const BOLT_CART_SHIPPING_ADDRESS         = 'shipping_address';
const BOLT_CART_SHIPMENT_TAX_AMOUNT      = 'tax_amount';
const BOLT_CART_TAX_AMOUNT               = 'tax_amount';
const BOLT_CART_TOTAL_AMOUNT             = 'total_amount';
const BOLT_CART_USER_NOTE                = 'user_note';
const BOLT_CART_DISCOUNT_ON_TOTAL        = 'discount_on_total';

// Bolt Discount Categories
const BOLT_DISCOUNT_CATEGORY_COUPON   = "coupon";
const BOLT_DISCOUNT_CATEGORY_GIFTCARD = "giftcard";

// Other Bolt fields
const BOLT_FIELD_CART_TAX     = 'cart_tax';
const BOLT_FIELD_FEE_TAX      = 'fee_tax';
const BOLT_FIELD_SHIPPING_TAX = 'shipping_tax';

// Error answer elements
const BOLT_ERR_DISCOUNT_CODE = 'discount_code';
const BOLT_ERR_NEW_VALUE     = 'new_value';
const BOLT_ERR_OLD_VALUE     = 'old_value';
const BOLT_ERR_ORDER_STATUS  = 'order_status';
const BOLT_ERR_PRODUCT_ID    = 'product_id';
const BOLT_ERR_PRODUCT_NAME  = 'product_name';
const BOLT_ERR_REASON        = 'reason';
const BOLT_ERR_TEXT          = 'text';

// Bolt metrics
const BOLT_METRIC_TYPE_COUNT   = 'count';
const BOLT_METRIC_TYPE_LATENCY = 'latency';

const BOLT_FIELD_VALUE       = 'value';
const BOLT_FIELD_METRIC_TYPE = 'metric_type';
const BOLT_FIELD_TIMESTAMP   = 'timestamp';

const BOLT_METRIC_NAME_ORDER_TOKEN                 = 'order_token';
const BOLT_METRIC_NAME_ORDER_CREATION              = 'order_creation';
const BOLT_METRIC_NAME_WEBHOOKS                    = 'webhooks';
const BOLT_METRIC_NAME_FEATURE_SWITCH_UPGRADE_DATA = 'feature_switch.upgradedata';
const BOLT_METRIC_NAME_FEATURE_SWITCH_WEB_HOOK     = 'feature_switch.webhook';

const BOLT_METRIC_NAME_SHIP_TAX = 'ship_tax';
const BOLT_METRIC_NAME_SHIP     = 'ship';
const BOLT_METRIC_NAME_TAX      = 'tax';

// Error codes for general error
const E_BOLT_GENERAL_ERROR_FULL_CODE = 5001;

// Error codes for shipping&tax hook
const E_BOLT_SHIPPING_PO_BOX_SHIPPING_DISALLOWED = 6101;
const E_BOLT_SHIPPING_GENERAL_ERROR              = 6102;
const E_BOLT_SHIPPING_CUSTOM_ERROR               = 6103;

//Product page checkout error constants
const E_BOLT_OUT_OF_STOCK            = 6301;
const E_BOLT_INVALID_SIZE            = 6302;
const E_BOLT_INVALID_QUANTITY        = 6303;
const E_BOLT_INVALID_REFERENCE       = 6304;
const E_BOLT_INVALID_AMOUNT          = 6305;
const E_BOLT_ENCRYPTED_USER_ID_ERROR = 6306;

// Error codes for subscriptions
const E_BOLT_SUBSCRIPTION_GENERAL_ERROR = 6401;

// Tracking event types
const BOLT_CHECKOUT_TRACKING_EVENT_CHECKOUT_START            = 'onCheckoutStart';
const BOLT_CHECKOUT_TRACKING_EVENT_SHIPPING_DETAILS_COMPLETE = 'onShippingDetailsComplete';
const BOLT_CHECKOUT_TRACKING_EVENT_SHIPPING_OPTIONS_COMPLETE = 'onShippingOptionsComplete';
const BOLT_CHECKOUT_TRACKING_EVENT_PAYMENT_SUBMIT            = 'onPaymentSubmit';
const BOLT_CHECKOUT_TRACKING_EVENT_SUCCESS                   = 'onSuccess';


// The const via which Bolt identifies the type of plugin.
const BOLT_PLUGIN_TYPE = 'WOO_COMMERCE';

// Payment method was used for Bolt transaction
const BOLT_PROCESSOR_VANTIV   = 'vantiv';
const BOLT_PROCESSOR_PAYPAL   = 'paypal';
const BOLT_PROCESSOR_AFTERPAY = 'afterpay';
const BOLT_PROCESSOR_DISPLAY  = array(
	'paypal'   => 'PayPal',
	'afterpay' => 'Afterpay',
);

// Order shipment tracking elements
const BOLT_ORDER_SHIPMENT_TRACK_TRANSACTION_REFERENCE = 'transaction_reference';
const BOLT_ORDER_SHIPMENT_TRACK_TRACKING_NUMBER       = 'tracking_number';
const BOLT_ORDER_SHIPMENT_TRACK_CARRIER               = 'carrier';
const BOLT_ORDER_SHIPMENT_TRACK_ITEMS                 = 'items';
const BOLT_ORDER_SHIPMENT_TRACK_IS_NON_BOLT_ORDER     = 'is_non_bolt_order';
const BOLT_ORDER_SHIPMENT_TRACK_ITEMS_PROPERTY_NAME   = 'name';
const BOLT_ORDER_SHIPMENT_TRACK_ITEMS_PROPERTY_VALUE  = 'value';
const BOLT_ORDER_SHIPMENT_TRACK_ITEMS_REFERENCE       = 'reference';
const BOLT_ORDER_SHIPMENT_TRACK_ITEMS_OPTIONS         = 'options';

// The max character length of Bolt cart item property
const BOLT_CART_ITEM_PROPERTIES_LENGTH_LIMIT = 1024;
// The hint text for the value of cart item property if its length exceed limitation
const BOLT_CART_ITEM_PROPERTIES_VAL_HINT = 'Please check the woocommerce order details page for reference, its original value exceed the limitation of Bolt field';
// Used to specify the default priority where Bolt checkout button hooked in.
const BOLT_PRIORITY_FOR_HTML_HOOKS_DEFAULT = 22;
// Used to specify the priority where Bolt checkout button hooked in if the feature switch WOOC_HOOK_PRIORITY_CHANGED_SWITCH_NAME is enabled.
const BOLT_PRIORITY_FOR_HTML_HOOKS_CHANGED = 19;
// In the case of long processing, we ignore Bolt's aborts and give the calculation 40 seconds to complete
const BOLT_API_ENDPOINT_TIME_LIMIT = 40;

// Debug info
const BOLT_DEBUG_PHP_VERSION           = 'php_version';
const BOLT_DEBUG_PLATFORM_VERSION      = 'platform_version';
const BOLT_DEBUG_BOLT_CONFIG_SETTINGS  = 'bolt_config_settings';
const BOLT_DEBUG_OTHER_PLUGIN_VERSIONS = 'other_plugin_versions';
const BOLT_DEBUG_LOGS                  = 'logs';
