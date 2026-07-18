<?php
/**
 * Uninstall cleanup integration test (requires WordPress + WooCommerce).
 *
 * @package WooStockSync
 */

/**
 * Verifies uninstall removes tables, options, and lock meta.
 *
 * @covers WSS_Install
 */
class Test_Uninstall extends WSS_Integration_TestCase {

	public function set_up() {
		parent::set_up();
		WSS_Install::install();
	}

	public function test_uninstall_removes_tables_options_and_meta() {
		global $wpdb;

		update_option( 'wss_settings', array( 'source_type' => 'upload' ) );
		update_option( 'wss_db_version', '1' );

		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_wss_locked', 'yes' );

		$runner = new WSS_Runner();
		$runner->create_run(
			array(
				'source_type' => 'upload',
				'source_ref'  => 'feed.csv',
				'mapping'     => array(),
			)
		);

		$runs_table = $wpdb->prefix . 'wss_runs';
		$this->assertSame( $runs_table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $runs_table ) ) );

		WSS_Install::uninstall();

		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $runs_table ) ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wss_rows' ) ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wss_snapshots' ) ) );

		$this->assertFalse( get_option( 'wss_settings', false ) );
		$this->assertFalse( get_option( 'wss_db_version', false ) );
		$this->assertFalse( get_option( 'wss_active_run', false ) );

		$this->assertSame( '', get_post_meta( $post_id, '_wss_locked', true ) );
	}
}
