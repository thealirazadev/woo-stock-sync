<?php
/**
 * Settings screen render, sanitize, and save.
 *
 * @package WooStockSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders and persists the plugin settings (stored in the wss_settings option).
 */
class WSS_Settings {

	/**
	 * Maximum accepted upload size for a feed file, in bytes.
	 */
	const UPLOAD_MAX_BYTES = 67108864; // 64 MB.

	/**
	 * Placeholder shown in the auth header value field when a value is stored.
	 */
	const MASK = '********';

	/**
	 * Field-level validation errors from the last save attempt, keyed by field.
	 *
	 * @var array
	 */
	private $errors = array();

	/**
	 * Constructor: register the settings save handler.
	 */
	public function __construct() {
		add_action( 'admin_post_wss_save_settings', array( $this, 'save' ) );
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function render() {
		$settings = wss_get_settings();

		$feedback = get_transient( 'wss_settings_feedback_' . get_current_user_id() );
		if ( is_array( $feedback ) ) {
			delete_transient( 'wss_settings_feedback_' . get_current_user_id() );
			$this->errors = isset( $feedback['errors'] ) ? $feedback['errors'] : array();
			if ( isset( $feedback['values'] ) && is_array( $feedback['values'] ) ) {
				$settings = array_merge( $settings, $feedback['values'] );
			}
		}

		$errors  = $this->errors;
		$columns = $this->known_columns( $settings );

		echo '<div class="wrap wss-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Stock Sync', 'woo-stock-sync' ) . '</h1>';
		WSS_Admin::render_tabs( 'settings' );

		require WSS_PATH . 'templates/admin/settings.php';

		echo '</div>';
	}

	/**
	 * Columns available to the mapping selects: the saved mapping plus, for an uploaded file, the
	 * columns read from it. Remote sources are read on demand via the Load columns button.
	 *
	 * @param array $settings Plugin settings.
	 * @return array Column names.
	 */
	private function known_columns( array $settings ) {
		$known = array();
		foreach ( $settings['mapping'] as $column ) {
			if ( '' !== $column ) {
				$known[] = $column;
			}
		}

		if ( 'upload' === $settings['source_type'] && '' !== $settings['upload_path'] ) {
			$feed    = new WSS_Feed();
			$columns = $feed->list_columns( $settings );
			if ( is_array( $columns ) ) {
				$known = array_merge( $known, $columns );
			}
		}

		return array_values( array_unique( $known ) );
	}

