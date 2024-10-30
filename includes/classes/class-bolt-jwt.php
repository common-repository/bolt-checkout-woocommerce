<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Bolt JWT
 *
 * @class   Bolt_JWT
 * @version 1.3.5
 * @author  Bolt
 */
class Bolt_JWT {

	/**
	 * The single instance of the class.
	 *
	 * @var Bolt_JWT
	 * @since 2.15.0
	 */
	private static $instance;

	/**
	 * Get the instance and use the functions inside it.
	 *
	 * This plugin utilises the PHP singleton design pattern.
	 *
	 * @return object self::$instance Instance
	 *
	 * @since     2.15.0
	 * @static
	 * @access    public
	 *
	 */
	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Throw error on object clone.
	 *
	 * The whole idea of the singleton design pattern is that there is a single
	 * object therefore, we don't want the object to be cloned.
	 *
	 * @since  2.15.0
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
	 * @since  2.15.0
	 * @access public
	 */
	public function __wakeup() {
		// Unserialize instances of the class is forbidden.
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cheatin&#8217; huh?', 'bolt-checkout-woocommerce' ), '1.0' );
	}

	/**
	 * Constructor Function.
	 *
	 * @since  2.15.0
	 * @access public
	 */
	public function __construct() {
		self::$instance = $this;
	}

	/**
	 * Reset the instance of the class
	 *
	 * @since  2.15.0
	 * @access public
	 */
	public static function reset() {
		self::$instance = null;
	}



	const ASN1_INTEGER = 0x02;
	const ASN1_SEQUENCE = 0x10;
	const ASN1_BIT_STRING = 0x03;

	/**
	 * When checking nbf, iat or expiration times,
	 * we want to provide some extra leeway time to
	 * account for clock skew.
	 */
	public static $leeway = 1000; // 1 second

	/**
	 * Allow the current timestamp to be specified.
	 * Useful for fixing a value within unit testing.
	 *
	 * Will default to PHP time() value if null.
	 */
	public static $timestamp = null;

	public static $supported_algs = [
		'ES256' => [ 'openssl', 'SHA256' ],
		'HS256' => [ 'hash_hmac', 'SHA256' ],
		'HS384' => [ 'hash_hmac', 'SHA384' ],
		'HS512' => [ 'hash_hmac', 'SHA512' ],
		'RS256' => [ 'openssl', 'SHA256' ],
		'RS384' => [ 'openssl', 'SHA384' ],
		'RS512' => [ 'openssl', 'SHA512' ],
	];

