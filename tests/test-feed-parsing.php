<?php
/**
 * Unit tests for feed parsing, mapping, and validation (pure logic; no WordPress required).
 *
 * @package WooStockSync
 */

/**
 * Feed parsing, mapping, and validation tests.
 *
 * @covers WSS_Feed
 */
class Test_Feed_Parsing extends \PHPUnit\Framework\TestCase {

	/**
	 * Feed instance.
	 *
	 * @var WSS_Feed
	 */
	private $feed;

	/**
	 * Default settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Default mapping.
	 *
	 * @var array
	 */
	private $mapping;

	/**
	 * Temp files to clean up.
	 *
	 * @var array
	 */
	private $tmp = array();

	protected function setUp(): void {
		parent::setUp();

		$this->feed     = new WSS_Feed();
		$this->settings = array(
			'source_type'       => 'upload',
			'feed_url'          => '',
			'auth_header_name'  => '',
			'auth_header_value' => '',
			'upload_path'       => '',
			'blank_clears_sale' => false,
		);
		$this->mapping  = array(
			'sku'           => 'sku',
			'stock'         => 'qty',
			'regular_price' => 'price',
			'sale_price'    => 'sale_price',
		);
	}

	protected function tearDown(): void {
		foreach ( $this->tmp as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		$this->tmp = array();
		remove_all_filters( 'wss_row_cap' );

		parent::tearDown();
	}

	/**
	 * Write a temp feed file with the given content and extension.
	 *
	 * @param string $content File content.
	 * @param string $ext     Extension (csv|json).
	 * @return string Path.
	 */
	private function write_tmp( $content, $ext = 'csv' ) {
		$base = tempnam( sys_get_temp_dir(), 'wssfeed' );
		$path = $base . '.' . $ext;
		rename( $base, $path );
		file_put_contents( $path, $content );
		$this->tmp[] = $path;

		return $path;
	}

	/**
	 * Parse a file and return array( result, rows ).
	 *
	 * @param string     $path     File path.
	 * @param array|null $settings Settings override.
	 * @return array
	 */
	private function parse_file( $path, $settings = null ) {
		$settings = ( null === $settings ) ? $this->settings : $settings;
		$source   = $this->feed->open_run_source( 'upload', $path, $settings );
		$this->assertIsArray( $source );

		$rows   = array();
		$emit   = function ( $row ) use ( &$rows ) {
			$rows[] = $row;
		};
		$result = $this->feed->parse( $source, $this->mapping, $settings, $emit );

		return array( $result, $rows );
	}

	public function test_fixture_produces_expected_statuses() {
		list( $result, $rows ) = $this->parse_file( __DIR__ . '/fixtures/sample-feed.csv' );

		$this->assertSame( 7, $result );

		$this->assertSame( 'staged', $rows[0]['status'] );
		$this->assertSame(
			array(
				'stock'         => '10',
				'regular_price' => '19.99',
				'sale_price'    => '14.99',
			),
			$rows[0]['new_values']
		);

		// Blank sale price is omitted (no change) when clearing is disabled.
		$this->assertSame(
			array(
				'stock'         => '5',
				'regular_price' => '9.99',
			),
			$rows[1]['new_values']
		);

		$this->assertSame( 'failed', $rows[3]['status'] );
		$this->assertSame( 'invalid_number', $rows[3]['reason'] );

		$this->assertSame( 'failed', $rows[4]['status'] );
		$this->assertSame( 'invalid_sale_price', $rows[4]['reason'] );

		$this->assertSame( 'failed', $rows[5]['status'] );
		$this->assertSame( 'duplicate_sku', $rows[5]['reason'] );

		$this->assertSame( 'staged', $rows[6]['status'] );
	}

	public function test_missing_sku_is_failed() {
		$path           = $this->write_tmp( "sku,qty,price,sale_price\n,5,9.99,\n" );
		list( , $rows ) = $this->parse_file( $path );

		$this->assertSame( 'failed', $rows[0]['status'] );
		$this->assertSame( 'missing_sku', $rows[0]['reason'] );
	}

	public function test_numeric_validation() {
		$path           = $this->write_tmp(
			"sku,qty,price,sale_price\n"
			. "A,10,abc,\n"
			. "B,10,\"1,5\",\n"
			. "C,10,-3,\n"
			. "D,0,9.99,\n"
		);
		list( , $rows ) = $this->parse_file( $path );

		$this->assertSame( 'invalid_number', $rows[0]['reason'], 'abc rejected' );
		$this->assertSame( 'invalid_number', $rows[1]['reason'], 'comma decimal rejected' );
		$this->assertSame( 'invalid_number', $rows[2]['reason'], 'negative rejected' );
		$this->assertSame( 'staged', $rows[3]['status'], 'zero and 9.99 accepted' );
		$this->assertSame(
			array(
				'stock'         => '0',
				'regular_price' => '9.99',
			),
			$rows[3]['new_values']
		);
	}

	public function test_sale_price_must_be_below_regular() {
		$path           = $this->write_tmp( "sku,qty,price,sale_price\nA,1,10.00,10.00\nB,1,10.00,9.99\n" );
		list( , $rows ) = $this->parse_file( $path );

		$this->assertSame( 'invalid_sale_price', $rows[0]['reason'], 'equal sale rejected' );
		$this->assertSame( 'staged', $rows[1]['status'], 'lower sale accepted' );
	}

	public function test_blank_sale_clears_only_when_enabled() {
		$content = "sku,qty,price,sale_price\nA,1,10.00,\n";

		$path           = $this->write_tmp( $content );
		list( , $rows ) = $this->parse_file( $path );
		$this->assertArrayNotHasKey( 'sale_price', $rows[0]['new_values'], 'blank sale = no change by default' );

		$settings                      = $this->settings;
		$settings['blank_clears_sale'] = true;
		$path2                         = $this->write_tmp( $content );
		list( , $rows2 )               = $this->parse_file( $path2, $settings );
		$this->assertArrayHasKey( 'sale_price', $rows2[0]['new_values'] );
		$this->assertSame( '', $rows2[0]['new_values']['sale_price'], 'blank sale clears when enabled' );
	}

	public function test_bom_and_crlf_header() {
		$content               = "\xEF\xBB\xBFsku,qty,price,sale_price\r\nSKU-9,4,3.50,\r\n";
		$path                  = $this->write_tmp( $content );
		list( $result, $rows ) = $this->parse_file( $path );

		$this->assertSame( 1, $result );
		$this->assertSame( 'SKU-9', $rows[0]['sku'] );
		$this->assertSame(
			array(
				'stock'         => '4',
				'regular_price' => '3.50',
			),
			$rows[0]['new_values']
		);
	}

	public function test_malformed_line_does_not_abort_parse() {
		// A short row (missing columns) still yields a result and the parse continues.
		$path                  = $this->write_tmp( "sku,qty,price,sale_price\nA,1\nB,2,5.00,\n" );
		list( $result, $rows ) = $this->parse_file( $path );

		$this->assertSame( 2, $result );
		$this->assertSame( 'A', $rows[0]['sku'] );
		$this->assertSame( 'staged', $rows[1]['status'] );
	}

	public function test_header_only_file_has_zero_rows() {
		$path                  = $this->write_tmp( "sku,qty,price,sale_price\n" );
		list( $result, $rows ) = $this->parse_file( $path );

		$this->assertSame( 0, $result );
		$this->assertSame( array(), $rows );
	}

	public function test_row_cap_fails_fast() {
		add_filter(
			'wss_row_cap',
			static function () {
				return 3;
			}
		);

		$path           = $this->write_tmp( "sku,qty,price,sale_price\nA,1,1,\nB,1,1,\nC,1,1,\nD,1,1,\n" );
		list( $result ) = $this->parse_file( $path );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'too_large', $result->get_error_code() );
	}

