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
		$type = ( '' !== $override_url || 'url' === $settings['source_type'] ) ? 'url' : 'upload';

		if ( 'url' === $type ) {
			$url = ( '' !== $override_url ) ? $override_url : $settings['feed_url'];
			if ( '' === $url ) {
				return new WP_Error( 'invalid_settings', __( 'No feed URL is configured.', 'woo-stock-sync' ) );
			}

			$path = $this->download_remote( $url, $settings['auth_header_name'], $settings['auth_header_value'] );
			if ( is_wp_error( $path ) ) {
				return $path;
			}

			return array(
				'format' => self::detect_format( $path, $url ),
				'path'   => $path,
				'temp'   => true,
				'type'   => 'url',
			);
		}

		$path = $settings['upload_path'];
		if ( '' === $path || ! file_exists( $path ) ) {
			return new WP_Error( 'invalid_settings', __( 'No uploaded feed file was found. Upload a file in Settings.', 'woo-stock-sync' ) );
		}

		return array(
			'format' => self::detect_format( $path ),
			'path'   => $path,
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
			$hint_ext = strtolower( pathinfo( wp_parse_url( $hint, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
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
}
