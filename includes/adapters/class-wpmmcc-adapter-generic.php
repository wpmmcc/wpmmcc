<?php
/**
 * WPMMCC Generic Ecosystem Adapter (WP-A-13, doc 05 §1).
 *
 * Provides a universal fallback handler for custom post types and custom fields
 * (The Events Calendar, Events Manager, Directorist, HivePress, Site Reviews, GiveWP, WP Job Manager, WP Recipe Maker).
 *
 * @package WPMMCC\Adapters
 */

namespace WPMMCC\Adapters;

use WPMMCC\Sync\Rewriter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Adapter_Generic
 */
class Adapter_Generic implements Adapter_Interface {

	/**
	 * Adapter slug.
	 */
	public function get_slug(): string {
		return 'generic';
	}

	/**
	 * Supported post types.
	 */
	public function get_supported_post_types(): array {
		return array(
			'tribe_events',
			'tribe_venue',
			'tribe_organizer',
			'event',
			'location',
			'job_listing',
			'at_biz_dir',
			'hp_listing',
			'hp_vendor',
			'site-review',
			'give_forms',
			'wprm_recipe',
		);
	}

	/**
	 * Always active as generic fallback.
	 */
	public function is_plugin_active(): bool {
		return true;
	}

	/**
	 * Export generic custom payload.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @return array
	 */
	public function export_custom_payload( int $post_id, $post ): array {
		$all_meta = get_post_meta( $post_id );
		$cleaned  = array();

		foreach ( $all_meta as $k => $v ) {
			if ( \WPMMCC\Sync\Write_Policy::is_local_meta( (string) $k ) ) {
				continue;
			}
			$cleaned[ $k ] = is_array( $v ) && 1 === count( $v ) ? $v[0] : $v;
		}

		return array(
			'post_type'   => $post->post_type,
			'meta_fields' => $cleaned,
		);
	}

	/**
	 * Import generic custom payload.
	 *
	 * @param int   $target_post_id Target post ID.
	 * @param array $payload Exported payload.
	 * @param array $context Synchronization context.
	 * @return bool
	 */
	public function import_custom_payload( int $target_post_id, array $payload, array $context = array() ): bool {
		$url_map = $context['url_map'] ?? array();

		if ( ! empty( $payload['meta_fields'] ) && is_array( $payload['meta_fields'] ) ) {
			foreach ( $payload['meta_fields'] as $key => $val ) {
				if ( ! \WPMMCC\Sync\Write_Policy::allows_meta( (string) $key, $context ) ) {
					continue;
				}
				if ( ! empty( $url_map ) && ( is_string( $val ) || is_array( $val ) ) ) {
					$val = Rewriter::deep_rewrite_array_tree( $val, $url_map );
				}
				update_post_meta( $target_post_id, $key, wp_slash( $val ) );
			}
		}

		return true;
	}

	/**
	 * Translatable fields for generic post types.
	 */
	public function get_translatable_fields( string $post_type ): array {
		return array(
			'post_title',
			'post_content',
			'post_excerpt',
		);
	}
}
