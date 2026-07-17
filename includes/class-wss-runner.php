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
