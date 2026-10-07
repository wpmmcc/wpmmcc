<?php
/**
 * WPMMCC conflict-review shadow drafts (WMC-11).
 *
 * When a manual_review peer pushes a version that collides with a local edit,
 * Rest_Push stages the remote version as a private draft (the "shadow") and
 * re-points the canonical mapping at it, so a human can Accept Remote or Keep
 * Local. The shadow is a LOCAL staging copy, not content of the entity: it
 * carries the entity's canonical uuid only so the review can be resolved. This
 * class is the single place that knows what a shadow is, so every path that
 * enumerates, exports, records or resolves entities treats it the same way:
 *
 *  - it is never offered to a peer (digest, pull, export, CDC queue),
 *  - deleting or trashing it never becomes a tombstone for the real entity,
 *  - an entity uuid resolves to the real (displaced) post, not to the shadow,
 *  - a peer cannot write the flag onto a post through packet meta.
 *
 * @package WPMMCC\Sync
 */

namespace WPMMCC\Sync;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Shadow_Draft
 */
class Shadow_Draft {

	/**
	 * Post meta: 1 on a shadow draft (the flag IS the review-queue membership).
	 */
	const META_FLAG = '_wpmmcc_shadow_conflict';

	/**
	 * Post meta: uuid of the peer whose version the shadow holds.
	 */
	const META_ORIGIN = '_wpmmcc_conflict_origin_uuid';

	/**
	 * Post meta: id of the displaced local post (0 on legacy shadows).
	 */
	const META_LOCAL_ID = '_wpmmcc_shadow_local_id';

	/**
	 * Title prefix that marks a shadow in every list screen.
	 */
	const TITLE_PREFIX = '[Sync Conflict Review] ';

	/**
	 * Candidate posts inspected when resolving a uuid through postmeta.
	 */
	const SCAN_LIMIT = 25;

	/**
	 * Post meta a packet may never write: this site's own identity and review
	 * state. A peer that ships them (or an exporter that used to) must not be
	 * able to turn an ordinary post into a shadow, hide it from the digest, or
	 * re-label which entity it is.
	 */
	const LOCAL_ONLY_META = array(
		self::META_FLAG,
		self::META_ORIGIN,
		self::META_LOCAL_ID,
		'_wpmmcc_canonical_uuid',
	);

	/**
	 * Is this post a conflict-review shadow?
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_shadow( int $post_id ): bool {
		return $post_id > 0 && 1 === (int) get_post_meta( $post_id, self::META_FLAG, true );
	}

	/**
	 * May a packet write this post meta key?
	 *
	 * @param string $meta_key Meta key from the wire.
	 * @return bool
	 */
	public static function is_local_only_meta( string $meta_key ): bool {
		return Write_Policy::is_local_meta( $meta_key );
	}

	/**
	 * The title a shadow carries for a remote title (prefix applied once).
	 *
	 * @param string $title Remote title.
	 * @return string
	 */
	public static function prefixed_title( string $title ): string {
		return 0 === strpos( $title, self::TITLE_PREFIX ) ? $title : self::TITLE_PREFIX . $title;
	}

	/**
	 * The displaced local post a shadow stands in for.
	 *
	 * @param int $shadow_id Shadow post ID.
	 * @return int Post ID, 0 when none was recorded (legacy shadow), it no
	 *             longer exists, or it is itself a shadow.
	 */
	public static function counterpart_id( int $shadow_id ): int {
		$local_id = (int) get_post_meta( $shadow_id, self::META_LOCAL_ID, true );
		if ( $local_id <= 0 || $local_id === $shadow_id ) {
			return 0;
		}
		if ( ! get_post( $local_id ) || self::is_shadow( $local_id ) ) {
			return 0;
		}
		return $local_id;
	}

	/**
	 * The post that really is the entity a mapping row points at: the post
	 * itself, or (when the mapping was re-pointed at a shadow) the displaced
	 * local post.
	 *
	 * @param int $mapped_id local_object_id of a cross-mapping row.
	 * @return int Post ID, 0 when the row points at a shadow with no real post.
	 */
	public static function entity_post_id( int $mapped_id ): int {
		if ( $mapped_id <= 0 ) {
			return 0;
		}
		return self::is_shadow( $mapped_id ) ? self::counterpart_id( $mapped_id ) : $mapped_id;
	}

