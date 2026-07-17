<?php
/**
 * WP-CLI commands.
 *
 * @package WooStockSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Implements `wp wss <subcommand>`: fetch, dry-run, apply, rollback, runs.
 */
class WSS_CLI {

	/**
	 * The runner instance.
	 *
	 * @return WSS_Runner
	 */
	private function runner() {
		return WSS_Plugin::instance()->runner();
	}

	/**
	 * Create a run from the saved settings, fetch, stage, and diff it synchronously.
	 *
	 * ## OPTIONS
	 *
	 * [--source=<url>]
	 * : Override the configured feed URL for this run.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wss fetch
	 *     wp wss fetch --source=https://example.com/feed.csv
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function fetch( $args, $assoc_args ) {
		unset( $args );

		$runner = $this->runner();
		$source = isset( $assoc_args['source'] ) ? esc_url_raw( $assoc_args['source'] ) : '';

		$run_id = $runner->begin_run( 'cli', 0, $source );
		if ( is_wp_error( $run_id ) ) {
			WP_CLI::error( $run_id->get_error_message() );
		}

		$runner->handle_fetch( $run_id );

		$guard = 0;
		while ( 'diffing' === wss_get_run( $run_id )->status && $guard < 1000000 ) {
			$runner->handle_diff_batch( $run_id );
			++$guard;
		}

		$run = wss_get_run( $run_id );
		if ( 'failed' === $run->status ) {
			WP_CLI::error( $run->error );
		}

		$counts = $runner->count_rows( $run_id );
		WP_CLI::success(
			sprintf(
				'Run %d previewed. Total: %d, planned: %d, no change: %d, skipped: %d, failed: %d.',
				$run_id,
				(int) $run->rows_total,
				$counts['planned'],
				$counts['no_change'],
				$counts['skipped'],
				$counts['failed']
			)
		);
	}

	/**
	 * Print the diff rows for a run.
	 *
	 * ## OPTIONS
	 *
	 * [--run=<id>]
	 * : Run ID. Defaults to the latest previewed run.
	 *
	 * [--status=<status>]
	 * : Filter to a single row status (planned, no_change, skipped, failed, applied).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * @subcommand dry-run
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function dry_run( $args, $assoc_args ) {
		unset( $args );

		$run_id = isset( $assoc_args['run'] ) ? (int) $assoc_args['run'] : $this->latest_run_by_status( 'previewed' );
		$run    = wss_get_run( $run_id );

		if ( ! $run ) {
			WP_CLI::error( 'Run not found (invalid_run).' );
		}

		if ( in_array( $run->status, array( 'pending', 'fetching', 'diffing' ), true ) ) {
			WP_CLI::error( 'This run has no diff yet (invalid_run_state).' );
		}

		$status_filter = isset( $assoc_args['status'] ) ? sanitize_key( $assoc_args['status'] ) : '';
		$format        = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		$items = $this->build_diff_items( $run_id, $status_filter );

		WP_CLI\Utils\format_items( $format, $items, array( 'product_id', 'sku', 'field', 'current', 'new', 'status', 'reason' ) );
	}

	/**
	 * Apply (or resume) a previewed run synchronously.
	 *
	 * ## OPTIONS
	 *
	 * --run=<id>
	 * : The run to apply.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wss apply --run=42 --yes
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function apply( $args, $assoc_args ) {
		unset( $args );

		$runner = $this->runner();
		$run_id = isset( $assoc_args['run'] ) ? (int) $assoc_args['run'] : 0;
		$run    = wss_get_run( $run_id );

		if ( ! $run ) {
			WP_CLI::error( 'Run not found (invalid_run).' );
		}

		$resumable = ( 'previewed' === $run->status ) || ( 'applying' === $run->status && $runner->is_stalled( $run ) );
		if ( ! $resumable ) {
			WP_CLI::error( 'This run cannot be applied in its current state (invalid_run_state).' );
		}

		$holder = $runner->get_lock_holder();
		if ( $holder && $holder !== $run_id ) {
			WP_CLI::error( 'Another sync is already running (lock_held).' );
		}

		WP_CLI::confirm( sprintf( 'Apply run %d now?', $run_id ), $assoc_args );

		$guard = 0;
		do {
			$runner->handle_apply_batch( $run_id );
			$run = wss_get_run( $run_id );
			WP_CLI::log( sprintf( 'Processed %d of %d rows.', $runner->count_processed( $run ), (int) $run->rows_total ) );
			++$guard;
		} while ( 'applying' === $run->status && $guard < 1000000 );

		$counts = $runner->count_rows( $run_id );
		$failed = $counts['failed'] + $counts['apply_failed'];
		WP_CLI::success(
			sprintf(
				'Run %d applied. Applied: %d, skipped: %d, failed: %d%s.',
				$run_id,
				$counts['applied'],
				$counts['skipped'],
				$failed,
				$this->reason_summary( $run_id )
			)
		);
	}

	/**
	 * Roll back the most recent applied run synchronously.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wss rollback --yes
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function rollback( $args, $assoc_args ) {
		unset( $args );

		$runner = $this->runner();
		$run_id = $runner->latest_applied_run_id();

		if ( ! $run_id ) {
			WP_CLI::error( 'There is no applied run to roll back (nothing_to_rollback).' );
		}

		$holder = $runner->get_lock_holder();
		if ( $holder && $holder !== $run_id ) {
			WP_CLI::error( 'Another sync is already running (lock_held).' );
		}

		WP_CLI::confirm( sprintf( 'Roll back run %d? This overwrites any manual edits made since it was applied.', $run_id ), $assoc_args );

		$guard = 0;
		do {
			$runner->handle_rollback_batch( $run_id );
			$run = wss_get_run( $run_id );
			++$guard;
		} while ( 'rolling_back' === $run->status && $guard < 1000000 );

		$counts = $runner->count_rows( $run_id );
		WP_CLI::success(
			sprintf(
				'Run %d rolled back. Rolled back: %d, rollback failed: %d.',
				$run_id,
				$counts['rolled_back'],
				$counts['rollback_failed']
			)
		);
	}

	/**
	 * Build a " (reason: count, ...)" summary from a run's row reasons.
	 *
	 * @param int $run_id Run ID.
	 * @return string
	 */
	private function reason_summary( $run_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT reason, COUNT(*) AS n FROM {$wpdb->prefix}wss_rows WHERE run_id = %d AND reason IS NOT NULL AND reason <> '' GROUP BY reason ORDER BY reason ASC",
				$run_id
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return '';
		}

