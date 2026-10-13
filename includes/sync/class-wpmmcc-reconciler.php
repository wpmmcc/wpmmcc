<?php
/**
 * WPMMCC Reconciliation & CDC Engine (WP-A-06, doc 09 §4, doc 14 §6).
 *
 * Implements full content synchronization scanning, CDC queue consumption,
 * and SHA-256 fingerprint drift reconciliation between paired sites.
 *
 * @package WPMMCC\Sync
 */

namespace WPMMCC\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Reconciler
 */
class Reconciler {

	/**
	 * Post types a digest covers when the caller names none. The same set on
	 * the serving side (what a pulling peer is offered) and the scanning side
	 * (the local reconcile sweep), so neither leg is blind to products.
	 *
	 * @var string[]
	 */
	const DEFAULT_POST_TYPES = array( 'post', 'page', 'product' );

	/**
	 * Compare a batch of fingerprints from remote peer against local cross mappings.
	 *
	 * Verdict semantics (doc 09 §4):
	 *  - in_sync:       submitted fingerprint equals the entity's current state.
	 *  - missing:       no mapping row AND no local entity → source deleted it.
	 *  - source_newer:  local state drifted past the submitted fingerprint.
	 *  - local_edited:  mapping row exists, checksum drifted, remote clock did
	 *                   not advance past the stored clock (conservative arm).
	 *
	 * FL-16 (doc 24 §四.5): when no mapping row exists for (uuid, peer) — the
	 * normal case in the client-mediated topology, where the caller's peer
	 * uuid is the mediator's and mapping rows are keyed by origin site uuids —
	 * fall back to live-entity reconciliation: resolve the local post by
	 * canonical uuid, recompute its CURRENT fingerprint with the exact
	 * Packet::export_post() construction (FL-15), and verdict against that.
	 * Without this arm every mediated rotation verdicts 'missing' and the
	 * client tombstones its entire known set at the target.
	 *
	 * @param array  $remote_fingerprints Array of ['canonical_uuid' => string, 'source_fingerprint' => string, 'vector_clock' => int].
	 * @param string $peer_uuid Peer UUID.
	 * @return array Verdicts keyed by canonical_uuid: 'in_sync' | 'missing' | 'source_newer' | 'local_edited'.
	 */
	public static function reconcile_fingerprints( array $remote_fingerprints, string $peer_uuid ): array {
		global $wpdb;

		$verdicts = array();

		foreach ( $remote_fingerprints as $item ) {
			$uuid         = sanitize_text_field( $item['canonical_uuid'] ?? '' );
			$remote_fp    = sanitize_text_field( $item['source_fingerprint'] ?? '' );
			$remote_clock = (int) ( $item['vector_clock'] ?? 1 );

			if ( '' === $uuid ) {
				continue;
			}

			$cache_key     = 'mapping_' . md5( $uuid . '_' . $peer_uuid );
			$local_mapping = wp_cache_get( $cache_key, 'wpmmcc' );
			if ( false === $local_mapping ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table or connection command; WordPress has no replacement API
				$local_mapping = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$wpdb->prefix}wpmmcc_cross_mappings WHERE canonical_uuid = %s AND peer_uuid = %s LIMIT 1",
						$uuid,
						$peer_uuid
					)
				);
				if ( $local_mapping ) {
					wp_cache_set( $cache_key, $local_mapping, 'wpmmcc', 300 );
				}
			}

			if ( ! $local_mapping ) {
				// FL-16: no row for this peer — reconcile against the live entity.
				$verdicts[ $uuid ] = self::verdict_against_live_entity( $uuid, $remote_fp, $remote_clock );
				continue;
			}

			// In-sync check
			if ( hash_equals( $local_mapping->content_checksum, $remote_fp ) ) {
				$verdicts[ $uuid ] = 'in_sync';
				continue;
			}

			// If remote clock is ahead of local mapped clock
			if ( $remote_clock > (int) $local_mapping->vector_clock ) {
				$verdicts[ $uuid ] = 'source_newer';
			} else {
				// Local may have diverged
				$verdicts[ $uuid ] = 'local_edited';
			}
		}

		return $verdicts;
	}

	/**
	 * FL-16 fallback arm: verdict a submitted fingerprint against the live
	 * local entity identified by canonical uuid.
	 *
	 * Contract (mirrors the client's verified simulation model):
	 *   entity gone  → 'missing'
	 *   fp equal     → 'in_sync'
	 *   fp differs   → 'source_newer'
	 *
	 * Convergence: a source_newer verdict makes the client re-pull; export_post()
	 * ships clock+1 and (FL-14) persists it, so the recomputed fingerprint then
	 * equals the newly shipped one and the next rotation returns in_sync.
	 *
	 * @param string $uuid Canonical uuid.
	 * @param string $remote_fp Submitted fingerprint.
	 * @param int    $remote_clock Submitted vector clock (unused by the pure
	 *                             fingerprint contract; kept for signature clarity).
	 * @return string Verdict.
	 */
	private static function verdict_against_live_entity( string $uuid, string $remote_fp, int $remote_clock ): string {
		$local_id = self::find_local_post_id_for_uuid( $uuid );
		if ( $local_id <= 0 ) {
			return 'missing';
		}

		$post = get_post( $local_id );
		if ( ! $post ) {
			return 'missing';
		}

		// Current clock: the source-side self row (FL-14 bump/persist), else 1.
		global $wpdb;
		$clock_key = 'vclock_' . $local_id;
		$clock     = wp_cache_get( $clock_key, 'wpmmcc' );
		if ( false === $clock ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table or connection command; WordPress has no replacement API
			$clock = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT vector_clock FROM {$wpdb->prefix}wpmmcc_cross_mappings WHERE local_object_type = 'post' AND local_object_id = %d LIMIT 1",
					$local_id
				)
			);
			wp_cache_set( $clock_key, $clock, 'wpmmcc', 300 );
		}
		$clock = (int) $clock;
		if ( $clock <= 0 ) {
			$clock = 1;
		}

		$current_fp = self::current_fingerprint_for_post( $post, $local_id, $clock );

		if ( '' !== $remote_fp && hash_equals( $current_fp, $remote_fp ) ) {
			return 'in_sync';
		}
		return 'source_newer';
	}

	/**
	 * FL-15 (doc 24 §四.5): resolve the local post id for a canonical uuid.
	 *
	 * Mapping row first (self rows carry canonical_uuid since FL-14; inbound
	 * rows also match), then the _wpmmcc_canonical_uuid postmeta fallback
	 * that Event_Recorder::resolve_or_create_canonical_uuid() writes.
	 *
	 * @param string $uuid Canonical uuid.
	 * @return int Local post id or 0.
	 */
	private static function find_local_post_id_for_uuid( string $uuid ): int {
		global $wpdb;

		if ( '' === $uuid ) {
			return 0;
		}

		$cache_key = 'local_post_uuid_' . md5( $uuid );
		$local_id  = wp_cache_get( $cache_key, 'wpmmcc' );
		if ( false === $local_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table or connection command; WordPress has no replacement API
			$local_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT local_object_id FROM {$wpdb->prefix}wpmmcc_cross_mappings WHERE canonical_uuid = %s AND local_object_type = 'post' LIMIT 1",
					$uuid
				)
			);
			if ( $local_id > 0 ) {
				wp_cache_set( $cache_key, $local_id, 'wpmmcc', 300 );
			}
		}
		$local_id = (int) $local_id;
		// A mapping re-pointed at a conflict-review draft stands for the
		// displaced local post, which is the entity this site really has (WMC-11).
		$local_id = Shadow_Draft::entity_post_id( $local_id );
		if ( $local_id > 0 ) {
			return $local_id;
		}

		// Postmeta fallback (shadows skipped).
		return Shadow_Draft::find_post_id_by_uuid( $uuid );
	}

	/**
	 * FL-15 (doc 24 §四.5): canonical entity fingerprint, byte-identical to
	 * Packet::export_post() + Packet::build() construction.
	 *
	 * Pre-fix generate_digest() hashed ONLY {post_title, post_content} while
	 * packets hashed all seven core fields — the two fingerprint formats could
	 * never be equal, so 'in_sync' was unreachable for digest-sourced
	 * fingerprints and drift verdicts degenerated to clock comparisons.
	 *
	 * @param \WP_Post|object $post Post object (WP_Post or a raw digest row).
	 * @param int             $local_id Local post id (meta lookup).
	 * @param int             $clock Current vector clock.
	 * @return string sha256(origin_uuid|clock|content_hash) — packet format.
	 */
	public static function current_fingerprint_for_post( $post, int $local_id, int $clock ): string {
		$core_fields = array(
			'post_title'     => $post->post_title,
			'post_content'   => $post->post_content,
			'post_excerpt'   => $post->post_excerpt ?? '',
			'post_date_gmt'  => $post->post_date_gmt ?? '',
			'comment_status' => $post->comment_status ?? 'open',
			'ping_status'    => $post->ping_status ?? 'open',
			'menu_order'     => (int) ( $post->menu_order ?? 0 ),
		);

		$raw_meta   = get_post_meta( $local_id );
		$meta_clean = Packet::sanitize_meta_for_export( is_array( $raw_meta ) ? $raw_meta : array() );

		$content_hash = hash( 'sha256', wp_json_encode( $core_fields ) . wp_json_encode( $meta_clean ) );
		$origin_uuid  = (string) get_option( 'wpmmcc_origin_uuid', '' );

		return hash( 'sha256', $origin_uuid . '|' . $clock . '|' . $content_hash );
	}

	/**
	 * Active peers (status = 'active'), request-cached.
	 *
	 * The cache key is invalidated by every peer-lifecycle handler (connect,
	 * handshake, disconnect), so writers such as Event_Recorder can rely on it
	 * to decide who a change must be queued for.
	 *
	 * @return array[] Peer rows (ARRAY_A).
	 */
	public static function get_active_peers(): array {
		global $wpdb;

		$peers = wp_cache_get( 'active_peers_list', 'wpmmcc' );
		if ( false === $peers ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table or connection command; WordPress has no replacement API
			$peers = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}wpmmcc_peers WHERE status = %s",
					'active'
				),
				\ARRAY_A
			);
			wp_cache_set( 'active_peers_list', $peers, 'wpmmcc', 60 );
		}

		return is_array( $peers ) ? $peers : array();
	}

	/**
	 * Get pending CDC journal events, oldest first.
	 *
	 * Never cached: a queue read served from a stale cache re-ships events
	 * that were just acked (WMC-09). Shipping uses Push_Queue::pending(),
	 * which adds per-peer scoping and retry ordering.
	 *
	 * @param int $limit Maximum events to fetch.
	 * @return array
	 */
	public static function get_pending_cdc_events( int $limit = 50 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- pending journal page; a cached page would resend events that were just acked
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wpmmcc_journal WHERE direction = 'outbound' AND consumed = 0 ORDER BY id ASC LIMIT %d",
				$limit
			),
			\ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Mark CDC journal events as consumed.
	 *
	 * @param int[] $event_ids List of journal IDs.
	 * @return void
	 */
	public static function mark_cdc_events_consumed( array $event_ids ): void {
		global $wpdb;

		if ( empty( $event_ids ) ) {
			return;
		}

		$ids = array_values( array_map( 'intval', $event_ids ) );
		foreach ( $ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table or connection command; WordPress has no replacement API
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}wpmmcc_journal SET consumed = 1 WHERE id = %d",
					$id
				)
			);
		}
		wp_cache_delete( 'admin_journal_recent', 'wpmmcc' );
		$journal_generation = wp_cache_get( 'wpmmcc_journal_generation', 'wpmmcc' );
		wp_cache_set( 'wpmmcc_journal_generation', ( is_int( $journal_generation ) ? $journal_generation : 0 ) + 1, 'wpmmcc' );
	}

	/**
	 * Generate lightweight entity fingerprint digest for scheduled reconciliation (doc 09 §4.1).
	 * Uses Keyset Cursor Pagination on ID for performance (O(log n)).
	 *
	 * @param int   $limit Keyset limit.
	 * @param int   $last_seen_id Keyset cursor for pagination.
	 * @param array $post_types Post types to scan.
	 * @return array List of digests: ['canonical_uuid', 'source_fingerprint', 'vector_clock', 'local_id', 'post_status'].
	 */
	public static function generate_digest( int $limit = 100, int $last_seen_id = 0, array $post_types = self::DEFAULT_POST_TYPES ): array {
		global $wpdb;

		$clean_post_types = array_values( array_filter( array_map( 'sanitize_key', $post_types ) ) );
		if ( empty( $clean_post_types ) ) {
			$clean_post_types = self::DEFAULT_POST_TYPES;
		}

		$cache_key = 'digest_posts_' . md5( implode( ',', $clean_post_types ) . '_' . $last_seen_id . '_' . $limit );
		$rows      = wp_cache_get( $cache_key, 'wpmmcc' );
		if ( false === $rows ) {
			// Conflict-review drafts are excluded IN the query, not after it: a
			// page that lost rows to a post-filter comes back short, and a short
			// page reads as "end of catalog" to the client (WMC-11).
			$merged = array();
			foreach ( $clean_post_types as $post_type ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table or connection command; WordPress has no replacement API
				$type_rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT ID, post_title, post_content, post_excerpt, post_date_gmt, comment_status, ping_status, menu_order, post_status, post_modified_gmt, post_type FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish', 'draft', 'trash') AND ID NOT IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s ) AND ID > %d ORDER BY ID ASC LIMIT %d",
						$post_type,
						Shadow_Draft::META_FLAG,
						'1',
						$last_seen_id,
						$limit
					)
				);
				if ( is_array( $type_rows ) ) {
					foreach ( $type_rows as $type_row ) {
						$merged[] = $type_row;
					}
				}
			}
			usort(
				$merged,
				static function ( $left, $right ): int {
					return (int) $left->ID <=> (int) $right->ID;
				}
			);
			$rows = array_slice( $merged, 0, $limit );
			if ( '' !== (string) $wpdb->last_error ) {
				$rows = array();
			}
			wp_cache_set( $cache_key, $rows, 'wpmmcc', 30 );
		}

		$digest = array();
		if ( empty( $rows ) ) {
			return $digest;
		}

		foreach ( $rows as $row ) {
			$local_id = (int) $row->ID;
			$uuid     = Event_Recorder::resolve_or_create_canonical_uuid( 'post', $local_id );

			// Retrieve vector clock
			$clock_key = 'vclock_' . $local_id;
			$clock     = wp_cache_get( $clock_key, 'wpmmcc' );
			if ( false === $clock ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table or connection command; WordPress has no replacement API
				$clock = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT vector_clock FROM {$wpdb->prefix}wpmmcc_cross_mappings WHERE local_object_type = 'post' AND local_object_id = %d LIMIT 1",
						$local_id
					)
				) ?: 1;
				wp_cache_set( $clock_key, $clock, 'wpmmcc', 300 );
			}

			// FL-15 (doc 24 §四.5): fingerprint via the canonical construction
			// (identical to Packet::export_post()) so digest fingerprints and
			// packet checksums are directly comparable — pre-fix the digest
			// hashed only {title, content} and 'in_sync' was unreachable.
			$fingerprint = self::current_fingerprint_for_post( $row, $local_id, (int) $clock );

			$digest[] = array(
				'canonical_uuid'     => $uuid,
				'source_fingerprint' => $fingerprint,
				'vector_clock'       => $clock,
				'local_id'           => $local_id,
				'post_status'        => $row->post_status,
				'post_type'          => $row->post_type,
				'last_modified'      => $row->post_modified_gmt ?? '',
			);
		}

		return $digest;
	}

	/**
	 * Fingerprint window submitted to one peer per scheduled run (WMC-08 [B]).
	 * The window is keyset-cursored PER PEER and wraps at the catalog end, so
	 * the whole catalog is covered across runs — a per-RUN bound, not a
	 * coverage limit (previously only the first 100 entities were compared).
	 */
	const RECONCILE_WINDOW = 100;

	/**
	 * Upper bound on drift push ATTEMPTS per peer per scheduled run (accepted
	 * or not), so a peer that refuses everything still costs a bounded number
	 * of requests.
	 */
	const MAX_DRIFT_PUSHES_PER_PEER = 20;

	/**
	 * Option that holds one peer's reconcile-sweep keyset cursor (WMC-08).
	 *
	 * @param string $peer_uuid Peer UUID.
	 * @return string
	 */
	public static function reconcile_cursor_option( string $peer_uuid ): string {
		return 'wpmmcc_reconcile_cursor_' . md5( $peer_uuid );
	}

	/**
	 * Forget a disconnected peer's sweep cursors (reconcile and pull). Its
	 * cross-mappings are cascaded away with it, so a re-paired peer has to
	 * start its passes at the top of the catalog, not mid-way.
	 *
	 * @param string $peer_uuid Peer UUID.
	 * @return void
	 */
	public static function forget_peer_cursors( string $peer_uuid ): void {
		delete_option( self::reconcile_cursor_option( $peer_uuid ) );
		delete_option( Manual_Sync::pull_cursor_option( $peer_uuid ) );
	}

	/**
	 * Run scheduled reconciliation for all active peers (doc 09 §4, WordPress Cron).
	 *
	 * 3.8flash A5 (tasks/cursor feedback): the loop previously produced no
	 * peer-side effects — it compared the local digest against local mapping
	 * rows (no wp_remote_* call anywhere), consumed up to 100 outbound CDC
	 * events without shipping anything, and incremented entities_fixed
	 * without fixing any entity. It now reuses the SAME transport legs the
	 * manual console (Manual_Sync) and the REST surface use, so the
	 * scheduled loop cannot drift from the wire contract:
	 *
	 * 1. Push leg (per push-capable peer): ship pending CDC packets
	 *    (Manual_Sync::push_now — bounded; consumed only on peer ack).
	 * 2. Reconcile leg (per push-capable peer): submit this peer's current
	 *    fingerprint sweep window to the peer's /sync/reconcile-digest and
	 *    push packets for entities the peer verdicts missing / source_newer
	 *    (bounded per run; the window is keyset-cursored per peer and wraps
	 *    at the catalog end, so the whole catalog is covered across runs —
	 *    WMC-08 [B], previously only the first 100 entities ever compared).
	 * 3. Pull leg (per pull-capable peer): ingest entities the peer serves
	 *    that have no local mapping yet (Manual_Sync::pull_now — bounded).
	 * 4. Logs a summary with the real counters in wpmmcc_journal.
	 *
	 * @return array Summary of reconciliation actions.
	 */
	public static function run_scheduled_reconcile(): array {
		$summary = array(
			'cdc_consumed'   => 0,
			'peers_scanned'  => 0,
			'entities_fixed' => 0,
			'trashed_synced' => 0,
			'errors'         => array(),
		);

		// 0. Optional confirmed audit retention. No peers or elapsed time
		// alone authorize deletion, and unfinished deliveries never expire.
		Push_Queue::prune();

		// 1. Fetch active peers
		$peers = self::get_active_peers();

		if ( empty( $peers ) ) {
			return $summary;
		}

		$summary['peers_scanned'] = count( $peers );

		foreach ( $peers as $peer ) {
			$peer_uuid = (string) ( $peer['peer_uuid'] ?? '' );
			$endpoint  = rtrim( (string) ( $peer['endpoint_url'] ?? '' ), '/' );
			$direction = (string) ( $peer['direction'] ?? 'bidirectional' );

			// 2. This peer's fingerprint sweep window (WMC-08 [B]): the
			// scheduled reconcile previously compared only the FIRST 100
			// entities — drift beyond them was never reconciled. The window
			// is per-peer and keyset-cursored; a short page wraps to 0, so
			// successive runs cover the WHOLE catalog in bounded windows.
			$cursor_key = self::reconcile_cursor_option( $peer_uuid );
			$cursor     = max( 0, (int) get_option( $cursor_key, 0 ) );
			$window     = self::generate_digest( self::RECONCILE_WINDOW, $cursor );
			$wrap       = count( $window ) < self::RECONCILE_WINDOW;
			$max_id     = $cursor;

			// Trash alignment observation (actual trash propagation rides the
			// CDC trash events on the push leg; the fingerprint model
			// deliberately excludes post_status — FL-15 byte-parity with packets).
			foreach ( $window as $item ) {
				if ( 'trash' === ( $item['post_status'] ?? '' ) ) {
					$summary['trashed_synced']++;
				}
				$max_id = max( $max_id, (int) ( $item['local_id'] ?? 0 ) );
			}

			if ( '' === $endpoint ) {
				$summary['errors'][] = sprintf( 'Peer %s: no endpoint URL - remote legs skipped', $peer_uuid );
				continue;
			}

			$can_push = 'pull_only' !== $direction;
			$can_pull = 'push_only' !== $direction;

			// 3a. Push leg: ship pending CDC packets (bounded per run).
			if ( $can_push ) {
				$pushed = Manual_Sync::push_now( $peer, 'scheduled' );
				$summary['cdc_consumed']   += (int) $pushed['pushed'];
				$summary['entities_fixed'] += (int) $pushed['pushed'];
				if ( (int) $pushed['failed'] > 0 && count( $summary['errors'] ) < 10 ) {
					$summary['errors'][] = sprintf( 'Peer %s: CDC push - %s', $peer_uuid, $pushed['message'] );
				}
			}

			// 3b. Reconcile leg: submit this peer's sweep window and push
			// drift. The cursor moves only past entities that were actually
			// reconciled, so a window lost to an outage — or drift beyond the
			// per-run push cap — is picked up by the next run instead of
			// waiting for the whole catalog to be swept again.
			if ( $can_push ) {
				$drift = self::push_drift_for_peer( $peer, $peer_uuid, $window, $cursor, $summary['errors'] );
				$summary['entities_fixed'] += $drift['pushed'];
				$covered = $drift['resolved_through'] >= $max_id;
				// Cursor options are read only by this sweep: never autoload them.
				update_option( $cursor_key, ( $covered && $wrap ) ? 0 : $drift['resolved_through'], false );
			}

			// 3c. Pull leg: ingest entities the peer serves that we lack.
			if ( $can_pull ) {
				$pulled = Manual_Sync::pull_now( $peer, 'scheduled' );
				$summary['entities_fixed'] += (int) $pulled['pulled'];
				if ( (int) $pulled['failed'] > 0 && count( $summary['errors'] ) < 10 ) {
					$summary['errors'][] = sprintf( 'Peer %s: pull - %s', $peer_uuid, $pulled['message'] );
				}
			}
		}

		// 4. Record summary in wpmmcc_journal
		Event_Recorder::record_journal_entry(
			array(
				'event_type'      => 'reconcile_summary',
				'direction'       => 'local',
				'canonical_uuid'  => 'reconcile_' . gmdate( 'Ymd_His' ),
				'object_type'     => 'system',
				'local_object_id' => 0,
				'outcome'         => empty( $summary['errors'] ) ? 'success' : 'partial',
				'diff_summary'    => $summary,
				'consumed'        => 1,
			)
		);

		return $summary;
	}

	/**
	 * Reconcile leg for one peer: submit the sweep window's fingerprints to
	 * the peer's /sync/reconcile-digest, then push packets for entities the
	 * peer verdicts missing / source_newer (real peer-side effects, bounded).
	 *
	 * local_edited verdicts are skipped by design: the peer has diverged
	 * locally and forcing our copy over it would race the arbiter/review flow.
	 *
	 * Sweep bookkeeping (WMC-08): the window is walked in ascending local ID
	 * and `resolved_through` is the highest ID up to which EVERY window entity
	 * is settled, so the caller can advance its cursor without skipping work:
	 *  - a window the peer never answered (transport error / non-2xx) settles
	 *    nothing — the next run re-submits it;
	 *  - an entity the peer rejects (an entity-level 4xx, or a plain 5xx: the
	 *    same run's reconcile-digest just proved the peer alive) or that cannot
	 *    be exported is settled: it is reported and gets another chance on the
	 *    next pass, but it must not stall the sweep (head-of-line blocking,
	 *    cf. WMC-01);
	 *  - a dead push transport, an answer that says the peer path is
	 *    unavailable (rate limit, timeout, gateway error, maintenance, auth or
	 *    path trouble, a peer-wide error code — Manual_Sync::classify_push_response
	 *    'outage'), or work beyond MAX_DRIFT_PUSHES_PER_PEER attempts, stops
	 *    the walk: the first entity it did not handle stays ahead of the
	 *    cursor. An outage says nothing about the entity, so marching on would
	 *    skip every entity the peer refused while it was overloaded.
	 *
	 * @param array    $peer Peer row (endpoint_url/shared_secret/key_salt).
	 * @param string   $peer_uuid Peer UUID.
	 * @param array    $local_digest Sweep window items (ascending local_id).
	 * @param int      $cursor Cursor the window started after; what `resolved_through` falls back to when nothing was settled.
	 * @param string[] $errors Summary errors sink (bounded by caller).
	 * @return array{pushed:int,resolved_through:int}
	 */
	private static function push_drift_for_peer( array $peer, string $peer_uuid, array $local_digest, int $cursor, array &$errors ): array {
		$fingerprints = array();
		$local_ids    = array();
		$window_max   = $cursor;
		foreach ( $local_digest as $item ) {
			$window_max = max( $window_max, (int) ( $item['local_id'] ?? 0 ) );
			$uuid       = (string) ( $item['canonical_uuid'] ?? '' );
			if ( '' === $uuid ) {
				continue;
			}
			$fingerprints[] = array(
				'canonical_uuid'     => $uuid,
				'source_fingerprint' => (string) ( $item['source_fingerprint'] ?? '' ),
				'vector_clock'       => (int) ( $item['vector_clock'] ?? 1 ),
			);
			$local_ids[ $uuid ] = (int) ( $item['local_id'] ?? 0 );
		}

		if ( empty( $fingerprints ) ) {
			// Nothing submittable, so nothing to hold the sweep back for.
			return array( 'pushed' => 0, 'resolved_through' => $window_max );
		}

		$resp = Manual_Sync::remote_call( $peer, '/sync/reconcile-digest', array( 'fingerprints' => $fingerprints ) );
		if ( is_wp_error( $resp ) ) {
			if ( count( $errors ) < 10 ) {
				$errors[] = sprintf( 'Peer %s: reconcile-digest - %s', $peer_uuid, $resp->get_error_message() );
			}
			return array( 'pushed' => 0, 'resolved_through' => $cursor );
		}

		$status   = (int) ( $resp['status'] ?? 0 );
		$verdicts = is_array( $resp['json']['data']['verdicts'] ?? null ) ? $resp['json']['data']['verdicts'] : array();
		if ( $status < 200 || $status >= 300 || count( $verdicts ) !== count( $local_ids )
			|| array_diff( array_keys( $verdicts ), array_keys( $local_ids ) )
			|| array_diff( array_values( $verdicts ), array( 'in_sync', 'missing', 'source_newer', 'local_edited' ) ) ) {
			if ( count( $errors ) < 10 ) {
				$errors[] = sprintf( 'Peer %s: reconcile-digest HTTP %d', $peer_uuid, $status );
			}
			return array( 'pushed' => 0, 'resolved_through' => $cursor );
		}

		$attempts = 0;
		$pushed   = 0;
		$resolved = $cursor;
		foreach ( $local_ids as $uuid => $local_id ) {
			$verdict = $verdicts[ $uuid ] ?? '';
			if ( $local_id > 0 && ( 'missing' === $verdict || 'source_newer' === $verdict ) ) {
				// The cap bounds work, not just accepted pushes: a peer that
				// rejects every entity must not cost a request per drifted
				// entity of the whole window on every run.
				if ( $attempts >= self::MAX_DRIFT_PUSHES_PER_PEER ) {
					break; // Per-run cap: this entity is the next run's first.
				}
				++$attempts;

				try {
					$fp = '';
					foreach ( $fingerprints as $entry ) {
						if ( $entry['canonical_uuid'] === $uuid ) {
							$fp = $entry['source_fingerprint'];
							break;
						}
					}
					$push_resp = Outbound_Delivery::deliver( $peer, array(
						'canonical_uuid' => $uuid, 'local_object_id' => $local_id, 'source_fingerprint' => $fp,
					), static function () use ( $local_id, $peer ) {
						return Packet::export_post( $local_id, array( 'target_lang' => $peer['target_lang'] ?? 'zh_CN',
							'source_lang' => $peer['source_lang'] ?? get_locale(), 'sync_mode' => $peer['sync_mode'] ?? 'sync_only' ) );
					} );
				} catch ( \Throwable $e ) {
					// A poison entity (an adapter that throws) is one failed
					// entity: it must not abort the run, which would also
					// keep the cursor from ever moving past it.
					$push_resp = new \WP_Error( 'wpmmcc_export_exception', 'Original drift delivery could not be confirmed.' );
				}
				if ( is_wp_error( $push_resp ) ) {
					if ( count( $errors ) < 10 ) {
						$errors[] = sprintf( 'Peer %s: drift %s - %s', $peer_uuid, $uuid, $push_resp->get_error_message() );
					}
					if ( 'wpmmcc_outbound_export_failed' === $push_resp->get_error_code() ) {
						// A pre-intent export fault has no target effect.
						// Keep its audit, visit it again next sweep, and
						// do not starve healthy neighbours in this window.
						$resolved = $local_id;
						continue;
					}
					break;
				} else {
					$packet = $push_resp['packet'] ?? array();
					$push_status = (int) ( $push_resp['status'] ?? 0 );
					$push_json   = (array) ( $push_resp['json'] ?? array() );
					$push_class  = Manual_Sync::classify_push_response( $push_status, $push_json, $packet, $peer );
					if ( 'ok' === $push_class ) {
						$pushed++;
						if ( ( $packet['source_fingerprint'] ?? '' ) !== $fp ) {
							break; // The older original converged; the current drift remains ahead of the cursor.
						}
					} else {
						if ( count( $errors ) < 10 ) {
							$push_code = (string) ( $push_json['code'] ?? '' );
							$errors[]  = sprintf( 'Peer %s: drift push HTTP %d%s', $peer_uuid, $push_status, '' !== $push_code ? ' (' . $push_code . ')' : '' );
						}
						if ( 'outage' === $push_class ) {
							break; // The peer path is unavailable, not a verdict on this entity: it stays ahead of the cursor.
						}
					}
				}
			}
			$resolved = max( $resolved, $local_id );
		}

		return array( 'pushed' => $pushed, 'resolved_through' => $resolved );
	}
}
