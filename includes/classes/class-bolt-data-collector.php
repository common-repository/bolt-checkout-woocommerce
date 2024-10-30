<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bolt Data Collector
 * Provide work with Bolt API and relating transforming data from woocommerce formats to bolt format
 *
 * @class   Bolt_Data_Collector
 * @version 1.0
 * @author  Bolt
 */
class Bolt_Data_Collector {

	/**
	 * The single instance of the class.
	 *
	 * @since 2.0.3
	 * @var Bolt_Data_Collector|null
	 */
	private static $instance = null;

	/**
	 * Store Bolt payment configurations.
	 *
	 * @since 1.0
	 * @var $bolt_gateway_settings
	 */
	public $bolt_gateway_settings;

	/**
	 * Get Bolt_Data_Collector Instance.
	 *
	 * @return Bolt_Data_Collector Instance
	 * @since 2.0.3
	 * @static
	 *
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Bolt_Data_Collector constructor.
	 *
	 * @since 1.0
	 */
	public function __construct() {
		BugsnagHelper::initBugsnag();
		// Get an instance of the Bolt Gateway class.
		$this->bolt_gateway_settings = wc_bolt()->get_settings();
	}

	/**
	 * Calls the Bolt API endpoints
	 *
	 * @param string $path Path to the endpoint excluding base URL and version and category (ex. 'orders' or 'transactions/credit')
	 * @param array|null $data The POST body of the request to be sent as JSON, null for GET
	 * @param string $category The category grouping of the request, default: 'merchant'
	 *
	 * @return array      The response received from Bolt
	 *
	 * @throws Exception  Throws an exception on failed attempts to communicate with the Bolt API
	 */
	public function handle_api_request( $path, $data = null, $category = 'merchant' ) {
		return wc_bolt()->get_api_request()->handle_api_request( $path, $data, $category );
	}

	/**
	 * Create order request for bolt.
	 *
	 * @param string $type The type of checkout that this cart is being built for checkout|shopping_cart|product
	 * @param string $order_reference If empty then we generate new order_reference
	 * @param boolean $is_save_session If true then we save session with created bolt order
	 * Flag doesn't work for orderinvoice - we always save this type of order
	 *
	 * @return array|false  Cart data in an array if all required customer information is available, otherwise false
	 */
	public function build_order( $type = BOLT_CART_ORDER_TYPE_CHECKOUT, $order_reference = '', $is_save_session = true ) {
		if ( $type == BOLT_CART_ORDER_TYPE_ORDER_INVOICE ) {
			$cart = $this->build_cart_for_orderinvoice();
		} else if ( WC()->cart->is_empty() ) {
			BugsnagHelper::notifyException(
				new \Exception( "Cart is empty in function build_order" ),
				array(),
				'warning'
			);

			return false;
		} else {
			$cart = $this->build_cart( $type, $order_reference );
			if ( $is_save_session ) {
				$this->update_cart_session( $type, $cart[ BOLT_CART_ORDER_REFERENCE ] );
			}
		}

		return $cart
			? array( BOLT_CART => $cart )
			: false;
	}

	/**
	 * If the customer pay from the "invoice for order" email, then we prepare the $bolt_data from order data
	 * Built the set of array to send it for 'Bolt create_orders' API request.
	 *
	 * @return array|false   The cart array data to send to Bolt or false if sending the data should be suppressed
	 */
	private function build_cart_for_orderinvoice() {
		global $wp;
		$order_id = absint( $wp->query_vars['order-pay'] );

		$update_result = wc_bolt_data()->update_session( "session_data_$order_id", BOLT_CART_ORDER_TYPE_ORDER_INVOICE );
		if ( ! $update_result ) {
			throw new \Exception( __( 'Fail to create/update the Bolt cart session', 'woocommerce-bolt-payment-gateway' ) );
		}

		return $this->format_order_as_bolt_cart( $order_id );
	}