		$parts = array();
		foreach ( $rows as $row ) {
			$parts[] = sprintf( '%s: %d', $row['reason'], (int) $row['n'] );
		}

		return ' (' . implode( ', ', $parts ) . ')';
	}

	/**
	 * The most recent run in a given status, or 0.
	 *
	 * @param string $status Run status.
	 * @return int
	 */
	private function latest_run_by_status( $status ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wss_runs WHERE status = %s ORDER BY id DESC LIMIT 1", $status )
		);
	}

	/**
	 * Build flat diff records (one per changed field, or one per skipped/failed row).
	 *
	 * @param int    $run_id        Run ID.
	 * @param string $status_filter Optional row status filter.
	 * @return array
	 */
	private function build_diff_items( $run_id, $status_filter ) {
		global $wpdb;

		if ( '' !== $status_filter ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}wss_rows WHERE run_id = %d AND status = %s ORDER BY row_num ASC",
					$run_id,
					$status_filter
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wss_rows WHERE run_id = %d ORDER BY row_num ASC", $run_id )
			);
		}

		$multi_field = array( 'planned', 'no_change', 'applied', 'apply_failed', 'rolled_back', 'rollback_failed' );
		$items       = array();

		foreach ( (array) $rows as $row ) {
			$new_values = json_decode( $row->new_values, true );
			$current    = json_decode( (string) $row->current_values, true );
			$new_values = is_array( $new_values ) ? $new_values : array();
			$current    = is_array( $current ) ? $current : array();

			if ( in_array( $row->status, $multi_field, true ) && ! empty( $new_values ) ) {
				foreach ( $new_values as $field => $value ) {
					$items[] = array(
						'product_id' => (int) $row->product_id,
						'sku'        => $row->sku,
						'field'      => $field,
						'current'    => array_key_exists( $field, $current ) ? $current[ $field ] : '',
						'new'        => $value,
						'status'     => $row->status,
						'reason'     => (string) $row->reason,
					);
				}
				continue;
			}

			$items[] = array(
				'product_id' => (int) $row->product_id,
				'sku'        => $row->sku,
				'field'      => '',
				'current'    => '',
				'new'        => '',
				'status'     => $row->status,
				'reason'     => (string) $row->reason,
			);
		}

		return $items;
	}
}
