<?php
/**
 * WPMMCC Hierarchical Taxonomy Synchronizer (WP-A-05, ThreeWP Broadcast paradigm).
 *
 * Safely replicates taxonomy hierarchies (categories, tags, custom taxonomies)
 * from root parent terms down to leaves, preserving tree structures and mapping IDs.
 *
 * @package WPMMCC\Sync
 */

namespace WPMMCC\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Taxonomy_Syncer
 */
class Taxonomy_Syncer {

	/**
	 * Synchronize and assign taxonomies to a target post.
	 *
	 * @param int   $target_post_id Target post ID.
	 * @param array $taxonomies_manifest Map of [taxonomy => array of terms].
	 * @param string $peer_uuid Origin peer UUID.
	 * @return void
	 */
	public static function sync_post_taxonomies( int $target_post_id, array $taxonomies_manifest, string $peer_uuid = '' ): void {
		global $wpdb;

		if ( empty( $taxonomies_manifest ) || ! is_array( $taxonomies_manifest ) ) {
			return;
		}

		foreach ( $taxonomies_manifest as $taxonomy => $terms ) {
			if ( ! taxonomy_exists( $taxonomy ) || ! is_array( $terms ) ) {
				continue;
			}

			$target_term_ids = array();

			// Topological pass: Parents first, then children
			foreach ( $terms as $term_data ) {
				$name        = sanitize_text_field( $term_data['name'] ?? '' );
				$slug        = sanitize_title( $term_data['slug'] ?? $name );
				$parent_slug = sanitize_title( $term_data['parent_slug'] ?? '' );
				$source_id   = (int) ( $term_data['term_id'] ?? 0 );

				if ( '' === $name ) {
					continue;
				}

				// Resolve parent term ID on target site if parent_slug exists
				$target_parent_id = 0;
				if ( '' !== $parent_slug ) {
					$parent_term = get_term_by( 'slug', $parent_slug, $taxonomy );
					if ( $parent_term && ! is_wp_error( $parent_term ) ) {
						$target_parent_id = $parent_term->term_id;
					}
				}

				// Check if term already exists on target site
				$existing = term_exists( $slug, $taxonomy, $target_parent_id );
				$target_term_id = 0;

				if ( $existing ) {
					$target_term_id = is_array( $existing ) ? (int) $existing['term_id'] : (int) $existing;
				} else {
					// Create term on target site
					$created = wp_insert_term(
						$name,
						$taxonomy,
						array(
							'slug'   => $slug,
							'parent' => $target_parent_id,
						)
					);

					if ( ! is_wp_error( $created ) && is_array( $created ) ) {
						$target_term_id = (int) $created['term_id'];
					}
				}

				if ( $target_term_id > 0 ) {
					$target_term_ids[] = $target_term_id;

					// Record term mapping
					if ( '' !== $peer_uuid && $source_id > 0 ) {
						$term_canonical = 'term_' . md5( $taxonomy . '|' . $slug );
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table query.
						$wpdb->replace(
							$wpdb->prefix . 'wpmmcc_cross_mappings',
							array(
								'canonical_uuid'      => $term_canonical,
								'local_object_type'   => 'term',
								'local_object_id'     => $target_term_id,
								'peer_uuid'           => $peer_uuid,
								'remote_object_id'    => $source_id,
								'content_checksum'    => hash( 'sha256', $name . '|' . $slug ),
								'vector_clock'        => 1,
								'last_sync_direction' => 'inbound',
								'last_synced_at'      => current_time( 'mysql' ),
							),
							array( '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s' )
						);
						wp_cache_delete( 'mapping_' . md5( $term_canonical . '_' . $peer_uuid ), 'wpmmcc' );
					}
				}
			}

			// Assign all terms to the target post
			if ( ! empty( $target_term_ids ) ) {
				wp_set_object_terms( $target_post_id, array_unique( $target_term_ids ), $taxonomy, false );
			}
		}
	}
}
