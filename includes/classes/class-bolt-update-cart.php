<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class to implement update cart (add or remove add-on)
 *
 * @class   Bolt_Update_Cart
 * @author  Bolt
 */
class Bolt_Update_Cart {

	const UPDATE_CART_COUPON_ERR_MAPPING = array(
		\WC_Coupon::E_WC_COUPON_NOT_EXIST => E_BOLT_DISCOUNT_DOES_NOT_EXIST,
		'default'                         => E_BOLT_DISCOUNT_CANNOT_APPLY,
	);

	/**
	 * Bolt request for creation order.
	 *
	 * @var object
	 */
	private $request;

	/**
	 * Error information.
	 *
	 * @var Bolt_Error_Handler
	 */
	private $error_handler;

	/**
	 * Constructor Function.
	 *
	 * @access public
	 */
	public function __construct() {
		add_action( 'wc_bolt_presetup_set_cart_by_bolt_reference', array(
			$this,
			'restore_wc_original_session'
		), 10, 2 );
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
	 * @since  2.1.0
	 * @access public
	 *
	 */
	public function register_wp_rest_api_route( $routes ) {

		$routes['/bolt/update-cart'] = array(
			array( array( $this, 'update_cart' ), \WP_JSON_Server::CREATABLE | \WP_JSON_Server::ACCEPT_JSON ),
		);

		return $routes;
	}

	/**
	 * Register wordpress endpoints
	 *
	 * @since  2.1.0
	 * @access public
	 *
	 */
	public function register_order_endpoint() {
		register_rest_route( 'bolt', '/update-cart', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'update_cart' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Implement update cart endpoint
	 * @return WP_REST_Response
	 * @since  2.1.0
	 */
	public function update_cart() {

		BugsnagHelper::initBugsnag();
		try {
			$this->error_handler = new Bolt_Error_Handler();

			$hmac_header = @$_SERVER['HTTP_X_BOLT_HMAC_SHA256'];

			$get_data = file_get_contents( 'php://input' );

			//to prevent third party plugin write their errors to output
			ob_start();

			$this->request = json_decode( $get_data );

			if ( ! verify_signature( $get_data, $hmac_header ) ) {
				// Request can not be verified as originating from Bolt
				$this->error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array( 'reason' => 'Invalid HMAC header' ) );

				return $this->error_handler->build_error();
			}
			if ( ! isset( $this->request->order_reference ) ) {
				$this->error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array( 'reason' => 'Order reference is empty' ) );

				return $this->error_handler->build_error();
			}

			$order_reference = $this->request->order_reference;

			set_cart_by_bolt_reference_return_all_error( $order_reference, $this->error_handler );

			if ( $this->error_handler->has_error() ) {
				return $this->error_handler->build_error();
			}

			$cart_changed = false;

			if ( isset( $this->request->add_items ) ) {
				foreach ( $this->request->add_items as $item ) {
					if ( ! ( $result = apply_filters( 'wc_bolt_update_cart_endpoint_add_item', false, $item, $this->request, $this->error_handler ) ) ) {
						$result = $this->add_item_to_cart( $item );
					}
					if ( is_wp_error( $result ) ) {
						switch ( $result->get_error_code() ) {
							case E_BOLT_INVALID_REFERENCE:
								$this->error_handler->handle_error( E_BOLT_PRODUCT_DOES_NOT_EXIST, (object) array(
									BOLT_ERR_REASON => sprintf( __( 'The product %d in your cart.', 'woocommerce' ), $item->product_id ),
									'product_id'    => $item->product_id
								) );
								break;
							case E_BOLT_INVALID_QUANTITY:
							case E_BOLT_OUT_OF_STOCK:
								$this->error_handler->handle_error( E_BOLT_OUT_OF_INVENTORY, (object) array(
									BOLT_ERR_REASON => sprintf( __( 'Not enough units of %d are available in stock.', 'woocommerce' ), $item->product_id ),
									'product_id'    => $item->product_id
								) );
								break;
							default:
								$this->error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array(
									BOLT_ERR_REASON => $result->get_error_code() . ':' . $result->get_error_message(),
									'product_id'    => $item->product_id
								) );
								break;
						}
					} elseif ( $result === true ) {
						// Do a stock check at this point
						$check_cart_item_result = WC()->cart->check_cart_item_stock();
						if ( is_wp_error( $check_cart_item_result ) ) {
							$this->error_handler->handle_error( E_BOLT_OUT_OF_INVENTORY, (object) array(
								BOLT_ERR_REASON => $check_cart_item_result->get_error_message(),
								'product_id'    => $item->product_id
							) );

							return $this->error_handler->build_error();
						}
						$cart_changed = true;
					}
				}
			}

			if ( isset( $this->request->remove_items ) ) {
				foreach ( $this->request->remove_items as $item ) {
					if ( ! WC()->cart->is_empty() && count( WC()->cart->get_cart() ) == 1 && WC()->cart->get_fee_total() > 0 ) {
						foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
							$product    = $cart_item['data'];
							$product_id = $product->get_id();
							// If the item is the last one in the cart and there is still fee applied to the cart,
							// the removal action attempted is disallowed.
							if ( $product_id == (int) $item->product_id && (int) $cart_item['quantity'] == (int) $item->quantity ) {
								$this->error_handler->handle_error( E_BOLT_CART_ITEM_REMOVE_FAILED, (object) array(
									BOLT_ERR_REASON => sprintf( __( 'Unable remove %s cause it is the last item in the cart and there is still fee(s).', 'bolt-checkout-woocommerce' ), $product->get_name() ),
									'product_id'    => strval( $product_id )
								) );
							}
						}
					}
					if ( $this->error_handler->has_error() ) {
						break;
					}
					if ( ! ( $result = apply_filters( 'wc_bolt_update_cart_endpoint_remove_item', false, $item, $this->request, $this->error_handler ) ) ) {
						$result = $this->remove_item_from_cart( $item->product_id, (int) $item->quantity );
					}
					if ( $result ) {
						$cart_changed = true;
					}
				}
			}

			if ( $this->error_handler->has_error() ) {
				return $this->error_handler->build_error();
			}

			if ( $cart_changed ) {
				// If any item changes, we need to check the applied coupons,
				// then remove invalid coupons. That's native behavior in WooC.
				WC()->cart->calculate_totals();
				WC()->cart->check_cart_coupons();
				$cart_changed = false;
			}

			$bolt_discounts = new Bolt_Discounts_Helper( WC()->cart );

			if ( isset( $this->request->discount_codes_to_add ) ) {
				foreach ( $this->request->discount_codes_to_add as $discount_code ) {
					$is_discount_added = $bolt_discounts->add_coupon_to_cart( $discount_code );
					if ( $is_discount_added ) {
						$cart_changed = true;
					}
				}
			}

			if ( isset( $this->request->discount_codes_to_remove ) ) {
				foreach ( $this->request->discount_codes_to_remove as $discount_code ) {
					$is_discount_removed = $bolt_discounts->remove_coupon_from_cart( $discount_code );
					if ( $is_discount_removed ) {
						$cart_changed = true;
					}
				}
			}

			if ( $this->error_handler->has_error() ) {
				return $this->error_handler->build_error();
			}

			if ( $cart_changed ) {
				WC()->cart->calculate_totals();
				// Validate the coupons after calculating cart totals.
				$bolt_discounts->validate_applied_coupons( array(), $this->error_handler, SELF::UPDATE_CART_COUPON_ERR_MAPPING );

				if ( $this->error_handler->has_error() ) {
					return $this->error_handler->build_error();
				}

				wc_bolt()->get_bolt_data_collector()->update_cart_session( 'cart', $this->request->order_reference );
			}

			Bolt_HTTP_Handler::clean_buffers( true );

			return Bolt_HTTP_Handler::prepare_http_response(
				array(
					BOLT_FIELD_NAME_STATUS       => BOLT_STATUS_SUCCESS,
					BOLT_FIELD_NAME_ORDER_CREATE => wc_bolt()->get_bolt_data_collector()->build_order( BOLT_CART_ORDER_TYPE_CART, $this->request->order_reference )
				)
			);
		} catch ( \Exception $e ) {
			$this->error_handler->handle_error( E_BOLT_GENERAL_ERROR, (object) array( 'reason' => $e->getMessage() ) );

			return $this->error_handler->build_error();
		}
	}

	/**
	 * Add item to cart
	 *
	 * @param $item
	 */
	private function add_item_to_cart( $item ) {
		$request_product_id = (int) $item->product_id;
		$request_quantity   = (int) $item->quantity;
		// If the product already exists in the cart, then update the quantity of related cart item.
		foreach ( WC()->cart->get_cart() as $cart_item_key => $values ) {
			$cart_product = $values['data'];
			if ( $cart_product->get_id() == $request_product_id ) {
				$update_quantity = $request_quantity + $values['quantity'];
				// Update cart validation.
				$passed_validation = apply_filters( 'woocommerce_update_cart_validation', true, $cart_item_key, $values, $update_quantity );

				// is_sold_individually.
				if ( $cart_product->is_sold_individually() && $update_quantity > 1 ) {
					$this->error_handler->handle_error( E_BOLT_CART_ITEM_ADD_FAILED, (object) array(
						BOLT_ERR_REASON => sprintf( __( 'You can only have 1 %s in your cart.', 'woocommerce' ), $cart_product->get_name() ),
						'product_id'    => $request_product_id
					) );

					return false;
				}

				if ( $passed_validation ) {
					WC()->cart->set_quantity( $cart_item_key, $update_quantity, false );

					return true;
				} else {
					$this->error_handler->handle_error( E_BOLT_CART_ITEM_ADD_FAILED, (object) array(
						BOLT_ERR_REASON => 'Update cart validation fails',
						'product_id'    => $request_product_id
					) );

					return false;
				}
			}
		}

		// If the product does not exists in the cart, then add to cart.

		// Ensure that product exists.
		$product = wc_get_product( $request_product_id );
		if ( ! $product || 'publish' !== get_post_status( $request_product_id ) ) {
			$this->error_handler->handle_error( E_BOLT_PRODUCT_DOES_NOT_EXIST, (object) array(
				BOLT_ERR_REASON => sprintf( __( 'The product %d does not exist.', 'woocommerce' ), $request_product_id ),
				'product_id'    => $request_product_id
			) );

			return false;
		}

		// Ensure that supplied product price matches the price in the catalog.
		$woo_price = convert_monetary_value_to_bolt_format( $product->get_price() );
		if ( $woo_price <> $item->price ) {
			$this->error_handler->handle_error(
				E_BOLT_ITEM_PRICE_HAS_BEEN_UPDATED,
				(object) array(
					BOLT_ERR_PRODUCT_ID => $request_product_id,
					BOLT_ERR_NEW_VALUE  => $woo_price,
					BOLT_ERR_OLD_VALUE  => $item->price
				)
			);

			return false;
		}

		$result = add_to_cart( $request_product_id, $request_quantity );

		return $result;
	}

	/**
	 * Remove item from cart
	 *
	 * @param $product_id
	 * @param $quantity
	 */
	private function remove_item_from_cart( $remove_product_id, $quantity = 1 ) {
		// TODO: support case when we have few items with the same product_id (it possible if we use third-party plugins for products)
		$product_id = (int) $remove_product_id;
		$product    = wc_get_product( $product_id );
		if ( ! $product || 'publish' !== get_post_status( $product_id ) ) {
			$this->error_handler->handle_error( E_BOLT_PRODUCT_DOES_NOT_EXIST, (object) array(
				BOLT_ERR_REASON => sprintf( __( 'The product %s does not exist.', 'woocommerce' ), $remove_product_id ),
				'product_id'    => $remove_product_id
			) );

			return false;
		}

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( $cart_item['product_id'] == $product_id
			     || ( 'variation' == $product->get_type() && ! empty( $cart_item['variation_id'] ) && $cart_item['variation_id'] == $product_id ) ) {
				$quantity_in_cart = $cart_item['quantity'];
				if ( $quantity_in_cart > $quantity ) {
					WC()->cart->set_quantity( $cart_item_key, (int) $quantity_in_cart - (int) $quantity, false );

					return true;
				}
				// If $quanity_in_cart <= $quantity we just remove cart item
				$result = WC()->cart->remove_cart_item( $cart_item_key );
				if ( ! $result ) {
					$product = $cart_item['data'];
					$this->error_handler->handle_error( E_BOLT_CART_ITEM_REMOVE_FAILED, (object) array(
						BOLT_ERR_REASON => sprintf( __( 'Fail to remove product %s.', 'bolt-checkout-woocommerce' ), $product->get_name() ),
						'product_id'    => $product_id
					) );
				}

				return $result;
			}
		}
		// didn't find product
		$this->error_handler->handle_error( E_BOLT_CART_ITEM_REMOVE_FAILED, (object) array(
			BOLT_ERR_REASON => 'Cart does not contain product we try to remove',
			'product_id'    => $product_id
		) );

		return false;
	}

	/**
	 * Restore WooC original session
	 *
	 * @param $reference
	 * @param $original_session_data
	 */
	public function restore_wc_original_session( $reference, $original_session_data ) {
		if ( isset( $original_session_data['bolt_wc_cookie_name'] ) ) {
			$wc_cookie_name             = $original_session_data['bolt_wc_cookie_name'];
			$wc_cookie_value            = $original_session_data['bolt_wc_cookie'];
			$_COOKIE[ $wc_cookie_name ] = $wc_cookie_value;

			WC()->session->init_session_cookie();
		}
	}
}

new Bolt_Update_Cart();