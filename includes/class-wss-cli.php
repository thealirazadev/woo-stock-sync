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
