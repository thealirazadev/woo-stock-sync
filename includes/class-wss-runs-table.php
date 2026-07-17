<?php
/**
 * Runs list table.
 *
 * @package WooStockSync
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table for the sync runs history list.
 */
class WSS_Runs_Table extends WP_List_Table {}