	/**
	 * Decodes a JWT string into a PHP object.
	 *
	 * @param string $jwt The JWT
	 * @param string|array|resource $key The key, or map of keys.
	 *                                            If the algorithm used is asymmetric, this is the public key
	 * @param array $allowed_algs List of supported verification algorithms
	 *                                            Supported algorithms are 'ES256', 'HS256', 'HS384', 'HS512', 'RS256', 'RS384', and 'RS512'
	 *
	 * @return object The JWT's payload as a PHP object
	 *
	 *
	 * @throws \Exception
	 *
	 */
	public function decode( $jwt, $key, array $allowed_algs = [] ) {
		$timestamp = is_null( static::$timestamp ) ? time() : static::$timestamp;

		if ( empty( $key ) ) {
			throw new \Exception( 'Key may not be empty' );
		}
		$tks = explode( '.', $jwt );
		if ( count( $tks ) != 3 ) {
			throw new \Exception( 'Wrong number of segments' );
		}
		list( $headb64, $bodyb64, $cryptob64 ) = $tks;
		if ( null === ( $header = static::json_decode( static::urlsafe_b64_decode( $headb64 ) ) ) ) {
			throw new \Exception( 'Invalid header encoding' );
		}
		if ( null === $payload = static::json_decode( static::urlsafe_b64_decode( $bodyb64 ) ) ) {
			throw new \Exception( 'Invalid claims encoding' );
		}
		if ( false === ( $sig = static::urlsafe_b64_decode( $cryptob64 ) ) ) {
			throw new \Exception( 'Invalid signature encoding' );
		}
		if ( empty( $header->alg ) ) {
			throw new \Exception( 'Empty algorithm' );
		}
		if ( empty( static::$supported_algs[ $header->alg ] ) ) {
			throw new \Exception( 'Algorithm not supported' );
		}
		if ( ! in_array( $header->alg, $allowed_algs ) ) {
			throw new \Exception( 'Algorithm not allowed' );
		}
		if ( $header->alg === 'ES256' ) {
			// OpenSSL expects an ASN.1 DER sequence for ES256 signatures
			$sig = $this -> signature_to_DER( $sig );
		}

		if ( is_array( $key ) || $key instanceof \ArrayAccess ) {
			if ( isset( $header->kid ) ) {
				if ( ! isset( $key[ $header->kid ] ) ) {
					throw new \Exception( '"kid" invalid, unable to lookup correct key' );
				}
				$key = $key[ $header->kid ];
			} else {
				throw new \Exception( '"kid" empty, unable to lookup correct key' );
			}
		}

		// Check the signature
		if ( ! static::verify( "$headb64.$bodyb64", $sig, $key, $header->alg ) ) {
			throw new \Exception( 'Signature verification failed' );
		}

		// Check the nbf if it is defined. This is the time that the
		// token can actually be used. If it's not yet that time, abort.
		if ( isset( $payload->nbf ) && $payload->nbf > ( $timestamp + static::$leeway ) ) {
			throw new \Exception(
				'Cannot handle token prior to ' . date( DateTime::ISO8601, $payload->nbf )
			);
		}

		// Check that this token has been created before 'now'. This prevents
		// using tokens that have been created for later use (and haven't
		// correctly used the nbf claim).
		if ( isset( $payload->iat ) && $payload->iat > ( $timestamp + static::$leeway ) ) {
			throw new \Exception(
				'Cannot handle token prior to ' . date( DateTime::ISO8601, $payload->iat )
			);
		}

		// Check if this token has expired.
		if ( isset( $payload->exp ) && ( $timestamp - static::$leeway ) >= $payload->exp ) {
			throw new \Exception( 'Expired token' );
		}

		return $payload;
	}

	/**
	 * Decode a JSON string into a PHP object.
	 *
	 * @param string $input JSON string
	 *
	 * @return object Object representation of JSON string
	 *
	 * @throws \Exception
	 */
	public static function json_decode( $input ) {
		if ( version_compare( PHP_VERSION, '5.4.0', '>=' ) && ! ( defined( 'JSON_C_VERSION' ) && PHP_INT_SIZE > 4 ) ) {
			/** In PHP >=5.4.0, json_decode() accepts an options parameter, that allows you
			 * to specify that large ints (like Steam Transaction IDs) should be treated as
			 * strings, rather than the PHP default behaviour of converting them to floats.
			 */
			$obj = json_decode( $input, false, 512, JSON_BIGINT_AS_STRING );
		} else {
			/** Not all servers will support that, however, so for older versions we must
			 * manually detect large ints in the JSON string and quote them (thus converting
			 *them to strings) before decoding, hence the preg_replace() call.
			 */
			$max_int_length       = strlen( (string) PHP_INT_MAX ) - 1;
			$json_without_bigints = preg_replace( '/:\s*(-?\d{' . $max_int_length . ',})/', ': "$1"', $input );
			$obj                  = json_decode( $json_without_bigints );
		}

		if ( $errno = json_last_error() ) {
			static::handle_json_error( $errno );
		} elseif ( $obj === null && $input !== 'null' ) {
			throw new \Exception( 'Null result with non-null input' );
		}

		return $obj;
	}

	/**
	 * Decode a string with URL-safe Base64.
	 *
	 * @param string $input A Base64 encoded string
	 *
	 * @return string A decoded string
	 */
	public static function urlsafe_b64_decode( string $input ): string {
		$remainder = strlen( $input ) % 4;
		if ( $remainder ) {
			$padlen = 4 - $remainder;
			$input  .= str_repeat( '=', $padlen );
		}

		return base64_decode( strtr( $input, '-_', '+/' ) );
	}