	/**
	 * Render a mapping <select> for one product field.
	 *
	 * @param string $field    Mapping key (sku|stock|regular_price|sale_price).
	 * @param array  $columns  Available column names.
	 * @param array  $settings Plugin settings (for the current selection).
	 * @return void
	 */
	public function mapping_select( $field, array $columns, array $settings ) {
		$current = isset( $settings['mapping'][ $field ] ) ? $settings['mapping'][ $field ] : '';

		$options = $columns;
		if ( '' !== $current && ! in_array( $current, $options, true ) ) {
			$options[] = $current;
		}

		printf( '<select name="map_%1$s" id="wss-map-%1$s" class="wss-map-select" data-map="%1$s">', esc_attr( $field ) );
		echo '<option value="">' . esc_html__( '&mdash; Not mapped &mdash;', 'woo-stock-sync' ) . '</option>';
		foreach ( $options as $column ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $column ),
				selected( $column, $current, false ),
				esc_html( $column )
			);
		}
		echo '</select>';
	}

	/**
	 * Echo an inline field error, if any, for use in the template.
	 *
	 * @param string $field Field key.
	 * @return void
	 */
	public function field_error( $field ) {
		if ( empty( $this->errors[ $field ] ) ) {
			return;
		}

		printf(
			'<p class="wss-field-error" id="%1$s-error"><strong>%2$s</strong></p>',
			esc_attr( $field ),
			esc_html( $this->errors[ $field ] )
		);
	}

	/**
	 * Handle the settings form submission (admin-post).
	 *
	 * @return void
	 */
	public function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to save these settings.', 'woo-stock-sync' ) );
		}

		check_admin_referer( 'wss_save_settings', 'wss_save_settings_nonce' );

		$stored   = wss_get_settings();
		$clean    = $stored;
		$errors   = array();
		$raw_post = wp_unslash( $_POST );

		$clean['source_type'] = ( isset( $raw_post['source_type'] ) && 'url' === $raw_post['source_type'] ) ? 'url' : 'upload';

		$clean['feed_url'] = isset( $raw_post['feed_url'] ) ? esc_url_raw( trim( $raw_post['feed_url'] ) ) : '';
		if ( '' !== $clean['feed_url'] ) {
			$scheme = wp_parse_url( $clean['feed_url'], PHP_URL_SCHEME );
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				$errors['feed_url'] = __( 'The feed URL must start with http:// or https://.', 'woo-stock-sync' );
			}
		}
		if ( 'url' === $clean['source_type'] && '' === $clean['feed_url'] ) {
			$errors['feed_url'] = __( 'A feed URL is required when the source is a remote URL.', 'woo-stock-sync' );
		}

		$header_name = isset( $raw_post['auth_header_name'] ) ? trim( $raw_post['auth_header_name'] ) : '';
		if ( '' !== $header_name && ! preg_match( "/^[A-Za-z0-9!#\$%&'*+.^_`|~-]+$/", $header_name ) ) {
			$errors['auth_header_name'] = __( 'The header name contains invalid characters.', 'woo-stock-sync' );
		} else {
			$clean['auth_header_name'] = sanitize_text_field( $header_name );
		}

		// Blank value keeps the stored secret; an explicit clear removes it.
		if ( ! empty( $raw_post['auth_header_clear'] ) ) {
			$clean['auth_header_value'] = '';
		} elseif ( isset( $raw_post['auth_header_value'] ) && '' !== trim( $raw_post['auth_header_value'] ) ) {
			$clean['auth_header_value'] = sanitize_text_field( $raw_post['auth_header_value'] );
		}

		$upload = $this->handle_upload();
		if ( is_wp_error( $upload ) ) {
			$errors['feed_file'] = $upload->get_error_message();
		} elseif ( is_array( $upload ) ) {
			$clean['upload_path'] = $upload['path'];
			$clean['upload_name'] = $upload['name'];
		}

		$mapping = array(
			'sku'           => '',
			'stock'         => '',
			'regular_price' => '',
			'sale_price'    => '',
		);
		foreach ( array_keys( $mapping ) as $field ) {
			$mapping[ $field ] = isset( $raw_post[ 'map_' . $field ] ) ? sanitize_text_field( $raw_post[ 'map_' . $field ] ) : '';
		}
		$clean['mapping']           = $mapping;
		$clean['blank_clears_sale'] = ! empty( $raw_post['blank_clears_sale'] );

		if ( '' === $mapping['sku'] ) {
			$errors['map_sku'] = __( 'Map the SKU column. It is required to match feed rows to products.', 'woo-stock-sync' );
		}
		if ( '' === $mapping['stock'] && '' === $mapping['regular_price'] && '' === $mapping['sale_price'] ) {
			$errors['map_values'] = __( 'Map at least one of stock, regular price, or sale price.', 'woo-stock-sync' );
		}

		if ( ! empty( $errors ) ) {
			$this->store_feedback( $errors, $clean );
			$this->redirect( 'invalid_settings', 'error' );
		}

		update_option( 'wss_settings', $clean, false );
		$this->redirect( 'settings_saved', 'success' );
	}

	/**
	 * Validate and store an uploaded feed file in the protected uploads subdir.
	 *
	 * @return array|WP_Error|null Array with path+name on success, WP_Error on failure, null when
	 *                             no file was submitted.
	 */
	private function handle_upload() {
		// The nonce is verified by save() before this runs; each field is sanitized individually below.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$field = isset( $_FILES['feed_file'] ) ? (array) $_FILES['feed_file'] : array();

		if ( empty( $field['name'] ) ) {
			return null;
		}

		$file = array(
			'name'     => isset( $field['name'] ) ? sanitize_file_name( wp_unslash( $field['name'] ) ) : '',
			'type'     => isset( $field['type'] ) ? sanitize_text_field( wp_unslash( $field['type'] ) ) : '',
			'tmp_name' => isset( $field['tmp_name'] ) ? $field['tmp_name'] : '',
			'error'    => isset( $field['error'] ) ? (int) $field['error'] : UPLOAD_ERR_NO_FILE,
			'size'     => isset( $field['size'] ) ? (int) $field['size'] : 0,
		);

		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			return new WP_Error( 'upload_error', __( 'The feed file could not be uploaded. Please try again.', 'woo-stock-sync' ) );
		}

		if ( $file['size'] > self::UPLOAD_MAX_BYTES ) {
			return new WP_Error( 'upload_too_large', __( 'The feed file is larger than the allowed size.', 'woo-stock-sync' ) );
		}

		$check = wp_check_filetype(
			$file['name'],
			array(
				'csv'  => 'text/csv',
				'json' => 'application/json',
			)
		);
		$ext   = $check['ext'];
		if ( ! in_array( $ext, array( 'csv', 'json' ), true ) ) {
			return new WP_Error( 'upload_type', __( 'Only .csv and .json feed files are accepted.', 'woo-stock-sync' ) );
		}

		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'upload_invalid', __( 'The upload could not be verified.', 'woo-stock-sync' ) );
		}

		$allowed_mimes = array( 'text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel', 'application/json', 'text/json' );
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$mime  = $finfo ? finfo_file( $finfo, $file['tmp_name'] ) : '';
			if ( $finfo ) {
				finfo_close( $finfo );
			}
			if ( $mime && ! in_array( $mime, $allowed_mimes, true ) ) {
				return new WP_Error( 'upload_mime', __( 'The feed file type could not be verified as CSV or JSON.', 'woo-stock-sync' ) );
			}
		}

		$dir = $this->prepare_uploads_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$target = trailingslashit( $dir ) . 'feed-' . wp_generate_password( 12, false ) . '.' . $ext;
		if ( ! @move_uploaded_file( $file['tmp_name'], $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure handled below.
			wss_log( 'Failed to move uploaded feed file into place.', array( 'target' => $target ) );
			return new WP_Error( 'upload_move', __( 'The feed file could not be saved.', 'woo-stock-sync' ) );
		}

		return array(
			'path' => $target,
			'name' => $file['name'],
		);
	}

	/**
	 * Ensure the protected uploads subdirectory exists with deny rules.
	 *
	 * @return string|WP_Error Absolute directory path, or WP_Error on failure.
	 */
	private function prepare_uploads_dir() {
		$dir = wss_uploads_dir();

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'upload_dir', __( 'The feed storage directory could not be created.', 'woo-stock-sync' ) );
		}

		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a protection file to our own uploads subdir.
		}

		$index = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a protection file to our own uploads subdir.
		}

		return $dir;
	}

	/**
	 * Persist field errors and submitted values across the redirect.
	 *
	 * @param array $errors Field errors.
	 * @param array $values Sanitized submitted values.
	 * @return void
	 */
	private function store_feedback( array $errors, array $values ) {
		set_transient(
			'wss_settings_feedback_' . get_current_user_id(),
			array(
				'errors' => $errors,
				'values' => $values,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * Redirect back to the settings screen with a notice.
	 *
	 * @param string $notice Notice key.
	 * @param string $type   Notice type (success|warning|error).
	 * @return void
	 */
	private function redirect( $notice, $type = 'success' ) {
		wp_safe_redirect(
			WSS_Admin::page_url(
				array(
					'tab'             => 'settings',
					'wss_notice'      => $notice,
					'wss_notice_type' => $type,
				)
			)
		);
		exit;
	}
}
