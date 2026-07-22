<?php
/**
 * Rollback integration tests.
 *
 * @package WooStockSync
 */

/**
 * Integration tests for rollback from snapshots (requires WordPress + WooCommerce).
 *
 * @covers WSS_Runner
 */
class Test_Rollback extends WSS_Integration_TestCase {

	/**
	 * Runner.
	 *
	 * @var WSS_Runner
	 */
	private $runner;

	/**
	 * Temp files.
	 *
	 * @var array
	 */
	private $tmp = array();

	public function set_up() {
		parent::set_up();
		WSS_Install::install();
		$this->runner = new WSS_Runner();
	}

	public function tear_down() {
		foreach ( $this->tmp as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		$this->tmp = array();
		parent::tear_down();
	}

	private function make_product( $sku, $stock, $regular ) {
		$product = new WC_Product_Simple();
		$product->set_sku( $sku );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );
		$product->set_regular_price( $regular );
		return $product->save();
	}

	private function apply_feed( $csv, $mapping ) {
		$path        = tempnam( sys_get_temp_dir(), 'wssroll' ) . '.csv';
		$this->tmp[] = $path;
		file_put_contents( $path, $csv );

		$run_id = $this->runner->create_run(
			array(
				'source_type' => 'upload',
				'source_ref'  => $path,
				'mapping'     => $mapping,
			)
		);
		update_option( 'wss_settings', array_merge( wss_settings_defaults(), array( 'mapping' => $mapping ) ) );

		$this->runner->handle_fetch( $run_id );
		$guard = 0;
		while ( 'diffing' === wss_get_run( $run_id )->status && $guard < 1000 ) {
			$this->runner->handle_diff_batch( $run_id );
			++$guard;
		}

		$guard = 0;
		while ( in_array( wss_get_run( $run_id )->status, array( 'previewed', 'applying' ), true ) && $guard < 5000 ) {
			$this->runner->handle_apply_batch( $run_id );
			++$guard;
		}

		return $run_id;
	}

	private function roll_back( $run_id ) {
		$guard = 0;
		while ( in_array( wss_get_run( $run_id )->status, array( 'applied', 'rolling_back' ), true ) && $guard < 5000 ) {
			$this->runner->handle_rollback_batch( $run_id );
			++$guard;
		}
	}

	private function rows_by_sku( $run_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wss_rows WHERE run_id = %d", $run_id ) );
		$map  = array();
		foreach ( $rows as $row ) {
			$map[ $row->sku ] = $row;
		}
		return $map;
	}

	private function mapping() {
		return array(
			'sku'           => 'sku',
			'stock'         => 'qty',
			'regular_price' => 'price',
			'sale_price'    => '',
		);
	}

	public function test_rollback_restores_prior_values() {
		$id     = $this->make_product( 'R-1', 5, '10.00' );
		$run_id = $this->apply_feed( "sku,qty,price\nR-1,20,15.00\n", $this->mapping() );

		$this->assertSame( 20.0, (float) wc_get_product( $id )->get_stock_quantity() );

		$this->roll_back( $run_id );

		$this->assertSame( 'rolled_back', wss_get_run( $run_id )->status );
		$restored = wc_get_product( $id );
		$this->assertSame( 5.0, (float) $restored->get_stock_quantity() );
		$this->assertSame( '10.00', $restored->get_regular_price() );

		$rows = $this->rows_by_sku( $run_id );
		$this->assertSame( 'rolled_back', $rows['R-1']->status );
	}

	public function test_rollback_restores_a_managed_but_unset_stock_quantity() {
		// A product that manages stock but has no quantity set (managed-but-null). The prior state is
		// null, and rollback must return it to null, not leave the applied value and not coerce to 0.
		$product = new WC_Product_Simple();
		$product->set_sku( 'R-NULL' );
		$product->set_manage_stock( true );
		$product->set_regular_price( '10.00' );
		$id = $product->save();

		$this->assertNull( wc_get_product( $id )->get_stock_quantity(), 'precondition: stock starts unset' );

		$run_id = $this->apply_feed( "sku,qty,price\nR-NULL,50,15.00\n", $this->mapping() );

		$this->assertSame( 50.0, (float) wc_get_product( $id )->get_stock_quantity(), 'apply set the quantity' );

		$this->roll_back( $run_id );

		$this->assertSame( 'rolled_back', wss_get_run( $run_id )->status );
		$restored = wc_get_product( $id );
		$this->assertNull( $restored->get_stock_quantity(), 'stock is restored to the managed-but-unset (null) state' );
		$this->assertSame( '10.00', $restored->get_regular_price() );

		$rows = $this->rows_by_sku( $run_id );
		$this->assertSame( 'rolled_back', $rows['R-NULL']->status );
	}

	public function test_deleted_product_is_recorded_and_others_restore() {
		$id_a   = $this->make_product( 'R-A', 5, '10.00' );
		$id_b   = $this->make_product( 'R-B', 5, '10.00' );
		$run_id = $this->apply_feed( "sku,qty,price\nR-A,20,15.00\nR-B,20,15.00\n", $this->mapping() );

		wp_delete_post( $id_b, true );

		$this->roll_back( $run_id );

		$rows = $this->rows_by_sku( $run_id );
		$this->assertSame( 'rolled_back', $rows['R-A']->status );
		$this->assertSame( 'rollback_failed', $rows['R-B']->status );
		$this->assertSame( 'product_missing', $rows['R-B']->reason );
		$this->assertSame( 5.0, (float) wc_get_product( $id_a )->get_stock_quantity() );
	}
}
