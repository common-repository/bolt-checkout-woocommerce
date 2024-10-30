<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper class around interacting with address.
 *
 * @class   Bolt_Address_Helper
 * @version 1.2.3
 * @author  Bolt
 */

/**
 * Bolt_Address_Helper.
 */
class Bolt_Address_Helper {

	/**
	 * The single instance of the class.
	 *
	 * @var Bolt_Address_Helper
	 * @since 1.2.3
	 */
	private static $_instance;

	/**
	 * Get the instance and use the functions inside it.
	 *
	 * This plugin utilises the PHP singleton design pattern.
	 *
	 * @return object self::$_instance Instance
	 *
	 * @since     1.2.3
	 * @static
	 * @access    public
	 *
	 */
	public static function get_instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Throw error on object clone.
	 *
	 * The whole idea of the singleton design pattern is that there is a single
	 * object therefore, we don't want the object to be cloned.
	 *
	 * @since  1.2.3
	 * @access public
	 *
	 */
	public function __clone() {
		// Cloning instances of the class is forbidden.
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cheatin&#8217; huh?', 'bolt-checkout-woocommerce' ), '1.0' );
	}

	/**
	 * Disable Unserialize of the class.
	 *
	 * @since  1.2.3
	 * @access public
	 */
	public function __wakeup() {
		// Unserialize instances of the class is forbidden.
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cheatin&#8217; huh?', 'bolt-checkout-woocommerce' ), '1.0' );
	}

	/**
	 * Constructor Function.
	 *
	 * @since  1.2.3
	 * @access public
	 */
	public function __construct() {
		global $woocommerce;
		self::$_instance = $this;
		$this->init();
	}

	/**
	 * Reset the instance of the class
	 *
	 * @since  1.2.3
	 * @access public
	 */
	public static function reset() {
		self::$_instance = null;
	}

	/**
	 * Init Bolt_Address_Helper class.
	 *
	 * @since  1.2.5
	 * @access public
	 */
	public function init() {
		// Hook for replacing checkout address fields.
		add_filter( 'woocommerce_default_address_fields', array( $this, 'disable_wc_region_field_validation' ), 9999 );
		add_filter( 'wc_bolt_order_creation_hint_data', array( $this, 'modify_hint_data_sent_to_bolt' ) );
	}

	/**
	 * Returns ISO 2-digit country code by taking either country code or name.
	 * Example: "US" -> "US", "United States" -> "US"
	 *
	 * @param string $country_name_or_code Either country name or code.
	 *
	 * @return string  2-digit country code string or empty string
	 * @since  1.0
	 * @access public
	 *
	 */
	public function get_country_code( $country_name_or_code ) {
		// In some enviroment WC()->countries->countries only contains 'United States (US)'
		if ( 'United States' === $country_name_or_code ) {
			return 'US';
		}
		if ( '' === $country_name_or_code ) {
			return '';
		}

		$countries = WC()->countries->countries;
		if ( isset( $countries[ $country_name_or_code ] ) ) {
			return $country_name_or_code;
		}
		$key = array_search( $country_name_or_code, $countries );
		if ( $key ) {
			return $key;
		}

		BugsnagHelper::notifyException( new \Exception(
			"Country code not found for input: [{$country_name_or_code}]" ),
			array(),
			'info' );

		// indicate that something is wrong
		return 'ZZ';
	}

	/**
	 * Returns country name by taking either country code or name.
	 * Example: "US" -> "United States", "United States" -> "United States"
	 *
	 * @param string $country_name_or_code Either country name or code.
	 *
	 * @return string  country name.
	 * @since  1.0
	 * @access public
	 *
	 */
	public function get_country_name( $country_name_or_code ) {
		if ( '' == $country_name_or_code ) {
			BugsnagHelper::notifyException( new \Exception(
				"empty input in function get_country_name:" ), array(), 'info' );

			return '';
		}
		$countries = WC()->countries->countries;
		if ( isset( $countries[ $country_name_or_code ] ) ) {
			return $countries[ $country_name_or_code ];
		}
		if ( in_array( $country_name_or_code, $countries ) ) {
			return $country_name_or_code;
		}
		BugsnagHelper::notifyException( new \Exception(
			"Country name not found for input: [{$country_name_or_code}]" ), array(), 'info' );

		return $country_name_or_code;
	}

