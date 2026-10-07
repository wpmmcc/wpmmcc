<?php
/**
 * WPMMCC Media Gallery & Podcasting Adapter (Envira Gallery, Seriously Simple Podcasting, EDD, doc 05 §1).
 *
 * Synchronizes image galleries, podcast episodes with audio enclosures, and digital download files.
 *
 * @package WPMMCC\Adapters
 */

namespace WPMMCC\Adapters;

use WPMMCC\Sync\Rewriter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Adapter_Media_Gallery
 */
class Adapter_Media_Gallery implements Adapter_Interface {

	/**
	 * Adapter slug.
	 */
	public function get_slug(): string {
		return 'media_gallery';
	}

	/**
	 * Supported post types.
	 */
	public function get_supported_post_types(): array {
		return array( 'envira', 'podcast', 'download' );
	}

	/**
	 * Check if any of the media plugins are active.
	 */
	public function is_plugin_active(): bool {
		return class_exists( 'Envira_Gallery' ) || class_exists( 'SeriouslySimplePodcasting' ) || class_exists( 'Easy_Digital_Downloads' );
	}

	/**
	 * Export gallery, podcast, or download payload.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @return array
	 */
	public function export_custom_payload( int $post_id, $post ): array {
		$payload = array( 'type' => $post->post_type );

		if ( 'envira' === $post->post_type ) {
			$gallery_data = get_post_meta( $post_id, '_eg_gallery_data', true );
			if ( '' !== $gallery_data && false !== $gallery_data && ! is_array( $gallery_data ) ) {
				// G-20: undecodable gallery tree — audit instead of silently exporting nothing.
				Adapter_Registry::record_parse_error(
					'media_gallery',
					$post_id,
					(string) $post->post_type,
					'_eg_gallery_data could not be decoded into a gallery array',
					'outbound'
				);
			}
			$payload['gallery_data'] = is_array( $gallery_data ) ? $gallery_data : array();
		} elseif ( 'podcast' === $post->post_type ) {
			$payload['audio_file'] = (string) get_post_meta( $post_id, 'audio_file', true );
			$payload['duration']   = (string) get_post_meta( $post_id, 'duration', true );
			$payload['filesize']   = (string) get_post_meta( $post_id, 'filesize', true );
		} elseif ( 'download' === $post->post_type ) {
			$payload['edd_price'] = get_post_meta( $post_id, 'edd_price', true );
			$edd_files = get_post_meta( $post_id, 'edd_download_files', true );
			if ( '' !== $edd_files && false !== $edd_files && ! is_array( $edd_files ) ) {
				// G-20: corrupt download-files tree.
				Adapter_Registry::record_parse_error(
					'media_gallery',
					$post_id,
					(string) $post->post_type,
					'edd_download_files could not be decoded into a file list',
					'outbound'
				);
			}
			$payload['edd_files'] = is_array( $edd_files ) ? $edd_files : array();
		}

		return $payload;
	}

	/**
	 * Import payload on target site.
	 *
	 * @param int   $target_post_id Target post ID.
	 * @param array $payload Exported payload.
	 * @param array $context Synchronization context.
	 * @return bool
	 */
	public function import_custom_payload( int $target_post_id, array $payload, array $context = array() ): bool {
		$type    = $payload['type'] ?? '';
		$url_map = $context['url_map'] ?? array();

		if ( 'envira' === $type && ! empty( $payload['gallery_data'] ) && ! is_array( $payload['gallery_data'] ) ) {
			// G-20: inbound gallery payload is not a decodable array.
			Adapter_Registry::record_parse_error(
				'media_gallery',
				$target_post_id,
				'envira',
				'Inbound gallery_data payload is not an array'
			);
		} elseif ( 'envira' === $type && ! empty( $payload['gallery_data'] ) ) {
			$data = Rewriter::deep_rewrite_array_tree( $payload['gallery_data'], $url_map );
			update_post_meta( $target_post_id, '_eg_gallery_data', $data );
		} elseif ( 'podcast' === $type ) {
			if ( isset( $payload['audio_file'] ) ) {
				$audio = Rewriter::deep_rewrite_array_tree( $payload['audio_file'], $url_map );
				update_post_meta( $target_post_id, 'audio_file', esc_url_raw( $audio ) );
			}
			if ( isset( $payload['duration'] ) ) {
				update_post_meta( $target_post_id, 'duration', sanitize_text_field( $payload['duration'] ) );
			}
			if ( isset( $payload['filesize'] ) ) {
				update_post_meta( $target_post_id, 'filesize', sanitize_text_field( $payload['filesize'] ) );
			}
		} elseif ( 'download' === $type ) {
			if ( isset( $payload['edd_price'] ) ) {
				update_post_meta( $target_post_id, 'edd_price', sanitize_text_field( $payload['edd_price'] ) );
			}
			if ( ! empty( $payload['edd_files'] ) && is_array( $payload['edd_files'] ) ) {
				$files = Rewriter::deep_rewrite_array_tree( $payload['edd_files'], $url_map );
				update_post_meta( $target_post_id, 'edd_download_files', $files );
			}
		}

		return true;
	}

	/**
	 * Translatable fields for media galleries and podcasts.
	 */
	public function get_translatable_fields( string $post_type ): array {
		return array(
			'post_title',
			'post_content',
			'post_excerpt',
		);
	}
}
