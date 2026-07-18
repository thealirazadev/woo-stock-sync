<?php
/**
 * Minimal WP_CLI stubs so the CLI command callbacks can be invoked directly in tests.
 *
 * @package WooStockSync
 */

namespace {
	if ( ! class_exists( 'WP_CLI' ) ) {
		/**
		 * Minimal WP_CLI stand-in for tests.
		 */
		class WP_CLI {
			/** @var array */
			public static $success = array();
			/** @var array */
			public static $log = array();

			public static function success( $message ) {
				self::$success[] = $message;
			}

			public static function log( $message ) {
				self::$log[] = $message;
			}

			public static function error( $message ) {
				throw new \RuntimeException( 'cli_error: ' . $message );
			}

			public static function confirm( $question, $assoc_args = array() ) {
				unset( $question, $assoc_args );
			}

			public static function add_command( $name, $callable ) {
				unset( $name, $callable );
			}
		}
	}
}

namespace WP_CLI\Utils {
	if ( ! function_exists( 'WP_CLI\\Utils\\format_items' ) ) {
		function format_items( $format, $items, $columns ) {
			$GLOBALS['wss_cli_format'] = array( $format, $items, $columns );
		}
	}
}
