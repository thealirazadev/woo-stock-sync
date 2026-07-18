<?php
/**
 * Plugin Name:       Woo Stock Sync
 * Plugin URI:        https://github.com/thealirazadev/woo-stock-sync
 * Description:       Synchronize WooCommerce stock quantities and prices from a supplier CSV or JSON feed by SKU, with a dry-run diff, batched apply, and rollback.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Ali Raza
 * Author URI:        https://github.com/thealirazadev
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woo-stock-sync
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   8.8
 *
 * @package WooStockSync
 */

defined( 'ABSPATH' ) || exit;

define( 'WSS_VERSION', '1.0.0' );
define( 'WSS_FILE', __FILE__ );
define( 'WSS_PATH', plugin_dir_path( __FILE__ ) );
define( 'WSS_URL', plugin_dir_url( __FILE__ ) );
define( 'WSS_BASENAME', plugin_basename( __FILE__ ) );

/*
 * Processing caps. Deliberately constants (with filters at the point of use), not settings, so a
 * store owner cannot tune the plugin into an out-of-memory or timeout situation. See docs/rules.md.
 */
define( 'WSS_ROW_CAP', 50000 );
define( 'WSS_JSON_MAX_BYTES', 20 * 1024 * 1024 );
define( 'WSS_DEFAULT_BATCH_SIZE', 25 );

require_once WSS_PATH . 'includes/wss-functions.php';
require_once WSS_PATH . 'includes/class-wss-install.php';
require_once WSS_PATH . 'includes/class-wss-feed.php';
require_once WSS_PATH . 'includes/class-wss-runner.php';
require_once WSS_PATH . 'includes/class-wss-settings.php';
require_once WSS_PATH . 'includes/class-wss-admin.php';
require_once WSS_PATH . 'includes/class-wss-plugin.php';

/**
 * Whether WooCommerce is active and loaded in the current request.
 *
 * @return bool
 */
function wss_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

/**
 * Activation callback. Refuses to activate without WooCommerce, then installs the schema.
 *
 * @return void
 */
function wss_activate() {
	if ( ! wss_woocommerce_active() ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'Woo Stock Sync requires WooCommerce to be installed and active. Please activate WooCommerce first.', 'woo-stock-sync' ),
			'',
			array( 'back_link' => true )
		);
	}

	WSS_Install::activate();
}
register_activation_hook( __FILE__, 'wss_activate' );

/**
 * Render the admin notice shown when WooCommerce is missing.
 *
 * @return void
 */
function wss_woocommerce_missing_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Woo Stock Sync requires WooCommerce to be installed and active. The plugin is inactive until WooCommerce is enabled.', 'woo-stock-sync' )
	);
}

/**
 * Boot the plugin once all plugins are loaded, provided WooCommerce is present.
 *
 * @return void
 */
function wss_bootstrap() {
	if ( ! wss_woocommerce_active() ) {
		add_action( 'admin_notices', 'wss_woocommerce_missing_notice' );
		return;
	}

	load_plugin_textdomain( 'woo-stock-sync', false, dirname( WSS_BASENAME ) . '/languages' );

	WSS_Plugin::instance();

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once WSS_PATH . 'includes/class-wss-cli.php';
		WP_CLI::add_command( 'wss', 'WSS_CLI' );
	}
}
add_action( 'plugins_loaded', 'wss_bootstrap' );
