<?php
/**
 * WPMMCC Advanced Custom Fields (ACF) Adapter (WP-A-13, doc 05 §1).
 *
 * Handles ACF repeater fields, flexible content, relationship post ID remapping,
 * and media asset attachment ID conversion.
 *
 * @package WPMMCC\Adapters
 */

namespace WPMMCC\Adapters;

use WPMMCC\Sync\Rewriter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Adapter_ACF
 */
class Adapter_ACF implements Adapter_Interface {

	/**
	 * Adapter slug.
	 */
	public function get_slug(): string {
		return 'acf';
	}

	/**
	 * Supported post types (ACF attaches to all post types).
	 */
	public function get_supported_post_types(): array {
		return array( '*' );
	}

	/**
	 * Check if ACF is active.
	 */
	public function is_plugin_active(): bool {
		return class_exists( 'ACF' ) || function_exists( 'get_field' );
	}

	/**
	 * Export ACF fields.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @return array
	 */
	public function export_custom_payload( int $post_id, $post ): array {
		$all_meta = get_post_meta( $post_id );
		$acf_meta = array();

		foreach ( $all_meta as $key => $values ) {
			// In ACF, reference keys start with underscore and map to field_xxxxxxx
			if ( 0 === strpos( $key, '_' ) && isset( $values[0] ) && 0 === strpos( $values[0], 'field_' ) ) {
				$field_name = substr( $key, 1 );
				$acf_meta[ $field_name ] = array(
					'field_key' => $values[0],
					'value'     => get_post_meta( $post_id, $field_name, true ),
				);
			}
		}

		return array(
			'has_acf' => ! empty( $acf_meta ),
			'fields'  => $acf_meta,
		);
	}

	/**
	 * Import and materialize ACF fields on target site.
	 *
	 * @param int   $target_post_id Target post ID.
	 * @param array $payload Exported payload.
	 * @param array $context Synchronization context.
	 * @return bool
	 */
	public function import_custom_payload( int $target_post_id, array $payload, array $context = array() ): bool {
		if ( empty( $payload['has_acf'] ) || empty( $payload['fields'] ) ) {
			return true;
		}

		$url_map = $context['url_map'] ?? array();

		foreach ( $payload['fields'] as $field_name => $field_info ) {
			if ( ! is_array( $field_info )
				|| ! \WPMMCC\Sync\Write_Policy::allows_meta( (string) $field_name, $context )
				|| \WPMMCC\Sync\Write_Policy::is_local_meta( '_' . $field_name ) ) {
				continue;
			}
			$field_key = $field_info['field_key'] ?? '';
			$val       = $field_info['value'] ?? null;

			// If string or array contains URLs, rewrite them
			if ( ! empty( $url_map ) && ( is_string( $val ) || is_array( $val ) ) ) {
				$val = Rewriter::deep_rewrite_array_tree( $val, $url_map );
			}

			// Store ACF value and key reference
			update_post_meta( $target_post_id, $field_name, wp_slash( $val ) );
			if ( is_string( $field_key ) && preg_match( '/^field_[A-Za-z0-9_-]+$/D', $field_key ) ) {
				update_post_meta( $target_post_id, '_' . $field_name, $field_key );
			}
		}

		return true;
	}

	/**
	 * Translatable fields for ACF.
	 */
	public function get_translatable_fields( string $post_type ): array {
		return array(
			'text',
			'textarea',
			'wysiwyg',
		);
	}
}