	public function test_format_detection_of_a_url_without_a_path_is_deprecation_free() {
		// Remote feeds land in a wp_tempnam() ".tmp" file, so detection always falls through to the
		// URL hint. A supplier URL with no path made pathinfo() receive null on every fetch.
		$path = $this->write_tmp( "sku,qty,price,sale_price\nA,1,1,\n", 'tmp' );

		$deprecations = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- the assertion is that no deprecation is raised; restored in finally.
		set_error_handler(
			static function ( $errno, $errstr ) use ( &$deprecations ) {
				unset( $errno );
				$deprecations[] = $errstr;
				return true;
			},
			E_DEPRECATED
		);

		try {
			$method = new ReflectionMethod( 'WSS_Feed', 'detect_format' );
			$method->setAccessible( true );
			$format = $method->invoke( null, $path, 'https://example.com' );
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $deprecations, 'format detection must not emit deprecations' );
		$this->assertSame( 'csv', $format );
	}

	public function test_currency_and_thousands_separators_are_rejected() {
		// Supplier feeds often ship money-formatted values. These must be rejected outright, never
		// silently truncated (a stray comma or symbol turning 1,000 into 1).
		$path           = $this->write_tmp(
			"sku,qty,price,sale_price\n"
			. "A,10,\$9.99,\n"
			. "B,10,\"1,000\",\n"
			. "C,10,9.99USD,\n"
			. "D,\"1 000\",9.99,\n"
		);
		list( , $rows ) = $this->parse_file( $path );

		$this->assertSame( 'invalid_number', $rows[0]['reason'], 'currency symbol rejected' );
		$this->assertSame( 'invalid_number', $rows[1]['reason'], 'thousands separator rejected' );
		$this->assertSame( 'invalid_number', $rows[2]['reason'], 'trailing currency code rejected' );
		$this->assertSame( 'invalid_number', $rows[3]['reason'], 'space-grouped quantity rejected' );
	}

