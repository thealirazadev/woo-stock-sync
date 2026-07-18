<?php
/**
 * Uninstall cleanup: drops plugin tables, options, meta, uploaded feeds, and scheduled actions.
 *
 * @package WooStockSync
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/wss-functions.php';
require_once __DIR__ . '/includes/class-wss-install.php';

WSS_Install::uninstall();