	/**
	 * Generate bolt data by woocommerce order
	 *
	 * @param $order_id
	 * @param $order_reference
	 *
	 * @return array
	 * @since 2.0.0
	 *
	 */
	public function format_order_as_bolt_cart( $order_id, $order_reference = '' ) {
		$order        = wc_get_order( $order_id );
		$order_number = $order->get_order_number();

		if ( ! $order_reference ) {
			$order_reference = $order_id;
		}

		$bolt_cart                       = array(
			BOLT_CART_ORDER_REFERENCE => "$order_reference",
			BOLT_CART_DISPLAY_ID      => "$order_number"
		);
		$bolt_cart[ BOLT_CART_CURRENCY ] = $order->get_currency();
		$order                           = wc_get_order( $order_id );
		$total_amount                    = convert_monetary_value_to_bolt_format( $order->get_total(), $bolt_cart[ BOLT_CART_CURRENCY ] );
		$tax_amount                      = convert_monetary_value_to_bolt_format( $order->get_total_tax(), $bolt_cart[ BOLT_CART_CURRENCY ] );
		$shipping_total                  = convert_monetary_value_to_bolt_format( $order->get_shipping_total(), $bolt_cart[ BOLT_CART_CURRENCY ] );
		$shipping_tax_total              = convert_monetary_value_to_bolt_format( $order->get_shipping_tax(), $bolt_cart[ BOLT_CART_CURRENCY ] );
		$contains_physical_products      = false;
		if ( count( $order->get_items() ) > 0 ) {
			foreach ( $order->get_items() as $item_id => $item ) {
				$item_data  = $item->get_data();
				$product    = $item->get_product();
				$product_id = $product->get_id();
				// For backoffice order, the merchant may update the subtotal of line item and as a result the item unit price in this order can be different from its original price,
				// and it would cause price different issue in Bolt plugin. so the correct way is to calculate the item unit price by subtotal/quantity instead of its original price.
				$quantity_in_cart   = intval( $item->get_quantity() );
				$line_item_subtotal = $item->get_subtotal();
				$bolt_item_data     = array(
					BOLT_CART_ITEM_REFERENCE    => "$product_id",
					BOLT_CART_ITEM_NAME         => $product->get_name(),
					BOLT_CART_ITEM_SKU          => $product->get_sku(),
					BOLT_CART_ITEM_DESCRIPTION  => $product->get_short_description() ?: ( substr( $product->get_short_description(), 0, 8182 ) ?: '' ),
					BOLT_CART_ITEM_TOTAL_AMOUNT => convert_monetary_value_to_bolt_format( $line_item_subtotal, $bolt_cart[ BOLT_CART_CURRENCY ] ),
					BOLT_CART_ITEM_UNIT_PRICE   => convert_monetary_value_to_bolt_format( $line_item_subtotal / $quantity_in_cart, $bolt_cart[ BOLT_CART_CURRENCY ] ),
					BOLT_CART_ITEM_QUANTITY     => $quantity_in_cart,
					BOLT_CART_ITEM_TYPE         => $product->is_virtual() ? BOLT_CART_ITEM_TYPE_DIGITAL : BOLT_CART_ITEM_TYPE_PHYSICAL,
					BOLT_CART_ITEM_IMAGE_URL    => get_image_url_by_product( $product )
				);

				$contains_physical_products = $contains_physical_products || ( ! $product->is_virtual() );

				//metadata about product from module woocommerce-product-addon
				if ( isset ( $item_data['meta_data'] ) && $item_data['meta_data'] ) {
					$properties         = array();
					$product_attributes = array();
					if ( $product->is_type( 'variation' ) ) {
						$parent_product = wc_get_product( $product->get_parent_id() );
						foreach ( $parent_product->get_attributes() as $attribute ) {
							if ( ! $attribute['is_variation'] ) {
								continue;
							}
							$product_attributes[ sanitize_title( $attribute['name'] ) ] = $attribute['name'];
						}
					}
					foreach ( $item_data['meta_data'] as $meta_data ) {
						$property = (object) $meta_data->get_data();
						// Bolt only collects meta data which would be shown on the front page,
						// so for the types of meta data below, they should be excluded from Bolt order:
						// 1. the meta key is considered protected
						// 2. the value is serialized
						// 3. the value is array
						if ( is_protected_meta( $property->key, 'post' ) || is_serialized( $property->value ) || is_array( $property->value ) ) {
							continue;
						}
						if ( isset( $product_attributes[ $property->key ] ) ) {
							$property->key = $product_attributes[ $property->key ];
						}
						$properties[] = (object) array(
							'name'  => wc_attribute_label( $property->key ),
							'value' => replace_limit_exceeded_value_with_hint( $property->value, BOLT_CART_ITEM_PROPERTIES_LENGTH_LIMIT, BOLT_CART_ITEM_PROPERTIES_VAL_HINT ),
						);
					}
					$bolt_item_data[ BOLT_CART_ITEM_PROPERTIES ] = $properties;
				}

				$bolt_cart[ BOLT_CART_ITEMS ][] = $bolt_item_data;
			}
		}

		//fees. we add fees as items in the cart
		foreach ( $order->get_fees() as $fee_item_id => $fee_item ) {
			$bolt_item_data                 = array(
				BOLT_CART_ITEM_REFERENCE    => "$fee_item_id",
				BOLT_CART_ITEM_NAME         => $fee_item->get_name(),
				BOLT_CART_ITEM_SKU          => "wc_bolt_cart_fee-$fee_item_id",
				BOLT_CART_ITEM_DESCRIPTION  => $fee_item->get_name(),
				BOLT_CART_ITEM_TOTAL_AMOUNT => convert_monetary_value_to_bolt_format( $order->get_line_total( $fee_item ), $bolt_cart[ BOLT_CART_CURRENCY ] ),
				BOLT_CART_ITEM_UNIT_PRICE   => convert_monetary_value_to_bolt_format( $order->get_line_total( $fee_item ), $bolt_cart[ BOLT_CART_CURRENCY ] ),
				BOLT_CART_ITEM_QUANTITY     => 1,
			);
			$bolt_cart[ BOLT_CART_ITEMS ][] = $bolt_item_data;
		}

		$billing_country_code                   = bolt_addr_helper()->get_country_code( $order->get_billing_country() );
		$billing_country_name                   = bolt_addr_helper()->get_country_name( WC()->countries->countries[ $billing_country_code ] );
		$bolt_cart[ BOLT_CART_BILLING_ADDRESS ] = array(
			BOLT_STREET_ADDRESS1 => $order->get_billing_address_1() ?: '',
			BOLT_STREET_ADDRESS2 => $order->get_billing_address_2() ?: '',
			BOLT_FIRST_NAME      => $order->get_billing_first_name() ?: '',
			BOLT_LAST_NAME       => $order->get_billing_last_name() ?: '',
			BOLT_LOCALITY        => $order->get_billing_city() ?: '',
			BOLT_REGION          => $order->get_billing_state() ?: '',
			BOLT_POSTAL_CODE     => $order->get_billing_postcode() ?: '',
			BOLT_COUNTRY_CODE    => $billing_country_code,
			BOLT_COUNTRY         => $billing_country_name,
			BOLT_PHONE           => $order->get_billing_phone() ?: '',
			BOLT_EMAIL           => $order->get_billing_email() ?: '',
			BOLT_COMPANY         => $order->get_billing_company() ?: '',
		);

		$shipping_methods = $order->get_shipping_methods();
		if ( $contains_physical_products || $shipping_methods ) {
			$chosen_shipping_carrier   = '';
			$chosen_shipping_service   = '';
			$chosen_shipping_reference = '';
			foreach ( $shipping_methods as $shipping_method ) {
				$chosen_shipping           = $shipping_method;
				$chosen_shipping_carrier   = $chosen_shipping->get_name();
				$chosen_shipping_service   = $chosen_shipping->get_name();
				$chosen_shipping_reference = $chosen_shipping->get_method_id() . ':' . $chosen_shipping->get_instance_id();
				break;
			}

			$shipping_country_code              = bolt_addr_helper()->get_country_code( $order->get_shipping_country() );
			$shipping_country_name              = bolt_addr_helper()->get_country_name( WC()->countries->countries[ $shipping_country_code ] );
			$bolt_cart[ BOLT_CART_SHIPMENTS ][] = array(
				BOLT_CART_SHIPMENT_COST       => $shipping_total,
				BOLT_CART_SHIPMENT_TAX_AMOUNT => $shipping_tax_total,
				BOLT_CART_SHIPMENT_CARRIER    => $chosen_shipping_carrier,
				BOLT_CART_SHIPMENT_SERVICE    => $chosen_shipping_service,
				BOLT_CART_SHIPMENT_REFERENCE  => $chosen_shipping_reference,
				BOLT_CART_SHIPPING_ADDRESS    => array(
					BOLT_STREET_ADDRESS1 => $order->get_shipping_address_1(),
					BOLT_STREET_ADDRESS2 => $order->get_shipping_address_2(),
					BOLT_FIRST_NAME      => $order->get_shipping_first_name(),
					BOLT_LAST_NAME       => $order->get_shipping_last_name(),
					BOLT_LOCALITY        => $order->get_shipping_city(),
					BOLT_REGION          => $order->get_shipping_state(),
					BOLT_POSTAL_CODE     => $order->get_shipping_postcode(),
					BOLT_COUNTRY_CODE    => $shipping_country_code,
					BOLT_COUNTRY         => $shipping_country_name,
					BOLT_PHONE           => $order->get_billing_phone(),
					BOLT_EMAIL           => $order->get_billing_email(),
					BOLT_COMPANY         => $order->get_shipping_company() ?: '',
				)
			);
		}

		$bolt_cart[ BOLT_CART_TAX_AMOUNT ] = $tax_amount;

		///////////////////////////////////////////////////////
		// When the shopmanager create the order on the backend,
		// he may directly change the subtotal of line item,
		// it is pre-discount which is separate from the coupon,
		// and we should calculate it when try to create bolt order
		///////////////////////////////////////////////////////
		$order_discounts_total = convert_monetary_value_to_bolt_format( $order->get_discount_total(), $bolt_cart[ BOLT_CART_CURRENCY ] );

		$total_discount_amount = 0;
		$discounts             = array();

		foreach ( $order->get_items( 'coupon' ) as $coupon_item_id => $coupon_item ) {
			$coupon_code           = $coupon_item->get_code();
			$discount_amount       = $coupon_item->get_discount();
			$wc_coupon             = new \WC_Coupon( $coupon_code );
			$discounts[]           = array(
				BOLT_CART_DISCOUNT_AMOUNT      => convert_monetary_value_to_bolt_format( $discount_amount, $bolt_cart[ BOLT_CART_CURRENCY ] ),
				BOLT_CART_DISCOUNT_DESCRIPTION => $wc_coupon->get_description() ?: 'Coupon (' . (string) $coupon_code . ')',
				BOLT_CART_DISCOUNT_REFERENCE   => (string) $coupon_code,
				BOLT_CART_DISCOUNT_CATEGORY    => BOLT_DISCOUNT_CATEGORY_COUPON
			);
			$total_discount_amount += convert_monetary_value_to_bolt_format( $discount_amount );
		}

		$bolt_discounts = new Bolt_Discounts_Helper();
		foreach ( $bolt_discounts->get_third_party_discounts_by_order( $order ) as $discount ) {
			$discounts[]           = array(
				BOLT_CART_DISCOUNT_AMOUNT      => convert_monetary_value_to_bolt_format( $discount[ BOLT_CART_DISCOUNT_AMOUNT ], $bolt_cart[ BOLT_CART_CURRENCY ] ),
				BOLT_CART_DISCOUNT_DESCRIPTION => $discount[ BOLT_CART_DISCOUNT_DESCRIPTION ],
				BOLT_CART_DISCOUNT_REFERENCE   => $discount[ BOLT_CART_DISCOUNT_REFERENCE ],
				BOLT_CART_DISCOUNT_CATEGORY    => $discount[ BOLT_CART_DISCOUNT_CATEGORY ]
			);
			$total_discount_amount += convert_monetary_value_to_bolt_format( $discount[ BOLT_CART_DISCOUNT_AMOUNT ], $bolt_cart[ BOLT_CART_CURRENCY ] );
		}

		//////////////////////////////////////////////////////////
		// Compare the total discount of this order with the total
		// amount of coupons, if it is greater than coupons,
		// we should count the extra discount amount in,
		// and if it is less, based on the rule of woocommerce,
		// we should override the coupons.
		//////////////////////////////////////////////////////////
		if ( $order_discounts_total > $total_discount_amount ) {
			$discounts[] = array(
				BOLT_CART_DISCOUNT_AMOUNT      => $order_discounts_total - $total_discount_amount,
				BOLT_CART_DISCOUNT_DESCRIPTION => 'Discount',
				BOLT_CART_DISCOUNT_REFERENCE   => 'Discount'
			);
		} elseif ( $order_discounts_total < $total_discount_amount ) {
			$discounts   = array();
			$discounts[] = array(
				BOLT_CART_DISCOUNT_AMOUNT      => $order_discounts_total,
				BOLT_CART_DISCOUNT_DESCRIPTION => 'Discount',
				BOLT_CART_DISCOUNT_REFERENCE   => 'Discount'
			);
		}

		$bolt_cart[ BOLT_CART_DISCOUNTS ]    = $discounts;
		$bolt_cart[ BOLT_CART_TOTAL_AMOUNT ] = $total_amount;

		//get order note
		//TODO we also need to send the user note to bolt for normal checkout payonly mode
		$bolt_cart[ BOLT_CART_USER_NOTE ] = (string) $order->get_customer_note();

		return $bolt_cart;
	}

