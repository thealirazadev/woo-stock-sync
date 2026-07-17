<?php
/**
 * Settings screen markup.
 *
 * Rendered inside WSS_Settings::render(); has access to $this (WSS_Settings),
 * $settings (array), and $errors (array).
 *
 * @package WooStockSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * These variables are provided by the including method.
 *
 * @var WSS_Settings $this
 * @var array        $settings
 * @var array        $errors
 */

$wss_has_secret = ( '' !== $settings['auth_header_value'] );
?>

<?php if ( ! empty( $errors ) ) : ?>
	<div class="notice notice-error" tabindex="-1" id="wss-error-summary">
		<p><?php esc_html_e( 'Please correct the highlighted fields and save again.', 'woo-stock-sync' ); ?></p>
	</div>
<?php endif; ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="wss-settings-form">
	<input type="hidden" name="action" value="wss_save_settings" />
	<?php wp_nonce_field( 'wss_save_settings', 'wss_save_settings_nonce' ); ?>

	<h2><?php esc_html_e( 'Feed source', 'woo-stock-sync' ); ?></h2>
	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Source type', 'woo-stock-sync' ); ?></th>
				<td>
					<fieldset>
						<legend class="screen-reader-text"><span><?php esc_html_e( 'Source type', 'woo-stock-sync' ); ?></span></legend>
						<label>
							<input type="radio" name="source_type" value="upload" <?php checked( 'upload', $settings['source_type'] ); ?> />
							<?php esc_html_e( 'Uploaded file', 'woo-stock-sync' ); ?>
						</label><br />
						<label>
							<input type="radio" name="source_type" value="url" <?php checked( 'url', $settings['source_type'] ); ?> />
							<?php esc_html_e( 'Remote URL', 'woo-stock-sync' ); ?>
						</label>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wss-feed-url"><?php esc_html_e( 'Feed URL', 'woo-stock-sync' ); ?></label></th>
				<td>
					<input type="url" class="regular-text" id="wss-feed-url" name="feed_url"
						value="<?php echo esc_attr( $settings['feed_url'] ); ?>"
						<?php echo empty( $errors['feed_url'] ) ? '' : 'aria-describedby="feed_url-error"'; ?> />
					<?php $this->field_error( 'feed_url' ); ?>
					<p class="description"><?php esc_html_e( 'HTTP or HTTPS URL returning CSV or a flat JSON array. Used when the source type is Remote URL.', 'woo-stock-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wss-auth-name"><?php esc_html_e( 'Auth header name', 'woo-stock-sync' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="wss-auth-name" name="auth_header_name"
						value="<?php echo esc_attr( $settings['auth_header_name'] ); ?>"
						<?php echo empty( $errors['auth_header_name'] ) ? '' : 'aria-describedby="auth_header_name-error"'; ?> />
					<?php $this->field_error( 'auth_header_name' ); ?>
					<p class="description"><?php esc_html_e( 'Optional. Example: Authorization or X-Api-Key.', 'woo-stock-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wss-auth-value"><?php esc_html_e( 'Auth header value', 'woo-stock-sync' ); ?></label></th>
				<td>
					<input type="password" class="regular-text" id="wss-auth-value" name="auth_header_value" autocomplete="new-password"
						value="" placeholder="<?php echo $wss_has_secret ? esc_attr( WSS_Settings::MASK ) : ''; ?>" />
					<?php if ( $wss_has_secret ) : ?>
						<p class="description">
							<label>
								<input type="checkbox" name="auth_header_clear" value="1" />
								<?php esc_html_e( 'Clear the stored header value', 'woo-stock-sync' ); ?>
							</label>
						</p>
						<p class="description"><?php esc_html_e( 'A value is stored. Leave blank to keep it, enter a new value to replace it, or tick the box to remove it.', 'woo-stock-sync' ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Optional. Stored securely and never shown again after saving.', 'woo-stock-sync' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wss-feed-file"><?php esc_html_e( 'Upload feed file', 'woo-stock-sync' ); ?></label></th>
				<td>
					<input type="file" id="wss-feed-file" name="feed_file" accept=".csv,.json"
						<?php echo empty( $errors['feed_file'] ) ? '' : 'aria-describedby="feed_file-error"'; ?> />
					<?php $this->field_error( 'feed_file' ); ?>
					<?php if ( '' !== $settings['upload_name'] ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: uploaded file name. */
								esc_html__( 'Current file: %s', 'woo-stock-sync' ),
								'<code>' . esc_html( $settings['upload_name'] ) . '</code>'
							);
							?>
						</p>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Used when the source type is Uploaded file. CSV or JSON, up to 64 MB.', 'woo-stock-sync' ); ?></p>
				</td>
			</tr>
		</tbody>
	</table>

	<?php submit_button( __( 'Save settings', 'woo-stock-sync' ) ); ?>
</form>
