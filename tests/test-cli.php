<?php
/**
 * WP-CLI command integration tests (requires WordPress + WooCommerce).
 *
 * @package WooStockSync
 */

/**
 * WP-CLI command tests.
 *
 * @covers WSS_CLI
 */
class Test_CLI extends WSS_Integration_TestCase {

	/**
	 * CLI instance.
	 *
	 * @var WSS_CLI
	 */
	private $cli;

	/**
	 * Temp files.
	 *
	 * @var array
	 */
	private $tmp = array();

	public function set_up() {
		parent::set_up();
		WSS_Install::install();
		$this->cli       = new WSS_CLI();
		WP_CLI::$success = array();
		WP_CLI::$log     = array();
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

	private function configure( $csv, $mapping ) {
		$path        = tempnam( sys_get_temp_dir(), 'wsscli' ) . '.csv';
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
	}

	public function test_fetch_creates_a_previewed_run() {
		$this->make_product( 'C-1', 5, '10.00' );
		$mapping = array(
			'sku'           => 'sku',
			'stock'         => 'qty',
			'regular_price' => 'price',
			'sale_price'    => '',
		);
		$this->configure( "sku,qty,price\nC-1,20,15.00\n", $mapping );

		$this->cli->fetch( array(), array() );

		$this->assertNotEmpty( WP_CLI::$success );

		global $wpdb;
		$status = $wpdb->get_var( "SELECT status FROM {$wpdb->prefix}wss_runs ORDER BY id DESC LIMIT 1" );
		$this->assertSame( 'previewed', $status );
	}

	public function test_apply_with_invalid_run_errors() {
		$this->expectException( RuntimeException::class );
		$this->cli->apply(
			array(),
			array(
				'run' => 999999,
				'yes' => true,
			)
		);
	}

	public function test_rollback_with_nothing_applied_errors() {
		$this->expectException( RuntimeException::class );
		$this->cli->rollback( array(), array( 'yes' => true ) );
	}

	public function test_runs_outputs_items() {
		$this->cli->runs( array(), array( 'format' => 'json' ) );
		$this->assertArrayHasKey( 'wss_cli_format', $GLOBALS );
	}
}