	/**
	 * Resolve a canonical uuid through postmeta, skipping shadows.
	 *
	 * @param string $uuid Canonical uuid.
	 * @return int Post ID or 0.
	 */
	public static function find_post_id_by_uuid( string $uuid ): int {
		global $wpdb;

		if ( '' === $uuid ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Postmeta reverse lookup; no core API resolves a meta value to a post.
		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wpmmcc_canonical_uuid' AND meta_value = %s ORDER BY post_id ASC LIMIT %d",
				$uuid,
				self::SCAN_LIMIT
			)
		);
		foreach ( (array) $candidates as $candidate ) {
			$post_id = (int) ( is_object( $candidate ) ? $candidate->post_id : ( $candidate['post_id'] ?? 0 ) );
			if ( $post_id > 0 && ! self::is_shadow( $post_id ) ) {
				return $post_id;
			}
		}
		return 0;
	}

	/**
	 * Read the review context of a shadow. Fail-closed: only a post that
	 * carries the flag can be resolved through the review actions, so a forged
	 * post id cannot hijack an ordinary post through them.
	 *
	 * @param int $post_id Shadow draft post ID.
	 * @return array{canonical_uuid:string,origin_uuid:string,local_id:int}|WP_Error
	 */
	public static function load_context( int $post_id ) {
		if ( $post_id <= 0 ) {
			return new WP_Error( 'wpmmcc_invalid_post', __( 'Invalid post id.', 'wpmmcc' ) );
		}
		if ( null === get_post( $post_id ) ) {
			return new WP_Error( 'wpmmcc_not_a_post', __( 'Post not found (already removed?).', 'wpmmcc' ) );
		}
		if ( ! self::is_shadow( $post_id ) ) {
			return new WP_Error( 'wpmmcc_not_a_shadow', __( 'That post is not a sync conflict review draft.', 'wpmmcc' ) );
		}
		return array(
			'canonical_uuid' => (string) get_post_meta( $post_id, '_wpmmcc_canonical_uuid', true ),
			'origin_uuid'    => (string) get_post_meta( $post_id, self::META_ORIGIN, true ),
			'local_id'       => (int) get_post_meta( $post_id, self::META_LOCAL_ID, true ),
		);
	}

	/**
	 * Accept Remote: promote the shadow in place.
	 *
	 * The mapping already points at the shadow, so acceptance strips the review
	 * prefix, adopts the displaced post's publish state (or publishes when none
	 * was recorded), clears the review meta and journals the decision. The
	 * displaced post is never deleted; it is detached from the entity instead,
	 * so exactly one post answers to the canonical uuid from here on (the
	 * promoted one) and the displaced post carries on as an ordinary local
	 * post with an identity of its own.
	 *
	 * @param int $post_id Shadow draft post ID.
	 * @return array|WP_Error Review context on success.
	 */
	public static function accept_remote( int $post_id ) {
		$context = self::load_context( $post_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		// The packet status was forced to draft at shadow time: restore the
		// state the original carried instead of blanket-publishing.
		$counterpart = $context['local_id'] > 0 ? get_post( $context['local_id'] ) : null;
		$status      = $counterpart ? (string) $counterpart->post_status : 'publish';

		$shadow = get_post( $post_id );
		$title  = (string) ( $shadow->post_title ?? '' );
		if ( 0 === strpos( $title, self::TITLE_PREFIX ) ) {
			$title = substr( $title, strlen( self::TITLE_PREFIX ) );
		}

		// Ingestion scope: the promoted content is inbound-sourced (the peer
		// sent it), so it must not echo outbound CDC rows.
		$updated = Event_Recorder::run_in_ingestion_scope(
			static function () use ( $post_id, $title, $status ) {
				return wp_update_post(
					array(
						'ID'          => $post_id,
						'post_title'  => $title,
						'post_status' => $status,
					),
					true
				);
			}
		);
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		delete_post_meta( $post_id, self::META_FLAG );
		delete_post_meta( $post_id, self::META_ORIGIN );
		delete_post_meta( $post_id, self::META_LOCAL_ID );

		self::detach_counterpart( (int) $context['local_id'], $context['canonical_uuid'] );

		Event_Recorder::record_journal_entry(
			array(
				'event_type'      => 'conflict_accept_remote',
				'direction'       => 'inbound',
				'canonical_uuid'  => $context['canonical_uuid'],
				'object_type'     => 'post',
				'local_object_id' => $post_id,
				'peer_uuid'       => $context['origin_uuid'],
				'outcome'         => 'success',
				'message'         => sprintf( 'Conflict resolved: remote (shadow draft) accepted as the local representative for %s.', $context['canonical_uuid'] ),
				'consumed'        => 1,
			)
		);

		wp_cache_delete( 'conflict_queue', 'wpmmcc' );
		wp_cache_delete( 'canonical_uuid_post_' . $post_id, 'wpmmcc' );
		return $context;
	}

	/**
	 * Keep Local: re-point the canonical mapping onto the displaced post and
	 * discard the staging shadow.
	 *
	 * The mapping keeps its content_checksum / vector_clock, so the peer's next
	 * digest of the SAME revision verdicts in_sync instead of re-offering it;
	 * last_synced_at is stamped now (the local edit is hereby the synced
	 * state, not drift). The next genuinely NEW remote revision still
	 * progresses normally.
	 *
	 * @param int $post_id Shadow draft post ID.
	 * @return array|WP_Error Review context on success.
	 */
	public static function keep_local( int $post_id ) {
		global $wpdb;

		$context = self::load_context( $post_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		if ( $context['local_id'] <= 0 ) {
			return new WP_Error( 'wpmmcc_no_counterpart', __( 'This shadow draft has no recorded local counterpart (legacy shadow). Keep Local needs the original mapping target; resolve manually (edit the shadow, or Accept Remote).', 'wpmmcc' ) );
		}
		if ( ! get_post( $context['local_id'] ) ) {
			return new WP_Error( 'wpmmcc_counterpart_missing', __( 'The recorded local counterpart no longer exists. Resolve manually (edit the shadow, or Accept Remote).', 'wpmmcc' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom mapping table update.
		$updated = $wpdb->update(
			$wpdb->prefix . 'wpmmcc_cross_mappings',
			array(
				'local_object_id'     => $context['local_id'],
				'last_sync_direction' => 'outbound',
				'last_synced_at'      => current_time( 'mysql' ),
			),
			array(
				'canonical_uuid'  => $context['canonical_uuid'],
				'local_object_id' => $post_id,
			),
			array( '%d', '%s', '%s' ),
			array( '%s', '%d' )
		);
		if ( false === $updated ) {
			return new WP_Error( 'wpmmcc_mapping_update_failed', __( 'Mapping update failed while keeping the local version; nothing was deleted. Retry.', 'wpmmcc' ) );
		}

		// Ingestion scope: the staging shadow is inbound-sourced; deleting it
		// must not echo an outbound delete CDC row (which a peer would
		// materialize as a tombstone of ITS original).
		Event_Recorder::run_in_ingestion_scope(
			static function () use ( $post_id ) {
				wp_delete_post( $post_id, true );
			}
		);

		Event_Recorder::record_journal_entry(
			array(
				'event_type'      => 'conflict_keep_local',
				'direction'       => 'outbound',
				'canonical_uuid'  => $context['canonical_uuid'],
				'object_type'     => 'post',
				'local_object_id' => $context['local_id'],
				'peer_uuid'       => $context['origin_uuid'],
				'outcome'         => 'success',
				'message'         => sprintf( 'Conflict resolved: local version kept for %s; shadow staging draft removed and mapping re-pointed.', $context['canonical_uuid'] ),
				'consumed'        => 1,
			)
		);

		// Same key set handle_push refreshes for a re-pointed mapping, plus the queue.
		wp_cache_delete( 'mapping_' . md5( $context['canonical_uuid'] . '_' . $context['origin_uuid'] ), 'wpmmcc' );
		wp_cache_delete( 'canonical_mapping_' . md5( $context['canonical_uuid'] ), 'wpmmcc' );
		wp_cache_delete( 'canonical_uuid_post_' . $context['local_id'], 'wpmmcc' );
		wp_cache_delete( 'vclock_' . $context['local_id'], 'wpmmcc' );
		wp_cache_delete( 'conflict_queue', 'wpmmcc' );
		return $context;
	}

	/**
	 * Release the displaced post from the entity it used to be: drop its
	 * canonical uuid and every mapping row that binds it to that uuid. It is
	 * picked up as a new entity of its own the next time the catalog is read.
	 *
	 * @param int    $local_id Displaced post ID (0 = none recorded).
	 * @param string $uuid     Canonical uuid of the entity.
	 * @return void
	 */
	private static function detach_counterpart( int $local_id, string $uuid ): void {
		global $wpdb;

		if ( $local_id <= 0 || '' === $uuid || self::is_shadow( $local_id ) ) {
			return;
		}
		if ( $uuid !== (string) get_post_meta( $local_id, '_wpmmcc_canonical_uuid', true ) ) {
			return;
		}

		delete_post_meta( $local_id, '_wpmmcc_canonical_uuid' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom mapping table delete.
		$wpdb->delete(
			$wpdb->prefix . 'wpmmcc_cross_mappings',
			array(
				'canonical_uuid'  => $uuid,
				'local_object_id' => $local_id,
			),
			array( '%s', '%d' )
		);
		wp_cache_delete( 'canonical_uuid_post_' . $local_id, 'wpmmcc' );
		wp_cache_delete( 'vclock_' . $local_id, 'wpmmcc' );
		wp_cache_delete( 'cross_site_alt_' . $local_id, 'wpmmcc' );
		wp_cache_delete( 'canonical_mapping_' . md5( $uuid ), 'wpmmcc' );
	}
}
