<?php
/**
 * Remote feed fetch integration tests.
 *
 * @package WooStockSync
 */

/**
 * Integration tests for downloading a remote feed (requires WordPress).
 *
 * @covers WSS_Feed
 */
class Test_Remote_Fetch extends WSS_Integration_TestCase {

	/**
	 * Arguments of the last outgoing HTTP request.
	 *
	 * @var array|null
	 */
	private $request_args = null;

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		$this->request_args = null;
		parent::tear_down();
	}

	public function test_remote_fetch_rejects_unsafe_urls() {
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) {
				$this->request_args = $args;
				return $pre;
			},
			10,
			2
		);

		$feed   = new WSS_Feed();
		$result = $feed->download_remote( 'http://127.0.0.1/feed.csv' );

		$this->assertIsArray( $this->request_args );
		$this->assertTrue( $this->request_args['reject_unsafe_urls'], 'Feed fetches must use the safe HTTP API.' );
		$this->assertWPError( $result );
		$this->assertSame( 'fetch_failed', $result->get_error_code() );
	}
}
