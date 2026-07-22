<?php
/**
 * Feed source resolution, streaming parse, and row validation.
 *
 * @package WooStockSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves a feed source, streams CSV or decodes JSON, lists columns, and validates rows.
 */
class WSS_Feed {

	/**
	 * Download a remote feed to a temporary file, sending the optional auth header.
	 *
	 * The response body is streamed to disk so memory stays flat regardless of feed size. The auth
	 * header value is used but never logged.
	 *
	 * @param string $url          Feed URL (http/https).
	 * @param string $header_name  Optional auth header name.
	 * @param string $header_value Optional auth header value.
	 * @return string|WP_Error Temp file path on success, WP_Error on failure.
	 */
	public function download_remote( $url, $header_name = '', $header_value = '' ) {
		$tmp = wp_tempnam( 'wss-feed' );
		if ( ! $tmp ) {
			return new WP_Error( 'fetch_failed', __( 'A temporary file for the feed could not be created.', 'woo-stock-sync' ) );
		}

		$args = array(
			'timeout'  => 30,
			'stream'   => true,
			'filename' => $tmp,
			'headers'  => array(),
		);

		if ( '' !== $header_name && '' !== $header_value ) {
			$args['headers'][ $header_name ] = $header_value;
		}

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp );
			wss_log(
				'Remote feed fetch failed.',
				array(
					'url'   => $url,
					'error' => $response->get_error_message(),
				)
			);
			return new WP_Error( 'fetch_failed', __( 'The feed URL could not be reached. Check the URL and your connection, then try again.', 'woo-stock-sync' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			wp_delete_file( $tmp );
			wss_log(
				'Remote feed returned a non-success status.',
				array(
					'url'    => $url,
					'status' => $code,
				)
			);
			return new WP_Error(
				'fetch_failed',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The feed URL returned HTTP %d. Check the URL and the auth header in Settings.', 'woo-stock-sync' ),
					$code
				)
			);
		}

