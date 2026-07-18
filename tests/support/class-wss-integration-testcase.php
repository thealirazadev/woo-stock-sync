<?php
/**
 * Base test case for integration tests that need WordPress + WooCommerce.
 *
 * When the WordPress test suite is loaded it extends WP_UnitTestCase; otherwise it extends the
 * plain PHPUnit test case and skips every test so the suite stays green under stubs.
 *
 * @package WooStockSync
 */

if ( class_exists( 'WP_UnitTestCase' ) ) {
	/**
	 * Integration base (full WordPress test suite).
	 */
	abstract class WSS_Integration_TestCase extends WP_UnitTestCase {}
} else {
	/**
	 * Integration base (stub mode): every test is skipped.
	 */
	abstract class WSS_Integration_TestCase extends \PHPUnit\Framework\TestCase {
		protected function setUp(): void {
			parent::setUp();
			$this->markTestSkipped( 'Requires the WordPress test suite (run under wp-env or bin/install-wp-tests.sh).' );
		}
	}
}
