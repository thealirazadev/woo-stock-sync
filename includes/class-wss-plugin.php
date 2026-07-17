<?php
/**
 * Plugin orchestrator.
 *
 * @package WooStockSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Singleton that wires the plugin's components together on load.
 */
class WSS_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var WSS_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get (and lazily build) the singleton instance.
	 *
	 * @return WSS_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire the plugin's components.
	 */
	private function __construct() {
		// Apply pending schema migrations on load in admin and CLI contexts only.
		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			WSS_Install::maybe_upgrade();
		}
	}
}
