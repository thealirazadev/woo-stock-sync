<?php
/**
 * Schedule registration, scheduled-fetch skip, and cancel integration tests.
 *
 * @package WooStockSync
 */

/**
 * Integration tests for scheduling and cancellation (requires WordPress + WooCommerce/Action Scheduler).
 *
 * @covers WSS_Runner
 */
class Test_Schedule extends WSS_Integration_TestCase {

	/**
	 * Runner.
	 *
	 * @var WSS_Runner
	 */
	private $runner;

	public function set_up() {
		parent::set_up();
		WSS_Install::install();
		$this->runner = new WSS_Runner();
	}

	public function tear_down() {
		as_unschedule_all_actions( 'wss_scheduled_fetch', array(), 'woo-stock-sync' );
		$this->runner->force_release_lock();
		parent::tear_down();
	}

	public function test_sync_schedule_registers_updates_and_clears() {
		$this->runner->sync_schedule( 'hourly' );
		$this->assertTrue( as_has_scheduled_action( 'wss_scheduled_fetch', array(), 'woo-stock-sync' ) );

		// Changing the interval leaves exactly one recurring action, not two.
		$this->runner->sync_schedule( 'daily' );
		$pending = as_get_scheduled_actions(
			array(
				'hook'   => 'wss_scheduled_fetch',
				'status' => 'pending',
				'group'  => 'woo-stock-sync',
			),
			'ids'
		);
		$this->assertCount( 1, $pending );

		// Manual removes the recurring action.
		$this->runner->sync_schedule( 'manual' );
		$this->assertFalse( as_has_scheduled_action( 'wss_scheduled_fetch', array(), 'woo-stock-sync' ) );
	}

	public function test_scheduled_fetch_skips_when_locked() {
		$this->runner->acquire_lock( 999 );
		$this->runner->handle_scheduled_fetch();

		global $wpdb;
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wss_runs WHERE trigger_type = 'schedule'" );
		$this->assertSame( 0, $count, 'No scheduled run is created while the lock is held.' );
	}

	public function test_cancel_previewed_run() {
		$run_id = $this->runner->create_run(
			array(
				'source_type' => 'upload',
				'source_ref'  => 'feed.csv',
				'mapping'     => array(),
			)
		);
		$this->runner->set_status( $run_id, 'previewed' );

		$this->assertTrue( $this->runner->cancel_run( $run_id ) );
		$this->assertSame( 'cancelled', wss_get_run( $run_id )->status );

		// A second cancel is a no-op.
		$this->assertFalse( $this->runner->cancel_run( $run_id ) );
	}
}
