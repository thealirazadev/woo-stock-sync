<?php
/**
 * Generate a synthetic supplier CSV feed for benchmarking the parser/validator.
 *
 * Dev-only tooling (excluded from the shipped zip and from phpcs). Writes a header plus N rows of
 * the shape the plugin expects: sku, qty, price, sale_price. SKUs are unique; ~70% of rows leave
 * the sale price blank ("no change"); the rest carry a sale strictly below the regular price, so
 * the whole file passes validation and exercises the happy path.
 *
 * Usage: php bin/generate-feed.php <rows> <output.csv>
 *
 * @package WooStockSync
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$rows = isset( $argv[1] ) ? (int) $argv[1] : 100000;
$out  = isset( $argv[2] ) ? $argv[2] : ( sys_get_temp_dir() . '/wss-bench-feed.csv' );

if ( $rows < 1 ) {
	fwrite( STDERR, "rows must be >= 1\n" );
	exit( 1 );
}

$handle = fopen( $out, 'wb' );
if ( ! $handle ) {
	fwrite( STDERR, "could not open {$out} for writing\n" );
	exit( 1 );
}

mt_srand( 42 ); // Deterministic output for reproducible runs.

fwrite( $handle, "sku,qty,price,sale_price\n" );

for ( $i = 1; $i <= $rows; $i++ ) {
	$qty   = mt_rand( 0, 500 );
	$price = mt_rand( 500, 10000 ) / 100; // 5.00 - 100.00
	$sale  = '';
	if ( 0 === $i % 3 ) {
		$sale = number_format( $price * 0.8, 2, '.', '' ); // strictly below regular
	}

	fwrite(
		$handle,
		sprintf( "SKU-%d,%d,%s,%s\n", $i, $qty, number_format( $price, 2, '.', '' ), $sale )
	);
}

fclose( $handle );

$bytes = filesize( $out );
fwrite(
	STDOUT,
	sprintf( "Wrote %s rows (%s bytes) to %s\n", number_format( $rows ), number_format( $bytes ), $out )
);
