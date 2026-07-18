<?php
/**
 * Schema installation and versioned migrations.
 *
 * @package WooStockSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and migrates the plugin's custom tables via dbDelta.
 *
 * Every schema change is a new bump of {@see WSS_Install::DB_VERSION} plus SQL in
 * {@see WSS_Install::create_tables()}. Applied migrations are never edited afterward; a fix ships
 * as a new version. dbDelta makes each run idempotent.
 */
class WSS_Install {

	/**
	 * Current schema version. Bump when the table definitions change.
	 */
	const DB_VERSION = '1';

	/**
	 * Activation callback: install (or upgrade) the schema.
	 *
	 * @return void
	 */
	public static function activate() {
		self::install();
	}

	/**
	 * Run the installer when the stored schema version is behind the code.
	 *
	 * Hooked on load in admin/CLI contexts so upgrades apply without a manual reactivation.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( self::DB_VERSION === get_option( 'wss_db_version' ) ) {
			return;
		}

		self::install();
	}

	/**
	 * Create/upgrade tables and record the applied version.
	 *
	 * @return void
	 */
	public static function install() {
		self::create_tables();
		update_option( 'wss_db_version', self::DB_VERSION );
	}

	/**
	 * Remove everything the plugin created: tables, options, lock meta, uploaded feeds, and jobs.
	 *
	 * @return void
	 */
	public static function uninstall() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping the plugin's own tables on uninstall.
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}wss_snapshots`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}wss_rows`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}wss_runs`" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange

		delete_option( 'wss_settings' );
		delete_option( 'wss_db_version' );
		delete_option( 'wss_active_run' );

		delete_post_meta_by_key( '_wss_locked' );

		self::delete_uploads_dir();

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			$hooks = array( 'wss_fetch_run', 'wss_diff_batch', 'wss_apply_batch', 'wss_rollback_batch', 'wss_scheduled_fetch' );
			foreach ( $hooks as $hook ) {
				as_unschedule_all_actions( $hook, array(), 'woo-stock-sync' );
			}
		}
	}

	/**
	 * Delete the protected uploads subdirectory and any feed files in it.
	 *
	 * @return void
	 */
	private static function delete_uploads_dir() {
		if ( ! function_exists( 'wss_uploads_dir' ) ) {
			return;
		}

		$dir = wss_uploads_dir();

		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( false === WP_Filesystem() ) {
			return;
		}

		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->is_dir( $dir ) ) {
			$wp_filesystem->delete( $dir, true );
		}
	}

	/**
	 * Create the runs, rows, and snapshots tables via dbDelta.
	 *
	 * @return void
	 */
	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$runs            = $wpdb->prefix . 'wss_runs';
		$rows            = $wpdb->prefix . 'wss_rows';
		$snapshots       = $wpdb->prefix . 'wss_snapshots';

		$schema = "CREATE TABLE {$runs} (
			id bigint(20) unsigned NOT NULL auto_increment,
			status varchar(20) NOT NULL default 'pending',
			trigger_type varchar(10) NOT NULL default 'manual',
			source_type varchar(10) NOT NULL default 'upload',
			source_ref text NULL,
			mapping longtext NULL,
			rows_total int(10) unsigned NOT NULL default 0,
			rows_planned int(10) unsigned NOT NULL default 0,
			rows_skipped int(10) unsigned NOT NULL default 0,
			rows_failed int(10) unsigned NOT NULL default 0,
			rows_applied int(10) unsigned NOT NULL default 0,
			error text NULL,
			created_by bigint(20) unsigned NOT NULL default 0,
			created_at datetime NOT NULL default '0000-00-00 00:00:00',
			finished_at datetime NULL default NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset_collate};\n";

		$schema .= "CREATE TABLE {$rows} (
			id bigint(20) unsigned NOT NULL auto_increment,
			run_id bigint(20) unsigned NOT NULL default 0,
			row_num int(10) unsigned NOT NULL default 0,
			sku varchar(100) NOT NULL default '',
			product_id bigint(20) unsigned NULL default NULL,
			new_values longtext NULL,
			current_values longtext NULL,
			status varchar(20) NOT NULL default 'staged',
			reason varchar(30) NULL default NULL,
			message text NULL,
			updated_at datetime NOT NULL default '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY run_status (run_id, status, id),
			KEY run_row (run_id, row_num)
		) {$charset_collate};\n";

		$schema .= "CREATE TABLE {$snapshots} (
			id bigint(20) unsigned NOT NULL auto_increment,
			run_id bigint(20) unsigned NOT NULL default 0,
			product_id bigint(20) unsigned NOT NULL default 0,
			prior_values longtext NULL,
			restored tinyint(1) NOT NULL default 0,
			PRIMARY KEY  (id),
			UNIQUE KEY run_product (run_id, product_id)
		) {$charset_collate};\n";

		dbDelta( $schema );
	}
}
