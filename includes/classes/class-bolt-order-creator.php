<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class to implement order creation on pre auth stage
 *
 * @class   Bolt_Page_Checkout
 * @version 1.3.5
 * @author  Bolt
 */
class Bolt_Order_Creator {

	const ORDER_CREATOR_COUPON_ERR_MAPPING = array(
		\WC_Coupon::E_WC_COUPON_NOT_EXIST => E_BOLT_DISCOUNT_DOES_NOT_EXIST,
		'default'                         => E_BOLT_DISCOUNT_CANNOT_APPLY,
	);

	/**
	 * Bolt request for creation order.
	 *
	 * @since 1.3.5
	 * @var object
	 */
	private $request;

	/**
	 * Error information.
	 *
	 * @since 1.3.5
	 * @var Bolt_Error_Handler
	 */
	private $error_handler;

	/**
	 * Constructor Function.
	 *
	 * @since  1.3.5
	 * @access public
	 *
	 */
	public function __construct() {
		if ( WC_BOLT_WP_REST_API_ADDON ) {
			add_filter( 'json_endpoints', array( $this, 'register_wp_rest_api_route' ) );
		} else {
			add_action( 'rest_api_init', array( $this, 'register_order_endpoint' ) );
		}
	}


	/**
	 * Register WP REST API route
	 *
	 * @param array $routes
	 *
	 * @return array
	 * @since  1.3.5
	 * @access public
	 *
	 */
	public function register_wp_rest_api_route( $routes ) {

		$routes['/bolt/create-order'] = array(
			array( array( $this, 'create_order' ), \WP_JSON_Server::CREATABLE | \WP_JSON_Server::ACCEPT_JSON ),
		);

		return $routes;
	}

