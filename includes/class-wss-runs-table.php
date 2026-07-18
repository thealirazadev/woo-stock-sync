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
class WSS_Runs_Table extends WP_List_Table {

	/**
	 * Runs per page.
	 */
	const PER_PAGE = 20;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'run',
				'plural'   => 'runs',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Column definitions.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'id'      => __( 'ID', 'woo-stock-sync' ),
			'date'    => __( 'Date', 'woo-stock-sync' ),
			'trigger' => __( 'Trigger', 'woo-stock-sync' ),
			'source'  => __( 'Source', 'woo-stock-sync' ),
			'status'  => __( 'Status', 'woo-stock-sync' ),
			'planned' => __( 'Planned', 'woo-stock-sync' ),
			'skipped' => __( 'Skipped', 'woo-stock-sync' ),
			'failed'  => __( 'Failed', 'woo-stock-sync' ),
			'applied' => __( 'Applied', 'woo-stock-sync' ),
		);
	}

	/**
	 * Load the current page of runs.
	 *
	 * @return void
	 */
	public function prepare_items() {
		global $wpdb;

		$current = $this->get_pagenum();
		$offset  = ( $current - 1 ) * self::PER_PAGE;

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wss_runs" );
		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wss_runs ORDER BY id DESC LIMIT %d OFFSET %d",
				self::PER_PAGE,
				$offset
			)
		);

		$this->items           = is_array( $items ) ? $items : array();
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $total / self::PER_PAGE ),
			)
		);
	}

	/**
	 * Default column output (escaped).
	 *
	 * @param object $item        Run row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'trigger':
				return esc_html( $item->trigger_type );
			case 'source':
				return esc_html( wss_format_source( $item ) );
			case 'planned':
				return (string) (int) $item->rows_planned;
			case 'skipped':
				return (string) (int) $item->rows_skipped;
			case 'failed':
				return (string) (int) $item->rows_failed;
			case 'applied':
				return (string) (int) $item->rows_applied;
			default:
				return '';
		}
	}

	/**
	 * ID column with a View row action.
	 *
	 * @param object $item Run row.
	 * @return string
	 */
	public function column_id( $item ) {
		$url = WSS_Admin::page_url( array( 'run' => (int) $item->id ) );

		$view = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'View', 'woo-stock-sync' )
		);

		return sprintf( '<a href="%s">%d</a>', esc_url( $url ), (int) $item->id )
			. $this->row_actions( array( 'view' => $view ) );
	}

	/**
	 * Date column, localized from the stored UTC time.
	 *
	 * @param object $item Run row.
	 * @return string
	 */
	public function column_date( $item ) {
		if ( empty( $item->created_at ) || '0000-00-00 00:00:00' === $item->created_at ) {
			return '&mdash;';
		}

		return esc_html( wp_date( 'Y-m-d H:i', strtotime( $item->created_at . ' UTC' ) ) );
	}

	/**
	 * Status column badge.
	 *
	 * @param object $item Run row.
	 * @return string
	 */
	public function column_status( $item ) {
		return wss_status_badge( $item->status );
	}

	/**
	 * Empty-state message.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No sync runs yet. Configure a feed in Settings, then Fetch and preview.', 'woo-stock-sync' );
	}
}
