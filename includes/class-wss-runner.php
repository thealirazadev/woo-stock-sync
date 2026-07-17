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
	 * Option name for the single-run mutex.
	 */
	const LOCK_OPTION = 'wss_active_run';

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
		add_action( 'wss_apply_batch', array( $this, 'handle_apply_batch' ) );
		add_action( 'wss_rollback_batch', array( $this, 'handle_rollback_batch' ) );
		add_action( 'wss_scheduled_fetch', array( $this, 'handle_scheduled_fetch' ) );
		add_action( 'update_option_wss_settings', array( $this, 'resync_schedule' ) );
		add_action( 'add_option_wss_settings', array( $this, 'resync_schedule' ) );
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

		$this->release_lock( $run_id );

		wss_log(
			'Run failed.',
			array(
				'run_id'  => (int) $run_id,
				'message' => $message,
			)
		);
	}

	/**
	 * Try to acquire the single-run lock for a run.
	 *
	 * Atomic thanks to the unique key on option_name: add_option only succeeds when the option does
	 * not yet exist. Re-entrant for the run that already holds it (so a resumed run can reacquire).
	 *
	 * @param int $run_id Run ID.
	 * @return bool True if the run now holds the lock.
	 */
	public function acquire_lock( $run_id ) {
		$run_id = (int) $run_id;

		if ( add_option( self::LOCK_OPTION, $run_id, '', 'no' ) ) {
			return true;
		}

		return $this->get_lock_holder() === $run_id;
	}

	/**
	 * The run ID currently holding the lock, or 0.
	 *
	 * @return int
	 */
	public function get_lock_holder() {
		return (int) get_option( self::LOCK_OPTION, 0 );
	}

	/**
	 * Release the lock if it is held by the given run.
	 *
	 * @param int $run_id Run ID.
	 * @return void
	 */
	public function release_lock( $run_id ) {
		if ( $this->get_lock_holder() === (int) $run_id ) {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Force-release the lock regardless of holder (stale-lock recovery).
	 *
	 * @return void
	 */
	public function force_release_lock() {
		delete_option( self::LOCK_OPTION );
	}

	/**
	 * Whether a processing run has stalled: no row progress for 10+ minutes and no pending action.
	 *
	 * @param object $run Run row.
	 * @return bool
	 */
	public function is_stalled( $run ) {
		$hooks = array(
			'applying'     => 'wss_apply_batch',
			'rolling_back' => 'wss_rollback_batch',
			'diffing'      => 'wss_diff_batch',
		);

		if ( ! isset( $hooks[ $run->status ] ) ) {
			return false;
		}

		global $wpdb;

		$last = $wpdb->get_var(
			$wpdb->prepare( "SELECT MAX(updated_at) FROM {$wpdb->prefix}wss_rows WHERE run_id = %d", (int) $run->id )
		);

		$reference = $last ? $last : $run->created_at;
		$idle_for  = time() - (int) strtotime( $reference . ' UTC' );
		if ( $idle_for < 10 * MINUTE_IN_SECONDS ) {
			return false;
		}

		if ( function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( $hooks[ $run->status ], array( (int) $run->id ), 'woo-stock-sync' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether a stale lock can be released: held for 15+ minutes with no row progress.
	 *
	 * @return bool
	 */
	public function lock_is_stale() {
		$holder = $this->get_lock_holder();
		if ( ! $holder ) {
			return false;
		}

		$run = wss_get_run( $holder );
		if ( ! $run ) {
			return true;
		}

		global $wpdb;

		$last = $wpdb->get_var(
			$wpdb->prepare( "SELECT MAX(updated_at) FROM {$wpdb->prefix}wss_rows WHERE run_id = %d", (int) $holder )
		);

		$reference = $last ? $last : $run->created_at;

		return ( time() - (int) strtotime( $reference . ' UTC' ) ) > 15 * MINUTE_IN_SECONDS;
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

		if ( $this->get_lock_holder() ) {
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
	 * Register/update/remove the recurring scheduled fetch to match the setting.
	 *
	 * Unschedules any existing action first, so exactly one recurring action exists and a changed
	 * interval reschedules cleanly.
	 *
	 * @param string $schedule manual|hourly|twicedaily|daily.
	 * @return void
	 */
	public function sync_schedule( $schedule ) {
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		$intervals = array(
			'hourly'     => HOUR_IN_SECONDS,
			'twicedaily' => 12 * HOUR_IN_SECONDS,
			'daily'      => DAY_IN_SECONDS,
		);

		as_unschedule_all_actions( 'wss_scheduled_fetch', array(), 'woo-stock-sync' );

		if ( isset( $intervals[ $schedule ] ) ) {
			as_schedule_recurring_action(
				time() + $intervals[ $schedule ],
				$intervals[ $schedule ],
				'wss_scheduled_fetch',
				array(),
				'woo-stock-sync'
			);
		}
	}

	/**
	 * Re-sync the schedule whenever settings are saved.
	 *
	 * @return void
	 */
	public function resync_schedule() {
		$settings = wss_get_settings();
		$this->sync_schedule( $settings['schedule'] );
	}

	/**
	 * Recurring action: start a scheduled run from the saved settings.
	 *
	 * @return void
	 */
	public function handle_scheduled_fetch() {
		$run_id = $this->begin_run( 'schedule', 0 );

		if ( is_wp_error( $run_id ) ) {
			wss_log(
				'Scheduled fetch skipped.',
				array(
					'reason'  => $run_id->get_error_code(),
					'message' => $run_id->get_error_message(),
				),
				'warning'
			);
		}
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

		if ( ! $this->acquire_lock( $run_id ) ) {
			$this->fail_run( $run_id, __( 'Another sync is already running. This run was not started.', 'woo-stock-sync' ) );
			return;
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

		if ( $this->is_locked( $product ) ) {
			$this->update_row(
				$row->id,
				array(
					'product_id' => $product_id,
					'status'     => 'skipped',
					'reason'     => 'locked',
					'message'    => __( 'This product is locked from stock sync.', 'woo-stock-sync' ),
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
	 * Whether a product (or its parent, for a variation) is locked from stock sync.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	private function is_locked( $product ) {
		if ( 'yes' === get_post_meta( $product->get_id(), '_wss_locked', true ) ) {
			return true;
		}

		$parent_id = (int) $product->get_parent_id();
		if ( $parent_id && 'yes' === get_post_meta( $parent_id, '_wss_locked', true ) ) {
			return true;
		}

		return false;
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

		// A previewed run awaiting review must not hold the lock.
		$this->release_lock( $run_id );

		// Scheduled runs may auto-apply when the owner has opted in.
		$run = wss_get_run( $run_id );
		if ( $run && 'schedule' === $run->trigger_type ) {
			$settings = wss_get_settings();
			if ( 'auto_apply' === $settings['scheduled_mode'] ) {
				as_enqueue_async_action( 'wss_apply_batch', array( $run_id ), 'woo-stock-sync' );
			}
		}

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
	 * Background action: apply a batch of planned rows via WooCommerce CRUD.
	 *
	 * Runs only from previewed (first batch) or applying (resume). The cursor is derived from the
	 * remaining planned rows, so a batch that dies mid-way resumes with no row applied twice.
	 *
	 * @param int $run_id Run ID.
	 * @return void
	 */
	public function handle_apply_batch( $run_id ) {
		$run = wss_get_run( $run_id );
		if ( ! $run || ! in_array( $run->status, array( 'previewed', 'applying' ), true ) ) {
			return;
		}

		if ( ! $this->acquire_lock( $run_id ) ) {
			wss_log(
				'Apply skipped; the sync lock is held by another run.',
				array(
					'run_id' => (int) $run_id,
					'holder' => $this->get_lock_holder(),
				),
				'warning'
			);
			return;
		}

		if ( 'previewed' === $run->status ) {
			$this->set_status( $run_id, 'applying' );
		}

		global $wpdb;

		$batch = (int) apply_filters( 'wss_batch_size', WSS_DEFAULT_BATCH_SIZE );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wss_rows WHERE run_id = %d AND status = 'planned' ORDER BY id ASC LIMIT %d",
				$run_id,
				$batch
			)
		);

		foreach ( (array) $rows as $row ) {
			$this->apply_row( $row );
		}

		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wss_rows WHERE run_id = %d AND status = 'planned'",
				$run_id
			)
		);

		if ( $remaining > 0 ) {
			as_enqueue_async_action( 'wss_apply_batch', array( $run_id ), 'woo-stock-sync' );
			return;
		}

		$this->finalize_applied( $run_id );
	}

	/**
	 * Apply one planned row. A thrown error is caught and recorded; the batch continues.
	 *
	 * @param object $row Row.
	 * @return void
	 */
	private function apply_row( $row ) {
		$new_values = json_decode( $row->new_values, true );
		$new_values = is_array( $new_values ) ? $new_values : array();

		$product = wc_get_product( (int) $row->product_id );
		if ( ! $product ) {
			$this->update_row(
				$row->id,
				array(
					'status'  => 'apply_failed',
					'reason'  => 'wc_error',
					'message' => __( 'The product could not be loaded.', 'woo-stock-sync' ),
				)
			);
			return;
		}

		// Re-check the lock at apply time: a product may have been locked after the preview.
		if ( $this->is_locked( $product ) ) {
			$this->update_row(
				$row->id,
				array(
					'status'  => 'skipped',
					'reason'  => 'locked',
					'message' => __( 'This product is locked from stock sync.', 'woo-stock-sync' ),
				)
			);
			return;
		}

		$this->snapshot_product( (int) $row->run_id, $product );

		try {
			$this->write_values( $product, $new_values );
			$product->save();

			$this->update_row(
				$row->id,
				array(
					'status'  => 'applied',
					'reason'  => '',
					'message' => '',
				)
			);
		} catch ( \Throwable $error ) {
			wss_log(
				'Apply failed for a row.',
				array(
					'row_id' => (int) $row->id,
					'error'  => $error->getMessage(),
				)
			);
			$this->update_row(
				$row->id,
				array(
					'status'  => 'apply_failed',
					'reason'  => 'wc_error',
					'message' => $error->getMessage(),
				)
			);
		}
	}

	/**
	 * Capture a product's prior values once per run, before the first write.
	 *
	 * Insert-once via the unique (run_id, product_id) key, so a resumed batch never overwrites the
	 * true prior value that rollback depends on.
	 *
	 * @param int        $run_id  Run ID.
	 * @param WC_Product $product Product.
	 * @return void
	 */
	private function snapshot_product( $run_id, $product ) {
		global $wpdb;

		$prior = array(
			'stock_quantity' => $product->managing_stock() ? $product->get_stock_quantity() : null,
			'regular_price'  => $product->get_regular_price( 'edit' ),
			'sale_price'     => $product->get_sale_price( 'edit' ),
		);

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}wss_snapshots (run_id, product_id, prior_values, restored) VALUES (%d, %d, %s, 0)",
				(int) $run_id,
				(int) $product->get_id(),
				wp_json_encode( $prior )
			)
		);

		if ( false === $result ) {
			wss_log(
				'Failed to write a product snapshot.',
				array(
					'run_id'     => (int) $run_id,
					'product_id' => (int) $product->get_id(),
					'db_error'   => $wpdb->last_error,
				)
			);
		}
	}

	/**
	 * Write mapped values onto a product via CRUD setters.
	 *
	 * @param WC_Product $product    Product.
	 * @param array      $new_values Field => value.
	 * @return void
	 */
	private function write_values( $product, array $new_values ) {
		foreach ( $new_values as $field => $value ) {
			if ( 'stock' === $field ) {
				if ( $product->managing_stock() ) {
					$product->set_stock_quantity( $value );
				}
			} elseif ( 'regular_price' === $field ) {
				$product->set_regular_price( $value );
			} elseif ( 'sale_price' === $field ) {
				$product->set_sale_price( '' === $value ? '' : $value );
			}
		}
	}

	/**
	 * Finalize an applied run: total the counts, mark applied, and release the lock.
	 *
	 * @param int $run_id Run ID.
	 * @return void
	 */
	private function finalize_applied( $run_id ) {
		$counts = $this->count_rows( $run_id );

		$this->set_status(
			$run_id,
			'applied',
			array(
				'rows_planned' => $counts['planned'],
				'rows_applied' => $counts['applied'],
				'rows_skipped' => $counts['skipped'],
				'rows_failed'  => $counts['failed'] + $counts['apply_failed'],
				'finished_at'  => current_time( 'mysql', true ),
			)
		);

		$this->release_lock( $run_id );

		wss_log(
			'Run applied.',
			array(
				'run_id' => (int) $run_id,
				'counts' => $counts,
			),
			'info'
		);
	}

	/**
	 * The ID of the most recent applied run, or 0.
	 *
	 * @return int
	 */
	public function latest_applied_run_id() {
		global $wpdb;

		$id = $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}wss_runs WHERE status = 'applied' ORDER BY id DESC LIMIT 1" );

		return (int) $id;
	}

	/**
	 * Background action: roll a run back from its snapshots, in batches.
	 *
	 * @param int $run_id Run ID.
	 * @return void
	 */
	public function handle_rollback_batch( $run_id ) {
		$run = wss_get_run( $run_id );
		if ( ! $run || ! in_array( $run->status, array( 'applied', 'rolling_back' ), true ) ) {
			return;
		}

		if ( 'applied' === $run->status && $this->latest_applied_run_id() !== (int) $run_id ) {
			return; // Only the most recent applied run can be rolled back.
		}

		if ( ! $this->acquire_lock( $run_id ) ) {
			wss_log(
				'Rollback skipped; the sync lock is held by another run.',
				array(
					'run_id' => (int) $run_id,
					'holder' => $this->get_lock_holder(),
				),
				'warning'
			);
			return;
		}

		if ( 'applied' === $run->status ) {
			$this->set_status( $run_id, 'rolling_back' );
		}

		global $wpdb;

		$batch = (int) apply_filters( 'wss_batch_size', WSS_DEFAULT_BATCH_SIZE );
		$snaps = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wss_snapshots WHERE run_id = %d AND restored = 0 ORDER BY id ASC LIMIT %d",
				$run_id,
				$batch
			)
		);

		foreach ( (array) $snaps as $snap ) {
			$this->rollback_snapshot( $run_id, $snap );
		}

		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wss_snapshots WHERE run_id = %d AND restored = 0",
				$run_id
			)
		);

		if ( $remaining > 0 ) {
			as_enqueue_async_action( 'wss_rollback_batch', array( $run_id ), 'woo-stock-sync' );
			return;
		}

		$this->set_status( $run_id, 'rolled_back', array( 'finished_at' => current_time( 'mysql', true ) ) );
		$this->release_lock( $run_id );

		wss_log( 'Run rolled back.', array( 'run_id' => (int) $run_id ), 'info' );
	}

	/**
	 * Restore one snapshot's prior values. Marks the snapshot processed either way.
	 *
	 * @param int    $run_id Run ID.
	 * @param object $snap   Snapshot row.
	 * @return void
	 */
	private function rollback_snapshot( $run_id, $snap ) {
		$prior      = json_decode( $snap->prior_values, true );
		$prior      = is_array( $prior ) ? $prior : array();
		$product_id = (int) $snap->product_id;

		$this->mark_snapshot_restored( $snap->id );

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			$this->set_rollback_row_status( $run_id, $product_id, 'rollback_failed', 'product_missing', __( 'The product no longer exists.', 'woo-stock-sync' ) );
			return;
		}

		try {
			if ( array_key_exists( 'stock_quantity', $prior ) && null !== $prior['stock_quantity'] && $product->managing_stock() ) {
				$product->set_stock_quantity( $prior['stock_quantity'] );
			}
			if ( array_key_exists( 'regular_price', $prior ) ) {
				$product->set_regular_price( (string) $prior['regular_price'] );
			}
			if ( array_key_exists( 'sale_price', $prior ) ) {
				$sale = ( null === $prior['sale_price'] ) ? '' : (string) $prior['sale_price'];
				$product->set_sale_price( $sale );
			}
			$product->save();

			$this->set_rollback_row_status( $run_id, $product_id, 'rolled_back', '', '' );
		} catch ( \Throwable $error ) {
			wss_log(
				'Rollback failed for a product.',
				array(
					'run_id'     => (int) $run_id,
					'product_id' => $product_id,
					'error'      => $error->getMessage(),
				)
			);
			$this->set_rollback_row_status( $run_id, $product_id, 'rollback_failed', 'wc_error', $error->getMessage() );
		}
	}

	/**
	 * Mark a snapshot as restored so the cursor advances.
	 *
	 * @param int $snapshot_id Snapshot ID.
	 * @return void
	 */
	private function mark_snapshot_restored( $snapshot_id ) {
		global $wpdb;

		$wpdb->update( $wpdb->prefix . 'wss_snapshots', array( 'restored' => 1 ), array( 'id' => (int) $snapshot_id ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * Update the applied row for a product to a rollback status.
	 *
	 * @param int    $run_id     Run ID.
	 * @param int    $product_id Product ID.
	 * @param string $status     New row status.
	 * @param string $reason     Reason.
	 * @param string $message    Message.
	 * @return void
	 */
	private function set_rollback_row_status( $run_id, $product_id, $status, $reason, $message ) {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'wss_rows',
			array(
				'status'     => $status,
				'reason'     => $reason,
				'message'    => $message,
				'updated_at' => current_time( 'mysql', true ),
			),
			array(
				'run_id'     => (int) $run_id,
				'product_id' => (int) $product_id,
				'status'     => 'applied',
			),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d', '%d', '%s' )
		);
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
