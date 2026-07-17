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
}
