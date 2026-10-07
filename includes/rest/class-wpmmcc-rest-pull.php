<?php
/**
 * WPMMCC REST Pull Controller (POST /wp-json/wpmmcc/v1/sync/pull).
 *
 * Allows authorized peers to pull Sync Packets for specified canonical UUIDs.
 *
 * @package WPMMCC\REST
 */

namespace WPMMCC\REST;

use WPMMCC\Sync\Packet;
use WPMMCC\Sync\Shadow_Draft;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Rest_Pull
 */
class Rest_Pull extends Rest_Base {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/sync/pull',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_pull' ),
				'permission_callback' => array( 'WPMMCC\REST\Rest_Middleware', 'verify_hmac' ),
			)
		);
	}

	/**
	 * Handle pull request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_pull( WP_REST_Request $request ) {
		global $wpdb;

		$params = $request->get_json_params() ?: $request->get_params();
		$uuids  = $params['canonical_uuids'] ?? array();

		if ( ! is_array( $uuids ) || empty( $uuids ) ) {
			return $this->format_error( 'wpmmcc_missing_uuids', __( 'canonical_uuids array must be provided.', 'wpmmcc' ), 400 );
		}

		$packets = array();

		foreach ( array_slice( $uuids, 0, 50 ) as $uuid ) {
			$uuid = sanitize_text_field( $uuid );

			$cache_key = 'canonical_mapping_' . md5( $uuid );
			$row       = wp_cache_get( $cache_key, 'wpmmcc' );
			if ( false === $row ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom mapping table query.
				$row = $wpdb->get_row(
					$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpmmcc_cross_mappings WHERE canonical_uuid = %s LIMIT 1", $uuid )
				);
				if ( $row ) {
					wp_cache_set( $cache_key, $row, 'wpmmcc', 300 );
				}
			}

			// §68 (Path A pull gap): the digest resolves canonical uuids via
			// Event_Recorder::resolve_or_create_canonical_uuid(), which stores
			// FIRST-SEEN uuids in postmeta (_wpmmcc_canonical_uuid) and only
			// back-fills a cross_mappings self row when the post is later
			// saved or exported. A freshly paired source therefore serves
			// digest uuids that have no cross_mappings row yet, and this
			// table-only lookup skipped every item (pull always returned 0
			// packets). Fall back to a postmeta reverse lookup so the same
			// uuid store the digest reads is the one this pull resolves.
			if ( ! $row ) {
				$meta_post_id = Shadow_Draft::find_post_id_by_uuid( $uuid );
				if ( $meta_post_id > 0 ) {
					$row = (object) array(
						'local_object_type' => 'post',
						'local_object_id'   => $meta_post_id,
					);
				}
			}

			if ( ! $row || 'post' !== $row->local_object_type ) {
				continue;
			}

			// A mapping re-pointed at a conflict-review draft stands for the
			// displaced local post. Serve that post, never the draft (WMC-11).
			$mapped_id = (int) $row->local_object_id;
			$post_id   = Shadow_Draft::entity_post_id( $mapped_id );
			if ( $post_id <= 0 && Shadow_Draft::is_shadow( $mapped_id ) ) {
				$post_id = Shadow_Draft::find_post_id_by_uuid( $uuid );
			}

			$post = $post_id > 0 ? get_post( $post_id ) : null;
			if ( ! $post ) {
				continue;
			}

			$packet = Packet::export_post( $post_id );
			if ( ! is_wp_error( $packet ) ) {
				$packets[] = $packet;
			}
		}

		return $this->format_success( array( 'packets' => $packets ), 200 );
	}
}
