<?php
/**
 * Small shared helper functions.
 *
 * @package WooStockSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Log a structured message through the WooCommerce logger.
 *
 * All plugin logging goes through this helper so every entry lands under the
 * `woo-stock-sync` source (WooCommerce > Status > Logs) with a consistent shape.
 * Never pass the feed auth header value in the context; it must stay out of logs.
 *
 * @param string $message Human-readable message.
 * @param array  $context Optional context; JSON-encoded and appended to the message.
 * @param string $level   Severity: emergency|alert|critical|error|warning|notice|info|debug.
 * @return void
 */
function wss_log( $message, $context = array(), $level = 'error' ) {
	if ( ! function_exists( 'wc_get_logger' ) ) {
		return;
	}

	$logger = wc_get_logger();
	if ( ! $logger ) {
		return;
	}

	if ( ! empty( $context ) ) {
		$message .= ' ' . wp_json_encode( $context );
	}

	$logger->log( $level, $message, array( 'source' => 'woo-stock-sync' ) );
}
