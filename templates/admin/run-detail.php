<?php
/**
 * Run detail screen markup.
 *
 * Rendered inside WSS_Admin::render_run_detail(); has access to $run (object),
 * $table (WSS_Rows_Table), and $status_filter (string).
 *
 * @package WooStockSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Provided by the including method.
 *
 * @var object         $run
 * @var WSS_Rows_Table $table
 * @var string         $status_filter
 * @var bool           $is_stalled
 * @var bool           $is_latest_applied
 */

$wss_total     = (int) $run->rows_total;
$wss_planned   = (int) $run->rows_planned;
$wss_skipped   = (int) $run->rows_skipped;
$wss_failed    = (int) $run->rows_failed;
$wss_applied   = (int) $run->rows_applied;
$wss_no_change = max( 0, $wss_total - $wss_planned - $wss_skipped - $wss_failed - $wss_applied );
$wss_mapping   = json_decode( (string) $run->mapping, true );
$wss_in_prog   = in_array( $run->status, array( 'pending', 'fetching', 'diffing', 'applying', 'rolling_back' ), true );
?>
<div class="wrap wss-wrap">
	<h1 class="wp-heading-inline">
		<?php
		printf(
			/* translators: %d: run ID. */
			esc_html__( 'Sync Run #%d', 'woo-stock-sync' ),
			absint( $run->id )
		);
		?>
	</h1>
	<a href="<?php echo esc_url( WSS_Admin::page_url() ); ?>" class="page-title-action"><?php esc_html_e( 'Back to runs', 'woo-stock-sync' ); ?></a>
	<hr class="wp-header-end" />

	<?php if ( '' !== (string) $run->error ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $run->error ); ?></p></div>
	<?php endif; ?>

	<div class="wss-run-summary">
		<p>
			<strong><?php esc_html_e( 'Status:', 'woo-stock-sync' ); ?></strong>
			<?php echo wp_kses_post( wss_status_badge( $run->status ) ); ?>
		</p>
		<table class="widefat striped wss-run-meta">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Trigger', 'woo-stock-sync' ); ?></th>
					<td><?php echo esc_html( $run->trigger_type ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Source', 'woo-stock-sync' ); ?></th>
					<td><?php echo esc_html( wss_format_source( $run ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Started', 'woo-stock-sync' ); ?></th>
					<td>
						<?php
						echo ( empty( $run->created_at ) || '0000-00-00 00:00:00' === $run->created_at )
							? '&mdash;'
							: esc_html( wp_date( 'Y-m-d H:i', strtotime( $run->created_at . ' UTC' ) ) );
						?>
					</td>
				</tr>
			</tbody>
		</table>

		<p class="wss-counts">
			<?php
			printf(
				/* translators: 1: total, 2: planned, 3: no-change, 4: skipped, 5: failed, 6: applied. */
				esc_html__( 'Total: %1$d, planned: %2$d, no change: %3$d, skipped: %4$d, failed: %5$d, applied: %6$d.', 'woo-stock-sync' ),
				absint( $wss_total ),
				absint( $wss_planned ),
				absint( $wss_no_change ),
				absint( $wss_skipped ),
				absint( $wss_failed ),
				absint( $wss_applied )
			);
			?>
		</p>

		<?php if ( is_array( $wss_mapping ) ) : ?>
			<details class="wss-mapping-snapshot">
				<summary><?php esc_html_e( 'Column mapping used', 'woo-stock-sync' ); ?></summary>
				<ul>
					<?php foreach ( $wss_mapping as $wss_field => $wss_column ) : ?>
						<li>
							<code><?php echo esc_html( $wss_field ); ?></code>
							&rarr;
							<code><?php echo '' !== $wss_column ? esc_html( $wss_column ) : esc_html__( '(not mapped)', 'woo-stock-sync' ); ?></code>
						</li>
					<?php endforeach; ?>
				</ul>
			</details>
		<?php endif; ?>
	</div>

	<?php if ( 'previewed' === $run->status ) : ?>
		<?php
		$wss_age     = ( empty( $run->created_at ) || '0000-00-00 00:00:00' === $run->created_at )
			? ''
			: human_time_diff( strtotime( $run->created_at . ' UTC' ), time() );
		$wss_confirm = ( '' !== $wss_age )
			? sprintf(
				/* translators: %s: human-readable age, e.g. "3 hours". */
				__( 'This preview was created %s ago. Apply it now?', 'woo-stock-sync' ),
				$wss_age
			)
			: __( 'Apply this sync now?', 'woo-stock-sync' );
		?>
		<p class="wss-actions">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wss-inline-form" data-wss-confirm="<?php echo esc_attr( $wss_confirm ); ?>">
				<input type="hidden" name="action" value="wss_apply_run" />
				<input type="hidden" name="run_id" value="<?php echo esc_attr( $run->id ); ?>" />
				<?php wp_nonce_field( 'wss_apply_run', 'wss_apply_run_nonce' ); ?>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply sync', 'woo-stock-sync' ); ?></button>
			</form>
		</p>
	<?php endif; ?>

	<?php if ( ! empty( $is_latest_applied ) ) : ?>
		<p class="wss-actions">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wss-inline-form" data-wss-confirm="<?php echo esc_attr__( 'Roll back this sync? This restores the stock and prices captured before the sync and overwrites any manual edits made since.', 'woo-stock-sync' ); ?>">
				<input type="hidden" name="action" value="wss_rollback_run" />
				<input type="hidden" name="run_id" value="<?php echo esc_attr( $run->id ); ?>" />
				<?php wp_nonce_field( 'wss_rollback_run', 'wss_rollback_run_nonce' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Roll back sync', 'woo-stock-sync' ); ?></button>
			</form>
		</p>
	<?php endif; ?>

	<?php if ( ! empty( $is_stalled ) ) : ?>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'This run appears to have stalled. You can resume it from where it stopped.', 'woo-stock-sync' ); ?></p>
		</div>
		<p class="wss-actions">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wss-inline-form" data-wss-confirm="<?php echo esc_attr__( 'Resume this sync from where it stopped?', 'woo-stock-sync' ); ?>">
				<input type="hidden" name="action" value="wss_apply_run" />
				<input type="hidden" name="run_id" value="<?php echo esc_attr( $run->id ); ?>" />
				<?php wp_nonce_field( 'wss_apply_run', 'wss_apply_run_nonce' ); ?>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Resume sync', 'woo-stock-sync' ); ?></button>
			</form>
		</p>
	<?php elseif ( $wss_in_prog ) : ?>
		<p class="wss-progress-note" role="status">
			<?php esc_html_e( 'This run is still processing. Reload the page to see the latest results.', 'woo-stock-sync' ); ?>
		</p>
	<?php endif; ?>

	<h2 class="screen-reader-text"><?php esc_html_e( 'Row results', 'woo-stock-sync' ); ?></h2>
	<?php $table->views(); ?>
	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( WSS_Admin::PAGE ); ?>" />
		<input type="hidden" name="run" value="<?php echo esc_attr( $run->id ); ?>" />
		<?php $table->display(); ?>
	</form>
</div>
