<?php
/**
 * Integration tests for the dry-run diff (requires WordPress + WooCommerce).
 *
 * @package WooStockSync
 */

/**
 * Dry-run diff status tests.
 *
 * @covers WSS_Runner
 */
class Test_Diff extends WSS_Integration_TestCase {

	/**
	 * Runner under test.
	 *
	 * @var WSS_Runner
	 */
	private $runner;

	/**
	 * Temp files to remove.
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

	/**
	 * Create a simple, stock-managed product.
	 *
	 * @param string $sku      SKU.
	 * @param int    $stock    Stock quantity.
	 * @param string $regular  Regular price.
	 * @param string $sale     Sale price.
	 * @param bool   $manage   Whether to manage stock.
	 * @return int Product ID.
	 */
	private function make_product( $sku, $stock, $regular, $sale = '', $manage = true ) {
		$product = new WC_Product_Simple();
		$product->set_sku( $sku );
		$product->set_manage_stock( $manage );
		if ( $manage ) {
			$product->set_stock_quantity( $stock );
		}
		$product->set_regular_price( $regular );
		if ( '' !== $sale ) {
			$product->set_sale_price( $sale );
		}
		return $product->save();
	}

	/**
	 * Configure settings and stage + diff a run from a CSV body.
	 *
	 * @param string $csv     CSV content.
	 * @param array  $mapping Column mapping.
	 * @return int Run ID.
	 */
	private function run_diff( $csv, $mapping ) {
		$path        = tempnam( sys_get_temp_dir(), 'wssdiff' ) . '.csv';
		$this->tmp[] = $path;
		file_put_contents( $path, $csv );

		update_option(
			'wss_settings',
			array_merge(
				wss_settings_defaults(),
				array(
					'source_type' => 'upload',
					'upload_path' => $path,
					'mapping'     => $mapping,
				)
			)
		);

		$run_id = $this->runner->create_run(
			array(
				'trigger_type' => 'manual',
				'source_type'  => 'upload',
				'source_ref'   => $path,
				'mapping'      => $mapping,
			)
		);

		$this->runner->handle_fetch( $run_id );

		$guard = 0;
		while ( 'diffing' === wss_get_run( $run_id )->status && $guard < 1000 ) {
			$this->runner->handle_diff_batch( $run_id );
			++$guard;
		}

		return $run_id;
	}

	/**
	 * Read a run's rows keyed by SKU.
	 *
	 * @param int $run_id Run ID.
	 * @return array
	 */
	private function rows_by_sku( $run_id ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wss_rows WHERE run_id = %d", $run_id )
		);
		$map  = array();
		foreach ( $rows as $row ) {
			$map[ $row->sku ] = $row;
		}
		return $map;
	}

	public function test_diff_statuses() {
		$this->make_product( 'D-CHANGE', 5, '20.00' );
		$this->make_product( 'D-SAME', 8, '9.99' );

		$mapping = array(
			'sku'           => 'sku',
			'stock'         => 'qty',
			'regular_price' => 'price',
			'sale_price'    => '',
		);

		$csv = "sku,qty,price\n"
			. "D-CHANGE,12,25.00\n"
			. "D-SAME,8,9.99\n"
			. "D-UNKNOWN,3,4.00\n";

		$run_id = $this->run_diff( $csv, $mapping );
		$run    = wss_get_run( $run_id );
		$rows   = $this->rows_by_sku( $run_id );

		$this->assertSame( 'previewed', $run->status );
		$this->assertSame( 'planned', $rows['D-CHANGE']->status );
		$this->assertSame( 'no_change', $rows['D-SAME']->status );
		$this->assertSame( 'skipped', $rows['D-UNKNOWN']->status );
		$this->assertSame( 'unknown_sku', $rows['D-UNKNOWN']->reason );
		$this->assertSame( 1, (int) $run->rows_planned );
	}

	public function test_stock_not_managed_when_stock_only() {
		$this->make_product( 'D-NOSTOCK', 0, '10.00', '', false );

		$mapping = array(
			'sku'           => 'sku',
			'stock'         => 'qty',
			'regular_price' => '',
			'sale_price'    => '',
		);

		$run_id = $this->run_diff( "sku,qty\nD-NOSTOCK,15\n", $mapping );
		$rows   = $this->rows_by_sku( $run_id );

		$this->assertSame( 'skipped', $rows['D-NOSTOCK']->status );
		$this->assertSame( 'stock_not_managed', $rows['D-NOSTOCK']->reason );
	}
}
