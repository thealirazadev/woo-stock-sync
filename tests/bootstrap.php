<?php
/**
 * PHPUnit bootstrap.
 *
 * If the WordPress test suite is available it boots WordPress + WooCommerce + this plugin for
 * integration tests. Otherwise it loads lightweight stubs so the pure-logic unit tests can run
 * standalone (integration tests self-skip via WSS_Integration_TestCase).
 *
 * @package WooStockSync
 */

$wss_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $wss_tests_dir ) {
	$wss_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

$wss_has_wp_suite = file_exists( $wss_tests_dir . '/includes/functions.php' );

if ( $wss_has_wp_suite ) {
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
} else {
	fwrite( STDERR, "WordPress test suite not found; running pure-logic tests with stubs.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writing to STDERR in the test harness.

	require __DIR__ . '/support/stubs.php';
	require dirname( __DIR__ ) . '/includes/class-wss-feed.php';
}

require __DIR__ . '/support/class-wss-integration-testcase.php';
