<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper class around interacting with coupons.
 *
 * @class   Bolt_Discounts_Helper
 * @version 1.2.7
 * @author  Bolt
 */

/**
 * Bolt_Discounts_Helper.
 */
class Bolt_Discounts_Helper extends \WC_Discounts {
	const E_BOLT_INSUFFICIENT_INFORMATION = 6200;
	const E_BOLT_CODE_INVALID = 6201;
	const E_BOLT_CODE_EXPIRED = 6202;
	const E_BOLT_CODE_NOT_AVAILABLE = 6203;
	const E_BOLT_CODE_LIMIT_REACHED = 6204;
	const E_BOLT_MINIMUM_CART_AMOUNT_REQUIRED = 6205;
	const E_BOLT_UNIQUE_EMAIL_REQUIRED = 6206;
	const E_BOLT_ITEMS_NOT_ELIGIBLE = 6207;
	const E_BOLT_SERVICE = 6001;
	const E_BOLT_CUSTOM_ERROR_MSG = 6103;

	const E_BOLT_YITH_YWGC_PREMIUM_GENERAL_ERROR = 60001; // YITH WooCommerce Gift Cards Premium general error code

	/**
	 * Reference to cart or order object.
	 *
	 * @since 1.2.7
	 * @var mixed
	 */
	protected $object;

	/**
	 * Reference to the transaction object retrieved from the Bolt API endpoint.
	 *
	 * @since 1.2.7
	 * @var   WC_Cart
	 */
	private $api_request;

	/**
	 * Constructor Function.
	 *
	 * @param mixed $object Cart or order object.
	 *
	 * @since  1.2.7
	 * @access public
	 *
	 */
	public function __construct( $object = array() ) {
		if ( is_a( $object, 'WC_Cart' ) ) {
			$this->set_items_from_cart( $object );
		} elseif ( is_a( $object, 'WC_Order' ) ) {
			$this->set_items_from_order( $object );
		} elseif ( isset( $object->cart->order_reference ) ) {
			// Load wc_cart from bolt cart session
			// Retrieve the active cart session by order reference
			set_cart_by_bolt_reference( $object->cart->order_reference );
			reset_wc_notices();
			WC()->cart->maybe_set_cart_cookies();
			$this->set_items_from_cart( WC()->cart );
			$this->api_request = $object;
		}
	}

	/**
	 * Helper function to add coupon to cart
	 *
	 * @param string $discount_code Discount code to be validated and added.
	 * @param string $billing_email Billing email to check email restrictions on a discount.
	 *
	 * @return Coupon object
	 * @since  2.5.0
	 * @access public
	 *
	 */
	public function add_coupon_to_cart( $discount_code, $billing_email = '' ) {
		// If the customer try to add coupon code of 3rd-party plugin, we can use this filter to insert discount info.
		$the_coupon = apply_filters( 'wc_bolt_add_third_party_discounts_to_cart', null, $discount_code );

		if ( ! empty( $the_coupon ) ) {
			return $the_coupon;
		}

		$the_coupon = $this->add_wc_coupon_to_cart( $discount_code );

		return $the_coupon;
	}

