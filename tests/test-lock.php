<?php
/**
 * Single-run mutex unit tests.
 *
 * @package WooStockSync
 */

/**
 * Unit tests for the single-run mutex (pure option logic; no WordPress required).
 *
 * @covers WSS_Runner
 */
class Test_Lock extends \PHPUnit\Framework\TestCase {

	/**
	 * Runner instance.
	 *
	 * @var WSS_Runner
	 */
	private $runner;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wss_stub_options'] = array();
		$this->runner                = new WSS_Runner();

		// Under the WordPress test suite the lock is a real option and this case is not wrapped in
		// the WP_UnitTestCase transaction, so clearing the stub array alone leaves it set.
		$this->runner->force_release_lock();
	}

	protected function tearDown(): void {
		$this->runner->force_release_lock();
		parent::tearDown();
	}

	public function test_acquire_is_exclusive_and_reentrant() {
		$this->assertTrue( $this->runner->acquire_lock( 1 ) );
		$this->assertSame( 1, $this->runner->get_lock_holder() );

		// A different run cannot acquire while it is held.
		$this->assertFalse( $this->runner->acquire_lock( 2 ) );
		$this->assertSame( 1, $this->runner->get_lock_holder() );

		// The holder may reacquire (resume).
		$this->assertTrue( $this->runner->acquire_lock( 1 ) );
	}

	public function test_release_only_by_holder() {
		$this->runner->acquire_lock( 5 );

		$this->runner->release_lock( 6 );
		$this->assertSame( 5, $this->runner->get_lock_holder(), 'A non-holder cannot release the lock.' );

		$this->runner->release_lock( 5 );
		$this->assertSame( 0, $this->runner->get_lock_holder() );
	}

	public function test_force_release_clears_any_holder() {
		$this->runner->acquire_lock( 9 );
		$this->runner->force_release_lock();

		$this->assertSame( 0, $this->runner->get_lock_holder() );
		$this->assertTrue( $this->runner->acquire_lock( 10 ), 'The lock is free after a force release.' );
	}
}
