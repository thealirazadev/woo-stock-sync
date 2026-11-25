<?php
/**
 * Status color families for run and row badges (pure logic; no WordPress required).
 *
 * @package WooStockSync
 */

/**
 * Unit tests for wss_status_family().
 *
 * @covers ::wss_status_family
 */
class Test_Status extends \PHPUnit\Framework\TestCase {

	public function test_terminal_success_statuses_map_to_success() {
		$this->assertSame( 'success', wss_status_family( 'applied' ) );
		$this->assertSame( 'success', wss_status_family( 'rolled_back' ) );
		$this->assertSame( 'success', wss_status_family( 'planned' ) );
	}

	public function test_in_flight_statuses_map_to_attention() {
		$this->assertSame( 'attention', wss_status_family( 'applying' ) );
		$this->assertSame( 'attention', wss_status_family( 'rolling_back' ) );
		$this->assertSame( 'attention', wss_status_family( 'stalled' ) );
	}

	public function test_failure_statuses_map_to_error() {
		$this->assertSame( 'error', wss_status_family( 'failed' ) );
		$this->assertSame( 'error', wss_status_family( 'apply_failed' ) );
		$this->assertSame( 'error', wss_status_family( 'rollback_failed' ) );
	}

	public function test_unknown_status_falls_back_to_neutral() {
		$this->assertSame( 'neutral', wss_status_family( 'not-a-status' ) );
		$this->assertSame( 'neutral', wss_status_family( '' ) );
	}
}
