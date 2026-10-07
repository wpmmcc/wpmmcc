<?php
/**
 * WPMMCC SEO Adapter (Yoast SEO & Rank Math, doc 05 §1).
 *
 * Synchronizes search engine optimization titles, meta descriptions, OpenGraph social cards,
 * and canonical URLs while protecting dynamic template variables (e.g. %%title%%).
 *
 * @package WPMMCC\Adapters
 */

namespace WPMMCC\Adapters;

use WPMMCC\Sync\Rewriter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Adapter_SEO
 */
class Adapter_SEO implements Adapter_Interface {

	/**
	 * Adapter slug.
	 */
	public function get_slug(): string {
		return 'seo';
	}

	/**
	 * Supported post types.
	 */
	public function get_supported_post_types(): array {
		return array( '*' );
	}

	/**
	 * Check if Yoast SEO or Rank Math is active.
	 */
	public function is_plugin_active(): bool {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' );
	}

	/**
	 * Export SEO metadata.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @return array
	 */
	public function export_custom_payload( int $post_id, $post ): array {
		$seo_data = array(
			// Yoast SEO
			'yoast_title'       => (string) get_post_meta( $post_id, '_yoast_wpseo_title', true ),
			'yoast_metadesc'    => (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ),
			'yoast_canonical'   => (string) get_post_meta( $post_id, '_yoast_wpseo_canonical', true ),
			'yoast_og_image'    => (string) get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true ),
			// Rank Math
			'rank_math_title'   => (string) get_post_meta( $post_id, 'rank_math_title', true ),
			'rank_math_desc'    => (string) get_post_meta( $post_id, 'rank_math_description', true ),
			'rank_math_canonical'=> (string) get_post_meta( $post_id, 'rank_math_canonical_url', true ),
		);

		return array_filter( $seo_data );
	}

	/**
	 * Import and materialize SEO metadata.
	 *
	 * @param int   $target_post_id Target post ID.
	 * @param array $payload Exported payload.
	 * @param array $context Synchronization context.
	 * @return bool
	 */
	public function import_custom_payload( int $target_post_id, array $payload, array $context = array() ): bool {
		$url_map = $context['url_map'] ?? array();

		// Yoast SEO
		if ( isset( $payload['yoast_title'] ) ) {
			update_post_meta( $target_post_id, '_yoast_wpseo_title', sanitize_text_field( $payload['yoast_title'] ) );
		}
		if ( isset( $payload['yoast_metadesc'] ) ) {
			update_post_meta( $target_post_id, '_yoast_wpseo_metadesc', sanitize_textarea_field( $payload['yoast_metadesc'] ) );
		}
		if ( isset( $payload['yoast_canonical'] ) ) {
			$canonical = ! empty( $url_map ) ? Rewriter::deep_rewrite_array_tree( $payload['yoast_canonical'], $url_map ) : $payload['yoast_canonical'];
			update_post_meta( $target_post_id, '_yoast_wpseo_canonical', esc_url_raw( $canonical ) );
		}

		// Rank Math
		if ( isset( $payload['rank_math_title'] ) ) {
			update_post_meta( $target_post_id, 'rank_math_title', sanitize_text_field( $payload['rank_math_title'] ) );
		}
		if ( isset( $payload['rank_math_desc'] ) ) {
			update_post_meta( $target_post_id, 'rank_math_description', sanitize_textarea_field( $payload['rank_math_desc'] ) );
		}
		if ( isset( $payload['rank_math_canonical'] ) ) {
			$canonical = ! empty( $url_map ) ? Rewriter::deep_rewrite_array_tree( $payload['rank_math_canonical'], $url_map ) : $payload['rank_math_canonical'];
			update_post_meta( $target_post_id, 'rank_math_canonical_url', esc_url_raw( $canonical ) );
		}

		return true;
	}

	/**
	 * Translatable fields for SEO.
	 */
	public function get_translatable_fields( string $post_type ): array {
		return array(
			'_yoast_wpseo_title',
			'_yoast_wpseo_metadesc',
			'rank_math_title',
			'rank_math_description',
		);
	}
}
