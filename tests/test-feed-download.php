<?php
/**
 * Remote feed download integration tests.
 *
 * @package WooStockSync
 */

/**
 * Integration tests for WSS_Feed::download_remote() (requires WordPress).
 *
 * @covers WSS_Feed
 */
class Test_Feed_Download extends WSS_Integration_TestCase {

	/**
	 * Arguments the last HTTP request was made with.
	 *
	 * @var array|null
	 */
	private $captured_args = null;

	/**
	 * The pre_http_request mock added by a test, if any.
	 *
	 * @var callable|null
	 */
	private $mock = null;

	public function tear_down() {
		if ( $this->mock ) {
			remove_filter( 'pre_http_request', $this->mock, 10 );
		}
		parent::tear_down();
	}

	public function test_download_uses_a_safe_request_and_sends_the_auth_header() {
		$this->mock = function ( $pre, $args ) {
			$this->captured_args = $args;
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => $args['filename'],
			);
		};
		add_filter( 'pre_http_request', $this->mock, 10, 2 );

		$path = ( new WSS_Feed() )->download_remote( 'https://feeds.example.com/stock.csv', 'X-Api-Key', 'secret-value' );

		$this->assertIsString( $path );
		$this->assertTrue( $this->captured_args['reject_unsafe_urls'] );
		$this->assertSame( 'secret-value', $this->captured_args['headers']['X-Api-Key'] );

		wp_delete_file( $path );
	}

	public function test_loopback_feed_url_is_refused_without_a_request() {
		$result = ( new WSS_Feed() )->download_remote( 'http://127.0.0.1/feed.csv' );

		$this->assertWPError( $result );
		$this->assertSame( 'fetch_failed', $result->get_error_code() );
	}
}