	/**
	 * Helper function to add WooC coupon to cart
	 *
	 * @param string $discount_code Discount code to be validated and added.
	 * @param string $billing_email Billing email to check email restrictions on a discount.
	 *
	 * @return WC_Coupon Coupon object
	 * @since  2.15.0
	 * @access public
	 *
	 */
	public function add_wc_coupon_to_cart( $discount_code, $billing_email = '' ) {
		// Coupons are globally disabled.
		if ( ! wc_coupons_enabled() ) {
			throw new \Exception( __( 'WooCommerce coupon is globally disabled', 'woocommerce-bolt-payment-gateway' ), \WC_Coupon::E_WC_COUPON_NOT_APPLICABLE );
		}

		// Sanitize coupon code.
		$coupon_code = wc_format_coupon_code( $discount_code );

		// Get the coupon.
		$the_coupon = new \WC_Coupon( $coupon_code );

		// Check if applied.
		// Cause the Bolt may call discount hook several times during checkout,
		// if the coupon is already applied, we would remove the coupon first and then apply it again
		if ( $this->validate_if_already_applied( $the_coupon ) ) {
			$this->object->remove_coupon( $coupon_code );
		}

		// Check it can be used with cart.
		$this->validate_coupon( $the_coupon, $billing_email, true );

		if ( ! $this->object->add_discount( $coupon_code ) ) {
			if ( 0 === wc_notice_count( WC_NOTICE_TYPE_ERROR ) ) {
				throw new \Exception( __( 'Fail to apply coupon to cart', 'woocommerce-bolt-payment-gateway' ), \WC_Coupon::E_WC_COUPON_NOT_APPLICABLE );
			} else {
				$err_notices = wc_get_notices( WC_NOTICE_TYPE_ERROR );
				$error_msg   = '';
				foreach ( $err_notices as $notice ) {
					// WooCommerce notice has different structures in different versions.
					$error_msg .= wc_kses_notice( get_wc_notice_message( $notice ) );
				}
				throw new \Exception( $error_msg, \WC_Coupon::E_WC_COUPON_NOT_APPLICABLE );
			}
		}

		// For the Bolt discount hook, there is no need to show success notices on the page.
		$this->remove_coupon_success_notices( $the_coupon );

		return $the_coupon;
	}

	/**
	 * Removes coupon from a cart if it is already applied
	 *
	 * @param string $discount_code Discount code to be validated and removed.
	 *
	 * @return true | Exception   Returns true if the coupon was removed or it will throw an error
	 * @throws \Exception when unknown currency code is passed
	 * @since  2.5.0
	 * @access public
	 *
	 */
	public function remove_coupon_from_cart( $discount_code ) {
		$coupon_code = wc_format_coupon_code( $discount_code );
		$is_removed  = apply_filters( 'wc_bolt_remove_coupon_from_cart', false, $discount_code );
		if ( $is_removed ) {
			return true;
		}
		// Get the coupon.
		$the_coupon = new \WC_Coupon( $coupon_code );
		// if the coupon is not applied, we should throw an error
		if ( ! $this->validate_if_already_applied( $the_coupon ) ) {
			throw new \Exception( 'Unable to remove coupon since the coupon is already applied' );
		}
		if ( ! $this->object->remove_coupon( $coupon_code ) ) {
			throw new \Exception( 'Unable to remove coupon' );
		}
		$this->remove_coupon_success_notices( $the_coupon );

		return true;
	}