	/**
	 * Verify a signature with the message, key and method. Not all methods
	 * are symmetric, so we must have a separate verify and sign method.
	 *
	 * @param string          $msg       The original message (header and body)
	 * @param string          $signature The original signature
	 * @param string|resource $key       For HS*, a string key works. for RS*, must be a resource of an openssl public key
	 * @param string          $alg       The algorithm
	 *
	 * @return bool
	 *
	 * @throws \Exception
	 */
	private static function verify( $msg, $signature, $key, $alg ) {
		if ( empty( static::$supported_algs[ $alg ] ) ) {
			throw new \Exception( 'Algorithm not supported' );
		}

		list( $function, $algorithm ) = static::$supported_algs[ $alg ];
		switch ( $function ) {
			case 'openssl':
				$success = openssl_verify( $msg, $signature, $key, $algorithm );
				if ( $success === 1 ) {
					return true;
				} elseif ( $success === 0 ) {
					return false;
				}
				// returns 1 on success, 0 on failure, -1 on error.
				throw new \Exception(
					'OpenSSL error: ' . openssl_error_string()
				);
			case 'hash_hmac':
			default:
				$hash = hash_hmac( $algorithm, $msg, $key, true );
				if ( function_exists( 'hash_equals' ) ) {
					return hash_equals( $signature, $hash );
				}
				$len = min( static::safe_strlen( $signature ), static::safe_strlen( $hash ) );

				$status = 0;
				for ( $i = 0; $i < $len; $i ++ ) {
					$status |= ( ord( $signature[ $i ] ) ^ ord( $hash[ $i ] ) );
				}
				$status |= ( static::safe_strlen( $signature ) ^ static::safe_strlen( $hash ) );

				return $status === 0;
		}
	}

	/**
	 * Helper method to create a JSON error.
	 *
	 * @param int $errno An error number from json_last_error()
	 *
	 * @return void
	 * @throws \Exception
	 */
	private static function handle_json_error( $errno ) {
		$messages = [
			JSON_ERROR_DEPTH          => 'Maximum stack depth exceeded',
			JSON_ERROR_STATE_MISMATCH => 'Invalid or malformed JSON',
			JSON_ERROR_CTRL_CHAR      => 'Unexpected control character found',
			JSON_ERROR_SYNTAX         => 'Syntax error, malformed JSON',
			JSON_ERROR_UTF8           => 'Malformed UTF-8 characters' //PHP >= 5.3.3
		];
		throw new \Exception(
			$messages[ $errno ] ?? 'Unknown JSON error: ' . $errno
		);
	}

