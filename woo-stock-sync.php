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

register_activation_hook( __FILE__, array( 'WSS_Install', 'activate' ) );

/**
 * Boot the plugin once all plugins are loaded.
 *
 * @return void
 */
function wss_bootstrap() {
	load_plugin_textdomain( 'woo-stock-sync', false, dirname( WSS_BASENAME ) . '/languages' );

	WSS_Plugin::instance();
}
add_action( 'plugins_loaded', 'wss_bootstrap' );
