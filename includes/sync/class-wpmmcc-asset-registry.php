<?php
/**
 * WPMMCC CAS Asset Registry (WP-A-07, doc 14 §3, doc 14 §5).
 *
 * Implements Content Addressable Storage (CAS) deduplication with SHA-256
 * lookup/register over local attachments. Media bytes arrive via the signed
 * client→target transfer chain (/sync/media-chunk): the plugin performs no
 * remote fetch of its own, so there is no plugin-side download surface —
 * the download-side SSRF floor, redirect refusal and size cap live in the
 * client's sync_engine (12 批 A3 / Y-1), not here.
 *
 * @package WPMMCC\Sync
 */

namespace WPMMCC\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Asset_Registry
 */
class Asset_Registry {

	/**
	 * Check if an asset exists in local CAS registry by SHA-256.
	 *
	 * @param string $sha256 SHA-256 hash of the media file.
	 * @return int Attachment ID if found and file exists, 0 otherwise.
	 */
	public static function lookup_cas_attachment( string $sha256 ): int {
		global $wpdb;

		if ( empty( $sha256 ) ) {
			return 0;
		}

		$cache_key     = 'cas_asset_' . $sha256;
		$attachment_id = wp_cache_get( $cache_key, 'wpmmcc' );
		if ( false === $attachment_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table or connection command; WordPress has no replacement API
			$attachment_id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT attachment_id FROM {$wpdb->prefix}wpmmcc_asset_registry WHERE sha256 = %s LIMIT 1", $sha256 )
			);
			wp_cache_set( $cache_key, $attachment_id, 'wpmmcc', 3600 );
		}

		if ( $attachment_id <= 0 ) {
			return 0;
		}

		// Verify physical file existence to prevent stale references
		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! is_file( $file_path ) || hash_file( 'sha256', $file_path ) !== $sha256 ) {
			// A read never erases retained CAS/receipt evidence.
			return 0;
		}

		return $attachment_id;
	}

	/**
	 * Register an attachment in the CAS table.
	 *
	 * @param int    $attachment_id WordPress attachment post ID.
	 * @param string $sha256 File SHA-256 hash.
	 * @param int    $size_bytes File size in bytes.
	 * @param string $origin_peer_uuid Origin peer UUID.
	 * @return bool
	 */
	public static function register_cas_asset( int $attachment_id, string $sha256, int $size_bytes, string $origin_peer_uuid = '' ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table or connection command; WordPress has no replacement API
		$result = $wpdb->replace(
			$wpdb->prefix . 'wpmmcc_asset_registry',
			array(
				'sha256'           => $sha256,
				'size_bytes'       => $size_bytes,
				'attachment_id'    => $attachment_id,
				'origin_peer_uuid' => $origin_peer_uuid,
				'first_seen_at'    => current_time( 'mysql' ),
			),
			array( '%s', '%d', '%d', '%s', '%s' )
		);

		wp_cache_delete( 'cas_asset_' . $sha256, 'wpmmcc' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- read-back proves the registry row just written
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT attachment_id, size_bytes FROM {$wpdb->prefix}wpmmcc_asset_registry WHERE sha256 = %s", $sha256
		), ARRAY_A );
		if ( false === $result || ! is_array( $row ) || (int) $row['attachment_id'] !== $attachment_id || (int) $row['size_bytes'] !== $size_bytes ) {
			return false;
		}
		wp_cache_set( 'cas_asset_' . $sha256, $attachment_id, 'wpmmcc', 3600 );
		return true;
	}

	/**
	 * Compute SHA-256 hash of a local file.
	 *
	 * @param string $file_path Absolute path to file.
	 * @return string 64-character lowercase hex hash.
	 */
	public static function hash_file( string $file_path ): string {
		if ( ! file_exists( $file_path ) ) {
			return '';
		}
		return (string) hash_file( 'sha256', $file_path );
	}
}