	/**
	 * Gets the State/Region/Province code given a region name/code
	 *
	 * @param string $country_code The ISO 3166-1 alpha-2 rendition of the country
	 * @param string $region The name/code of the region
	 * @param boolean $direct_encoding If we already have proper region code, then only need to encode it for use
	 *
	 * @return string  The state code if it exist, otherwise, the region name, unchanged
	 * @since  1.2.3
	 * @access public
	 *
	 */
	public function get_region_code( $country_code, $region, $direct_encoding = false ) {
		if ( ! $direct_encoding ) {
			$region = $this->get_region_code_without_encoding( $country_code, $region );
		}

		// Since woocommerce checkout validation would encode the valid state values,
		// we have to use htmlentities to adapt that
		return htmlentities( $region, ENT_QUOTES );
	}

	/**
	 * Gets the State/Region/Province code given a region name/code without encoding
	 *
	 * @param string $country_code The ISO 3166-1 alpha-2 rendition of the country
	 * @param string $region The name/code of the region
	 *
	 * @return string  The state code if it exist, otherwise, the region name, unchanged
	 * @since  2.16.0
	 * @access public
	 *
	 */
	public function get_region_code_without_encoding( $country_code, $region ) {
		$countries = new \WC_Countries();

		$region = $this->normalize_region_name( $country_code, $region );

		if ( $states = $countries->get_states( $country_code ) ) {
			$region = array_search( strtolower( $region ), array_map( 'strtolower', $states ) ) ?: $region;
		}

		return $region;
	}

