<?php
/**
 * Minimal WordPress/WooCommerce function and class stubs.
 *
 * Loaded only when the full WordPress test suite is unavailable, so the pure-logic unit tests
 * (feed parsing, validation, mapping) can run standalone. Integration tests self-skip in this mode.
 *
 * @package WooStockSync
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}
if ( ! defined( 'WSS_ROW_CAP' ) ) {
	define( 'WSS_ROW_CAP', 50000 );
}
if ( ! defined( 'WSS_JSON_MAX_BYTES' ) ) {
	define( 'WSS_JSON_MAX_BYTES', 20 * 1024 * 1024 );
}
if ( ! defined( 'WSS_DEFAULT_BATCH_SIZE' ) ) {
	define( 'WSS_DEFAULT_BATCH_SIZE', 25 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Very small WP_Error stand-in.
	 */
	class WP_Error {
		/** @var string */
		public $error_code;
		/** @var string */
		public $error_message;

		/**
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 */
		public function __construct( $code = '', $message = '' ) {
			$this->error_code    = $code;
			$this->error_message = $message;
		}

		/** @return string */
		public function get_error_code() {
			return $this->error_code;
		}

		/** @return string */
		public function get_error_message() {
			return $this->error_message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		unset( $domain );
		return $text;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		unset( $domain );
		return $text;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		unset( $tag );
		return $value;
	}
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number ) {
		return number_format( (float) $number );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return wp_json_encode_stub( $data );
	}
}
if ( ! function_exists( 'wp_json_encode_stub' ) ) {
	function wp_json_encode_stub( $data ) {
		return json_encode( $data );
	}
}
if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $file ) {
		if ( is_file( $file ) ) {
			unlink( $file );
		}
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $string ) {
		return rtrim( $string, '/\\' ) . '/';
	}
}
