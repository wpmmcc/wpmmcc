<?php
/**
 * WPMMCC REST Media Controller (WP-A-07, doc 14 §5.2).
 *
 * Implements chunked media streaming and secure tokenized media downloads.
 *
 * @package WPMMCC\REST
 */

namespace WPMMCC\REST;

use WPMMCC\Sync\Asset_Registry;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Rest_Media
 */
class Rest_Media extends Rest_Base {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/sync/media-chunk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_chunk' ),
				'permission_callback' => array( 'WPMMCC\REST\Rest_Middleware', 'verify_hmac' ),
			)
		);
		register_rest_route( $this->namespace, '/sync/media-receipt', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'handle_receipt' ),
			'permission_callback' => array( Rest_Middleware::class, 'verify_hmac' ),
		) );

		register_rest_route(
			$this->namespace,
			'/sync/media-download',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_download' ),
				'permission_callback' => '__return_true', // Validated via time-limited signed token
			)
		);
	}

	/**
	 * Maximum number of chunks per file (chunk quota, doc 14 §5.2).
	 */
	const MAX_CHUNKS_PER_FILE = 512;

	/**
	 * Maximum decoded size of a single chunk (4MB).
	 */
	const MAX_CHUNK_BYTES = 4194304;

	public function handle_receipt( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) || ! self::valid_scope( $params ) ) {
			return $this->format_error( 'wpmmcc_media_scope_invalid', 'Original media scope is invalid.', 400 );
		}
		try {
			return $this->format_success( \WPMMCC\Sync\Media_Delivery::status(
				Rest_Middleware::requesting_peer_uuid( $request ), $params
			), 200 );
		} catch ( \Throwable $error ) {
			return $this->format_error( 'wpmmcc_media_unknown', 'Original media receipt is unavailable; no reapply.', 409 );
		}
	}

	private static function valid_scope( array $params ): bool {
		return is_string( $params['file_uuid'] ?? null ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,128}$/D', $params['file_uuid'] )
			&& is_int( $params['total'] ?? null ) && $params['total'] > 0 && $params['total'] <= self::MAX_CHUNKS_PER_FILE
			&& is_string( $params['filename'] ?? null ) && '' !== $params['filename']
			&& is_string( $params['mime_type'] ?? null ) && strlen( $params['mime_type'] ) <= 128
			&& is_string( $params['expected_sha256'] ?? null ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $params['expected_sha256'] )
			&& is_int( $params['expected_size'] ?? null ) && $params['expected_size'] > 0
			&& $params['expected_size'] <= \WPMMCC\Sync\Media_Delivery::MAX_FILE_BYTES;
	}

	/**
	 * Handle chunk upload.
	 *
	 * On final chunk: assembles the file, then runs the CAS registry
	 * (WP-A-07, doc 14 §3) — a content SHA-256 already present locally
	 * short-circuits to the existing attachment (reused=true, O(1) hit,
	 * no second copy on disk); otherwise the file lands in the media
	 * library and registers in the CAS table for future hits.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_chunk( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) || ! self::valid_scope( $params ) || ! is_string( $params['file_uuid'] ?? null )
			|| ! preg_match( '/^[A-Za-z0-9_-]{1,128}$/D', $params['file_uuid'] )
			|| ! is_int( $params['chunk_index'] ?? null ) || ! is_int( $params['total'] ?? null )
			|| $params['total'] < 1 || $params['total'] > self::MAX_CHUNKS_PER_FILE
			|| $params['chunk_index'] < 0 || $params['chunk_index'] >= $params['total']
			|| ! is_string( $params['bytes'] ?? null ) || '' === $params['bytes'] ) {
			return $this->format_error( 'wpmmcc_invalid_chunk_payload', 'Invalid chunk identity or index.', 400 );
		}
		$raw = $params['bytes'];
		if ( strlen( $raw ) > 4 * (int) ceil( self::MAX_CHUNK_BYTES / 3 ) ) {
			return $this->format_error( 'wpmmcc_chunk_too_large', 'Chunk exceeds its byte quota.', 413 );
		}
		$bytes = base64_decode( $raw, true );
		if ( false === $bytes || '' === $bytes || strlen( $bytes ) > self::MAX_CHUNK_BYTES || base64_encode( $bytes ) !== $raw ) {
			return $this->format_error( 'wpmmcc_invalid_chunk_encoding', 'Chunk encoding is invalid.', 400 );
		}
		$sender = Rest_Middleware::requesting_peer_uuid( $request );
		if ( '' === $sender ) {
			return $this->format_error( 'wpmmcc_chunk_owner_missing', 'Authenticated chunk owner is required.', 403 );
		}
		try {
			$result = \WPMMCC\Sync\Media_Delivery::accept( $sender, $params, $bytes );
			return $this->format_success( $result, 200 );
		} catch ( \Throwable $error ) {
			return $this->format_error( 'wpmmcc_media_unconfirmed', 'Original media intent retained; delivery not confirmed.', 409 );
		}
	}

	/**
	 * Handle media download with time-limited signed asset token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_download( WP_REST_Request $request ) {
		$token = sanitize_text_field( $request->get_param( 'asset_token' ) );
		if ( empty( $token ) ) {
			return $this->format_error( 'wpmmcc_missing_asset_token', __( 'Asset token is required.', 'wpmmcc' ), 401 );
		}

		$attachment_id = (int) get_transient( 'wpmmcc_at_' . $token );
		if ( $attachment_id <= 0 ) {
			return $this->format_error( 'wpmmcc_expired_asset_token', __( 'Asset token is invalid or expired.', 'wpmmcc' ), 401 );
		}

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return $this->format_error( 'wpmmcc_media_not_found', __( 'Physical media file not found.', 'wpmmcc' ), 404 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		\WP_Filesystem();
		global $wp_filesystem;

		$mime = (string) get_post_mime_type( $attachment_id );
		header( 'Content-Type: ' . ( $mime ?: 'application/octet-stream' ) );
		if ( $wp_filesystem && $wp_filesystem->exists( $file_path ) ) {
			header( 'Content-Length: ' . (string) $wp_filesystem->size( $file_path ) );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary stream cannot be HTML escaped.
			echo $wp_filesystem->get_contents( $file_path );
		} else {
			header( 'Content-Length: ' . (string) filesize( $file_path ) );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary stream cannot be HTML escaped.
			echo file_get_contents( $file_path );
		}
		exit;
	}
}
