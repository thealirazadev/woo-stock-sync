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

	<?php if ( $wss_in_prog ) : ?>
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
