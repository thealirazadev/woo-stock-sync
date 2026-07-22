<?php
/**
 * Batched apply integration tests.
 *
 * @package WooStockSync
 */

/**
 * Integration tests for batched apply: isolation, snapshots, resume (requires WordPress + WooCommerce).
 *
 * @covers WSS_Runner
 */
class Test_Apply extends WSS_Integration_TestCase {

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
		remove_all_filters( 'wss_batch_size' );
		remove_all_actions( 'woocommerce_before_product_object_save' );
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

	private function stage_and_diff( $csv, $mapping ) {
		$path        = tempnam( sys_get_temp_dir(), 'wssapply' ) . '.csv';
		$this->tmp[] = $path;
		file_put_contents( $path, $csv );

		$run_id = $this->runner->create_run(
			array(
				'source_type' => 'upload',
				'source_ref'  => $path,
				'mapping'     => $mapping,
			)
		);

		update_option(
			'wss_settings',
			array_merge( wss_settings_defaults(), array( 'mapping' => $mapping ) )
		);

		$this->runner->handle_fetch( $run_id );

		$guard = 0;
		while ( 'diffing' === wss_get_run( $run_id )->status && $guard < 1000 ) {
			$this->runner->handle_diff_batch( $run_id );
			++$guard;
		}

		return $run_id;
	}

	private function apply_run( $run_id ) {
		$guard = 0;
		while ( in_array( wss_get_run( $run_id )->status, array( 'previewed', 'applying' ), true ) && $guard < 5000 ) {
			$this->runner->handle_apply_batch( $run_id );
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

	public function test_valid_rows_apply_and_failures_are_isolated() {
		$this->make_product( 'A-OK', 5, '10.00' );
		$this->make_product( 'A-THROW', 5, '10.00' );

		add_action(
			'woocommerce_before_product_object_save',
			static function ( $product ) {
				if ( 'A-THROW' === $product->get_sku() ) {
					throw new Exception( 'forced failure' );
				}
			}
		);

		$mapping = array(
			'sku'           => 'sku',
			'stock'         => 'qty',
			'regular_price' => 'price',
			'sale_price'    => '',
		);
		$csv     = "sku,qty,price\nA-OK,20,15.00\nA-THROW,20,15.00\nA-UNKNOWN,3,4.00\n";

		$run_id = $this->stage_and_diff( $csv, $mapping );
		$this->apply_run( $run_id );

		$run  = wss_get_run( $run_id );
		$rows = $this->rows_by_sku( $run_id );

		$this->assertSame( 'applied', $run->status );
		$this->assertSame( 'applied', $rows['A-OK']->status );
		$this->assertSame( 'apply_failed', $rows['A-THROW']->status );
		$this->assertSame( 'wc_error', $rows['A-THROW']->reason );
		$this->assertSame( 'skipped', $rows['A-UNKNOWN']->status );

		$ok = wc_get_product( wc_get_product_id_by_sku( 'A-OK' ) );
		$this->assertSame( 20.0, (float) $ok->get_stock_quantity() );
		$this->assertSame( '15.00', $ok->get_regular_price() );

		// The failed product is untouched.
		$this->assertSame( 5.0, (float) wc_get_product( wc_get_product_id_by_sku( 'A-THROW' ) )->get_stock_quantity() );
	}

	public function test_a_product_deleted_before_apply_is_isolated() {
		$keep_id = $this->make_product( 'G-KEEP', 5, '10.00' );
		$gone_id = $this->make_product( 'G-GONE', 5, '10.00' );

		$mapping = array(
			'sku'           => 'sku',
			'stock'         => 'qty',
			'regular_price' => 'price',
			'sale_price'    => '',
		);
		$csv     = "sku,qty,price\nG-KEEP,20,15.00\nG-GONE,20,15.00\n";

		$run_id = $this->stage_and_diff( $csv, $mapping );

		// The product resolved at preview vanishes before apply (deleted in wp-admin).
		wp_delete_post( $gone_id, true );

		$this->apply_run( $run_id );

		$run  = wss_get_run( $run_id );
		$rows = $this->rows_by_sku( $run_id );

		$this->assertSame( 'applied', $run->status, 'the batch completes despite the missing product' );
		$this->assertSame( 'applied', $rows['G-KEEP']->status );
		$this->assertSame( 'apply_failed', $rows['G-GONE']->status );
		$this->assertSame( 'wc_error', $rows['G-GONE']->reason );
		$this->assertSame( 20.0, (float) wc_get_product( $keep_id )->get_stock_quantity() );
	}

	public function test_a_product_locked_after_preview_is_skipped_at_apply() {
		$open_id   = $this->make_product( 'G-OPEN', 5, '10.00' );
		$locked_id = $this->make_product( 'G-LOCK', 5, '10.00' );

		$mapping = array(
			'sku'           => 'sku',
			'stock'         => 'qty',
			'regular_price' => 'price',
			'sale_price'    => '',
		);
		$csv     = "sku,qty,price\nG-OPEN,20,15.00\nG-LOCK,20,15.00\n";

		$run_id = $this->stage_and_diff( $csv, $mapping );

		// Locked between preview and apply: the apply-time re-check must skip it, untouched.
		update_post_meta( $locked_id, '_wss_locked', 'yes' );

		$this->apply_run( $run_id );

		$rows = $this->rows_by_sku( $run_id );
		$this->assertSame( 'applied', $rows['G-OPEN']->status );
		$this->assertSame( 'skipped', $rows['G-LOCK']->status );
		$this->assertSame( 'locked', $rows['G-LOCK']->reason );
		$this->assertSame( 5.0, (float) wc_get_product( $locked_id )->get_stock_quantity(), 'locked product untouched' );
		$this->assertSame( 20.0, (float) wc_get_product( $open_id )->get_stock_quantity() );
	}

	public function test_snapshot_written_once_and_resume_is_idempotent() {
		$this->make_product( 'A-1', 5, '10.00' );
		$this->make_product( 'A-2', 5, '10.00' );

		add_filter(
			'wss_batch_size',
			static function () {
				return 1;
			}
		);

		$mapping = array(
			'sku'           => 'sku',
			'stock'         => 'qty',
			'regular_price' => 'price',
			'sale_price'    => '',
		);
		$csv     = "sku,qty,price\nA-1,20,15.00\nA-2,30,25.00\n";

		$run_id = $this->stage_and_diff( $csv, $mapping );

		// One batch of size 1 leaves the run applying with rows remaining.
		$this->runner->handle_apply_batch( $run_id );
		$this->assertSame( 'applying', wss_get_run( $run_id )->status );

		// Resume to completion.
		$this->apply_run( $run_id );
		$this->assertSame( 'applied', wss_get_run( $run_id )->status );

		global $wpdb;
		$snapshots = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wss_snapshots WHERE run_id = %d", $run_id ) );
		$this->assertSame( 2, $snapshots );

		// Re-running a batch after completion changes nothing.
		$this->runner->handle_apply_batch( $run_id );
		$this->assertSame( 2, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wss_snapshots WHERE run_id = %d", $run_id ) ) );
		$this->assertSame( 20.0, (float) wc_get_product( wc_get_product_id_by_sku( 'A-1' ) )->get_stock_quantity() );
	}
}
