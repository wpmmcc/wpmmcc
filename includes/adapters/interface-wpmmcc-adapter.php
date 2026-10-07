<?php
/**
 * WPMMCC Content Plugin Adapter Interface (WP-A-13, doc 05 §3).
 *
 * Defines the standard contract for third-party and core WordPress content adaptation,
 * learning from ThreeWP Broadcast add-on architecture and MultilingualPress object abstraction.
 *
 * @package WPMMCC\Adapters
 */

namespace WPMMCC\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface Adapter_Interface
 */
interface Adapter_Interface {

	/**
	 * Get unique adapter identifier (e.g. 'woocommerce', 'elementor', 'tutor_lms').
	 *
	 * @return string
	 */
	public function get_slug(): string;

	/**
	 * Get list of post types supported by this adapter.
	 *
	 * @return string[]
	 */
	public function get_supported_post_types(): array;

	/**
	 * Check if the underlying third-party plugin is currently active on this WordPress site.
	 *
	 * @return bool
	 */
	public function is_plugin_active(): bool;

	/**
	 * Export custom payload from the source post (e.g. variations, course trees, widget JSON).
	 *
	 * @param int      $post_id Source post ID.
	 * @param \WP_Post $post Source post object.
	 * @return array Custom payload array for Sync Packet entity.plugin_specific.
	 */
	public function export_custom_payload( int $post_id, $post ): array;

	/**
	 * Import and materialize custom payload on the target site.
	 *
	 * @param int   $target_post_id Newly created or updated target post ID.
	 * @param array $payload The custom payload from Sync Packet.
	 * @param array $context Synchronization context (mappings, urls, peers).
	 * @return bool True on success, false on error.
	 */
	public function import_custom_payload( int $target_post_id, array $payload, array $context = array() ): bool;

	/**
	 * Get list of translatable text field keys for this adapter.
	 *
	 * @param string $post_type Post type.
	 * @return array Array of field paths/keys suitable for translation pipelines.
	 */
	public function get_translatable_fields( string $post_type ): array;
}