	/**
	 * Gets the State/Region/Province name given a region name/code
	 *
	 * @param string $country_code The ISO 3166-1 alpha-2 rendition of the country
	 * @param string $region The name/code of the region
	 *
	 * @return string  The State/Region/Province name if it exist, otherwise, the region name, unchanged
	 * @since  1.3.2
	 * @access public
	 *
	 */
	public function get_region_name( $country_code, $region ) {
		$countries = new \WC_Countries();

		$region = $this->normalize_region_name( $country_code, $region );

		if ( $states = $countries->get_states( $country_code ) ) {
			$region = array_key_exists( strtoupper( $region ), $states )
				? $states[ strtoupper( $region ) ]
				: $region;
		}

		// Since woocommerce would encode the state values into html entities,
		// we have to decode the region name for showing.
		return html_entity_decode( $region, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * WooCommerce treats the US territories below as separate countries and does not have
	 * an option to use them as a state. If a user selects US as the country and one of these
	 * territories as the state in the Bolt Checkout Modal, this will update the country_code
	 * to reflect that territory before sending to WooCommerce.
	 *
	 * @param string $country_code The ISO 3166-1 alpha-2 rendition of the country
	 * @param string $region The name of the region
	 *
	 * @return string  The country code
	 * @since  1.2.3
	 * @access public
	 *
	 */
	public function verify_country_code( $country_code, $region ) {
		$us_territories_map = array(
			"American Somoa"                 => "AS",
			"Federated States of Micronesia" => "FM",
			"Guam"                           => "GU",
			"Marshall Islands"               => "MH",
			"Northern Mariana Islands"       => "MP",
			"Palau"                          => "PW",
			"Puerto Rico"                    => "PR",
			"Virgin Islands"                 => "VI",
		);

		if ( 'US' == $country_code && array_key_exists( $region, $us_territories_map ) ) {
			return $us_territories_map[ $region ];
		}

		return $country_code;
	}

	/**
	 * For the coutries outside USA, now bolt modal only provide input field for region/province,
	 * and if the customer fill the region with character in other languages, WooCommerce may not recognize it
	 * so we create this function to convert it into English. hard-coding for now
	 *
	 * @param string $country_code The ISO 3166-1 alpha-2 rendition of the country
	 * @param string $region_name The name of the region
	 *
	 * @return string  Normalized name that WooCommerce accepts (eg., Quebec)
	 * @since  1.2.3
	 * @access public
	 *
	 */
	public function normalize_region_name( $country_code, $region_name ) {
		if ( ! $region_name ) {
			return '';
		}

		$region_map = array(
			// Canadian region with French
			'Colombie-Britannique'      => 'British Columbia',
			'Nouveau-Brunswick'         => 'New Brunswick',
			'Terre-Neuve-et-Labrador'   => 'Newfoundland and Labrador',
			'Territoires du Nord-Ouest' => 'Northwest Territories',
			'Nouvelle-Écosse'           => 'Nova Scotia',
			'Île-du-Prince-Édouard'     => 'Prince Edward Island',
			'Québec'                    => 'Quebec',
			// US teritorries
			'American Forces Americas'  => 'Armed Forces (AA)',
			'American Forces Europe'    => 'Armed Forces (AE)',
			'American Forces Pacific'   => 'Armed Forces (AP)',
		);

		$valid_states = WC()->countries->get_states( $country_code );

		if ( 'NZ' == $country_code && "Hawke's Bay" == $region_name ) {
			// New Zealand. Issue with encoding single quote
			if ( in_array( 'Hawke&rsquo;s Bay', $valid_states ) ) {
				return 'Hawke&rsquo;s Bay';
			} else {
				return 'Hawke’s Bay';
			}
		}

		if ( array_key_exists( $region_name, $region_map ) ) {
			return $region_map[ $region_name ];
		}

		if ( 'CN' == $country_code ) {
			// Remove Sheng from user input (Google Map auto completion adds it)
			$region_name = str_replace( 'Sheng', '', $region_name );
		}

		return ( empty( $valid_states ) || ! is_array( $valid_states ) ) ? $region_name : $this->find_matching_region( $valid_states, $region_name );
	}

	public function find_matching_region( $valid_states, $region_name ) {
		$region_name = trim( $region_name );
		// First, search for exact match
		foreach ( $valid_states as $state_code => $state_name ) {
			// $region_name could be postal code, so always check if it equals to a valid $state_code
			if ( $region_name === $state_code ) {
				return $region_name;
			}

			$decoded = trim( html_entity_decode( $state_name, ENT_QUOTES, 'UTF-8' ) );
			if ( strcasecmp( $decoded, $region_name ) === 0 ) {
				return $state_name;
			}

			foreach ( $this->get_clean_state_names( $state_name ) as $name ) {
				if ( strcasecmp( $name, $region_name ) === 0 ) {
					return $state_name;
				}
			}
		}
		// Substring match. For instance woocommerce expects "bucurești" while user can enter "municipiul bucurești"
		foreach ( $valid_states as $state_code => $state_name ) {
			foreach ( $this->get_clean_state_names( $state_name ) as $name ) {
				if ( mb_stripos( $region_name, $name ) !== false ) {
					return $state_name;
				}
			}
		}
		// Substring match. For instance woocommerce expects "Fujian / 福建" while user would enter "Fujian".
		foreach ( $valid_states as $state_code => $state_name ) {
			foreach ( $this->get_clean_state_names( $state_name ) as $name ) {
				if ( mb_stripos( $name, $region_name ) !== false ) {
					return $state_name;
				}
			}
		}

		return $region_name;
	}

	/**
	 * Get cleaned up state name from WooCommerce's state name (encoded).
	 * Note this returns array because WooCommerce can have region name like 'Araba/Álava'.
	 * In this case the method returns ['Araba', 'Álava'].
	 *
	 * @param string $state_name Encoded string that represents state
	 *
	 * @return array Cleaned up state names
	 */
	private function get_clean_state_names( $state_name ) {
		$decoded = html_entity_decode( $state_name, ENT_QUOTES, 'UTF-8' );
		// Handle case like 'Pondicherry (Puducherry)'
		if ( preg_match( '/^([A-Za-z\s]*)\(([A-Za-z]*)\)/', $decoded, $matches ) && sizeof( $matches ) === 3 ) {
			return [ trim( $matches[1] ), trim( $matches[2] ) ];
		}

		// Handle case like 'Araba/Álava'.
		return array_map( 'trim', explode( '/', $decoded ) );
	}

	/**
	 * Check if the address contains PO Box
	 *
	 * @param string $address1 Shipping address 1
	 * @param string $address2 Shipping address 2
	 *
	 * @return boolean
	 * @since  1.2.3
	 * @access public
	 *
	 */
	public function check_if_address_contain_pobox( $address1, $address2 = null ) {
		$poBoxRegex = '/(?:P(?:ost(?:al)?)?[\.\-\s]*(?:(?:O(?:ffice)?[\.\-\s]*)?B(?:ox|in|\b|\d)|o(?:ffice|\b)(?:[-\s]*\d)|code)|box[-\s\b]*\d)/i';

		if ( preg_match( $poBoxRegex, $address1 ) || ( ! empty( $address2 ) && preg_match( $poBoxRegex, $address2 ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if an address field is required
	 *
	 * @param string $field Address field to be checked
	 * @param mixed $country Country.
	 * @param string $type Address type, 'billing_' or 'shipping_'.
	 *
	 * @return boolean
	 * @since  1.2.4
	 * @access public
	 *
	 */
	public function check_if_address_field_required( $field, $country, $type ) {
		$address_fields = WC()->countries->get_address_fields( $country, $type );
		if ( isset( $address_fields[ $field ] ) && $address_fields[ $field ]['required'] ) {
			return true;
		}

		return false;
	}

	/**
	 * Validates the shipping/billing address data.
	 *
	 * @param array $address_data An array of shipping data.
	 * @param string $type Address type, 'billing_' or 'shipping_', defaults to 'billing_'.
	 * @param boolean $is_apple_pay If true then we don't need to do postcode validation
	 *
	 * @return string  Validation error message, empty if validation passes
	 * @since 1.2.3
	 * @access public
	 *
	 */
	public function validate_address( $address_data, $type = WC_BILLING_PREFIX, $is_apple_pay = false ) {
		if ( empty( $address_data ) || ! isset( $address_data[ $type . 'country' ] ) ) {
			return '';
		}

		$country = $address_data[ $type . 'country' ];
		// The validation on address should be per country
		$address_fields = WC()->countries->get_address_fields( $country, $type );

		foreach ( $address_fields as $key => $field ) {
			if ( ! isset( $address_data[ $key ] ) ) {
				continue;
			}
			$format      = array_filter( isset( $field['validate'] ) ? (array) $field['validate'] : [] );
			$required    = ! empty( $field['required'] );
			$field_label = isset( $field['label'] ) ? $field['label'] : $key;

			// validate postcode
			if ( in_array( 'postcode', $format, true ) ) {
				// In countries where postcode determines address accurately enough (like in Canada)
				// apple pay doesn't report full postcode on shipping&tax step.
				// But we know that full postcode is provided for US
				if ( ! $is_apple_pay || ( 'US' == $country ) ) {
					$post_code = wc_format_postcode( $address_data[ $key ], $country );

					if ( ! \WC_Validation::is_postcode( $post_code, $country ) ) {
						return sprintf( __( '%s is not a valid postcode / ZIP.', 'woocommerce' ), wp_kses_post( $post_code ) );
					}
				} else {
					add_filter( 'woocommerce_validate_postcode', array(
						$this,
						'bypass_postcode_validation_for_apple_pay'
					), 100, 3 );
				}
			}

			// validate email
			if ( in_array( 'email', $format, true ) ) {
				if ( ! is_email( $address_data[ $key ] ) ) {
					/* translators: %s: email address */
					return sprintf( __( '%s is not a valid email address.', 'woocommerce' ), wp_kses_post( $address_data[ $key ] ) );
				}
			}

			// validate phone
			if ( in_array( 'phone', $format, true ) ) {
				$phone = wc_format_phone_number( $address_data[ $key ] );

				if ( '' !== $phone && ! \WC_Validation::is_phone( $phone ) ) {
					return sprintf( __( '%s is not a valid phone number.', 'woocommerce' ), wp_kses_post( $phone ) );
				}
			}

			// validate state
			if ( '' !== $address_data[ $key ] && in_array( 'state', $format, true ) ) {
				$valid_states = WC()->countries->get_states( $country );

				if ( ! empty( $valid_states ) && is_array( $valid_states ) ) {
					// Convert to state name => state code pairs, and make them uppercase for comparion
					$valid_state_values = array_map( '\BoltCheckout\Bolt_Compatibility_Helper::make_string_uppercase', array_flip( array_map( '\BoltCheckout\Bolt_Compatibility_Helper::make_string_uppercase', $valid_states ) ) );

					$region = Bolt_Compatibility_Helper::make_string_uppercase( $address_data[ $key ] );

					// $region could be state name or state code,
					// and here we try to convert the name to code if exist for comparion later
					if ( isset( $valid_state_values[ $region ] ) ) {
						$region = $valid_state_values[ $region ];
					}

					if ( ! in_array( $region, $valid_state_values, true ) ) {
						// revert $region to readable
						$region = htmlspecialchars_decode( wc_strtolower( $region ), ENT_QUOTES );
						// Since the state names of some countries are encoded in woocommerce, we need to decode them for error message
						$valid_states_str = html_entity_decode( implode( ', ', array_map( 'wc_strtolower', $valid_states ) ), ENT_QUOTES, 'UTF-8' );

						return sprintf( __( '"%1$s" is not a valid region name. Please enter one of the following: %2$s', 'woocommerce' ), wp_kses_post( $region ), $valid_states_str );
					}
				}
			}

			if ( $required && ( '' === $address_data[ $key ] ) ) {
				return sprintf( __( '%s is a required field.', 'woocommerce' ), wp_kses_post( $field_label ) );
			}
		}

		return '';
	}

	/**
	 * Get the shipping/billing address data from all data
	 *
	 * @param array $address_data An array of shipping data.
	 * @param string $type Address type, 'billing_' or 'shipping_', defaults to 'billing_'.
	 *
	 * @return array
	 * @since 2.0.0
	 * @access public
	 *
	 */
	public function get_address( $address_data, $type = WC_BILLING_PREFIX, $is_apple_pay = false ) {
		$result         = array();
		$country        = $address_data[ $type . 'country' ];
		$address_fields = WC()->countries->get_address_fields( $country, $type );

		foreach ( $address_fields as $key => $field ) {
			if ( isset( $address_data[ $key ] ) ) {
				$result [ $key ] = $address_data[ $key ];
			}
		}

		return $result;
	}

	/**
	 * Disable WooCommerce checkout validation on billing/shipping fields based on bolt settings
	 *
	 * @param array $fields Checkout address fields
	 *
	 * @return array
	 * @since  1.2.5
	 * @access public
	 *
	 */
	public function disable_wc_region_field_validation( $fields ) {

		if ( wc_bolt()->get_bolt_settings()->is_setting_enabled( Bolt_Settings::SETTING_NAME_DISABLE_WC_STATE_VALIDATION ) && isset( $fields['state'] ) ) {
			$fields['state']['required'] = false;
			$fields['state']['validate'] = array();
		}

		return $fields;
	}

	/**
	 * Validates if any required shipping/billing address field has empty value.
	 *
	 * @param array $address_data An array of shipping/billing data.
	 * @param string $type Address type, 'billing' or 'shipping', defaults to 'billing'.
	 *
	 * @return boolean  True if all the required fields have non-empty value and false if any required field has empty value.
	 * @since 1.2.8
	 * @access public
	 *
	 */
	public function validate_address_field_requirement( $address_data, $type = 'billing' ) {
		$checkout_fields = WC()->checkout()->get_checkout_fields();
		if ( ! array_key_exists( $type, $checkout_fields ) ) {
			return true; // If this type does not exist in checkout_fields, we just let it go
		}
		$address_fields = $checkout_fields[ $type ];

		foreach ( $address_data as $key => $value ) {
			if ( ! isset( $address_fields[ $key ] ) ) {
				continue;
			}
			$required = ! empty( $address_fields[ $key ]['required'] );
			if ( $required && $value === '' ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Modifies the customer address hint data prior to sending it to bolt
	 *
	 * @param array $hint_data An array of the customer address hint data.
	 *
	 * @return array
	 * @since 1.3.0
	 * @access public
	 *
	 */
	public function modify_hint_data_sent_to_bolt( $hint_data ) {

		if ( empty( @$hint_data[ BOLT_HINT_PREFILL ] ) ) {
			return $hint_data;
		}

		switch ( $hint_data[ BOLT_HINT_PREFILL ][ BOLT_HINT_COUNTRY ] ) {
			case 'United States':
				$hint_data[ BOLT_HINT_PREFILL ][ BOLT_HINT_COUNTRY ] = 'US';
				break;
			case 'Canada':
				$hint_data[ BOLT_HINT_PREFILL ][ BOLT_HINT_COUNTRY ] = 'CA';
		}

		if ( $hint_data[ BOLT_HINT_PREFILL ][ BOLT_HINT_COUNTRY ] == 'US' && ( strlen( $hint_data[ BOLT_HINT_PREFILL ][ BOLT_HINT_REGION ] ) == 2 ) ) {
			$hint_data[ BOLT_HINT_PREFILL ][ BOLT_HINT_REGION ] = WC()->countries->get_states( 'US' )[ strtoupper( $hint_data[ BOLT_HINT_PREFILL ][ BOLT_HINT_REGION ] ) ] ?: $hint_data['prefill'][ BOLT_HINT_REGION ];
		}

		return $hint_data;
	}

	/**
	 * Prepare billing/shipping address data for bolt cart session on checkout
	 *
	 * @return array|boolean False if fail to prepare data
	 * @since 1.3.2
	 * @access public
	 *
	 */
	public function prepare_checkout_address_data() {
		try {
			// The filter `wc_bolt_set_checkout_address_data` is for extensions which may add extra address fields
			$checkout_address_data = apply_filters(
				'wc_bolt_set_checkout_address_data', array(
					WC_BILLING_ADDRESS_1   => '',
					WC_BILLING_ADDRESS_2   => '',
					WC_BILLING_FIRST_NAME  => '',
					WC_BILLING_LAST_NAME   => '',
					WC_BILLING_CITY        => '',
					WC_BILLING_STATE       => '',
					WC_BILLING_POSTCODE    => '',
					WC_BILLING_COUNTRY     => '',
					WC_BILLING_PHONE       => '',
					WC_BILLING_EMAIL       => '',
					WC_SHIPPING_ADDRESS_1  => '',
					WC_SHIPPING_ADDRESS_2  => '',
					WC_SHIPPING_FIRST_NAME => '',
					WC_SHIPPING_LAST_NAME  => '',
					WC_SHIPPING_CITY       => '',
					WC_SHIPPING_STATE      => '',
					WC_SHIPPING_POSTCODE   => '',
					WC_SHIPPING_COUNTRY    => '',
				)
			);
			$addr_submission_data  = array();

			if ( isset( $_POST['post_data'] ) && ! empty( $_POST['post_data'] ) ) {
				// in ajax or post request
				$post_data     = explode( '&', $_POST['post_data'] );
				$checkout_data = array();
				foreach ( $post_data as $k => $value ) {
					$v = explode( '=', urldecode( $value ) );
					if ( isset( $checkout_address_data[ $v[0] ] ) ) {
						$checkout_address_data[ $v[0] ] = $v[1];
					}
					$checkout_data[ $v[0] ] = $v[1];
				}
				if ( ! isset( $checkout_data[ WC_SHIP_TO_DIFFERENT_ADDRESS ] ) && ! wc_ship_to_billing_address_only() ) {
					$temp_checkout_address_data = array();
					foreach ( $checkout_address_data as $addr_key => $addr_val ) {
						$temp_addr_val = $addr_val;
						if ( strpos( $addr_key, WC_SHIPPING_PREFIX ) === 0 ) {
							$sub_addr_key = substr( $addr_key, strlen( WC_SHIPPING_PREFIX ) );
							if ( isset( $checkout_data[ WC_BILLING_PREFIX . $sub_addr_key ] ) ) {
								$temp_addr_val = $checkout_data[ WC_BILLING_PREFIX . $sub_addr_key ];
							}
						}
						$temp_checkout_address_data[ $addr_key ] = $temp_addr_val;
					}
					$checkout_address_data = $temp_checkout_address_data;
				}
			} else {
				// When loading checkout page
				$billing_fields = WC()->checkout()->get_checkout_fields( 'billing' );
				foreach ( $billing_fields as $key => $field ) {
					if ( isset( $checkout_address_data[ $key ] ) ) {
						$checkout_address_data[ $key ] = WC()->checkout()->get_value( $key ) ?: '';
					}
				}
				$shipping_fields = WC()->checkout()->get_checkout_fields( 'shipping' );
				foreach ( $shipping_fields as $key => $field ) {
					if ( isset( $checkout_address_data[ $key ] ) ) {
						$checkout_address_data[ $key ] = WC()->checkout()->get_value( $key );
					}
				}
			}

			// Skip order creation if email is not valid (ie., user is still entering email, but due to onkeyup event in js, the ajax post incomplete email address )
			$billing_email = $checkout_address_data[ WC_BILLING_EMAIL ];
			if ( ! is_email( $billing_email ) ) {
				return false;
			}
			$shipping_email = $billing_email;

			$billing_first_name = $checkout_address_data[ WC_BILLING_FIRST_NAME ];
			$billing_last_name  = $checkout_address_data[ WC_BILLING_LAST_NAME ];
			$billing_address_1  = $checkout_address_data[ WC_BILLING_ADDRESS_1 ];
			$billing_address_2  = $checkout_address_data[ WC_BILLING_ADDRESS_2 ];
			$billing_city       = $checkout_address_data[ WC_BILLING_CITY ];
			$billing_postcode   = $checkout_address_data[ WC_BILLING_POSTCODE ];
			$billing_country    = $this->get_country_code( $checkout_address_data[ WC_BILLING_COUNTRY ] );
			$billing_state      = $checkout_address_data[ WC_BILLING_STATE ];
			$billing_phone      = $checkout_address_data[ WC_BILLING_PHONE ];

			$shipping_first_name   = $checkout_address_data[ WC_SHIPPING_FIRST_NAME ];
			$shipping_last_name    = $checkout_address_data[ WC_SHIPPING_LAST_NAME ];
			$shipping_address_1    = $checkout_address_data[ WC_SHIPPING_ADDRESS_1 ];
			$shipping_address_2    = $checkout_address_data[ WC_SHIPPING_ADDRESS_2 ];
			$shipping_city         = $checkout_address_data[ WC_SHIPPING_CITY ];
			$shipping_state        = $checkout_address_data[ WC_SHIPPING_STATE ];
			$shipping_postcode     = $checkout_address_data[ WC_SHIPPING_POSTCODE ];
			$shipping_country      = $this->get_country_code( $checkout_address_data[ WC_SHIPPING_COUNTRY ] );
			$shipping_country_name = $this->get_country_name( WC()->countries->countries[ $shipping_country ] );
			$shipping_phone        = $billing_phone;

			///////////////////////////////////////////////
			// assure all required billing fields are present.
			// if not, we do not add it to the cart submission data
			///////////////////////////////////////////////
			$billing_address_data = array();
			foreach ( $checkout_address_data as $addr_key => $addr_val ) {
				if ( strpos( $addr_key, WC_BILLING_PREFIX ) === 0 ) {
					$billing_address_data[ $addr_key ] = $addr_val;
				}
			}
			if ( $this->validate_address_field_requirement( $billing_address_data ) ) {
				$addr_submission_data[ BOLT_CART_BILLING_ADDRESS ] = array(
					BOLT_STREET_ADDRESS1 => $billing_address_1,
					BOLT_STREET_ADDRESS2 => $billing_address_2,
					BOLT_STREET_ADDRESS3 => '',
					BOLT_STREET_ADDRESS4 => '',
					BOLT_FIRST_NAME      => $billing_first_name,
					BOLT_LAST_NAME       => $billing_last_name,
					BOLT_LOCALITY        => $billing_city,
					BOLT_REGION          => $billing_state,
					BOLT_POSTAL_CODE     => $billing_postcode,
					BOLT_COUNTRY_CODE    => $billing_country,
					BOLT_PHONE           => $billing_phone,
					BOLT_EMAIL           => $billing_email,
				);
			} else {
				// Skip order creation if any required billing fields is empty
				return false;
			}

			///////////////////////////////////////////////
			// assure all required shipping fields are present.
			// if not, we do not add it to the cart submission data
			///////////////////////////////////////////////
			$shipping_address_data = array();
			foreach ( $checkout_address_data as $addr_key => $addr_val ) {
				if ( strpos( $addr_key, WC_SHIPPING_PREFIX ) === 0 ) {
					$shipping_address_data[ $addr_key ] = $addr_val;
				}
			}
			if ( $this->validate_address_field_requirement( $shipping_address_data, 'shipping' ) ) {
				$addr_submission_data[ BOLT_CART_SHIPPING_ADDRESS ] = array(
					BOLT_STREET_ADDRESS1 => $shipping_address_1,
					BOLT_STREET_ADDRESS2 => $shipping_address_2,
					BOLT_FIRST_NAME      => $shipping_first_name,
					BOLT_LAST_NAME       => $shipping_last_name,
					BOLT_LOCALITY        => $shipping_city,
					BOLT_REGION          => $shipping_state,
					BOLT_POSTAL_CODE     => $shipping_postcode,
					BOLT_COUNTRY_CODE    => $shipping_country,
					BOLT_COUNTRY         => $shipping_country_name,
					BOLT_PHONE           => $shipping_phone,
					BOLT_EMAIL           => $shipping_email,
				);
			} else {
				// Skip order creation if any required shipping fields is empty
				return false;
			}

			return $addr_submission_data;
		} catch ( \Exception $e ) {
			BugsnagHelper::notifyException( $e, $_POST, 'info' );

			return false;
		}
	}

	/**
	 * The post code from Apple Pay is incomplete, so we need to bypass the validation.
	 *
	 * @param string $postcode Postcode to validate.
	 * @param string $country Country to validate the postcode for.
	 * @param bool $valid whether or not the post code is valid
	 *
	 * @return bool
	 * @since 2.3.0
	 * @access public
	 *
	 */
	public function bypass_postcode_validation_for_apple_pay( $valid, $postcode, $country ) {
		return true;
	}

	// Methods for backward compatibility with extensions
	// TODO: remove them after update extensions

	public function bolt_verify_country( $country_code, $region ) {
		return $this->verify_country_code( $country_code, $region );
	}

	/**
	 * Generates shjpping address structured data.
	 *
	 * @param WC_Customer|WC_Order $instance Customer instance or Order instance.
	 *
	 * @return array
	 * @since 2.4.0
	 * @access public
	 *
	 */
	public function generate_shipping_address_data( $instance ) {
		if ( is_a( $instance, 'WC_Order' ) || is_a( $instance, 'WC_Customer' ) ) {
			$data = array(
				BOLT_HINT_FIRST_NAME      => $instance->get_shipping_first_name() ?: $instance->get_billing_first_name(),
				BOLT_HINT_LAST_NAME       => $instance->get_shipping_last_name() ?: $instance->get_billing_last_name(),
				BOLT_HINT_EMAIL           => $instance->get_billing_email(),
				BOLT_HINT_PHONE           => $instance->get_billing_phone(),
				BOLT_HINT_STREET_ADDRESS1 => $instance->get_shipping_address_1() ?: $instance->get_billing_address_1(),
				BOLT_HINT_STREET_ADDRESS2 => $instance->get_shipping_address_2() ?: $instance->get_billing_address_2(),
				BOLT_HINT_LOCALITY        => $instance->get_shipping_city() ?: $instance->get_billing_city(),
				BOLT_HINT_COUNTRY         => $country_code = bolt_addr_helper()->get_country_code( ( $instance->get_shipping_country() ?: $instance->get_billing_country() ) ),
				BOLT_HINT_REGION          => bolt_addr_helper()->get_region_name( $country_code, ( $instance->get_shipping_state() ?: $instance->get_billing_state() ) ),
				BOLT_HINT_POSTAL_CODE     => $instance->get_shipping_postcode() ?: $instance->get_billing_postcode(),
			);
		} else {
			$data = array();
		}

		return $data;
	}
}

/**
 * Returns the instance of Bolt_Address_Helper to use globally.
 *
 * @return Bolt_Address_Helper
 * @since  1.2.3
 *
 */
function bolt_addr_helper() {
	return Bolt_Address_Helper::get_instance();
}
