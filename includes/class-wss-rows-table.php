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
 *
 * Planned rows are expanded to one display row per changed field; skipped and failed rows show a
 * single display row.
 */
class WSS_Rows_Table extends WP_List_Table {

	/**
	 * Source rows per page.
	 */
	const PER_PAGE = 50;

	/**
	 * Run ID.
	 *
	 * @var int
	 */
	private $run_id;

	/**
	 * Active status filter (row status), or '' for all.
	 *
	 * @var string
	 */
	private $status_filter;

	/**
	 * Row counts by status for the current run.
	 *
	 * @var array
	 */
	private $counts = array();

	/**
	 * Constructor.
	 *
	 * @param int    $run_id        Run ID.
	 * @param string $status_filter Row status filter.
	 */
	public function __construct( $run_id, $status_filter = '' ) {
		$this->run_id        = (int) $run_id;
		$this->status_filter = $status_filter;

		parent::__construct(
			array(
				'singular' => 'row',
				'plural'   => 'rows',
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
			'product' => __( 'Product', 'woo-stock-sync' ),
			'sku'     => __( 'SKU', 'woo-stock-sync' ),
			'field'   => __( 'Field', 'woo-stock-sync' ),
			'current' => __( 'Current value', 'woo-stock-sync' ),
			'new'     => __( 'New value', 'woo-stock-sync' ),
			'action'  => __( 'Action', 'woo-stock-sync' ),
			'message' => __( 'Message', 'woo-stock-sync' ),
		);
	}

	/**
	 * Load and expand the current page of rows.
	 *
	 * @return void
	 */
	public function prepare_items() {
		global $wpdb;

		$this->load_counts();

		$current = $this->get_pagenum();
		$offset  = ( $current - 1 ) * self::PER_PAGE;

		if ( '' !== $this->status_filter ) {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}wss_rows WHERE run_id = %d AND status = %s",
					$this->run_id,
					$this->status_filter
				)
			);
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}wss_rows WHERE run_id = %d AND status = %s ORDER BY row_num ASC LIMIT %d OFFSET %d",
					$this->run_id,
					$this->status_filter,
					self::PER_PAGE,
					$offset
				)
			);
		} else {
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wss_rows WHERE run_id = %d", $this->run_id )
			);
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}wss_rows WHERE run_id = %d ORDER BY row_num ASC LIMIT %d OFFSET %d",
					$this->run_id,
					self::PER_PAGE,
					$offset
				)
			);
		}

		$this->items           = $this->expand_rows( is_array( $rows ) ? $rows : array() );
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
	 * Load row counts grouped by status.
	 *
	 * @return void
	 */
	private function load_counts() {
		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS n FROM {$wpdb->prefix}wss_rows WHERE run_id = %d GROUP BY status",
				$this->run_id
			),
			ARRAY_A
		);

		$this->counts = array();
		foreach ( (array) $results as $result ) {
			$this->counts[ $result['status'] ] = (int) $result['n'];
		}
	}

	/**
	 * Expand source rows into per-field display rows.
	 *
	 * @param array $rows Source rows.
	 * @return array Display items.
	 */
	private function expand_rows( array $rows ) {
		$multi_field = array( 'planned', 'no_change', 'applied', 'apply_failed', 'rolled_back', 'rollback_failed' );
		$items       = array();

		foreach ( $rows as $row ) {
			$new_values = json_decode( $row->new_values, true );
			$current    = json_decode( (string) $row->current_values, true );
			$new_values = is_array( $new_values ) ? $new_values : array();
			$current    = is_array( $current ) ? $current : array();

			if ( in_array( $row->status, $multi_field, true ) && ! empty( $new_values ) ) {
				foreach ( $new_values as $field => $value ) {
					$items[] = array(
						'row'     => $row,
						'field'   => $field,
						'current' => array_key_exists( $field, $current ) ? $current[ $field ] : null,
						'new'     => $value,
					);
				}
				continue;
			}

			$items[] = array(
				'row'     => $row,
				'field'   => '',
				'current' => null,
				'new'     => null,
			);
		}

		return $items;
	}

	/**
	 * Product column linking to the product editor.
	 *
	 * @param array $item Display item.
	 * @return string
	 */
	public function column_product( $item ) {
		$product_id = (int) $item['row']->product_id;
		if ( ! $product_id ) {
			return '&mdash;';
		}

		$name = get_the_title( $product_id );
		$name = '' !== $name ? $name : sprintf( '#%d', $product_id );
		$url  = admin_url( 'post.php?post=' . $product_id . '&action=edit' );

		return sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $name ) );
	}

	/**
	 * Default column output.
	 *
	 * @param array  $item        Display item.
	 * @param string $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		$row = $item['row'];

		switch ( $column_name ) {
			case 'sku':
				return esc_html( $row->sku );
			case 'field':
				return '' === $item['field'] ? '&mdash;' : esc_html( $this->field_label( $item['field'] ) );
			case 'current':
				return '' === $item['field'] ? '&mdash;' : $this->format_current( $item['current'] );
			case 'new':
				return '' === $item['field'] ? '&mdash;' : $this->format_new( $item['field'], $item['new'] );
			case 'action':
				$badge  = wss_status_badge( $row->status );
				$reason = '' !== (string) $row->reason ? ' <span class="wss-reason">' . esc_html( $row->reason ) . '</span>' : '';
				return $badge . $reason;
			case 'message':
				return esc_html( (string) $row->message );
			default:
				return '';
		}
	}

	/**
	 * Human label for a mapped field.
	 *
	 * @param string $field Field key.
	 * @return string
	 */
	private function field_label( $field ) {
		$labels = array(
			'stock'         => __( 'Stock', 'woo-stock-sync' ),
			'regular_price' => __( 'Regular price', 'woo-stock-sync' ),
			'sale_price'    => __( 'Sale price', 'woo-stock-sync' ),
		);

		return isset( $labels[ $field ] ) ? $labels[ $field ] : $field;
	}

	/**
	 * Format a current value for display.
	 *
	 * @param mixed $value Current value.
	 * @return string
	 */
	private function format_current( $value ) {
		if ( null === $value || '' === $value ) {
			return '&mdash;';
		}

		return esc_html( (string) $value );
	}

	/**
	 * Format a new value for display.
	 *
	 * @param string $field Field key.
	 * @param mixed  $value New value.
	 * @return string
	 */
	private function format_new( $field, $value ) {
		if ( 'sale_price' === $field && '' === $value ) {
			return esc_html__( 'Clear sale price', 'woo-stock-sync' );
		}

		if ( null === $value || '' === $value ) {
			return '&mdash;';
		}

		return esc_html( (string) $value );
	}

	/**
	 * Status filter links shown above the table.
	 *
	 * @return array
	 */
	public function get_views() {
		$base    = WSS_Admin::page_url( array( 'run' => $this->run_id ) );
		$total   = array_sum( $this->counts );
		$filters = array(
			''          => __( 'All', 'woo-stock-sync' ),
			'planned'   => __( 'Planned', 'woo-stock-sync' ),
			'no_change' => __( 'No change', 'woo-stock-sync' ),
			'skipped'   => __( 'Skipped', 'woo-stock-sync' ),
			'failed'    => __( 'Failed', 'woo-stock-sync' ),
			'applied'   => __( 'Applied', 'woo-stock-sync' ),
		);

		$views = array();
		foreach ( $filters as $status => $label ) {
			$count = ( '' === $status ) ? $total : ( isset( $this->counts[ $status ] ) ? $this->counts[ $status ] : 0 );
			if ( '' !== $status && 0 === $count ) {
				continue;
			}

			$url     = ( '' === $status ) ? $base : add_query_arg( 'status', $status, $base );
			$current = ( $status === $this->status_filter ) ? ' class="current"' : '';

			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				$current,
				esc_html( $label ),
				(int) $count
			);
		}

		return $views;
	}

	/**
	 * Empty-state message.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'Nothing to change. The store already matches the feed.', 'woo-stock-sync' );
	}
}