	public function test_duplicate_sku_keeps_the_first_row() {
		// First occurrence wins: it stages with its own values; later duplicates fail.
		$path           = $this->write_tmp( "sku,qty,price,sale_price\nDUP,10,19.99,\nDUP,99,29.99,\n" );
		list( , $rows ) = $this->parse_file( $path );

		$this->assertSame( 'staged', $rows[0]['status'] );
		$this->assertSame( '10', $rows[0]['new_values']['stock'], 'the first row is the one kept' );
		$this->assertSame( 'failed', $rows[1]['status'] );
		$this->assertSame( 'duplicate_sku', $rows[1]['reason'] );
	}

	public function test_malformed_json_fails_the_run() {
		$path           = $this->write_tmp( '{ "sku": "J-1", ', 'json' );
		list( $result ) = $this->parse_file( $path );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'fetch_failed', $result->get_error_code() );
	}

	public function test_json_scalar_entry_fails_the_row_without_aborting() {
		// A non-object element must not fatal the parse; it yields an empty row (missing SKU).
		$json                  = wp_json_encode(
			array(
				array(
					'sku'   => 'J-OK',
					'qty'   => 3,
					'price' => '4.00',
				),
				42,
			)
		);
		$path                  = $this->write_tmp( $json, 'json' );
		list( $result, $rows ) = $this->parse_file( $path );

		$this->assertSame( 2, $result );
		$this->assertSame( 'staged', $rows[0]['status'] );
		$this->assertSame( 'failed', $rows[1]['status'] );
		$this->assertSame( 'missing_sku', $rows[1]['reason'] );
	}

	public function test_missing_mapped_column_fails_the_run() {
		// The feed header omits the column the mapping points stock at (qty), so the run must fail
		// with a clear message instead of silently staging every row as "no change" for stock.
		$path           = $this->write_tmp( "sku,price,sale_price\nA,10.00,\n" );
		list( $result ) = $this->parse_file( $path );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'missing_column', $result->get_error_code() );
		$this->assertStringContainsString( 'qty', $result->get_error_message() );
	}

	public function test_json_feed_parsing() {
		$json                  = wp_json_encode(
			array(
				array(
					'sku'   => 'J-1',
					'qty'   => 7,
					'price' => '12.00',
				),
				array(
					'sku'   => 'J-2',
					'qty'   => 'nope',
					'price' => '5.00',
				),
			)
		);
		$path                  = $this->write_tmp( $json, 'json' );
		list( $result, $rows ) = $this->parse_file( $path );

		$this->assertSame( 2, $result );
		$this->assertSame( 'staged', $rows[0]['status'] );
		$this->assertSame( '7', $rows[0]['new_values']['stock'] );
		$this->assertSame( 'invalid_number', $rows[1]['reason'] );
	}
}
