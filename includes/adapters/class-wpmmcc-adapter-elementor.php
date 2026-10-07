<?php
/**
 * WPMMCC Elementor Adapter (WP-A-13, doc 05 §2.3).
 *
 * Handles deep JSON widget tree parsing, recursive media attachment ID and URL
 * remapping, and post-sync Elementor CSS cache clearing.
 *
 * @package WPMMCC\Adapters
 */

namespace WPMMCC\Adapters;

use WPMMCC\Sync\Rewriter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Adapter_Elementor
 */
class Adapter_Elementor implements Adapter_Interface {

	/**
	 * Adapter slug.
	 */
	public function get_slug(): string {
		return 'elementor';
	}

	/**
	 * Supported post types.
	 */
	public function get_supported_post_types(): array {
		return array( 'page', 'post', 'elementor_library' );
	}

	/**
	 * Check if Elementor is active.
	 */
	public function is_plugin_active(): bool {
		return class_exists( '\Elementor\Plugin' );
	}

	/**
	 * Export Elementor widget tree data.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @return array
	 */
	public function export_custom_payload( int $post_id, $post ): array {
		$edit_mode = get_post_meta( $post_id, '_elementor_edit_mode', true );
		if ( 'builder' !== $edit_mode ) {
			return array( 'is_elementor' => false );
		}

		$data_raw = get_post_meta( $post_id, '_elementor_data', true );
		$data_tree = is_string( $data_raw ) ? json_decode( $data_raw, true ) : $data_raw;

		// G-20: a non-empty widget tree that fails to decode is corrupt source
		// data — record it in the journal so silent widget-tree loss is visible.
		if ( is_string( $data_raw ) && '' !== trim( $data_raw ) && null === $data_tree ) {
			Adapter_Registry::record_parse_error(
				'elementor',
				$post_id,
				(string) $post->post_type,
				'Malformed _elementor_data JSON tree: ' . ( function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : 'decode failed' ),
				'outbound'
			);
		}

		return array(
			'is_elementor'     => true,
			'edit_mode'        => $edit_mode,
			'template_type'    => (string) get_post_meta( $post_id, '_elementor_template_type', true ),
			'version'          => (string) get_post_meta( $post_id, '_elementor_version', true ),
			'elementor_data'   => is_array( $data_tree ) ? $data_tree : array(),
			'page_settings'    => get_post_meta( $post_id, '_elementor_page_settings', true ) ?: array(),
		);
	}

	/**
	 * Import and materialize Elementor widget data with deep URL rewriting and CSS cache clearing.
	 *
	 * @param int   $target_post_id Target post ID.
	 * @param array $payload Exported payload.
	 * @param array $context Synchronization context.
	 * @return bool
	 */
	public function import_custom_payload( int $target_post_id, array $payload, array $context = array() ): bool {
		if ( empty( $payload['is_elementor'] ) ) {
			return true;
		}

		// 1. Set Elementor edit mode
		update_post_meta( $target_post_id, '_elementor_edit_mode', 'builder' );

		if ( ! empty( $payload['template_type'] ) ) {
			update_post_meta( $target_post_id, '_elementor_template_type', sanitize_key( $payload['template_type'] ) );
		}
		if ( ! empty( $payload['version'] ) ) {
			update_post_meta( $target_post_id, '_elementor_version', sanitize_text_field( $payload['version'] ) );
		}

		// 2. Deep rewrite Elementor widget tree
		$elementor_data = $payload['elementor_data'] ?? array();
		$url_map        = $context['url_map'] ?? array();

		if ( ! empty( $url_map ) ) {
			$elementor_data = Rewriter::deep_rewrite_array_tree( $elementor_data, $url_map );
		}

		// Elementor stores _elementor_data as raw JSON string in postmeta
		$json_to_store = wp_json_encode( $elementor_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		// G-20: an un-encodable widget tree would silently store nothing.
		if ( false === $json_to_store ) {
			Adapter_Registry::record_parse_error(
				'elementor',
				$target_post_id,
				(string) get_post( $target_post_id )->post_type,
				'Failed to JSON-encode _elementor_data widget tree for storage'
			);
			$json_to_store = '';
		}

		update_post_meta( $target_post_id, '_elementor_data', wp_slash( $json_to_store ) );

		if ( ! empty( $payload['page_settings'] ) && is_array( $payload['page_settings'] ) ) {
			$page_settings = Rewriter::deep_rewrite_array_tree( $payload['page_settings'], $url_map );
			update_post_meta( $target_post_id, '_elementor_page_settings', $page_settings );
		}

		// 3. Post-import CSS cache clearing & regeneration (doc 05 §2.3)
		if ( class_exists( '\Elementor\Plugin' ) ) {
			if ( isset( \Elementor\Plugin::$instance->files_manager ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}
			if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
				$css_file = new \Elementor\Core\Files\CSS\Post( $target_post_id );
				$css_file->update();
			}
		}

		return true;
	}

	/**
	 * Translatable fields for Elementor widgets.
	 */
	public function get_translatable_fields( string $post_type ): array {
		return array(
			'_elementor_data.title',
			'_elementor_data.editor',
			'_elementor_data.text',
			'_elementor_data.description',
		);
	}
}
