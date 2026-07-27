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
 * Apply a configured markup to a supplier price.
 *
 * Pure and side-effect free so it can be unit tested in isolation. The result is rounded to the
 * store's price decimals and never negative: a markup that would drive the price below zero is
 * clamped to 0. A markup of zero, a type other than percentage/fixed, or an empty/non-numeric price
 * returns the value untouched, preserving the v1 pass-through behavior exactly.
 *
 * @param string|float $price    Raw supplier price.
 * @param string       $type     Markup type: 'none', 'percentage', or 'fixed'.
 * @param string|float $value    Markup amount (percent for 'percentage', currency for 'fixed'; may
 *                               be negative for a markdown).
 * @param int          $decimals Decimal places to round to. Defaults to 2.
 * @return string|float The adjusted price as a decimal string, or the original value when passed through.
 */
function wss_adjust_price( $price, $type, $value, $decimals = 2 ) {
	// Empty or non-numeric prices pass through untouched (for example a blank/cleared sale price).
	if ( '' === $price || null === $price || ! is_numeric( $price ) ) {
		return $price;
	}

	$value = (float) $value;

	// No markup configured, or a zero amount: keep the raw value so pass-through is byte-identical.
	if ( ( 'percentage' !== $type && 'fixed' !== $type ) || 0.0 === $value ) {
		return $price;
	}

	$amount = (float) $price;
	if ( 'percentage' === $type ) {
		$amount += $amount * ( $value / 100 );
	} else {
		$amount += $value;
	}

	// Never write a negative price.
	if ( $amount < 0 ) {
		$amount = 0.0;
	}

	return number_format( $amount, max( 0, (int) $decimals ), '.', '' );
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

/**
 * The visual family (color group) for a run or row status.
 *
 * @param string $status Status value.
 * @return string One of: success, neutral, attention, error.
 */
function wss_status_family( $status ) {
	$map = array(
		'planned'         => 'success',
		'applied'         => 'success',
		'rolled_back'     => 'success',
		'no_change'       => 'neutral',
		'skipped'         => 'neutral',
		'cancelled'       => 'neutral',
		'pending'         => 'neutral',
		'staged'          => 'neutral',
		'locked'          => 'attention',
		'stalled'         => 'attention',
		'previewed'       => 'attention',
		'fetching'        => 'attention',
		'diffing'         => 'attention',
		'applying'        => 'attention',
		'rolling_back'    => 'attention',
		'failed'          => 'error',
		'apply_failed'    => 'error',
		'rollback_failed' => 'error',
	);

	return isset( $map[ $status ] ) ? $map[ $status ] : 'neutral';
}

/**
 * A human-readable label for a run or row status.
 *
 * @param string $status Status value.
 * @return string
 */
function wss_status_label( $status ) {
	$labels = array(
		'pending'         => __( 'Pending', 'woo-stock-sync' ),
		'fetching'        => __( 'Fetching', 'woo-stock-sync' ),
		'diffing'         => __( 'Diffing', 'woo-stock-sync' ),
		'previewed'       => __( 'Previewed', 'woo-stock-sync' ),
		'applying'        => __( 'Applying', 'woo-stock-sync' ),
		'applied'         => __( 'Applied', 'woo-stock-sync' ),
		'rolling_back'    => __( 'Rolling back', 'woo-stock-sync' ),
		'rolled_back'     => __( 'Rolled back', 'woo-stock-sync' ),
		'cancelled'       => __( 'Cancelled', 'woo-stock-sync' ),
		'failed'          => __( 'Failed', 'woo-stock-sync' ),
		'staged'          => __( 'Staged', 'woo-stock-sync' ),
		'planned'         => __( 'Planned', 'woo-stock-sync' ),
		'no_change'       => __( 'No change', 'woo-stock-sync' ),
		'skipped'         => __( 'Skipped', 'woo-stock-sync' ),
		'apply_failed'    => __( 'Apply failed', 'woo-stock-sync' ),
		'rollback_failed' => __( 'Rollback failed', 'woo-stock-sync' ),
		'locked'          => __( 'Locked', 'woo-stock-sync' ),
		'stalled'         => __( 'Stalled', 'woo-stock-sync' ),
	);

	return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( str_replace( '_', ' ', $status ) );
}

/**
 * A status badge (escaped, self-contained HTML) for admin tables.
 *
 * @param string $status Status value.
 * @return string
 */
function wss_status_badge( $status ) {
	return sprintf(
		'<span class="wss-badge wss-badge-%s">%s</span>',
		esc_attr( wss_status_family( $status ) ),
		esc_html( wss_status_label( $status ) )
	);
}