	/**
	 * Built the set of array to send it for 'Bolt create_orders' API request.
	 *
	 * @param string $type The type of checkout that this cart is being built for checkout|shopping_cart|product
	 * @param string $order_id The order id of bolt cart session if exist
	 * @param bool $calculate_cart Whether to calculate cart totals or not
	 *
	 * @return array|false   The cart array data to send to Bolt or false if sending the data should be suppressed
	 *
	 * @throws Exception   Thrown when the total itemized pricing differs by more than .10 cents than WooCommerce total price
	 */
	public function build_cart( $type, $order_id = '', $calculate_cart = true ) {
		do_action( 'wc_bolt_before_build_cart', $type, $order_id );
		// Generate order id if empty
		if ( empty( $order_id ) ) {
			$order_id = uniqid( BOLT_PREFIX_ORDER_REFERENCE, false );
		}

		// Calculate cart totals before building Bolt cart, to make sure count the hooks in.
		if ( $calculate_cart ) {
			Bolt_woocommerce_cart_calculation::calculate();
		}

		$bolt_cart = array( BOLT_CART_ORDER_REFERENCE => $order_id, BOLT_CART_DISPLAY_ID => $order_id );
		////////////////////////////////////////////////////////////////////////////

		$bolt_cart[ BOLT_CART_CURRENCY ] = get_woocommerce_currency();

		$cart_items = WC()->cart->get_cart();

		////////////////////////////////////////////////////////////////////////////
		// To calculate the amount in woocommerce, especially with bolt pay
		// it would be better to round it with no decimal digits after calculation
		// if we intval the result, send_curl_request would cause strange issue
		////////////////////////////////////////////////////////////////////////////
		$total_amount       = convert_monetary_value_to_bolt_format( WC()->cart->get_total( 'float' ) );
		$tax_amount         = convert_monetary_value_to_bolt_format( WC()->cart->get_total_tax() );
		$shipping_total     = ! empty( WC()->cart->shipping_total ) ? WC()->cart->shipping_total : 0;
		$shipping_total     = convert_monetary_value_to_bolt_format( $shipping_total );
		$shipping_tax_total = ! empty( WC()->cart->shipping_tax_total ) ? WC()->cart->shipping_tax_total : 0;
		$shipping_tax_total = convert_monetary_value_to_bolt_format( $shipping_tax_total );

		$line_subtotals = 0;

		foreach ( $cart_items as $cart_item_key => $line_item ) {

			$product    = $line_item['data'];
			$product_id = $product->get_id();

			$item_data = array(
				BOLT_CART_ITEM_REFERENCE    => "$product_id",
				BOLT_CART_ITEM_NAME         => $product->get_name(),
				BOLT_CART_ITEM_SKU          => $product->get_sku(),
				BOLT_CART_ITEM_DESCRIPTION  => $product->get_short_description() ?: ( substr( $product->get_short_description(), 0, 8182 ) ?: '' ),
				BOLT_CART_ITEM_TOTAL_AMOUNT => convert_monetary_value_to_bolt_format( $line_item['line_subtotal'] ),
				BOLT_CART_ITEM_UNIT_PRICE   => convert_monetary_value_to_bolt_format( $product->get_price() ),
				BOLT_CART_ITEM_QUANTITY     => intval( $line_item['quantity'] ),
				BOLT_CART_ITEM_TYPE         => $product->is_virtual() ? BOLT_CART_ITEM_TYPE_DIGITAL : BOLT_CART_ITEM_TYPE_PHYSICAL,
				BOLT_CART_ITEM_IMAGE_URL    => get_image_url_by_product( $product ),
				BOLT_CART_ITEM_PROPERTIES   => array()
			);

			$variation_data = array();

			// add variation data to BOLT_CART_ITEM_PROPERTIES.
			if ( $product->is_type( 'variation' ) && is_array( $line_item['variation'] ) ) {
				$parent_product = wc_get_product( $product->get_parent_id() );
				foreach ( $parent_product->get_attributes() as $attribute ) {
					if ( ! $attribute['is_variation'] ) {
						continue;
					}
					$attribute_key = 'attribute_' . sanitize_title( $attribute['name'] );
					if ( isset( $line_item['variation'][ $attribute_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						$variation_data[] = array(
							'key'   => $attribute['name'],
							'value' => $line_item['variation'][ $attribute_key ],
						);
					}
				}
			}

			// Filter item data to allow 3rd parties to add more to the array.
			$variation_data = apply_filters( 'woocommerce_get_item_data', $variation_data, $line_item );

			foreach ( $variation_data as $key => $data ) {
				// Only add unhidden data to BOLT_CART_ITEM_PROPERTIES.
				if ( empty( $data['hidden'] ) ) {
					$item_data[ BOLT_CART_ITEM_PROPERTIES ][] = (object) array(
						'name'  => wc_attribute_label( $data['key'] ),
						'value' => replace_limit_exceeded_value_with_hint( $data['value'], BOLT_CART_ITEM_PROPERTIES_LENGTH_LIMIT, BOLT_CART_ITEM_PROPERTIES_VAL_HINT ),
					);
				}
			}

			if ( isset( $line_item['addons'] ) ) {
				//metadata about product from module woocommerce-product-addon
				foreach ( $line_item['addons'] as $property ) {
					$property['value']                        = replace_limit_exceeded_value_with_hint( $property['value'], BOLT_CART_ITEM_PROPERTIES_LENGTH_LIMIT, BOLT_CART_ITEM_PROPERTIES_VAL_HINT );
					$item_data[ BOLT_CART_ITEM_PROPERTIES ][] = (object) $property;
				}
			}

			$line_subtotals                 += convert_monetary_value_to_bolt_format( $line_item['line_subtotal'] );
			$bolt_cart[ BOLT_CART_ITEMS ][] = $item_data;
		}

		//fees. we add fees as items in the cart
		$fee_total = 0;
		if ( $wc_fees = WC()->cart->get_fees() ) {
			// Save the cart fees in to session, and the fees can be restored when processing api webhooks.
			WC()->session->set( 'bolt_wc_fees', $wc_fees );
			foreach ( $wc_fees as $fee_item_id => $fee_item ) {
				$fee_amount = convert_monetary_value_to_bolt_format( $fee_item->amount );
				// Negative fees break Bolt checkout so we skip them, if merchant wants them applied as discounts do so
				if ( $fee_amount < 0 ) {
					continue;
				}
				$item_data = array(
					BOLT_CART_ITEM_REFERENCE    => "$fee_item->id",
					BOLT_CART_ITEM_NAME         => $fee_item->name,
					BOLT_CART_ITEM_SKU          => "wc_bolt_cart_fee-$fee_item->id",
					BOLT_CART_ITEM_DESCRIPTION  => $fee_item->name,
					BOLT_CART_ITEM_TOTAL_AMOUNT => $fee_amount,
					BOLT_CART_ITEM_UNIT_PRICE   => $fee_amount,
					BOLT_CART_ITEM_QUANTITY     => 1,
				);

				$fee_total                      += $fee_amount;
				$bolt_cart[ BOLT_CART_ITEMS ][] = $item_data;
			}
		}

		if ( BOLT_CART_ORDER_TYPE_CHECKOUT === $type ) {
			$checkout_address_data = false;
			if ( isset( $_POST['has_full_address'] ) && wc_string_to_bool( wc_clean( wp_unslash( $_POST['has_full_address'] ) ) ) ) {
				$checkout_address_data = bolt_addr_helper()->prepare_checkout_address_data();
			}

			if ( ! $checkout_address_data ) {
				return false;
			}
			$bolt_cart[ BOLT_CART_BILLING_ADDRESS ] = $checkout_address_data['billing_address'];
			if ( WC()->cart->needs_shipping() ) {
				$chosen_methods = WC()->cart->calculate_shipping();

				// Bolt server doesn't support multiple packages yet.
				// So we include only one shipping method with a cost equal to the total cost
				// of all shipping methods.
				if ( ! empty( $chosen_methods ) ) {
					$wc_shipping_rate        = current( $chosen_methods );
					$chosen_shipping_carrier = html_entity_decode( $wc_shipping_rate->get_label() );
					$chosen_shipping_service = html_entity_decode( $wc_shipping_rate->get_label() );

					$bolt_cart[ BOLT_CART_SHIPMENTS ][] = array(
						BOLT_CART_SHIPMENT_COST       => $shipping_total,
						BOLT_CART_SHIPMENT_TAX_AMOUNT => $shipping_tax_total,
						BOLT_CART_SHIPMENT_CARRIER    => $chosen_shipping_carrier,
						BOLT_CART_SHIPMENT_SERVICE    => $chosen_shipping_service,
						BOLT_CART_SHIPMENT_REFERENCE  => $wc_shipping_rate->get_id(),
						BOLT_CART_SHIPPING_ADDRESS    => $checkout_address_data['shipping_address'],
					);
				} else {
					BugsnagHelper::notifyException(
						new \Exception( "Shipping method isn't selected" ), array( 'bolt_cart' => $bolt_cart ), 'warning'
					);

					return false;
				}
			}

			$bolt_cart[ BOLT_CART_TAX_AMOUNT ] = $tax_amount;
		}

		$total_discount_amount = 0;
		$discounts             = array();
		$wc_discounts          = WC()->cart->get_coupon_discount_totals();

		foreach ( $wc_discounts as $coupon_code => $discount_amount ) {
			$coupon = new \WC_Coupon( $coupon_code );

			$discounts[]           = array(
				BOLT_CART_DISCOUNT_AMOUNT      => convert_monetary_value_to_bolt_format( $discount_amount ),
				BOLT_CART_DISCOUNT_DESCRIPTION => $coupon->get_description() ?: 'Coupon (' . (string) $coupon_code . ')',
				BOLT_CART_DISCOUNT_REFERENCE   => (string) $coupon_code,
				BOLT_CART_DISCOUNT_CATEGORY    => BOLT_DISCOUNT_CATEGORY_COUPON
			);
			$total_discount_amount += convert_monetary_value_to_bolt_format( $discount_amount );
		}

		$bolt_discounts = new Bolt_Discounts_Helper();
		foreach ( $bolt_discounts->get_third_party_discounts( $discounts ) as $discount ) {
			// For some types of coupons, for example, YITH Gift card,
			// WC()->cart->get_total() already count the discount amount in,
			// so there is no need to subtract it.
			if ( isset( $discount[ BOLT_CART_DISCOUNT_ON_TOTAL ] ) ) {
				$discount_on_total = $discount[ BOLT_CART_DISCOUNT_ON_TOTAL ];
				unset( $discount[ BOLT_CART_DISCOUNT_ON_TOTAL ] );
			} else {
				$discount_on_total = $discount[ BOLT_CART_DISCOUNT_AMOUNT ];
			}
			$discounts[]           = $discount;
			$total_discount_amount += $discount[ BOLT_CART_DISCOUNT_AMOUNT ];
			$total_amount          -= $discount_on_total;
		}

		$bolt_cart[ BOLT_CART_DISCOUNTS ] = $discounts;

		if ( $type == BOLT_CART_ORDER_TYPE_CART ) {
			// Woocommerce cart total can include shipping cost
			// We don't want to include shipping cost intp bolt order
			$expected_total_amount = $total_amount - $shipping_total - $tax_amount;

			$calculated_total_amount = convert_monetary_value_to_bolt_format( WC()->cart->get_subtotal() ) - $total_discount_amount + $fee_total;
			// For some types of coupons, for example, YITH Gift card, WooCommerce Smart Coupons etc.
			// we send all the available credits to Bolt, as a result, the $calculated_total_amount does not equal to $expected_total_amount,
			// and both of them are negative, in such a case, there is not need to compare.
			if ( $calculated_total_amount > 0 && $expected_total_amount > 0 && $calculated_total_amount <> $expected_total_amount ) {
				// In Bolt, we calculate total like the sum of subtotals by lines minus discount.
				// Wocommerce counts it as the sum of the total by lines.
				// Although woocommerce "core" discount classes have protect from them, third party discounts
				// like from plugin WooCommerce Points and Rewards can provide a discount with
				// non-round value, rounding error may accumulate, and discount total in Woocommerce can be wrong.
				// If the difference is only $0.01 we correct discount to have total the same as in Woocommerce.
				// TODO: This is temporary solution, we need to remove it once Pre-auth v2 is done
				$difference = $calculated_total_amount - $expected_total_amount;

				if ( ( abs( $difference ) == 1 ) && ( $total_discount_amount > 0 ) ) {
					$error_message                                                    = 'build cart: found and fixed rounding issue';
					$bolt_cart[ BOLT_CART_DISCOUNTS ][0][ BOLT_CART_DISCOUNT_AMOUNT ] += $difference;
					$calculated_total_amount                                          -= $difference;
				} else {
					$error_message = 'build cart: found rounding issue';
				}
				BugsnagHelper::notifyException(
					new \Exception( $error_message ),
					array(
						'difference'              => $difference,
						'wooc total amount'       => $total_amount,
						'calculated total amount' => $calculated_total_amount,
						'bolt_cart'               => $bolt_cart
					)
				);
			}
			$bolt_cart[ BOLT_CART_TOTAL_AMOUNT ] = $calculated_total_amount;
		} else {
			$bolt_cart[ BOLT_CART_TOTAL_AMOUNT ] = $total_amount;
		}

		// If the discounts include YITH Gift Card, all the available credits of the gift card would be sent to
		// the bolt server for further calculation, the car total may be less than zero at this moment, and
		// the max() can keep the total amount non-negative.
		$bolt_cart[ BOLT_CART_TOTAL_AMOUNT ] = max( 0, $bolt_cart[ BOLT_CART_TOTAL_AMOUNT ] );

		###########################################################################
		# Rounding can be set in several ways in WooCommerce via admin.
		# To deal with this, we adjust the tax so that the prices will add up.
		# We will also inform Bugsnag of the difference.
		###########################################################################
		if ( $type === BOLT_CART_ORDER_TYPE_CHECKOUT ) {
			$bolt_cart[ BOLT_CART_TAX_AMOUNT ] = $bolt_cart[ BOLT_CART_TOTAL_AMOUNT ] + $total_discount_amount - $line_subtotals - $shipping_total - $fee_total;

			if ( $bolt_cart[ BOLT_CART_TAX_AMOUNT ] != $tax_amount ) {
				$tolerance = 10;
				if ( abs( $bolt_cart[ BOLT_CART_TAX_AMOUNT ] - $tax_amount ) > $tolerance ) {
					throw new \Exception( "Error: Corrected tax differs by more than allowed tolerance of $tolerance cents.  Calculated tax: [{$bolt_cart[BOLT_CART_TAX_AMOUNT]}], Reported tax: [$tax_amount]" );
				} else {
					BugsnagHelper::notifyException( new \Exception( "Calculated and reported price difference resolved in tax.  Calculated tax: [{$bolt_cart[BOLT_CART_TAX_AMOUNT]}], Reported tax: [$tax_amount]" ), array(), 'warning' );
				}
			}
		}
		do_action( 'wc_bolt_after_build_cart', $type, $order_id, $bolt_cart );

		if ( WC()->session->has_session() ) {
			// Save cookie info of WooC session, so we can restore WooC original session if needed
			$wc_cookie = apply_filters( 'woocommerce_cookie', 'wp_woocommerce_session_' . COOKIEHASH );

			if ( isset( $_COOKIE[ $wc_cookie ] ) ) {
				WC()->session->set( 'bolt_wc_cookie_name', $wc_cookie );
				WC()->session->set( 'bolt_wc_cookie', $_COOKIE[ $wc_cookie ] );
			}
		}

		return apply_filters( 'wc_bolt_order_creation_cart_data', $bolt_cart );
	}

	/**
	 * Save session data in the storage
	 *
	 * @param $type - possible values checkout|cart|orderinvoice
	 * @param $order_id
	 *
	 * @since 2.0.0
	 *
	 */
	public function update_cart_session( $type, $order_id ) {
		if ( Bolt_Feature_Switch::instance()->is_native_cart_session_enabled() && $type == BOLT_CART_ORDER_TYPE_CART ) {
			$customer_id = WC()->session->get_customer_id();
			if ( empty( $customer_id ) ) {
				throw new \Exception( __( 'The customer session has expired.', 'woocommerce-bolt-payment-gateway' ) );
			}
			wc_bolt_data()->update_customer_id( $order_id, $customer_id );

			return;
		}
		do_action( 'wc_bolt_before_update_cart_session', $type, $order_id );
		if ( $type == BOLT_CART_ORDER_TYPE_ORDER_INVOICE ) {
			$data = 'orderinvoice';
		} else {
			WC()->cart->set_session(); #set session
			WC()->cart->maybe_set_cart_cookies(); #set cookie to allow opportunity to write session to DB
			// Save customer into session in case if user just logged in and right value
			// is still not written.
			WC()->customer->save();
			WC()->session->save_data(); #write session to DB
			//get woocommerce session from DB and save it in our DB
			$data = WC()->session->get_session_data();
			// Filter to save additional data to support third-party plugins
			$data = apply_filters( 'wc_bolt_update_cart_session', $data, $type, $order_id );
		}

		$update_result = wc_bolt_data()->update_session( "session_data_$order_id", $data );
		do_action( 'wc_bolt_after_update_cart_session', $type, $order_id, $data );
		if ( ! $update_result ) {
			throw new \Exception( __( 'Fail to create/update the Bolt cart session', 'woocommerce-bolt-payment-gateway' ) );
		}
	}

	/**
	 * Creates a Bolt order
	 *
	 * @param mixed $order_details Cart data in an array if all required customer information is available, otherwise false
	 *
	 * @return object  A Bolt Cart Object on success or an empty object if customer information was missing
	 */
	public function create_bolt_order( $order_details ) {
		$response = new \stdClass();
		if ( $order_details ) {
			try {
				$response = $this->handle_api_request( 'orders', $order_details );
			} catch ( \Exception $e ) {
				BugsnagHelper::notifyException( $e );
			}

			if ( $response->errors ) {
				BugsnagHelper::notifyException( new \Exception( "Bolt order creation error.  request: " . json_encode( $order_details, JSON_PRETTY_PRINT ) . "\n\n response: " . json_encode( $response, JSON_PRETTY_PRINT ) ) );
			}
		}

		return $response;
	}

	/**
	 * Generate bolt order by woocommerce cart and then
	 * call bolt order creation API or get value from cache if cart wasn't change
	 *
	 * @param string $type - possible values checkout|cart|orderinvoice
	 *
	 * @return array including order_details and  order_token
	 * @since 2.0.0
	 *
	 */
	public function get_order_and_order_token( $type = BOLT_CART_ORDER_TYPE_CHECKOUT ) {
		wc_bolt()->get_metrics_client()->save_start_time();
		$order_details = $this->build_order( $type, '', false );
		if ( ! $order_details ) {
			//If we on checkout page and address isn't filled out we still want to show bolt button
			wc_bolt()->get_metrics_client()->process_metric( BOLT_METRIC_NAME_ORDER_TOKEN, BOLT_STATUS_FAILURE );

			return array( '', '' );
		}

		$order_md5    = $this->calc_bolt_order_md5( $order_details );
		$order_amount = $order_details[ BOLT_CART ][ BOLT_CART_TOTAL_AMOUNT ];
		$bolt_data    = WC()->session->get( 'bolt_data', array() );

		if ( $bolt_data && ! empty( $bolt_data[ BOLT_CART_ORDER_REFERENCE ] )
		     && ! empty( wc_bolt_data()->get_session( $bolt_data[ BOLT_CART_ORDER_REFERENCE ] ) )
		     && ! empty( $bolt_data[ BOLT_CART_ORDER_TOKEN ] )
		     && isset( $bolt_data['order_amount'] ) && isset( $bolt_data['order_md5'] )
		     && ( $bolt_data['order_amount'] == $order_amount ) && ( $bolt_data['order_md5'] == $order_md5 ) ) {
			$order_reference                                         = $bolt_data[ BOLT_CART_ORDER_REFERENCE ];
			$order_token                                             = $bolt_data[ BOLT_CART_ORDER_TOKEN ];
			$order_details[ BOLT_CART ][ BOLT_CART_ORDER_REFERENCE ] = $order_reference;
			$order_details[ BOLT_CART ][ BOLT_CART_DISPLAY_ID ]      = $order_reference;
		} else {
			if ( Bolt_Feature_Switch::instance()->is_native_cart_session_enabled()
			     && $type == BOLT_CART_ORDER_TYPE_CART
			     && isset( $bolt_data[ BOLT_CART_ORDER_REFERENCE ] ) ) {
				// The cart reference from session is already saved in Bolt customer id table.
				// Although cart is changed, there is no need to generate a new order reference
				$order_reference                                         = $bolt_data[ BOLT_CART_ORDER_REFERENCE ];
				$order_details[ BOLT_CART ][ BOLT_CART_ORDER_REFERENCE ] = $order_reference;
				$order_details[ BOLT_CART ][ BOLT_CART_DISPLAY_ID ]      = $order_reference;

			} else {
				$order_reference = $order_details[ BOLT_CART ][ BOLT_CART_ORDER_REFERENCE ];
			}
			$order_creation_response = $this->create_bolt_order( $order_details );
			$order_token             = ! empty( $order_creation_response->token ) ? $order_creation_response->token : '';

			$bolt_data = array(
				             'customer_id'             => WC()->session->get_customer_id(),
				             BOLT_CART_ORDER_REFERENCE => $order_reference,
				             BOLT_CART_ORDER_TOKEN     => $order_token,
				             'order_amount'            => $order_amount,
				             'order_md5'               => $order_md5
			             ) + $bolt_data;

			// Save id into Woocommerce session to have it when we handle API calls
			if ( ! isset( $bolt_data['id'] ) && isset( $_COOKIE['bolt_customer_id'] ) ) {
				$bolt_data['id'] = $_COOKIE['bolt_customer_id'];
			}

			WC()->session->set( 'bolt_data', $bolt_data );
			// Save cart session if we created a new bolt order.
			// The discount endpoint and down the road other endpoints
			// could change bolt order, so we shouldn't update cart session
			// if it's already exist.
			$this->update_cart_session( $type, $order_reference );
		}


		wc_bolt()->get_metrics_client()->process_metric( BOLT_METRIC_NAME_ORDER_TOKEN, BOLT_STATUS_SUCCESS );

		return array( $order_details, $order_token );
	}

	/**
	 * Calculate md5 for bolt order (excluding order reference)
	 *
	 * @return string
	 * @since 2.0.0
	 *
	 */
	public function calc_bolt_order_md5( $order_details ) {
		unset( $order_details[ BOLT_CART ][ BOLT_CART_ORDER_REFERENCE ] );
		unset( $order_details[ BOLT_CART ][ BOLT_CART_DISPLAY_ID ] );

		// image_url can be different if merchant uses CDN
		array_walk(
			$order_details[ BOLT_CART ][ BOLT_CART_ITEMS ],
			function ( &$item ) {
				unset( $item[ BOLT_CART_ITEM_IMAGE_URL ] );
			}
		);

		$order_md5 = md5( json_encode( $order_details ) . WC()->session->get_customer_id() );

		return $order_md5;
	}

	// Methods for backward compatibility with extensions
	// TODO: remove them after update extensions
	public function api_request( $path, $data = null, $category = 'merchant' ) {
		return $this->handle_api_request( $path, $data, $category );
	}
}