	/**
	 * Get the number of bytes in cryptographic strings.
	 *
	 * @param string $str
	 *
	 * @return int
	 */
	private static function safe_strlen( $str ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $str, '8bit' );
		}

		return strlen( $str );
	}

	/**
	 * Convert an ECDSA signature to an ASN.1 DER sequence
	 *
	 * @param string $sig The ECDSA signature to convert
	 *
	 * @return string The encoded DER object
	 */
	private function signature_to_DER( $sig ) {
		// Separate the signature into r-value and s-value
		list( $r, $s ) = str_split( $sig, (int) ( strlen( $sig ) / 2 ) );

		// Trim leading zeros
		$r = ltrim( $r, "\x00" );
		$s = ltrim( $s, "\x00" );

		// Convert r-value and s-value from unsigned big-endian integers to
		// signed two's complement
		if ( ord( $r[0] ) > 0x7f ) {
			$r = "\x00" . $r;
		}
		if ( ord( $s[0] ) > 0x7f ) {
			$s = "\x00" . $s;
		}

		return $this->encode_DER(
			$this->ASN1_SEQUENCE,
			$this->encode_DER( $this->ASN1_INTEGER, $r ) .
			$this->encode_DER( $this->ASN1_INTEGER, $s )
		);
	}

	/**
	 * Encodes a value into a DER object.
	 *
	 * @param int $type DER tag
	 * @param string $value the value to encode
	 *
	 * @return string the encoded object
	 */
	private function encode_DER( $type, $value ) {
		$tag_header = 0;
		if ( $type === $this->ASN1_SEQUENCE ) {
			$tag_header |= 0x20;
		}

		// Type
		$der = chr( $tag_header | $type );

		// Length
		$der .= chr( strlen( $value ) );

		return $der . $value;
	}

	/**
	 * Parse a JWK key
	 *
	 * @param array $jwk An individual JWK
	 *
	 * @return resource|array An associative array that represents the key
	 *
	 * @throws \Exception
	 *
	 */
	public function parse_key( $jwk ) {
		if ( empty( $jwk ) ) {
			throw new \Exception( 'JWK must not be empty' );
		}
		if ( ! isset( $jwk->kty ) ) {
			throw new \Exception( 'JWK must contain a "kty" parameter' );
		}

		switch ( $jwk->kty ) {
			case 'RSA':
				if ( property_exists( $jwk, 'd' ) ) {
					throw new \Exception( 'RSA private keys are not supported' );
				}
				if ( ! isset( $jwk->n ) || ! isset( $jwk->e ) ) {
					throw new \Exception( 'RSA keys must contain values for both "n" and "e"' );
				}

				return static::create_pem_from_modulus_and_exponent( $jwk->n, $jwk->e );
			default:
				// Currently, only RSA is supported
				break;
		}
	}

	/**
	 * Create a public key represented in PEM format from RSA modulus and exponent information
	 *
	 * @param string $n The RSA modulus encoded in Base64
	 * @param string $e The RSA exponent encoded in Base64
	 *
	 * @return string The RSA public key represented in PEM format
	 *
	 */
	private static function create_pem_from_modulus_and_exponent( $n, $e ) {
		$modulus        = static::urlsafe_b64_decode( $n );
		$public_exponent = static::urlsafe_b64_decode( $e );

		$components = [
			'modulus'        => pack( 'Ca*a*', 2, static::encode_length( strlen( $modulus ) ), $modulus ),
			'$public_exponent' => pack( 'Ca*a*', 2, static::encode_length( strlen( $public_exponent ) ), $public_exponent )
		];

		$rsa_public_key = pack(
			'Ca*a*a*',
			48,
			static::encode_length( strlen( $components['modulus'] ) + strlen( $components['$public_exponent'] ) ),
			$components['modulus'],
			$components['$public_exponent']
		);

		// sequence(oid(1.2.840.113549.1.1.1), null)) = rsaEncryption.
		$rsaOID       = pack( 'H*', '300d06092a864886f70d0101010500' ); // hex version of MA0GCSqGSIb3DQEBAQUA
		$rsa_public_key = chr( 0 ) . $rsa_public_key;
		$rsa_public_key = chr( 3 ) . static::encode_length( strlen( $rsa_public_key ) ) . $rsa_public_key;

		$rsa_public_key = pack(
			'Ca*a*',
			48,
			static::encode_length( strlen( $rsaOID . $rsa_public_key ) ),
			$rsaOID . $rsa_public_key
		);

		return base64_encode( $rsa_public_key );
	}

	/**
	 * DER-encode the length
	 *
	 * DER supports lengths up to (2**8)**127, however, we'll only support lengths up to (2**8)**4.  See
	 * {@link http://itu.int/ITU-T/studygroups/com17/languages/X.690-0207.pdf#p=13 X.690 paragraph 8.1.3} for more information.
	 *
	 * @param int $length
	 *
	 * @return string
	 */
	private static function encode_length( $length ) {
		if ( $length <= 0x7F ) {
			return chr( $length );
		}

		$temp = ltrim( pack( 'N', $length ), chr( 0 ) );

		return pack( 'Ca*', 0x80 | strlen( $temp ), $temp );
	}


}

/**
 * Returns the instance of Bolt_JWT to use globally.
 *
 * @return Bolt_JWT
 * @since  2.15.0
 *
 */
function bolt_jwt() {
	return Bolt_JWT::get_instance();
}
