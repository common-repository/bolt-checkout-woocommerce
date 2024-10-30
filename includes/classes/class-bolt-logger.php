<?php

namespace BoltCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface BoltLoggerInterface {
	public static function error( $message );

	public static function warning( $message );

	public static function info( $message );

	public static function debug( $message );
}

class BoltLogger implements BoltLoggerInterface {

	const BOLT_LOG_FILE = 'woocommerce-bolt-payment';

	private static $is_enabled = false; // TODO read config
	private static $logger;

	public static function error( $message ) {
		self::log( 'error', $message );
	}

	public static function warning( $message ) {
		self::log( 'warning', $message );
	}

	public static function info( $message ) {
		self::log( 'info', $message );
	}

	public static function debug( $message ) {
		self::log( 'debug', $message );
	}

	private static function log( $level, $message ) {
		if ( ! self::$is_enabled ) {
			return;
		}
		if ( empty( self::$logger ) ) {
			if ( version_compare( \WC_VERSION, '3.0.0', '>=' ) ) {
				self::$logger = wc_get_logger();
			} else {
				self::$logger = new \WC_Logger();
			}
		}

		if ( version_compare( \WC_VERSION, '3.0.0', '>=' ) ) {
			self::$logger->log( $level, $message, array( 'source' => self::BOLT_LOG_FILE ) );
		} else {
			self::$logger->add( self::BOLT_LOG_FILE, $message );
		}
	}
}