	/**
	 * Apply coupon code from discount hook
	 *
	 * @return WP_REST_Response   Well-formed response sent to the Bolt Server
	 * @since  1.2.7
	 * @access public
	 *
	 */
	public function apply_coupon_from_discount_hook() {
		try {
			if ( ! is_a( $this->object, 'WC_Cart' ) || $this->object->is_empty() ) {
				throw new \Exception( __( 'Empty cart', 'woocommerce-bolt-payment-gateway' ), self::E_BOLT_INSUFFICIENT_INFORMATION );
			}
			// Adds a filter hook to response without validation&calculation for negative fee. 
			$is_coupon_negative_fee = apply_filters( 'wc_bolt_is_coupon_negative_fee', false, $this->api_request );
			if ( $is_coupon_negative_fee ) {
				$order_submission_data              = array();
				$order_submission_data[ BOLT_CART ] = array();
				$discounts                          = array();
				$discount_info                      = array();
				foreach ( $this->api_request->cart->discounts as $discount ) {
					$discounts[] = array(
						BOLT_CART_DISCOUNT_AMOUNT      => $discount->amount->amount,
						BOLT_CART_DISCOUNT_DESCRIPTION => (string) ( $discount->description ),
						BOLT_CART_DISCOUNT_REFERENCE   => $discount->code,
						BOLT_CART_DISCOUNT_CATEGORY    => $discount->discount_category
					);
					if ( $discount->code == $this->api_request->discount_code ) {
						$discount_info = array(
							'discount_code'     => $discount->code,
							'discount_type'     => 'fixed_amount',
							'discount_amount'   => $discount->amount->amount,
							'description'       => (string) ( $discount->description ),
							'discount_category' => $discount->discount_category
						);
					}
				}
				$order_submission_data[ BOLT_CART ][ BOLT_CART_DISCOUNTS ]    = $discounts;
				$order_submission_data[ BOLT_CART ][ BOLT_CART_TAX_AMOUNT ]   = $this->api_request->cart->tax_amount->amount;
				$order_submission_data[ BOLT_CART ][ BOLT_CART_TOTAL_AMOUNT ] = $this->api_request->cart->total_amount->amount;

			} else {
				// If the customer try to add coupon code of 3rd-party plugin, we can use this filter to insert discount info.
				$discount_info = apply_filters( 'wc_bolt_add_third_party_discounts_to_cart_from_discount_hook', array(), $this->api_request->discount_code );
				if ( empty( $discount_info ) ) {
					$billing_email = isset( $this->api_request->customer_email ) ? $this->api_request->customer_email : '';
					$the_coupon    = $this->add_wc_coupon_to_cart( $this->api_request->discount_code, $billing_email );
					$coupon_code   = wc_format_coupon_code( $this->api_request->discount_code );
					$discount_info = array(
						'discount_code'             => $coupon_code,
						'discount_type'             => $this->convert_to_bolt_discount_type( $the_coupon ),
						BOLT_CART_DISCOUNT_CATEGORY => BOLT_DISCOUNT_CATEGORY_COUPON
					);
				}
				// Some 3rd-party plugins add error notice instead of throwing exception if fail to add coupon
				if ( $notices = wc_get_notices( WC_NOTICE_TYPE_ERROR ) ) {
					$error_msg = '';
					foreach ( $notices as $notice ) {
						// WooCommerce notice has different structures in different versions.
						$error_msg .= wp_kses_post( get_wc_notice_message( $notice ) );
					}
					throw new \Exception( $error_msg, self::E_BOLT_CUSTOM_ERROR_MSG );
				}
				// After new coupon applied, we need to refresh the cart session
				$order_submission_data = wc_bolt()->get_bolt_data_collector()->build_order( BOLT_CART_ORDER_TYPE_CART, $this->api_request->cart->order_reference, ! Bolt_Feature_Switch::instance()->is_native_cart_session_enabled() );
				foreach ( $order_submission_data[ BOLT_CART ][ BOLT_CART_DISCOUNTS ] as $discount ) {
					if ( $discount[ BOLT_CART_DISCOUNT_REFERENCE ] === $discount_info['discount_code'] ) {
						$discount_info['discount_amount']             = $discount[ BOLT_CART_DISCOUNT_AMOUNT ];
						$discount_info['description']                 = $discount[ BOLT_CART_DISCOUNT_DESCRIPTION ];
						$discount_info[ BOLT_CART_DISCOUNT_CATEGORY ] = $discount[ BOLT_CART_DISCOUNT_CATEGORY ];
					}
				}
			}

			return Bolt_HTTP_Handler::prepare_http_response(
				array_merge(
					array(
						BOLT_FIELD_NAME_STATUS => BOLT_STATUS_SUCCESS,
						BOLT_CART              => array(
							BOLT_CART_TOTAL_AMOUNT => $order_submission_data[ BOLT_CART ][ BOLT_CART_TOTAL_AMOUNT ],
							BOLT_CART_TAX_AMOUNT   => isset( $order_submission_data[ BOLT_CART ][ BOLT_CART_TAX_AMOUNT ] ) ? $order_submission_data[ BOLT_CART ][ BOLT_CART_TAX_AMOUNT ] : 0,
							BOLT_CART_DISCOUNTS    => $order_submission_data[ BOLT_CART ][ BOLT_CART_DISCOUNTS ],
						),
					),
					$discount_info
				),
				HTTP_STATUS_OK,
				array( BOLT_HEADER_CACHED_VALUE => false )
			);
		} catch ( \Exception $e ) {
			return Bolt_HTTP_Handler::prepare_http_response(
				array(
					BOLT_FIELD_NAME_STATUS => BOLT_STATUS_FAILURE,
					BOLT_FIELD_NAME_ERROR  => array(
						BOLT_FIELD_NAME_ERROR_CODE    => $this->get_bolt_err_code( $e->getCode() ),
						BOLT_FIELD_NAME_ERROR_MESSAGE => $e->getMessage(),
					),
				),
				HTTP_STATUS_UNPROCESSABLE,
				array( BOLT_HEADER_CACHED_VALUE => false )
			);
		}
	}

