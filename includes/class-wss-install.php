<?php
/**
 * Schema installation and versioned migrations.
 *
 * @package WooStockSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and migrates the plugin's custom tables via dbDelta.
 */
class WSS_Install {

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate() {}
}