		return $tmp;
	}

	/**
	 * Resolve the configured feed source to a readable local file.
	 *
	 * @param array  $settings     Plugin settings.
	 * @param string $override_url Optional URL that overrides the configured source (CLI/schedule).
	 * @return array|WP_Error {
	 *     On success an array; on failure a WP_Error.
	 *
	 *     @type string $format Feed format: 'csv' or 'json'.
	 *     @type string $path   Absolute path to the readable file.
	 *     @type bool   $temp   Whether the file is temporary and should be deleted after use.
	 *     @type string $type   Source type: 'upload' or 'url'.
	 * }
	 */
	public function resolve_source( array $settings, $override_url = '' ) {
		if ( '' !== $override_url || 'url' === $settings['source_type'] ) {
			$url = ( '' !== $override_url ) ? $override_url : $settings['feed_url'];
			return $this->open_run_source( 'url', $url, $settings );
		}

		return $this->open_run_source( 'upload', $settings['upload_path'], $settings );
	}

	/**
	 * Open an explicit source (a run's stored source_type + source_ref) to a readable local file.
	 *
	 * @param string $source_type 'url' or 'upload'.
	 * @param string $source_ref  URL or absolute file path.
	 * @param array  $settings    Plugin settings (for the auth header on URL sources).
	 * @return array|WP_Error Source descriptor (format, path, temp, type), or WP_Error.
	 */
	public function open_run_source( $source_type, $source_ref, array $settings ) {
		if ( 'url' === $source_type ) {
			if ( '' === $source_ref ) {
				return new WP_Error( 'invalid_settings', __( 'No feed URL is configured.', 'woo-stock-sync' ) );
			}

			$path = $this->download_remote( $source_ref, $settings['auth_header_name'], $settings['auth_header_value'] );
			if ( is_wp_error( $path ) ) {
				return $path;
			}

			return array(
				'format' => self::detect_format( $path, $source_ref ),
				'path'   => $path,
				'temp'   => true,
				'type'   => 'url',
			);
		}

		if ( '' === $source_ref || ! file_exists( $source_ref ) ) {
			return new WP_Error( 'invalid_settings', __( 'The feed file is not available. Upload a feed file in Settings.', 'woo-stock-sync' ) );
		}

		return array(
			'format' => self::detect_format( $source_ref ),
			'path'   => $source_ref,
			'temp'   => false,
			'type'   => 'upload',
		);
	}

	/**
	 * Detect a feed's format from its extension, a URL hint, or a content sniff.
	 *
	 * @param string $path Local file path.
	 * @param string $hint Optional URL or filename hint.
	 * @return string 'csv' or 'json'.
	 */
	private static function detect_format( $path, $hint = '' ) {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( 'json' === $ext || 'csv' === $ext ) {
			return $ext;
		}

		if ( '' !== $hint ) {
			// wp_parse_url() returns null for a URL with no path (https://example.com); casting keeps
			// pathinfo() off the PHP 8.1+ "passing null" deprecation on every remote fetch.
			$hint_ext = strtolower( pathinfo( (string) wp_parse_url( $hint, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			if ( 'json' === $hint_ext || 'csv' === $hint_ext ) {
				return $hint_ext;
			}
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming reader; WP_Filesystem has no streaming API.
		if ( $handle ) {
			$chunk = fread( $handle, 64 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- streaming reader.
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming reader.
			$trimmed = ltrim( (string) $chunk );
			if ( isset( $trimmed[0] ) && ( '[' === $trimmed[0] || '{' === $trimmed[0] ) ) {
				return 'json';
			}
		}

		return 'csv';
	}

	/**
	 * List the feed's column names (CSV header row or first JSON object's keys).
	 *
	 * @param array  $settings     Plugin settings.
	 * @param string $override_url Optional URL override.
	 * @return array|WP_Error Array of column names, or WP_Error on failure.
	 */
	public function list_columns( array $settings, $override_url = '' ) {
		$source = $this->resolve_source( $settings, $override_url );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$columns = ( 'json' === $source['format'] )
			? $this->json_columns( $source['path'] )
			: $this->csv_columns( $source['path'] );

		if ( $source['temp'] && file_exists( $source['path'] ) ) {
			wp_delete_file( $source['path'] );
		}

		return $columns;
	}

	/**
	 * Read the header row of a CSV feed.
	 *
	 * @param string $path File path.
	 * @return array|WP_Error Column names or WP_Error.
	 */
	private function csv_columns( $path ) {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming reader.
		if ( ! $handle ) {
			return new WP_Error( 'fetch_failed', __( 'The feed file could not be opened.', 'woo-stock-sync' ) );
		}

		$header = fgetcsv( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming reader.

		if ( ! is_array( $header ) ) {
			return new WP_Error( 'empty_feed', __( 'The feed file has no header row.', 'woo-stock-sync' ) );
		}

		return $this->clean_header( $header );
	}

	/**
	 * Read the keys of the first object in a JSON feed.
	 *
	 * @param string $path File path.
	 * @return array|WP_Error Column names or WP_Error.
	 */
	private function json_columns( $path ) {
		$data = $this->decode_json_file( $path );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$first = reset( $data );
		if ( ! is_array( $first ) ) {
			return new WP_Error( 'fetch_failed', __( 'The JSON feed must be a flat array of objects.', 'woo-stock-sync' ) );
		}

		return $this->clean_header( array_keys( $first ) );
	}

	/**
	 * Decode a JSON feed file into an array of rows, under the size cap.
	 *
	 * @param string $path File path.
	 * @return array|WP_Error Decoded rows or WP_Error.
	 */
	private function decode_json_file( $path ) {
		$max = (int) apply_filters( 'wss_json_max_bytes', WSS_JSON_MAX_BYTES );
		if ( filesize( $path ) > $max ) {
			return new WP_Error( 'fetch_failed', __( 'The JSON feed is larger than the supported size. Use a CSV feed for very large catalogs.', 'woo-stock-sync' ) );
		}

		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file; JSON has no streaming decoder.
		if ( false === $raw ) {
			return new WP_Error( 'fetch_failed', __( 'The feed file could not be read.', 'woo-stock-sync' ) );
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'fetch_failed', __( 'The JSON feed could not be parsed. It must be a flat array of objects.', 'woo-stock-sync' ) );
		}

		if ( empty( $data ) ) {
			return new WP_Error( 'empty_feed', __( 'The JSON feed is empty.', 'woo-stock-sync' ) );
		}

		return $data;
	}

	/**
	 * Normalize a header/key list: strip the UTF-8 BOM, trim, and drop empty names.
	 *
	 * @param array $header Raw header cells or keys.
	 * @return array Cleaned, unique column names.
	 */
	private function clean_header( array $header ) {
		$columns = array();
		foreach ( $header as $index => $name ) {
			$name = trim( (string) $name );
			if ( 0 === $index ) {
				$name = preg_replace( '/^\xEF\xBB\xBF/', '', $name );
			}
			if ( '' !== $name ) {
				$columns[] = $name;
			}
		}

		return array_values( array_unique( $columns ) );
	}

	/**
	 * Parse a resolved feed source, emitting one validated row result per feed row.
	 *
	 * The emit callback receives each row array (see {@see WSS_Feed::map_and_validate_row()}) so the
	 * caller can stage rows without the whole feed ever being held in memory.
	 *
	 * @param array    $source   Resolved source (format + path).
	 * @param array    $mapping  Column mapping.
	 * @param array    $settings Plugin settings.
	 * @param callable $emit     Callback invoked with each row result.
	 * @return int|WP_Error Number of rows parsed, or WP_Error on a run-level failure.
	 */
	public function parse( array $source, array $mapping, array $settings, callable $emit ) {
		if ( 'json' === $source['format'] ) {
			return $this->parse_json( $source['path'], $mapping, $settings, $emit );
		}

		return $this->parse_csv( $source['path'], $mapping, $settings, $emit );
	}

	/**
	 * Parse a JSON feed (a flat array of objects) decoded under the size cap.
	 *
	 * @param string   $path     File path.
	 * @param array    $mapping  Column mapping.
	 * @param array    $settings Plugin settings.
	 * @param callable $emit     Row callback.
	 * @return int|WP_Error Rows parsed, or WP_Error on a run-level failure.
	 */
	private function parse_json( $path, array $mapping, array $settings, callable $emit ) {
		$data = $this->decode_json_file( $path );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$cap     = (int) apply_filters( 'wss_row_cap', WSS_ROW_CAP );
		$seen    = array();
		$row_num = 0;

		foreach ( $data as $entry ) {
			++$row_num;

			$assoc = is_array( $entry ) ? $entry : array();
			$emit( $this->map_and_validate_row( $row_num, $assoc, $mapping, $settings, $seen ) );

			if ( $row_num > $cap ) {
				return $this->too_large_error( $cap );
			}
		}

		return $row_num;
	}

	/**
	 * Stream a CSV feed row by row with fgetcsv (constant memory).
	 *
	 * @param string   $path     File path.
	 * @param array    $mapping  Column mapping.
	 * @param array    $settings Plugin settings.
	 * @param callable $emit     Row callback.
	 * @return int|WP_Error Rows parsed, or WP_Error on a run-level failure.
	 */
	private function parse_csv( $path, array $mapping, array $settings, callable $emit ) {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming reader.
		if ( ! $handle ) {
			return new WP_Error( 'fetch_failed', __( 'The feed file could not be opened.', 'woo-stock-sync' ) );
		}

		$header = fgetcsv( $handle );
		if ( ! is_array( $header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming reader.
			return new WP_Error( 'empty_feed', __( 'The feed file has no header row.', 'woo-stock-sync' ) );
		}

		$header_map = $this->header_map( $header );

		$missing = $this->missing_mapped_columns( $mapping, $header_map );
		if ( ! empty( $missing ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming reader.
			return new WP_Error(
				'missing_column',
				sprintf(
					/* translators: %s: comma-separated list of column names. */
					__( 'The feed is missing these mapped columns: %s. Check the column mapping in Settings against the feed header.', 'woo-stock-sync' ),
					implode( ', ', $missing )
				)
			);
		}

		$cap     = (int) apply_filters( 'wss_row_cap', WSS_ROW_CAP );
		$seen    = array();
		$row_num = 0;

		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- streaming read.
		while ( false !== ( $data = fgetcsv( $handle ) ) ) {
			if ( null === $data || array( null ) === $data ) {
				continue; // Skip blank lines.
			}

			++$row_num;

			$assoc = array();
			foreach ( $header_map as $index => $name ) {
				if ( '' === $name || isset( $assoc[ $name ] ) ) {
					continue;
				}
				$assoc[ $name ] = isset( $data[ $index ] ) ? $data[ $index ] : '';
			}

			$emit( $this->map_and_validate_row( $row_num, $assoc, $mapping, $settings, $seen ) );

			if ( $row_num > $cap ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming reader.
				return $this->too_large_error( $cap );
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming reader.

		return $row_num;
	}

	/**
	 * Build an index => cleaned column-name map from a raw CSV header row.
	 *
	 * @param array $header Raw header cells.
	 * @return array Index-keyed column names (BOM stripped, trimmed).
	 */
	private function header_map( array $header ) {
		$map = array();
		foreach ( $header as $index => $name ) {
			$name = trim( (string) $name );
			if ( 0 === $index ) {
				$name = preg_replace( '/^\xEF\xBB\xBF/', '', $name );
			}
			$map[ $index ] = $name;
		}

		return $map;
	}

	/**
	 * Mapped feed columns that are absent from the header.
	 *
	 * A saved mapping can drift out of sync with a feed (a supplier renames a column), which would
	 * otherwise stage every row as "no change" for that field with no signal to the operator. This
	 * lets the CSV path fail the run with a clear message instead.
	 *
	 * @param array $mapping   Column mapping (field => column name).
	 * @param array $available Column names present in the feed header.
	 * @return array Missing column names, unique and in mapping order.
	 */
	private function missing_mapped_columns( array $mapping, array $available ) {
		$present = array();
		foreach ( $available as $name ) {
			$present[ (string) $name ] = true;
		}

		$missing = array();
		foreach ( array( 'sku', 'stock', 'regular_price', 'sale_price' ) as $field ) {
			$column = isset( $mapping[ $field ] ) ? (string) $mapping[ $field ] : '';
			if ( '' !== $column && ! isset( $present[ $column ] ) && ! in_array( $column, $missing, true ) ) {
				$missing[] = $column;
			}
		}

		return $missing;
	}

	/**
	 * The run-level error returned when a feed exceeds the row cap.
	 *
	 * @param int $cap Row cap.
	 * @return WP_Error
	 */
	private function too_large_error( $cap ) {
		return new WP_Error(
			'too_large',
			sprintf(
				/* translators: %s: maximum row count. */
				__( 'The feed has more than %s rows, the supported maximum. Split it into smaller feeds.', 'woo-stock-sync' ),
				number_format_i18n( $cap )
			)
		);
	}

	/**
	 * Map a raw feed row (cells keyed by column name) to product-field values, and validate it.
	 *
	 * Never throws or aborts: an invalid row is returned with a 'failed' status and a reason so the
	 * caller can record it and continue.
	 *
	 * @param int   $row_num  1-based feed row position.
	 * @param array $assoc    Row cells keyed by column name.
	 * @param array $mapping  Column mapping.
	 * @param array $settings Plugin settings (for blank-clears-sale).
	 * @param array $seen     By-reference set of SKUs already seen in this feed.
	 * @return array {
	 *     @type int    $row_num
	 *     @type string $sku
	 *     @type array  $new_values Field => value for fields in play.
	 *     @type string $status     'staged' or 'failed'.
	 *     @type string $reason     Reason enum when failed, else ''.
	 *     @type string $message    Human-readable detail.
	 * }
	 */
	public function map_and_validate_row( $row_num, array $assoc, array $mapping, array $settings, array &$seen ) {
		$sku = isset( $assoc[ $mapping['sku'] ] ) ? trim( (string) $assoc[ $mapping['sku'] ] ) : '';

		$row = array(
			'row_num'    => (int) $row_num,
			'sku'        => $sku,
			'new_values' => array(),
			'status'     => 'staged',
			'reason'     => '',
			'message'    => '',
		);

		if ( '' === $sku ) {
			return self::fail_row( $row, 'missing_sku', __( 'The SKU cell is empty.', 'woo-stock-sync' ) );
		}

		if ( isset( $seen[ $sku ] ) ) {
			return self::fail_row( $row, 'duplicate_sku', __( 'This SKU already appeared earlier in the feed; the first row wins.', 'woo-stock-sync' ) );
		}
		$seen[ $sku ] = true;

		$new = array();
		foreach ( array( 'stock', 'regular_price', 'sale_price' ) as $field ) {
			$column = $mapping[ $field ];
			if ( '' === $column ) {
				continue;
			}

			$cell = isset( $assoc[ $column ] ) ? trim( (string) $assoc[ $column ] ) : '';

			if ( 'sale_price' === $field && '' === $cell ) {
				if ( ! empty( $settings['blank_clears_sale'] ) ) {
					$new['sale_price'] = '';
				}
				continue;
			}

			if ( '' === $cell ) {
				continue; // A blank cell means "no change" for this field.
			}

			if ( ! self::is_nonnegative_number( $cell ) ) {
				return self::fail_row(
					$row,
					'invalid_number',
					sprintf(
						/* translators: 1: field name, 2: offending value. */
						__( 'The %1$s value "%2$s" is not a valid non-negative number.', 'woo-stock-sync' ),
						$field,
						$cell
					)
				);
			}

			$new[ $field ] = $cell;
		}

		if ( isset( $new['regular_price'], $new['sale_price'] ) && '' !== $new['sale_price']
			&& (float) $new['sale_price'] >= (float) $new['regular_price'] ) {
			return self::fail_row( $row, 'invalid_sale_price', __( 'The sale price must be lower than the regular price.', 'woo-stock-sync' ) );
		}

		$row['new_values'] = $new;
		return $row;
	}

	/**
	 * Mark a row result failed with a reason and message.
	 *
	 * @param array  $row     Row result.
	 * @param string $reason  Reason enum.
	 * @param string $message Detail.
	 * @return array
	 */
	private static function fail_row( array $row, $reason, $message ) {
		$row['status']  = 'failed';
		$row['reason']  = $reason;
		$row['message'] = $message;
		return $row;
	}

	/**
	 * Whether a value is a numeric, non-negative quantity.
	 *
	 * @param string $value Raw cell value.
	 * @return bool
	 */
	private static function is_nonnegative_number( $value ) {
		return is_numeric( $value ) && (float) $value >= 0;
	}
}