	/**
	 * Check for user coupons. If a coupon is invalid, add an error notice.
	 *
	 * @param array $raw_data Value to set.
	 * @param Bolt_Error_Handler $error_handler Error storage
	 * @param array $err_code_mapping Convert normal error code into specific code by endpoint
	 *
	 * @return boolean
	 * @since  1.2.8
	 * @access public
	 *
	 */
	public function validate_applied_coupons( $raw_data = array(), $error_handler = null, $err_code_mapping = array( 'default' => E_BOLT_GENERAL_ERROR ) ) {
		try {
			if ( is_a( $this->object, 'WC_Cart' ) ) {
				if ( $this->object->is_empty() ) {
					throw new \Exception( __( 'Empty cart', 'woocommerce-bolt-payment-gateway' ) );
				}
				$applied_coupons = $this->object->get_applied_coupons();
				// Coupons are globally disabled.
				if ( ! empty( $applied_coupons ) && ! wc_coupons_enabled() ) {
					throw new \Exception( __( 'WooCommerce coupon is globally disabled', 'woocommerce-bolt-payment-gateway' ), \WC_Coupon::E_WC_COUPON_NOT_APPLICABLE );
				}
				if ( empty( $error_handler ) ) {
					$error_handler = new Bolt_Error_Handler();
				}
				foreach ( $applied_coupons as $coupon_code ) {
					// Check it can be used with cart.
					try {
						$the_coupon = new \WC_Coupon( $coupon_code );
					} catch ( \Exception $e ) {
						$error_handler->handle_error(
							isset( $err_code_mapping[ \WC_Coupon::E_WC_COUPON_NOT_EXIST ] ) ? $err_code_mapping[ \WC_Coupon::E_WC_COUPON_NOT_EXIST ] : $err_code_mapping['default'],
							(object) array(
								BOLT_ERR_REASON        => sprintf( __( 'Coupon "%s" does not exist!', 'woocommerce-bolt-payment-gateway' ), $coupon_code ),
								BOLT_ERR_DISCOUNT_CODE => $coupon_code,
							)
						);
						continue;
					}
					try {
						$billing_email = isset( $raw_data[ WC_BILLING_EMAIL ] ) ? $raw_data[ WC_BILLING_EMAIL ] : '';
						$this->validate_coupon( $the_coupon, $billing_email );
					} catch ( \Exception $e ) {
						$err_code   = $e->getCode();
						$err_reason = $the_coupon->get_coupon_error( $err_code );
						if ( empty( $err_reason ) ) {
							$err_reason = $e->getMessage();
						}
						$error_handler->handle_error(
							isset( $err_code_mapping[ $err_code ] ) ? $err_code_mapping[ $err_code ] : $err_code_mapping['default'],
							(object) array(
								BOLT_ERR_REASON        => $err_reason,
								BOLT_ERR_DISCOUNT_CODE => $coupon_code,
							)
						);
						continue;
					}
				}
			}
		} catch ( \Exception $e ) {
			$error_handler->handle_error(
				isset( $err_code_mapping[ E_BOLT_GENERAL_ERROR ] ) ? $err_code_mapping[ E_BOLT_GENERAL_ERROR ] : $err_code_mapping['default'],
				(object) array(
					BOLT_ERR_REASON => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Check if a coupon is valid.
	 *
	 * @param WC_Coupon $coupon Coupon data.
	 * @param string $billing_email Billing email of the customer.
	 * @param boolean $is_new True if trying to add a new coupon or false if not.
	 *
	 * @return boolean
	 * @since  1.2.7
	 * @access public
	 *
	 */
	public function validate_coupon( $coupon, $billing_email = '', $is_new = false ) {
		$coupon_code = $coupon->get_code();

		// Prevent adding coupons by post ID.
		if ( ! $this->validate_if_add_by_post_id( $coupon, $coupon_code ) ) {
			throw new \Exception( $coupon->get_coupon_error( \WC_Coupon::E_WC_COUPON_NOT_EXIST ), \WC_Coupon::E_WC_COUPON_NOT_EXIST );
		}

		$this->validate_coupon_exists( $coupon );
		$this->validate_coupon_usage_limit( $coupon );
		$this->validate_coupon_user_usage_limit( $coupon );
		$this->validate_coupon_expiry_date( $coupon );
		$this->validate_coupon_minimum_amount( $coupon );
		$this->validate_coupon_maximum_amount( $coupon );
		$this->validate_coupon_product_ids( $coupon );
		$this->validate_coupon_product_categories( $coupon );
		$this->validate_coupon_excluded_items( $coupon );
		$this->validate_coupon_eligible_items( $coupon );

		if ( ! apply_filters( 'woocommerce_coupon_is_valid', true, $coupon, $this ) ) {
			throw new \Exception( __( 'Discounts is not valid.', 'woocommerce' ), \WC_Coupon::E_WC_COUPON_INVALID_FILTERED );
		}

		// Check to see if an individual use coupon is set.
		if ( ! $this->validate_if_has_individual_use( $coupon, $is_new ) ) {
			throw new \Exception( $coupon->get_coupon_error( \WC_Coupon::E_WC_COUPON_ALREADY_APPLIED_INDIV_USE_ONLY ), \WC_Coupon::E_WC_COUPON_ALREADY_APPLIED_INDIV_USE_ONLY );
		}

		if ( ! empty( $billing_email ) ) {
			// Check to see if limit to defined email addresses.
			if ( ! $this->validate_if_limit_to_email( $coupon, $billing_email ) ) {
				throw new \Exception( $coupon->get_coupon_error( \WC_Coupon::E_WC_COUPON_NOT_YOURS_REMOVED ), \WC_Coupon::E_WC_COUPON_NOT_YOURS_REMOVED );
			}

			// Check coupon usage limits per user.
			if ( ! $this->validate_usage_limit_per_user( $coupon, $billing_email ) ) {
				throw new \Exception( $coupon->get_coupon_error( \WC_Coupon::E_WC_COUPON_USAGE_LIMIT_REACHED ), \WC_Coupon::E_WC_COUPON_USAGE_LIMIT_REACHED );
			}
		}

		return true;
	}

	/**
	 * Get Bolt error code by WooCommerce coupon error code.
	 *
	 * @param string $err_code WooCommerce coupon error codes.
	 *
	 * @return string
	 * @since  1.2.7
	 * @access public
	 *
	 */
	public function get_bolt_err_code( $err_code ) {
		/* Defined WooCommerce Discounts Error Codes:
		 *
		 * E_WC_COUPON_INVALID_FILTERED                 - 100: Invalid filtered.
		 * E_WC_COUPON_INVALID_REMOVED                  - 101: Invalid removed.
		 * E_WC_COUPON_NOT_YOURS_REMOVED                - 102: Not yours removed.
		 * E_WC_COUPON_ALREADY_APPLIED                  - 103: Already applied.
		 * E_WC_COUPON_ALREADY_APPLIED_INDIV_USE_ONLY   - 104: Individual use only.
		 * E_WC_COUPON_NOT_EXIST                        - 105: Not exists.
		 * E_WC_COUPON_USAGE_LIMIT_REACHED              - 106: Usage limit reached.
		 * E_WC_COUPON_EXPIRED                          - 107: Expired.
		 * E_WC_COUPON_MIN_SPEND_LIMIT_NOT_MET          - 108: Minimum spend limit not met.
		 * E_WC_COUPON_NOT_APPLICABLE                   - 109: Not applicable.
		 * E_WC_COUPON_NOT_VALID_SALE_ITEMS             - 110: Not valid for sale items.
		 * E_WC_COUPON_PLEASE_ENTER                     - 111: Missing coupon code.
		 * E_WC_COUPON_MAX_SPEND_LIMIT_MET              - 112: Maximum spend limit met.
		 * E_WC_COUPON_EXCLUDED_PRODUCTS                - 113: Excluded products.
		 * E_WC_COUPON_EXCLUDED_CATEGORIES              - 114: Excluded categories.
		 */
		$code_mapping = array(
			\WC_Coupon::E_WC_COUPON_INVALID_FILTERED               => self::E_BOLT_CODE_INVALID,
			\WC_Coupon::E_WC_COUPON_INVALID_REMOVED                => self::E_BOLT_CODE_INVALID,
			\WC_Coupon::E_WC_COUPON_NOT_YOURS_REMOVED              => self::E_BOLT_CODE_INVALID,
			\WC_Coupon::E_WC_COUPON_ALREADY_APPLIED                => self::E_BOLT_CODE_INVALID,
			\WC_Coupon::E_WC_COUPON_ALREADY_APPLIED_INDIV_USE_ONLY => self::E_BOLT_CODE_INVALID,
			\WC_Coupon::E_WC_COUPON_NOT_EXIST                      => self::E_BOLT_CODE_INVALID,
			\WC_Coupon::E_WC_COUPON_USAGE_LIMIT_REACHED            => self::E_BOLT_CODE_LIMIT_REACHED,
			\WC_Coupon::E_WC_COUPON_EXPIRED                        => self::E_BOLT_CODE_EXPIRED,
			\WC_Coupon::E_WC_COUPON_MIN_SPEND_LIMIT_NOT_MET        => self::E_BOLT_MINIMUM_CART_AMOUNT_REQUIRED,
			\WC_Coupon::E_WC_COUPON_NOT_APPLICABLE                 => self::E_BOLT_ITEMS_NOT_ELIGIBLE,
			\WC_Coupon::E_WC_COUPON_NOT_VALID_SALE_ITEMS           => self::E_BOLT_ITEMS_NOT_ELIGIBLE,
			\WC_Coupon::E_WC_COUPON_PLEASE_ENTER                   => self::E_BOLT_CODE_INVALID,
			\WC_Coupon::E_WC_COUPON_MAX_SPEND_LIMIT_MET            => self::E_BOLT_CODE_INVALID,
			\WC_Coupon::E_WC_COUPON_EXCLUDED_PRODUCTS              => self::E_BOLT_ITEMS_NOT_ELIGIBLE,
			\WC_Coupon::E_WC_COUPON_EXCLUDED_CATEGORIES            => self::E_BOLT_ITEMS_NOT_ELIGIBLE,
			self::E_BOLT_YITH_YWGC_PREMIUM_GENERAL_ERROR           => self::E_BOLT_CODE_INVALID,
		);

		return isset( $code_mapping[ $err_code ] ) ? $code_mapping[ $err_code ] : self::E_BOLT_INSUFFICIENT_INFORMATION;
	}

	/**
	 * Check if already applied.
	 *
	 * @param WC_Coupon $coupon Coupon data.
	 *
	 * @return boolean Returns true if the coupon is already applied and returns false if not
	 * @since  1.2.7
	 * @access public
	 *
	 */
	public function validate_if_already_applied( $coupon ) {
		if ( is_a( $this->object, 'WC_Cart' ) && $this->object->has_discount( $coupon->get_code() ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if adding coupon by post ID.
	 *
	 * @param WC_Coupon $coupon Coupon data.
	 * @param string $coupon_code Coupon code to check.
	 *
	 * @return boolean
	 * @since  1.2.7
	 * @access public
	 *
	 */
	public function validate_if_add_by_post_id( $coupon, $coupon_code ) {
		if ( $coupon->get_code() !== $coupon_code ) {
			return false;
		}

		return true;
	}

	/**
	 * Check to see if an individual use coupon is set.
	 *
	 * @param WC_Coupon $coupon Coupon data.
	 * @param boolean $is_new True if trying to add a new coupon or false if not.
	 *
	 * @return boolean
	 * @since  1.2.7
	 * @access public
	 *
	 */
	public function validate_if_has_individual_use( $coupon, $is_new ) {
		if ( is_a( $this->object, 'WC_Cart' ) && $this->object->get_applied_coupons() ) {
			$has_individual_use_coupon = false;
			foreach ( $this->object->get_applied_coupons() as $code ) {
				$applied_coupon = new \WC_Coupon( $code );
				if ( $applied_coupon->get_individual_use() && false === apply_filters( 'woocommerce_apply_with_individual_use_coupon', false, $coupon, $applied_coupon, $this->object->get_applied_coupons() ) ) {
					$has_individual_use_coupon = true;
				}
			}
			if ( $has_individual_use_coupon && ( $is_new || count( $this->object->get_applied_coupons() ) > 1 ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check to see if limit to defined email addresses.
	 *
	 * @param WC_Coupon $coupon Coupon data.
	 * @param string $email Email address.
	 *
	 * @return boolean
	 * @since  1.2.7
	 * @access public
	 *
	 */
	public function validate_if_limit_to_email( $coupon, $email ) {
		// Get user and posted emails to compare.
		$current_user = wp_get_current_user();
		$check_emails = array_unique(
			array_filter(
				array_map(
					'strtolower', array_map(
						'sanitize_email', array(
							$email,
							$current_user->user_email,
						)
					)
				)
			)
		);
		$restrictions = $coupon->get_email_restrictions();

		if ( is_a( $this->object, 'WC_Cart' ) && is_array( $restrictions ) && 0 < count( $restrictions ) && ! bolt_compat()->check_wc_cart_is_coupon_emails_allowed( $this->object, $check_emails, $restrictions ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check usage limits per user.
	 *
	 * @param WC_Coupon $coupon Coupon data.
	 * @param string $email Email address.
	 *
	 * @return boolean
	 * @since  1.2.7
	 * @access public
	 *
	 */
	public function validate_usage_limit_per_user( $coupon, $email ) {
		// Get user and posted emails to compare.
		$current_user = wp_get_current_user();
		$check_emails = array_unique(
			array_filter(
				array_map(
					'strtolower', array_map(
						'sanitize_email', array(
							$email,
							$current_user->user_email,
						)
					)
				)
			)
		);

		$limit_per_user = $coupon->get_usage_limit_per_user();

		if ( 0 < $limit_per_user ) {
			$used_by         = $coupon->get_used_by();
			$usage_count     = 0;
			$user_id_matches = array( get_current_user_id() );

			// Check usage against emails.
			foreach ( $check_emails as $check_email ) {
				$usage_count       += count( array_keys( $used_by, $check_email, true ) );
				$user              = get_user_by( 'email', $check_email );
				$user_id_matches[] = $user ? $user->ID : 0;
			}

			// Check against billing emails of existing users.
			$users_query = new \WP_User_Query(
				array(
					'fields'     => 'ID',
					'meta_query' => array(
						array(
							'key'     => '_billing_email',
							'value'   => $check_emails,
							'compare' => 'IN',
						),
					),
				)
			); // WPCS: slow query ok.

			$user_id_matches = array_unique( array_filter( array_merge( $user_id_matches, $users_query->get_results() ) ) );

			foreach ( $user_id_matches as $user_id ) {
				$usage_count += count( array_keys( $used_by, (string) $user_id, true ) );
			}

			if ( $usage_count >= $limit_per_user ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Maps the Magento discount type to a Bolt discount type
	 *
	 * @param WC_Coupon $coupon Coupon data.
	 *
	 * @return string
	 * @since  1.2.7
	 * @access public
	 *
	 */
	public function convert_to_bolt_discount_type( $coupon ) {
		switch ( $coupon->get_discount_type() ) {
			case "fixed_cart":
			case "fixed_product":
				return "fixed_amount";
			case "percent":
				return "percentage";
			default:
				return '';
		}
	}

	/**
	 * Get array of third party discounts applied to cart
	 *
	 * @param array $applied_discounts Applied discounts in the cart. Default to empty array.
	 *
	 * @return array
	 * @since  1.3.2
	 * @access public
	 *
	 */
	public function get_third_party_discounts( $applied_discounts = array() ) {
		$applied_discounts_code = array();
		foreach ( $applied_discounts as $key => $applied_discounts_info ) {
			$applied_discounts_code[ $applied_discounts_info['reference'] ] = 1;
		}
		$discounts = array();
		$discounts = $this->get_negative_fee_discounts( $discounts );

		return apply_filters( 'wc_bolt_get_third_party_discounts_cart', $discounts, $applied_discounts_code );
	}

	/**
	 * Remove notice message when coupon code applied/removed successfully in Bolt discount hook.
	 *
	 * @param WC_Coupon $coupon Coupon data.
	 *
	 * @since  2.0.0
	 * @access public
	 *
	 */
	public function remove_coupon_success_notices( $coupon ) {
		$wc_notices      = WC()->session->get( 'wc_notices', array() );
		$success_notices = isset( $wc_notices[ WC_NOTICE_TYPE_SUCCESS ] ) ? $wc_notices[ WC_NOTICE_TYPE_SUCCESS ] : array();
		if ( ! empty( $success_notices ) ) {
			$wc_notices[ WC_NOTICE_TYPE_SUCCESS ] = array();
			WC()->session->set( 'wc_notices', $wc_notices );
			$applied_notice_msg = $coupon->get_coupon_message( \WC_Coupon::WC_COUPON_SUCCESS );
			$removed_notice_msg = $coupon->get_coupon_message( \WC_Coupon::WC_COUPON_REMOVED );
			foreach ( $success_notices as $notice ) {
				$message = get_wc_notice_message( $notice );
				if ( $message !== $applied_notice_msg && $message !== $removed_notice_msg ) {
					wc_add_notice( $message, WC_NOTICE_TYPE_SUCCESS );
				}
			}
		}
	}

	/**
	 * Get array of third party discounts applied to order
	 *
	 * @return array
	 * @since  2.0.0
	 * @access public
	 *
	 */
	public function get_third_party_discounts_by_order( $order ) {
		$discounts = array();
		// support for PW WooCommerce Gift Cards https://wordpress.org/plugins/pw-woocommerce-gift-cards/
		if ( defined( "PWGC_SESSION_KEY" ) ) {
			foreach ( $order->get_items( 'pw_gift_card' ) as $order_item_id => $line ) {
				$amount      = $line->get_amount();
				$code        = $line->get_card_number();
				$discounts[] = array(
					BOLT_CART_DISCOUNT_AMOUNT      => $amount,
					BOLT_CART_DISCOUNT_DESCRIPTION => 'Gift card (' . $code . ')',
					BOLT_CART_DISCOUNT_REFERENCE   => $code,
					BOLT_CART_DISCOUNT_CATEGORY    => BOLT_DISCOUNT_CATEGORY_GIFTCARD
				);
			}
		}

		return apply_filters( 'wc_bolt_get_third_party_discounts_order', $discounts, $order );
	}

	/**
	 * Get an array of discounts from negative fees in cart
	 *
	 * @param array $discounts
	 *
	 * @return array
	 * @since
	 * @access public
	 */
	public function get_negative_fee_discounts( $discounts = array() ) {
		foreach ( WC()->cart->get_fees() as $fee_item_id => $fee_item ) {
			$fee_amount = convert_monetary_value_to_bolt_format( $fee_item->amount );
			if ( $fee_amount >= 0 ) {
				continue;
			}
			$discounts[] = array(
				BOLT_CART_DISCOUNT_AMOUNT      => abs( $fee_amount ),
				BOLT_CART_DISCOUNT_DESCRIPTION => $fee_item->name,
				BOLT_CART_DISCOUNT_REFERENCE   => (string) "$fee_item->id",
				BOLT_CART_DISCOUNT_CATEGORY    => BOLT_DISCOUNT_CATEGORY_COUPON,
				BOLT_CART_DISCOUNT_ON_TOTAL    => 0
			);
		}

		return $discounts;
	}
}