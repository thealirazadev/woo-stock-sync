<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite, WooCommerce, and this plugin.
 *
 * @package WooStockSync
 */

$wss_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $wss_tests_dir ) {
	$wss_tmp       = rtrim( sys_get_temp_dir(), '/\\' );
	$wss_tests_dir = $wss_tmp . '/wordpress-tests-lib';
}

if ( ! file_exists( $wss_tests_dir . '/includes/functions.php' ) ) {
	echo 'Could not find the WordPress test suite in ' . esc_html( $wss_tests_dir ) . PHP_EOL;
	echo 'Run bin/install-wp-tests.sh first, or set WP_TESTS_DIR.' . PHP_EOL;
	exit( 1 );
}

// Give access to tests_add_filter().
require_once $wss_tests_dir . '/includes/functions.php';

/**
 * Load WooCommerce and this plugin before the test suite boots WordPress.
 *
 * @return void
 */
function wss_manually_load_plugins() {
	$plugin_dir = dirname( __DIR__, 2 );

	$woocommerce = $plugin_dir . '/woocommerce/woocommerce.php';
	if ( file_exists( $woocommerce ) ) {
		require $woocommerce;
	}

	require dirname( __DIR__ ) . '/woo-stock-sync.php';
}
tests_add_filter( 'muplugins_loaded', 'wss_manually_load_plugins' );

/**
 * Install WooCommerce tables in the test database.
 *
 * @return void
 */
function wss_install_woocommerce() {
	if ( class_exists( 'WC_Install' ) ) {
		WC_Install::install();
	}
}
tests_add_filter( 'setup_theme', 'wss_install_woocommerce' );

require $wss_tests_dir . '/includes/bootstrap.php';
