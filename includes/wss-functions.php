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

/**
 * Default plugin settings.
 *
 * @return array
 */
function wss_settings_defaults() {
	return array(
		'source_type'       => 'upload',
		'feed_url'          => '',
		'auth_header_name'  => '',
		'auth_header_value' => '',
		'upload_path'       => '',
		'upload_name'       => '',
		'schedule'          => 'manual',
		'scheduled_mode'    => 'preview_only',
		'mapping'           => array(
			'sku'           => '',
			'stock'         => '',
			'regular_price' => '',
			'sale_price'    => '',
		),
		'blank_clears_sale' => false,
	);
}

/**
 * Read the stored settings merged over the defaults.
 *
 * @return array
 */
function wss_get_settings() {
	$defaults = wss_settings_defaults();
	$stored   = get_option( 'wss_settings', array() );

	if ( ! is_array( $stored ) ) {
		$stored = array();
	}

	$settings            = wp_parse_args( $stored, $defaults );
	$stored_mapping      = ( isset( $stored['mapping'] ) && is_array( $stored['mapping'] ) ) ? $stored['mapping'] : array();
	$settings['mapping'] = wp_parse_args( $stored_mapping, $defaults['mapping'] );

	return $settings;
}

/**
 * Absolute path to the protected uploads subdirectory for feed files.
 *
 * @return string
 */
function wss_uploads_dir() {
	$uploads = wp_upload_dir();

	return trailingslashit( $uploads['basedir'] ) . 'wss-feeds';
}

/**
 * Fetch a single run row by ID.
 *
 * @param int $run_id Run ID.
 * @return object|null Run row object, or null when not found.
 */
function wss_get_run( $run_id ) {
	global $wpdb;

	$run_id = absint( $run_id );
	if ( ! $run_id ) {
		return null;
	}

	$run = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wss_runs WHERE id = %d", $run_id ) );

	return $run ? $run : null;
}

/**
 * Format a run's source for display without leaking server file paths.
 *
 * @param object $run Run row.
 * @return string Human-safe source description.
 */
function wss_format_source( $run ) {
	if ( 'url' === $run->source_type ) {
		return (string) $run->source_ref;
	}

	return '' !== $run->source_ref ? basename( $run->source_ref ) : '';
}
