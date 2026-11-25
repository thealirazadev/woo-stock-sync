<?php
/**
 * Same-host check for reusing the stored auth header (pure logic; no WordPress required).
 *
 * @package WooStockSync
 */

/**
 * Unit tests for wss_same_feed_host().
 *
 * @covers ::wss_same_feed_host
 */
class Test_Feed_Host extends \PHPUnit\Framework\TestCase {

	public function test_same_host_matches_regardless_of_path_scheme_and_case() {
		$this->assertTrue( wss_same_feed_host( 'https://Feeds.Example.com/a.csv', 'https://feeds.example.com/b.json' ) );
		$this->assertTrue( wss_same_feed_host( 'http://feeds.example.com/a.csv', 'https://feeds.example.com/a.csv' ) );
	}

	public function test_different_host_does_not_match() {
		$this->assertFalse( wss_same_feed_host( 'https://attacker.example.net/collect', 'https://feeds.example.com/a.csv' ) );
		$this->assertFalse( wss_same_feed_host( 'https://feeds.example.com.attacker.net/a.csv', 'https://feeds.example.com/a.csv' ) );
	}

	public function test_missing_hosts_never_match() {
		$this->assertFalse( wss_same_feed_host( '', '' ) );
		$this->assertFalse( wss_same_feed_host( 'https://feeds.example.com/a.csv', '' ) );
		$this->assertFalse( wss_same_feed_host( '', 'https://feeds.example.com/a.csv' ) );
		$this->assertFalse( wss_same_feed_host( 'not a url', 'not a url' ) );
	}
}
