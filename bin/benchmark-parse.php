<?php
/**
 * Benchmark the CSV parser + validator throughput, standalone (no WordPress).
 *
 * Dev-only tooling (excluded from the shipped zip and from phpcs). Loads the same lightweight stubs
 * the pure-logic unit tests use, then streams a feed through WSS_Feed::parse with a no-op sink so
 * the timing reflects parsing + per-row mapping/validation only (no database, no product I/O).
 *
 * Usage:
 *   php bin/generate-feed.php 250000 /tmp/wss-bench-feed.csv
 *   php bin/benchmark-parse.php /tmp/wss-bench-feed.csv [iterations]
 *
 * @package WooStockSync
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

require __DIR__ . '/../tests/support/stubs.php';
require __DIR__ . '/../includes/class-wss-feed.php';

$path       = isset( $argv[1] ) ? $argv[1] : ( sys_get_temp_dir() . '/wss-bench-feed.csv' );
$iterations = isset( $argv[2] ) ? max( 1, (int) $argv[2] ) : 5;

if ( ! is_readable( $path ) ) {
	fwrite( STDERR, "feed not found: {$path} (run bin/generate-feed.php first)\n" );
	exit( 1 );
}

// Lift the 50k row cap so the whole synthetic feed is measured.
add_filter(
	'wss_row_cap',
	static function () {
		return PHP_INT_MAX;
	}
);

$settings = array(
	'source_type'       => 'upload',
	'feed_url'          => '',
	'auth_header_name'  => '',
	'auth_header_value' => '',
	'upload_path'       => $path,
	'blank_clears_sale' => false,
);
$mapping  = array(
	'sku'           => 'sku',
	'stock'         => 'qty',
	'regular_price' => 'price',
	'sale_price'    => 'sale_price',
);

$feed  = new WSS_Feed();
$bytes = filesize( $path );

$times = array();
$rows  = 0;

for ( $i = 0; $i < $iterations; $i++ ) {
	$source = $feed->open_run_source( 'upload', $path, $settings );
	if ( ! is_array( $source ) ) {
		fwrite( STDERR, "could not open source\n" );
		exit( 1 );
	}

	$count = 0;
	$sink  = static function ( $row ) use ( &$count ) {
		unset( $row );
		++$count;
	};

	$start   = hrtime( true );
	$result  = $feed->parse( $source, $mapping, $settings, $sink );
	$elapsed = ( hrtime( true ) - $start ) / 1e9;

	if ( is_wp_error( $result ) ) {
		fwrite( STDERR, 'parse failed: ' . $result->get_error_message() . "\n" );
		exit( 1 );
	}

	$rows    = (int) $result;
	$times[] = $elapsed;

	fwrite(
		STDOUT,
		sprintf( "run %d: %.3fs  %s rows/s\n", $i + 1, $elapsed, number_format( $rows / $elapsed ) )
	);
}

sort( $times );
$mid    = (int) floor( count( $times ) / 2 );
$median = ( 0 === count( $times ) % 2 ) ? ( $times[ $mid - 1 ] + $times[ $mid ] ) / 2 : $times[ $mid ];

fwrite( STDOUT, str_repeat( '-', 48 ) . "\n" );
fwrite(
	STDOUT,
	sprintf( "rows=%s  file=%.1f MB  median=%.3fs\n", number_format( $rows ), $bytes / 1048576, $median )
);
fwrite(
	STDOUT,
	sprintf(
		"median throughput=%s rows/s  %.1f MB/s  peak_mem=%.1f MB\n",
		number_format( $rows / $median ),
		( $bytes / 1048576 ) / $median,
		memory_get_peak_usage( true ) / 1048576
	)
);
