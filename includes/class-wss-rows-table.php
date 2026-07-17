<?php
/**
 * Run detail rows table.
 *
 * @package WooStockSync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table for the per-row diff results of a single run.
 */
class WSS_Rows_Table extends WP_List_Table {}