	/**
	 * Register wordpress endpoints
	 *
	 * @since  1.3.5
	 * @access public
	 *
	 */
	public function register_order_endpoint() {
		register_rest_route( 'bolt', '/create-order', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'create_order' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Implement Create order endpoint
	 *
	 * @return WP_REST_Response
	 * @since  1.3.5
	 * @access public
	 *
	 */
	public function create_order() {
		wc_bolt()->get_metrics_client()->save_start_time();
		$this->error_handler = new Bolt_Error_Handler( BOLT_ENDPOINT_ID_CREATE_ORDER );
		wc_bolt()->get_bolt_checkout()->set_error_handler( $this->error_handler );

		BugsnagHelper::initBugsnag();

		try {
			$hmac_header = @$_SERVER[ BOLT_HEADER_HMAC ];

			$get_data = file_get_contents( 'php://input' );

			BugsnagHelper::addBreadCrumbs( array( 'PRE AUTH REQUEST' => $get_data ) );
			$this->request = json_decode( $get_data );

			//to prevent third party plugin write their errors to output
			ob_start();

			if ( ! verify_signature( $get_data, $hmac_header ) ) {
				// Request can not be verified as originating from Bolt
				$this->error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array( BOLT_ERR_REASON => 'Invalid HMAC header' ) );

				return $this->error_handler->build_error();
			}
			if ( ! isset( $this->request->order->cart->order_reference ) ) {
				$this->error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array( BOLT_ERR_REASON => 'Order reference is empty' ) );

				return $this->error_handler->build_error();
			}

			// restore $_POST from database
			set_post_array_by_bolt_order( $this->request );
			$order_reference = $this->request->order->cart->order_reference;
			$result          = wc_bolt()->get_bolt_checkout()->check_if_order_exists_by_order_reference_and_shipping( $order_reference, $this->request->order->cart->shipments[0] );
			if ( $this->error_handler->has_error() ) {
				return $this->error_handler->build_error();
			}
			if ( $result instanceof \WP_REST_Response ) {
				return $result;
			}

			set_cart_by_bolt_reference_return_all_error( $order_reference, $this->error_handler );

			check_cart_item_stock( $this->error_handler );

			if ( $this->error_handler->has_error() ) {
				return $this->error_handler->build_error();
			}

			$bolt_discounts = new Bolt_Discounts_Helper( WC()->cart );
			$bolt_discounts->validate_applied_coupons( array( WC_BILLING_EMAIL => $this->request->order->cart->billing_address->email ), $this->error_handler, SELF::ORDER_CREATOR_COUPON_ERR_MAPPING );

			if ( $this->error_handler->has_error() ) {
				return $this->error_handler->build_error();
			}

			$this->compare_cart_items();

			if ( $this->error_handler->has_error() ) {
				return $this->error_handler->build_error();
			}

			// If we restored some information then a user is on checkout page.
			// Otherwise - on cart page or on product page checkout
			if ( isset( $_POST['in_bolt_checkout'] ) ) {
				$_POST['checkout'] = 1;
			}
			$result = wc_bolt()->get_bolt_checkout()->save_order( $this->request, true );
			if ( $this->error_handler->has_error() ) {
				return $this->error_handler->build_error();
			}

			return $result;

		} catch
		( \Exception $e ) {
			$this->error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array( BOLT_ERR_REASON => $e->getMessage() ) );

			return $this->error_handler->build_error();
		} finally {
			wc_bolt()->get_metrics_client()->process_metric( BOLT_METRIC_NAME_ORDER_CREATION, ( Bolt_HTTP_Handler::$status == HTTP_STATUS_OK ) ? BOLT_STATUS_SUCCESS : BOLT_STATUS_FAILURE );
		}
	}

	/**
	 * Check if woocommerce cart the same as bolt order
	 *
	 * @return bool false - if we have an error preventing futher processing, true - otherwise
	 * @since  2.0.0
	 * @access protected
	 *
	 */
	protected function compare_cart_items() {
		$bolt_items = array();
		foreach ( $this->request->order->cart->items as $item ) {
			//Now we just ignore the cart fees.
			//TODO : compare cart fees.
			if ( isset( $item->sku ) && strpos( $item->sku, 'wc_bolt_cart_fee-' ) === 0 ) {
				continue;
			}
			@$bolt_items[ $item->reference ] [ $item->unit_price->amount ] += $item->quantity;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			$quantity   = $item['quantity'];
			$product_id = $item['data']->get_id();
			$unit_price = convert_monetary_value_to_bolt_format( $item['data']->get_price() );

			if ( isset( $bolt_items[ $product_id ][ $unit_price ] ) && $bolt_items[ $product_id ][ $unit_price ] >= $quantity ) {
				$bolt_items[ $product_id ][ $unit_price ] -= $quantity;
			} else {
				//We have an error and need to identify: if price was changed or cart has totally expired

				//TODO When customer use product options addon and user has a few different items with the same product id
				//code below may mistakenly indicate that the price has changed, whereas in fact the cart has totally changed
				$error_found = false;
				foreach ( $bolt_items[ $product_id ] as $bolt_price => $bolt_quantity ) {
					if ( $bolt_quantity >= $quantity ) {
						$error_found                              = true;
						$bolt_items[ $product_id ][ $bolt_price ] -= $bolt_quantity;
						$this->error_handler->handle_error(
							E_BOLT_ITEM_PRICE_HAS_BEEN_UPDATED,
							(object) array(
								BOLT_ERR_PRODUCT_ID   => $product_id,
								BOLT_ERR_PRODUCT_NAME => $item['data']->get_name(),
								BOLT_ERR_NEW_VALUE    => $unit_price,
								BOLT_ERR_OLD_VALUE    => $bolt_price
							)
						);

						break;

					}
				}
				if ( ! $error_found ) {
					$this->error_handler->handle_error( E_BOLT_CART_HAS_EXPIRED, (object) array(
						BOLT_ERR_REASON => 'cart has exprired',
					) );

					return false;
				}
			}

		}
		foreach ( $bolt_items as $product_id => $bolt_product_items ) {
			foreach ( $bolt_product_items as $price => $quantity ) {
				if ( $quantity > 0 ) {
					$this->error_handler->handle_error( E_BOLT_CART_HAS_EXPIRED, (object) array(
						BOLT_ERR_REASON => 'cart has exprired',
					) );

					return false;
				}
			}
		}

		return true;
	}
}

new Bolt_Order_Creator();