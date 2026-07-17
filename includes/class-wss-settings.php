<?php
/**
 * Settings screen render, sanitize, and save.
 *
 * @package WooStockSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders and persists the plugin settings (stored in the wss_settings option).
 */
class WSS_Settings {

	/**
	 * Field-level validation errors from the last save attempt, keyed by field.
	 *
	 * @var array
	 */
	private $errors = array();

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function render() {
		$settings = wss_get_settings();
		$errors   = $this->errors;

		echo '<div class="wrap wss-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Stock Sync', 'woo-stock-sync' ) . '</h1>';
		WSS_Admin::render_tabs( 'settings' );

		require WSS_PATH . 'templates/admin/settings.php';

		echo '</div>';
	}
}
