<?php
/**
 * Run lifecycle: create, stage, diff, apply, rollback, cancel, resume.
 *
 * @package WooStockSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the run lifecycle and the Action Scheduler batch callbacks.
 */
class WSS_Runner {

	/**
	 * Statuses that count as an active (running) sync.
	 */
	const ACTIVE_STATUSES = array( 'fetching', 'diffing', 'applying', 'rolling_back' );

	/**
	 * Rows inserted per staging query.
	 */
	const STAGE_CHUNK = 250;

	/**
	 * Constructor: register the background action callbacks.
	 */
	public function __construct() {
		add_action( 'wss_fetch_run', array( $this, 'handle_fetch' ) );
		add_action( 'wss_diff_batch', array( $this, 'handle_diff_batch' ) );
	}

	/**
	 * Create a new run row in the pending state.
	 *
	 * @param array $args trigger_type, source_type, source_ref, mapping, created_by.
	 * @return int|WP_Error New run ID, or WP_Error on failure.
	 */
	public function create_run( array $args ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'trigger_type' => 'manual',
				'source_type'  => 'upload',
				'source_ref'   => '',
				'mapping'      => array(),
				'created_by'   => 0,
			)
		);

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wss_runs',
			array(
				'status'       => 'pending',
				'trigger_type' => $args['trigger_type'],
				'source_type'  => $args['source_type'],
				'source_ref'   => $args['source_ref'],
				'mapping'      => wp_json_encode( $args['mapping'] ),
				'created_by'   => (int) $args['created_by'],
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		if ( ! $inserted ) {
			wss_log( 'Failed to create run row.', array( 'db_error' => $wpdb->last_error ) );
			return new WP_Error( 'db_error', __( 'The sync run could not be created.', 'woo-stock-sync' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a run's status (and optional extra columns).
	 *
	 * @param int    $run_id Run ID.
	 * @param string $status New status.
	 * @param array  $extra  Additional column => value pairs.
	 * @return void
	 */
	public function set_status( $run_id, $status, array $extra = array() ) {
		global $wpdb;

		$int_columns = array( 'rows_total', 'rows_planned', 'rows_skipped', 'rows_failed', 'rows_applied', 'created_by' );
		$data        = array_merge( array( 'status' => $status ), $extra );
		$formats     = array();
		foreach ( array_keys( $data ) as $column ) {
			$formats[] = in_array( $column, $int_columns, true ) ? '%d' : '%s';
		}

		$wpdb->update( $wpdb->prefix . 'wss_runs', $data, array( 'id' => (int) $run_id ), $formats, array( '%d' ) );
	}

	/**
	 * Mark a run failed with a run-level error message and log it.
	 *
	 * @param int    $run_id  Run ID.
	 * @param string $message Friendly error message.
	 * @return void
	 */
	public function fail_run( $run_id, $message ) {
		$this->set_status(
			$run_id,
			'failed',
			array(
				'error'       => $message,
				'finished_at' => current_time( 'mysql', true ),
			)
		);

		wss_log(
			'Run failed.',
			array(
				'run_id'  => (int) $run_id,
				'message' => $message,
			)
		);
	}

	/**
	 * The ID of a currently-running sync, if any.
	 *
	 * Phase 1 overlap guard based on run status; the atomic option lock is added in Phase 2.
	 *
	 * @return int Run ID, or 0 when none is active.
	 */
	public function active_run_id() {
		global $wpdb;

		$id = $wpdb->get_var(
			"SELECT id FROM {$wpdb->prefix}wss_runs WHERE status IN ( 'fetching', 'diffing', 'applying', 'rolling_back' ) ORDER BY id DESC LIMIT 1"
		);

		return (int) $id;
	}

	/**
	 * Validate settings, create a run, and queue the fetch.
	 *
	 * @param string $trigger      Trigger type: manual|schedule|cli.
	 * @param int    $created_by   User ID (0 for schedule/CLI).
	 * @param string $override_url Optional URL that overrides the configured source.
	 * @return int|WP_Error Run ID, or WP_Error on failure.
	 */
	public function begin_run( $trigger = 'manual', $created_by = 0, $override_url = '' ) {
		$settings = wss_get_settings();
		$mapping  = $settings['mapping'];

		if ( '' === $mapping['sku'] ||
			( '' === $mapping['stock'] && '' === $mapping['regular_price'] && '' === $mapping['sale_price'] ) ) {
			return new WP_Error( 'invalid_settings', __( 'Configure the column mapping in Settings before running a sync.', 'woo-stock-sync' ) );
		}

		if ( '' !== $override_url || 'url' === $settings['source_type'] ) {
			$url = ( '' !== $override_url ) ? $override_url : $settings['feed_url'];
			if ( '' === $url ) {
				return new WP_Error( 'invalid_settings', __( 'No feed URL is configured.', 'woo-stock-sync' ) );
			}
			$source_type = 'url';
			$source_ref  = $url;
		} else {
			if ( '' === $settings['upload_path'] || ! file_exists( $settings['upload_path'] ) ) {
				return new WP_Error( 'invalid_settings', __( 'No uploaded feed file was found. Upload one in Settings.', 'woo-stock-sync' ) );
			}
			$source_type = 'upload';
			$source_ref  = $settings['upload_path'];
		}

		if ( $this->active_run_id() ) {
			return new WP_Error( 'lock_held', __( 'A sync is already running. Wait for it to finish before starting another.', 'woo-stock-sync' ) );
		}

		$run_id = $this->create_run(
			array(
				'trigger_type' => $trigger,
				'source_type'  => $source_type,
				'source_ref'   => $source_ref,
				'mapping'      => $mapping,
				'created_by'   => (int) $created_by,
			)
		);
		if ( is_wp_error( $run_id ) ) {
			return $run_id;
		}

		as_enqueue_async_action( 'wss_fetch_run', array( $run_id ), 'woo-stock-sync' );

		return $run_id;
	}

	/**
	 * Background action: fetch the feed and stage its rows.
	 *
	 * @param int $run_id Run ID.
	 * @return void
	 */
	public function handle_fetch( $run_id ) {
		$run = wss_get_run( $run_id );
		if ( ! $run || 'pending' !== $run->status ) {
			return; // Only a pending run is fetched; keeps re-runs idempotent.
		}

		$this->set_status( $run_id, 'fetching' );
		wss_log( 'Run started fetching.', array( 'run_id' => (int) $run_id ), 'info' );

		$settings = wss_get_settings();
		$mapping  = json_decode( $run->mapping, true );
		if ( ! is_array( $mapping ) ) {
			$mapping = $settings['mapping'];
		}

		$feed   = new WSS_Feed();
		$source = $feed->open_run_source( $run->source_type, $run->source_ref, $settings );
		if ( is_wp_error( $source ) ) {
			$this->fail_run( $run_id, $source->get_error_message() );
			return;
		}

		$buffer = array();
		$emit   = function ( $row ) use ( &$buffer, $run_id ) {
			$buffer[] = $row;
			if ( count( $buffer ) >= self::STAGE_CHUNK ) {
				$this->insert_rows( $run_id, $buffer );
				$buffer = array();
			}
		};

		$result = $feed->parse( $source, $mapping, $settings, $emit );

		if ( ! empty( $buffer ) ) {
			$this->insert_rows( $run_id, $buffer );
		}

		if ( $source['temp'] && file_exists( $source['path'] ) ) {
			wp_delete_file( $source['path'] );
		}

		if ( is_wp_error( $result ) ) {
			$this->delete_rows( $run_id );
			$this->fail_run( $run_id, $result->get_error_message() );
			return;
		}

		$this->set_status( $run_id, 'diffing', array( 'rows_total' => (int) $result ) );
		as_enqueue_async_action( 'wss_diff_batch', array( $run_id ), 'woo-stock-sync' );

		wss_log(
			'Run staged and queued for diff.',
			array(
				'run_id'     => (int) $run_id,
				'rows_total' => (int) $result,
			),
			'info'
		);
	}

	/**
	 * Background action: diff a batch of staged rows against current product data.
	 *
	 * @param int $run_id Run ID.
	 * @return void
	 */
	public function handle_diff_batch( $run_id ) {
		$run = wss_get_run( $run_id );
		if ( ! $run || 'diffing' !== $run->status ) {
			return;
		}

		global $wpdb;

		$batch = (int) apply_filters( 'wss_batch_size', WSS_DEFAULT_BATCH_SIZE );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, sku, new_values FROM {$wpdb->prefix}wss_rows WHERE run_id = %d AND status = 'staged' ORDER BY id ASC LIMIT %d",
				$run_id,
				$batch
			)
		);

		foreach ( (array) $rows as $row ) {
			$this->diff_row( $row );
		}

		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wss_rows WHERE run_id = %d AND status = 'staged'",
				$run_id
			)
		);

		if ( $remaining > 0 ) {
			as_enqueue_async_action( 'wss_diff_batch', array( $run_id ), 'woo-stock-sync' );
			return;
		}

		$this->finalize_preview( $run_id );
	}

	/**
	 * Diff one staged row: resolve its SKU and compute its planned action.
	 *
	 * @param object $row Row with id, sku, new_values.
	 * @return void
	 */
	private function diff_row( $row ) {
		$new_values = json_decode( $row->new_values, true );
		if ( ! is_array( $new_values ) ) {
			$new_values = array();
		}

		$product_id = wc_get_product_id_by_sku( $row->sku );
		if ( ! $product_id ) {
			$this->update_row(
				$row->id,
				array(
					'status'  => 'skipped',
					'reason'  => 'unknown_sku',
					'message' => __( 'No product matches this SKU.', 'woo-stock-sync' ),
				)
			);
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			$this->update_row(
				$row->id,
				array(
					'product_id' => $product_id,
					'status'     => 'skipped',
					'reason'     => 'unknown_sku',
					'message'    => __( 'The matched product could not be loaded.', 'woo-stock-sync' ),
				)
			);
			return;
		}

		$diff = $this->compute_field_diff( $product, $new_values );

		$this->update_row(
			$row->id,
			array(
				'product_id'     => $product_id,
				'current_values' => wp_json_encode( $diff['current'] ),
				'status'         => $diff['status'],
				'reason'         => $diff['reason'],
				'message'        => $diff['message'],
			)
		);
	}

	/**
	 * Compare a product's current values to the feed's new values.
	 *
	 * @param WC_Product $product    Product.
	 * @param array      $new_values Field => new value.
	 * @return array status, reason, message, current (field => current value).
	 */
	private function compute_field_diff( $product, array $new_values ) {
		$current       = array();
		$changed       = false;
		$applicable    = 0;
		$stock_skipped = false;

		foreach ( $new_values as $field => $new ) {
			if ( 'stock' === $field ) {
				if ( ! $product->managing_stock() ) {
					$stock_skipped = true;
					continue;
				}
				$cur              = $product->get_stock_quantity();
				$current['stock'] = $cur;
				++$applicable;
				if ( null === $cur || (float) $cur !== (float) $new ) {
					$changed = true;
				}
			} elseif ( 'regular_price' === $field ) {
				$cur                      = $product->get_regular_price( 'edit' );
				$current['regular_price'] = $cur;
				++$applicable;
				if ( ! $this->price_equals( $cur, $new ) ) {
					$changed = true;
				}
			} elseif ( 'sale_price' === $field ) {
				$cur                   = $product->get_sale_price( 'edit' );
				$current['sale_price'] = $cur;
				++$applicable;
				if ( '' === $new ) {
					if ( '' !== (string) $cur ) {
						$changed = true;
					}
				} elseif ( ! $this->price_equals( $cur, $new ) ) {
					$changed = true;
				}
			}
		}

		if ( 0 === $applicable ) {
			if ( $stock_skipped ) {
				return array(
					'status'  => 'skipped',
					'reason'  => 'stock_not_managed',
					'message' => __( 'This product does not manage stock, so there is nothing to change.', 'woo-stock-sync' ),
					'current' => $current,
				);
			}
			return array(
				'status'  => 'no_change',
				'reason'  => '',
				'message' => '',
				'current' => $current,
			);
		}

		if ( $changed ) {
			return array(
				'status'  => 'planned',
				'reason'  => '',
				'message' => $stock_skipped ? __( 'Stock is not managed for this product and will be left unchanged.', 'woo-stock-sync' ) : '',
				'current' => $current,
			);
		}

		return array(
			'status'  => 'no_change',
			'reason'  => '',
			'message' => '',
			'current' => $current,
		);
	}

	/**
	 * Whether two price values are equal once normalized.
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 * @return bool
	 */
	private function price_equals( $a, $b ) {
		return wc_format_decimal( (string) $a, false, true ) === wc_format_decimal( (string) $b, false, true );
	}

	/**
	 * Finalize a diffed run: total the counts and move it to previewed.
	 *
	 * @param int $run_id Run ID.
	 * @return void
	 */
	private function finalize_preview( $run_id ) {
		$counts = $this->count_rows( $run_id );

		$this->set_status(
			$run_id,
			'previewed',
			array(
				'rows_planned' => $counts['planned'],
				'rows_skipped' => $counts['skipped'],
				'rows_failed'  => $counts['failed'],
			)
		);

		wss_log(
			'Run previewed.',
			array(
				'run_id' => (int) $run_id,
				'counts' => $counts,
			),
			'info'
		);
	}

	/**
	 * Count a run's rows grouped into status buckets.
	 *
	 * @param int $run_id Run ID.
	 * @return array Bucket => count.
	 */
	public function count_rows( $run_id ) {
		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS n FROM {$wpdb->prefix}wss_rows WHERE run_id = %d GROUP BY status",
				$run_id
			),
			ARRAY_A
		);

		$map = array();
		foreach ( (array) $results as $result ) {
			$map[ $result['status'] ] = (int) $result['n'];
		}

		$buckets = array( 'planned', 'no_change', 'skipped', 'failed', 'applied', 'apply_failed', 'rolled_back', 'rollback_failed' );
		$counts  = array();
		foreach ( $buckets as $bucket ) {
			$counts[ $bucket ] = isset( $map[ $bucket ] ) ? $map[ $bucket ] : 0;
		}

		return $counts;
	}

	/**
	 * Update a single row.
	 *
	 * @param int   $row_id Row ID.
	 * @param array $data   Column => value.
	 * @return void
	 */
	private function update_row( $row_id, array $data ) {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql', true );

		$formats = array();
		foreach ( array_keys( $data ) as $column ) {
			$formats[] = ( 'product_id' === $column ) ? '%d' : '%s';
		}

		$wpdb->update( $wpdb->prefix . 'wss_rows', $data, array( 'id' => (int) $row_id ), $formats, array( '%d' ) );
	}

	/**
	 * Insert a chunk of staged rows in a single query.
	 *
	 * @param int   $run_id Run ID.
	 * @param array $rows   Row results from the parser.
	 * @return void
	 */
	private function insert_rows( $run_id, array $rows ) {
		if ( empty( $rows ) ) {
			return;
		}

		global $wpdb;

		$now          = current_time( 'mysql', true );
		$values       = array();
		$placeholders = array();

		foreach ( $rows as $row ) {
			$placeholders[] = '(%d, %d, %s, %s, %s, %s, %s, %s)';
			$values[]       = (int) $run_id;
			$values[]       = (int) $row['row_num'];
			$values[]       = substr( (string) $row['sku'], 0, 100 );
			$values[]       = wp_json_encode( $row['new_values'] );
			$values[]       = $row['status'];
			$values[]       = $row['reason'];
			$values[]       = $row['message'];
			$values[]       = $now;
		}

		$sql = "INSERT INTO {$wpdb->prefix}wss_rows (run_id, row_num, sku, new_values, status, reason, message, updated_at) VALUES " . implode( ', ', $placeholders );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders come from a fixed template; every value is bound through prepare().
		$result = $wpdb->query( $wpdb->prepare( $sql, $values ) );

		if ( false === $result ) {
			wss_log(
				'Failed to insert staged rows.',
				array(
					'run_id'   => (int) $run_id,
					'db_error' => $wpdb->last_error,
				)
			);
		}
	}

	/**
	 * Delete all rows staged for a run (used when a run fails during staging).
	 *
	 * @param int $run_id Run ID.
	 * @return void
	 */
	private function delete_rows( $run_id ) {
		global $wpdb;

		$wpdb->delete( $wpdb->prefix . 'wss_rows', array( 'run_id' => (int) $run_id ), array( '%d' ) );
	}
}
