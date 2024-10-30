<?php
/**
 * WooCommerce REST Exception Class
 *
 * Extends Exception to provide additional data.
 *
 * @package WooCommerce/API
 * @since   2.6.0
 */

namespace BoltCheckout;
defined( 'ABSPATH' ) || exit;

/**
 * BOLT_REST_Exception class.
 */
class BOLT_REST_Exception extends \WC_REST_Exception {
}
