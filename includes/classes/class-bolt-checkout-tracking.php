<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles settings and provides common tracking functions needed by Bolt eCommerce checkout tracking.
 *
 * @class   Bolt_Checkout_Tracking
 * @version 2.1.0
 * @author  Bolt
 */

/**
 * Bolt_Checkout_Tracking.
 */
class Bolt_Checkout_Tracking {

	/** @var Bolt_Checkout_Tracking the singleton instance */
	protected static $instance = null;

	/** @var array associative array of queued tracking JavaScript * */
	private $queued_js;

	/**
	 * Constructs the class.
	 *
	 * Adds the necessary hooks.
	 *
	 * @since 2.1.0
	 */
	public function __construct() {
		$this->queued_js = array();
	}

	/**
	 * Hook into actions and filters.
	 *
	 * @since 2.1.0
	 */
	private function init_hooks() {

		// initializes the Bolt tracking event handlers on checkout page
		add_action( 'woocommerce_after_checkout_form', array( $this, 'init_tracking_event_handler_on_checkout' ) );

		// Track order processed on order confirmation page
		add_action( 'woocommerce_thankyou', array( $this, 'track_order_success' ) );

		// output tracking meta data via ajax
		add_filter( 'woocommerce_update_order_review_fragments', array(
			$this,
			'output_tracking_meta_data'
		) );

		// print tracking JavaScript
		add_action( 'wp_footer', array( $this, 'print_js' ) );

		// clear tracking session
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'clear_tracking_session' ), 10, 3 );
	}

	/**
	 * Returns the plugin singleton instance.
	 *
	 * @return Bolt_Checkout_Tracking the singleton instance
	 * @since 2.1.0
	 */
	public static function instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->init_hooks();
		}

		return self::$instance;
	}

	/**
	 * Reset the instance of the class
	 *
	 * @since  2.1.0
	 * @access public
	 */
	public static function reset() {
		self::$instance = null;
	}

	/**
	 * Initializes various Bolt tracking event handlers on checkout page.
	 *
	 * @since 2.1.0
	 */
	public function init_tracking_event_handler_on_checkout() {

		// bail if tracking is disabled
		if ( $this->do_not_track() || ! $this->not_page_reload() ) {
			return;
		}

		$this->insert_html_elements();
		$this->track_started_checkout();
		$this->track_shipping_details_complete();
		$this->track_shipping_options_complete();
		$this->track_payment_submit();
	}

	/**
	 * Insert the html elements for updating the fragments, so Bolt tracking event can get proper meta data in ajax callback.
	 *
	 * @since 2.1.0
	 */
	public function insert_html_elements() {

		echo '<div id="bolt_tracking_shipping_details" style="display:none;"></div>';
		echo '<div id="bolt_tracking_shipping_options" style="display:none;"></div>';
	}

	/**
	 * Output tracking meta data via ajax.
	 *
	 * @since 2.1.0
	 */
	public function output_tracking_meta_data( $fragments ) {

		// bail if tracking is disabled
		if ( ! $this->do_not_track( true ) ) {
			$fragments = $this->output_shipping_details_when_complete( $fragments );
			$fragments = $this->output_shipping_options_when_complete( $fragments );
		}

		return $fragments;
	}

	/**
	 * Clear session data for Bolt tracking
	 *
	 * @since 2.1.0
	 */
	public function clear_tracking_session( $order_id, $posted_data, $order = null ) {
		WC()->session->set( 'bolt_tracking_data', array() );
	}


	/**
	 * Tracks the start of checkout.
	 *
	 * @since 2.1.0
	 */
	public function track_started_checkout() {

		// reset tracking session
		WC()->session->set( 'bolt_tracking_data', array() );

		$cart_items = array();

		foreach ( WC()->cart->get_cart() as $item ) {

			// add product
			$cart_items[] = $this->get_cart_item_details( ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'], $item['quantity'] );
		}

		$args = array(
			'cart_items' => $cart_items,
		);

		$js = sprintf( "$( document ).ready(function() {
            BoltTrack.recordEvent('%s', %s );
        });", BOLT_CHECKOUT_TRACKING_EVENT_CHECKOUT_START, wp_json_encode( $args ) );

		// enqueue JS
		$this->enqueue_js( BOLT_CHECKOUT_TRACKING_EVENT_CHECKOUT_START, $js );
	}

	/**
	 * Outputs the shipping details for Bolt onShippingDetailsComplete event.
	 *
	 * @since 2.1.0
	 */
	private function output_shipping_details_when_complete( $fragments ) {

		if ( isset( $_POST['has_full_address'] ) && wc_string_to_bool( wc_clean( wp_unslash( $_POST['has_full_address'] ) ) ) ) {
			$tracking_session     = WC()->session->get( 'bolt_tracking_data', array() );
			$shipping_details     = wp_json_encode( WC()->cart->get_shipping_packages() );
			$shipping_details_md5 = md5( $shipping_details );

			if ( empty( $tracking_session ) || ! isset( $tracking_session['shipping_details'] ) || $shipping_details_md5 != $tracking_session['shipping_details'] ) {
				$fragments['#bolt_tracking_shipping_details'] = '<div id="bolt_tracking_shipping_details" style="display:none;">' . $shipping_details . '</div>';
				$tracking_session['shipping_details']         = $shipping_details_md5;
				WC()->session->set( 'bolt_tracking_data', $tracking_session );
			}
		}

		return $fragments;
	}

	/**
	 * Tracks when a customer completes the shipping details on checkout.
	 *
	 * @since 2.1.0
	 */
	public function track_shipping_details_complete() {

		$js = sprintf( "$( document.body ).bind( 'updated_checkout', function( event, data ) {
                if( data.fragments && data.fragments.hasOwnProperty( '#bolt_tracking_shipping_details' ) ) {
                    BoltTrack.recordEvent('%s', { 'shipping_details' : jQuery('#bolt_tracking_shipping_details').html() } );
                }
            });", BOLT_CHECKOUT_TRACKING_EVENT_SHIPPING_DETAILS_COMPLETE );

		// enqueue JS
		$this->enqueue_js( BOLT_CHECKOUT_TRACKING_EVENT_SHIPPING_DETAILS_COMPLETE, $js );
	}

	/**
	 * Outputs the shipping options for Bolt onShippingOptionsComplete event.
	 *
	 * @since 2.1.0
	 */
	private function output_shipping_options_when_complete( $fragments ) {

		$chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );

		if ( ! empty( $chosen_shipping_methods ) ) {
			$tracking_session     = WC()->session->get( 'bolt_tracking_data', array() );
			$shipping_options     = wp_json_encode( (array) $chosen_shipping_methods );
			$shipping_options_md5 = md5( $shipping_options );

			if ( empty( $tracking_session ) || ! isset( $tracking_session['shipping_options'] ) || $shipping_options_md5 != $tracking_session['shipping_options'] ) {
				$fragments['#bolt_tracking_shipping_options'] = '<div id="bolt_tracking_shipping_options" style="display:none;">' . $shipping_options . '</div>';
				$tracking_session['shipping_options']         = $shipping_options_md5;
				WC()->session->set( 'bolt_tracking_data', $tracking_session );
			}
		}

		return $fragments;
	}

	/**
	 * Tracks when a customer chooses the shipping option(s) on checkout.
	 *
	 * @since 2.1.0
	 */
	public function track_shipping_options_complete() {

		$js = sprintf( "$( document.body ).bind( 'updated_checkout', function( event, data ) {
                if( data.fragments && data.fragments.hasOwnProperty( '#bolt_tracking_shipping_options' ) ) {
                    BoltTrack.recordEvent('%s', { 'shipping_options' : jQuery('#bolt_tracking_shipping_options').html() } );
                }
            });", BOLT_CHECKOUT_TRACKING_EVENT_SHIPPING_OPTIONS_COMPLETE );

		// enqueue JS
		$this->enqueue_js( BOLT_CHECKOUT_TRACKING_EVENT_SHIPPING_OPTIONS_COMPLETE, $js );
	}

	/**
	 * Tracks when a customer is going to submit the payment on checkout.
	 *
	 * @since 2.1.0
	 */
	public function track_payment_submit() {

		$js = sprintf( "$( 'form.woocommerce-checkout' ).on( 'checkout_place_order', function( event ) {
                var wc_payment_method = $( 'form.woocommerce-checkout' ).find( 'input[name=\"payment_method\"]:checked' ).val();
                BoltTrack.recordEvent('%s', { 'paymentMethod' : wc_payment_method } );
                return true;
            });", BOLT_CHECKOUT_TRACKING_EVENT_PAYMENT_SUBMIT );

		// enqueue JS
		$this->enqueue_js( BOLT_CHECKOUT_TRACKING_EVENT_PAYMENT_SUBMIT, $js );
	}

	/**
	 * Track order processed on order confirmation page.
	 *
	 * @since 2.1.0
	 */
	public function track_order_success( $order_id ) {

		if ( $this->do_not_track() || ! $this->not_page_reload() ) {
			return;
		}

		$order         = wc_get_order( $order_id );
		$order_details = array();

		// Order number
		$order_details['order_number'] = $order->get_order_number();

		// Order customer
		if ( $order->get_user_id() ) {
			$user_id                     = absint( $order->get_user_id() );
			$user                        = get_user_by( 'id', $user_id );
			$order_details['order_user'] = sprintf(
				esc_html__( '%1$s (#%2$s &ndash; %3$s)', 'woocommerce' ),
				$user->display_name,
				absint( $user->ID ),
				$user->user_email
			);
		} else {
			$order_details['order_user'] = 'Guest';
		}

		// Order discount total
		if ( 0 < $order->get_total_discount() ) {
			$order_details['order_discount_total'] = $this->round_amount( $order->get_total_discount() );
		}

		// Order shipping total
		$order_details['order_shipping_total'] = $this->round_amount( $order->get_shipping_total() );

		// Order tax totals
		if ( wc_tax_enabled() ) {
			$order_details['order_tax_totals'] = array();
			foreach ( $order->get_tax_totals() as $code => $tax_total ) {
				$order_details['order_tax_totals'][ $code ]['label'] = $tax_total->label;
				$order_details['order_tax_totals'][ $code ]['total'] = $this->round_amount( $tax_total->amount );
			}
		}

		// Order total
		$order_details['order_total'] = $this->round_amount( $order->get_total() );

		// Order payment gateway
		$order_details['payment_gateway'] = $order->get_payment_method();

		$js = sprintf( "$( document ).ready(function() {
            BoltTrack.recordEvent('%s', %s );
        });", BOLT_CHECKOUT_TRACKING_EVENT_SUCCESS, wp_json_encode( $order_details ) );

		// enqueue JS
		$this->enqueue_js( BOLT_CHECKOUT_TRACKING_EVENT_SUCCESS, $js );
	}

	/**
	 * Determines if tracking is disabled.
	 *
	 * @param bool $ajax_event (optional) Whether or not this is an ajax event that should be tracked. Defaults to false.
	 * @param int $user_id (optional) User ID to check roles for
	 *
	 * @return bool
	 * @since 2.1.0
	 *
	 */
	private function do_not_track( $ajax_event = false, $user_id = null ) {

		// do not track activity in the admin area, unless specified
		if ( ! defined( 'BoltCheckout\Bolt_Settings::SETTING_NAME_ENABLED' )
		     || ! wc_bolt()->get_bolt_settings()->is_setting_enabled( Bolt_Settings::SETTING_NAME_ENABLED )
		     || ! wc_bolt()->get_bolt_settings()->is_setting_enabled( Bolt_Settings::SETTING_NAME_ENABLE_BOLT_CHECKOUT_ANALYTICS )
		     || ( ! $ajax_event && is_ajax() )
		     || is_admin() ) {
			$do_not_track = true;
		} else {
			$do_not_track = false;
		}

		return (bool) apply_filters( 'wc_bolt_checkout_tracking_do_not_track', $do_not_track, $ajax_event, $user_id );
	}

	/**
	 * Determines if a request was not a page reload.
	 *
	 * Prevents duplication of tracking events when user submits
	 * a form, e.g. applying a coupon on the cart page.
	 *
	 * This is not intended to prevent pageview events on a manual page refresh.
	 * Those are valid user interactions and should still be tracked.
	 *
	 * @return bool true if not a page reload, false if page reload
	 * @since 2.1.0
	 *
	 */
	private function not_page_reload() {

		// no referer..consider it's not a reload.
		if ( ! isset( $_SERVER['HTTP_REFERER'] ) ) {
			return true;
		}

		// compare paths
		return ( parse_url( $_SERVER['HTTP_REFERER'], PHP_URL_PATH ) !== parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );
	}

	/**
	 * Gets the details to add a product to the tracking code.
	 *
	 * @param int $product_id ID of the product to add.
	 * @param int $quantity Optional. Quantity to add to the code.
	 *
	 * @return string Code to use within a tracking code.
	 * @global array $woocommerce_loop The WooCommerce loop position data
	 * @since 2.1.0
	 */
	private function get_cart_item_details( $product_id, $quantity = 1 ) {
		global $woocommerce_loop;

		$product = wc_get_product( $product_id );

		$product_details_data = apply_filters( 'wc_bolt_checkout_tracking_product_details_data', array(
			'id'       => $this->get_product_identifier( $product ),
			// The product ID or SKU
			'name'     => $product->get_title(),
			// The name of the product
			'category' => $this->get_category_hierarchy( $product ),
			// The category to which the product belongs (e.g. Apparel). Use / as a delimiter to specify up to 5-levels of hierarchy (e.g. Apparel/Men/T-Shirts).
			'variant'  => $this->get_product_variation_attributes( $product ),
			// The variant of the product
			'price'    => $product->get_price(),
			// The price of a product
			'quantity' => $quantity,
			// The quantity of a product
			'position' => isset( $woocommerce_loop['loop'] ) ? $woocommerce_loop['loop'] : '',
			// The product's position in a list or collection
		), $product );

		return $product_details_data;
	}

	/**
	 * Returns the identifier for a given product.
	 *
	 * @param \WC_Product|int $product the product object or ID
	 *
	 * @return string the product identifier, either its SKU or `#<id>`
	 * @since 2.1.0
	 *
	 */
	private function get_product_identifier( $product ) {

		if ( ! $product instanceof \WC_Product ) {
			$product = wc_get_product( $product );
		}

		if ( ! $product ) {
			return '';
		}

		if ( $product->get_sku() ) {
			$identifier = $product->get_sku();
		} else {
			$product_id = ( $parent_id = $product->get_parent_id() ) ? $parent_id : $product->get_id();
			$identifier = '#' . $product_id;
		}

		return $identifier;
	}

	/**
	 * Gets the category hierarchy up to 5 levels deep for the passed product.
	 *
	 * @param \WC_Product the product object
	 *
	 * @return string the category hierarchy or an empty string
	 * @since 2.1.0
	 */
	private function get_category_hierarchy( $product ) {
		$product_id = ( $parent_id = $product->get_parent_id() ) ? $parent_id : $product->get_id();

		$categories = wc_get_product_terms( $product_id, 'product_cat', array(
			'orderby' => 'parent',
			'order'   => 'DESC'
		) );

		if ( ! is_array( $categories ) || empty( $categories ) ) {
			return '';
		}

		$child_term = $categories[0];

		return trim( $this->get_category_parents( $child_term->term_id ), '/' );
	}

	/**
	 * Builds the category hierarchy recursively.
	 *
	 * @param int $term_id the category term ID
	 * @param string $separator the term separator
	 * @param array $visited the visited term IDs
	 *
	 * @return string the category hierarchy
	 * @since 2.1.0
	 */
	private function get_category_parents( $term_id, $separator = '/', $visited = array() ) {

		$chain  = '';
		$parent = get_term( $term_id, 'product_cat' );

		if ( is_wp_error( $parent ) ) {
			return $parent;
		}

		$name = $parent->name;

		if ( $parent->parent && ( $parent->parent !== $parent->term_id ) && ! in_array( $parent->parent, $visited, true ) && count( $visited ) < 4 ) {

			$visited[] = $parent->parent;

			$chain .= $this->get_category_parents( $parent->parent, $separator, $visited );
		}

		$chain .= $name . $separator;

		return $chain;
	}

	/**
	 * Returns a comma separated list of variation attributes for a given variation or variable product.
	 *
	 * For a variable prouct, the default variation attributes ar returned.
	 *
	 * @param \WC_Product|int $product the product object or ID
	 *
	 * @return string comma-separated list of variation attributes
	 * @since 2.1.0
	 */
	private function get_product_variation_attributes( $product ) {

		if ( ! $product instanceof \WC_Product ) {
			$product = wc_get_product( $product );
		}

		if ( ! $product ) {
			return '';
		}

		$variant = '';

		if ( 'variation' === $product->get_type() ) {
			$variant = implode( ',', array_values( $product->get_variation_attributes() ) );
		} elseif ( 'variable' === $product->get_type() ) {
			$attributes = $product->get_default_attributes();
			$variant    = implode( ', ', array_values( $attributes ) );
		}

		return $variant;
	}

	/**
	 * Enqueues the tracking JavaScript.
	 *
	 * This method queues tracking JavaScript so it can be later output in the
	 * correct order.
	 *
	 * @param string $type the tracking type.
	 * @param string $javascript
	 *
	 * @since 2.1.0
	 */
	private function enqueue_js( $type, $javascript ) {

		if ( ! isset( $this->queued_js[ $type ] ) ) {
			$this->queued_js[ $type ] = array();
		}

		$this->queued_js[ $type ][] = $javascript;
	}

	/**
	 * Prints the tracking JavaScript.
	 *
	 * This method prints the queued tracking JavaScript in the correct order.
	 *
	 * @internal
	 *
	 * @since 2.1.0
	 */
	public function print_js() {

		if ( $this->do_not_track() ) {
			return;
		}

		$javascript = '';

		$types = array(
			BOLT_CHECKOUT_TRACKING_EVENT_CHECKOUT_START,
			BOLT_CHECKOUT_TRACKING_EVENT_SHIPPING_DETAILS_COMPLETE,
			BOLT_CHECKOUT_TRACKING_EVENT_SHIPPING_OPTIONS_COMPLETE,
			BOLT_CHECKOUT_TRACKING_EVENT_PAYMENT_SUBMIT,
			BOLT_CHECKOUT_TRACKING_EVENT_SUCCESS
		);

		foreach ( $types as $type ) {

			if ( isset( $this->queued_js[ $type ] ) ) {

				foreach ( $this->queued_js[ $type ] as $code ) {
					$javascript .= "\n" . $code . "\n";
				}
			}
		}

		// enqueue the JavaScript
		wc_enqueue_js( $javascript );
	}

	/**
	 * Round amount.
	 *
	 * @param double $value Amount to round.
	 *
	 * @return float
	 */
	private function round_amount( $value ) {
		return number_format( round( $value, 2 ), 2, '.', '' );
	}
}

Bolt_Checkout_Tracking::instance();