<?php
/**
 * WPMMCC Manual Sync Console (3.8flash B1 / WPMMCC-P1-03).
 *
 * The wpmmcc lifecycle previously ended at "pairing": every sync was
 * passive (cron reconcile summary only) — no UI path ever pushed pending
 * outbound CDC to a peer, pulled missing content from a peer, or verified a
 * peer's reachability. This class closes those three manual legs using the
 * SAME primitives the REST surface uses (Rest_Middleware HMAC envelope,
 * Packet::export_post, Rest_Pull's uuid fetch, Rest_Push's ingest), so the
 * manual console cannot drift from the wire contract.
 *
 * @package WPMMCC\Sync
 */

namespace WPMMCC\Sync;

use WP_Error;
use WPMMCC\REST\Peer_Http;
use WPMMCC\REST\Rest_Middleware;
use WPMMCC\REST\Rest_Push;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Manual_Sync
 */
class Manual_Sync {

	/**
	 * Upper bound on packets per manual run (admin request must stay bounded).
	 */
	const MAX_PACKETS_PER_RUN = 10;

	/**
	 * Digest page size for the pull sweep — matches Rest_Digest::MAX_LIMIT,
	 * the largest page a peer serves, so one sweep step is one remote call.
	 */
	const PULL_DIGEST_PAGE = 200;

	/**
	 * Upper bound on digest items classified per pull run: a huge peer
	 * catalog must not turn one admin request into an unbounded page walk.
	 */
	const PULL_MAX_SCAN_PER_RUN = 1000;

	/**
	 * Option that holds one peer's pull-sweep keyset cursor (WMC-08).
	 *
	 * @param string $peer_uuid Peer UUID.
	 * @return string
	 */
	public static function pull_cursor_option( string $peer_uuid ): string {
		return 'wpmmcc_pull_cursor_' . md5( $peer_uuid );
	}

	/**
	 * Peer error codes that describe the peer / pairing, not one entity.
	 */
	const PEER_WIDE_ERROR_CODES = array(
		'wpmmcc_incompatible_schema',
		'wpmmcc_key_decryption_failed',
		'wpmmcc_clock_drift',
		'wpmmcc_peer_unauthorized',
	);

	/**
	 * Outbound request timeout (seconds) — short: a dead peer must fail fast,
	 * never hang the admin screen.
	 */
	const REMOTE_TIMEOUT = 10;

	/**
	 * Test a peer's reachability + pairing validity.
	 *
	 * Calls the peer's HMAC-authenticated /sync/digest with a 1-item probe:
	 * a 2xx with an items array proves network path, key decryption, HMAC
	 * signature, anti-replay and peer registration in one shot.
	 *
	 * @param array $peer Peer row (peer_uuid/endpoint_url/shared_secret/key_salt).
	 * @return array{ok:bool,latency_ms:int,message:string}
	 */
	public static function test_connection( array $peer ): array {
		$start = microtime( true );
		$resp  = self::remote_call( $peer, '/sync/digest', array( 'limit' => 1 ) );
		$latency = (int) round( ( microtime( true ) - $start ) * 1000 );

		$ok = false;
		$message = '';
		if ( is_wp_error( $resp ) ) {
			$message = $resp->get_error_message();
		} elseif ( 200 !== (int) ( $resp['status'] ?? 0 ) ) {
			$message = sprintf( 'HTTP %d from peer endpoint', (int) ( $resp['status'] ?? 0 ) );
		} else {
			// Rest_Base::format_success wraps payloads as {success, data}.
			$items = $resp['json']['data']['items'] ?? null;
			if ( ! is_array( $items ) ) {
				$message = 'Peer response missing data.items array (unexpected payload shape)';
			} else {
				$ok = true;
				$message = sprintf( 'Peer reachable and authenticated (%d digest item(s) served)', count( $items ) );
			}
		}

		Event_Recorder::record_journal_entry(
			array(
				'event_type'      => 'connection_test',
				'direction'       => 'outbound',
				'object_type'     => 'system',
				'local_object_id' => 0,
				'peer_uuid'       => (string) ( $peer['peer_uuid'] ?? '' ),
				'outcome'         => $ok ? 'success' : 'failed',
				'duration_ms'     => $latency,
				'message'         => $message,
				'consumed'        => 1,
			)
		);

		return array(
			'ok'         => $ok,
			'latency_ms' => $latency,
			'message'    => $message,
		);
	}

	/**
	 * Push pending outbound CDC to a peer (manual / scheduled leg of the queue).
	 *
	 * The queue rules live in Push_Queue; this method ships. Each pending
	 * ENTITY is one delivery (several queued saves of one post are not shipped
	 * separately) and its packet is built from the entity's CURRENT state:
	 *
	 *   post gone          -> delete tombstone
	 *   post in the trash  -> trash tombstone
	 *   otherwise          -> upsert (Packet::export_post), also when the queued
	 *                         event was a trash / delete that was since undone.
	 *
	 * A delete event whose post is already gone therefore ships instead of
	 * failing export forever at the head of the queue (WMC-01).
	 *
	 * Outcome of one delivery:
	 *  - 2xx: acked; every pending row of the entity for this peer is consumed.
	 *  - export failure or an entity-specific 4xx (400, 409, 413, 422 ...):
	 *    one attempt is charged, the entity is dead-lettered after
	 *    Push_Queue::MAX_ATTEMPTS, and the run moves on to the next entity.
	 *  - peer or path trouble (transport error, 401/403/404/408/429, 502-504,
	 *    peer-wide error codes): the run stops and nothing is charged. An
	 *    outage says nothing about the event; charging it would dead-letter the
	 *    whole queue during a peer restart.
	 *  - any other 5xx: charged only if the peer accepted another packet in the
	 *    same run (then the failure was specific to that entity).
	 * A failed entity moves to the retry lane either way, so it can delay the
	 * rows behind it by at most its share of one run.
	 *
	 * @param array  $peer Peer row.
	 * @param string $source Audit-trail source label ('manual' | 'scheduled') — prefixes journal event types.
	 * @return array{pushed:int,failed:int,dead:int,message:string}
	 */
	public static function push_now( array $peer, string $source = 'manual' ): array {
		$peer_uuid = (string) ( $peer['peer_uuid'] ?? '' );
		$pushed    = 0;
		$failed    = 0;
		$dead      = 0;
		$message   = '';
		$suspects  = array();

		Push_Queue::sweep_unshippable( $peer_uuid );

		foreach ( Push_Queue::pending_groups( self::MAX_PACKETS_PER_RUN, $peer_uuid ) as $group ) {
			$event = $group['leader'];

			$resp = Outbound_Delivery::deliver( $peer, $event, static function () use ( $event, $peer ) {
				return self::packet_for_event( $event, $peer );
			} );
			if ( is_wp_error( $resp ) ) {
				$failed++;
				$message = $resp->get_error_message();
				self::journal_push( $source, $event, $peer_uuid, 'failed', $message );
				if ( 'wpmmcc_outbound_export_failed' === $resp->get_error_code() ) {
					// No intent or target effect exists for this failed export.
					// Unknown frozen delivery and peer failures stay uncharged.
					$dead += self::charge( $event, $peer_uuid, $message, 0 );
					continue;
				}
				Push_Queue::record_failure( $event, $peer_uuid, $message, 0, false );
				break;
			}
			$packet = $resp['packet'] ?? array();
			$packet_id = (string) ( $packet['packet_id'] ?? '' );

			$status = (int) ( $resp['status'] ?? 0 );
			$class  = self::classify_push_response( $status, (array) ( $resp['json'] ?? array() ), $packet, $peer );

			if ( 'ok' === $class ) {
				// Peer acked the ingest (it journals the inbound side).
				Push_Queue::ack( $resp['ack_event'] ?? $event, $peer_uuid );
				$pushed++;
				self::journal_push( $source, $event, $peer_uuid, 'success', '', $packet_id );
				continue;
			}

			$failed++;
			$message  = sprintf( 'HTTP %d from peer /sync/push', $status );
			$detail   = $message . ( $status < 300 ? '; no matching committed receipt' : '' );
			self::journal_push( $source, $event, $peer_uuid, 'failed', $detail, $packet_id );

			if ( 'outage' === $class ) {
				Push_Queue::record_failure( $event, $peer_uuid, $detail, $status, false );
				break;
			}
			if ( 'suspect' === $class ) {
				$suspects[] = array( $event, $detail, $status );
				continue;
			}
			$dead += self::charge( $event, $peer_uuid, $detail, $status );
		}

		// An ambiguous 5xx is the entity's fault only when the peer proved
		// healthy by accepting another packet in the same run.
		foreach ( $suspects as $suspect ) {
			if ( $pushed > 0 ) {
				$dead += self::charge( $suspect[0], $peer_uuid, $suspect[1], $suspect[2] );
			} else {
				Push_Queue::record_failure( $suspect[0], $peer_uuid, $suspect[1], $suspect[2], false );
			}
		}

		$message = sprintf( 'Pushed %d, failed %d.', $pushed, $failed )
			. ( $dead > 0 ? sprintf( ' %d dead-lettered.', $dead ) : '' )
			. ( '' !== $message ? ' Last error: ' . $message : '' );
		Event_Recorder::record_journal_entry(
			array(
				'event_type'      => $source . '_push_summary',
				'direction'       => 'outbound',
				'object_type'     => 'system',
				'local_object_id' => 0,
				'peer_uuid'       => $peer_uuid,
				'outcome'         => $failed > 0 ? 'failed' : 'success',
				'diff_summary'    => array(
					'pushed' => $pushed,
					'failed' => $failed,
					'dead'   => $dead,
				),
				'message'         => $message,
				'consumed'        => Push_Queue::DONE,
			)
		);

		return array(
			'pushed'  => $pushed,
			'failed'  => $failed,
			'dead'    => $dead,
			'message' => $message,
		);
	}

	/**
	 * Spend one attempt on an entity; report whether it was dead-lettered.
	 *
	 * @param array  $event     Leader row.
	 * @param string $peer_uuid Peer.
	 * @param string $error     Cause.
	 * @param int    $status    Peer HTTP status (0 = local).
	 * @return int 1 when the entity is now dead-lettered, else 0.
	 */
	private static function charge( array $event, string $peer_uuid, string $error, int $status ): int {
		return 'dead' === Push_Queue::record_failure( $event, $peer_uuid, $error, $status, true ) ? 1 : 0;
	}

	/**
	 * Build the packet an entity's queued events resolve to, from the post's
	 * current state (see push_now()).
	 *
	 * @param array $event Leader journal row.
	 * @return array|WP_Error Sync packet or an export error.
	 */
	private static function packet_for_event( array $event, array $peer = array() ) {
		$canonical_uuid = (string) ( $event['canonical_uuid'] ?? '' );
		$post_id        = (int) ( $event['local_object_id'] ?? 0 );

		try {
			$post = get_post( $post_id );
			if ( ! ( $post instanceof \WP_Post ) ) {
				$packet = Packet::export_tombstone( $canonical_uuid, $post_id, 'delete' );
				$packet['target_lang'] = $peer['target_lang'] ?? 'zh_CN';
				return $packet;
			}
			if ( 'trash' === (string) $post->post_status ) {
				$packet = Packet::export_tombstone( $canonical_uuid, $post_id, 'trash', $post );
				$packet['target_lang'] = $peer['target_lang'] ?? 'zh_CN';
				return $packet;
			}
			return Packet::export_post( $post_id, array( 'target_lang' => $peer['target_lang'] ?? 'zh_CN',
				'source_lang' => $peer['source_lang'] ?? get_locale(), 'sync_mode' => $peer['sync_mode'] ?? 'sync_only' ) );
		} catch ( \Throwable $e ) {
			// A poison entity (e.g. an adapter that throws) must not take the
			// whole run, or the cron event, down with it.
			return new WP_Error( 'wpmmcc_export_exception', $e->getMessage() );
		}
	}

	/**
	 * Classify a /sync/push response. Shared with the reconcile sweep's drift
	 * push, which must tell "the peer path is unavailable" from "this entity
	 * was refused" the same way the CDC queue does.
	 *
	 * @param int   $status HTTP status.
	 * @param array $json   Decoded body (WP_Error JSON has a 'code').
	 * @return string 'ok' | 'outage' | 'suspect' | 'entity'
	 */
	public static function classify_push_response( int $status, array $json, array $packet = array(), array $peer = array() ): string {
		if ( $status >= 200 && $status < 300 ) {
			$data = $json['data'] ?? array();
			return true === ( $json['success'] ?? false )
				&& true === ( $data['committed'] ?? false )
				&& ! empty( $packet )
				&& ( $data['packet_id'] ?? '' ) === ( $packet['packet_id'] ?? null )
				&& ( $data['fingerprint_ack'] ?? '' ) === ( $packet['source_fingerprint'] ?? null )
				&& ( $data['canonical_uuid'] ?? '' ) === ( $packet['entity']['guid'] ?? null )
				&& ( $data['origin_site_uuid'] ?? '' ) === ( $packet['origin_context']['origin_site_uuid'] ?? null )
				&& ( $data['target_lang'] ?? '' ) === ( $packet['target_lang'] ?? null )
				&& ! empty( $peer ) && ( $data['target_site_uuid'] ?? '' ) === ( $peer['peer_uuid'] ?? null )
				&& in_array( $data['status'] ?? '', array( 'success', 'skipped' ), true )
				&& ( 'upsert' !== ( $packet['action'] ?? '' ) || ( is_int( $data['target_id'] ?? null ) && $data['target_id'] > 0
					&& Packet::url_belongs_to( (string) ( $data['target_url'] ?? '' ), Packet::peer_site_url( (string) ( $peer['endpoint_url'] ?? '' ) ) ) ) )
				? 'ok' : 'outage';
		}
		if ( in_array( (string) ( $json['code'] ?? '' ), self::PEER_WIDE_ERROR_CODES, true ) ) {
			return 'outage';
		}
		if ( $status < 400 || in_array( $status, array( 401, 403, 404, 408, 429, 502, 503, 504 ), true ) ) {
			return 'outage';
		}
		return $status >= 500 ? 'suspect' : 'entity';
	}

	/**
	 * Journal one push attempt (audit row; never part of the CDC queue).
	 *
	 * @param string $source    'manual' | 'scheduled'.
	 * @param array  $event     Queue row the attempt was for.
	 * @param string $peer_uuid Peer.
	 * @param string $outcome   'success' | 'failed'.
	 * @param string $message   Detail ('' = none).
	 * @param string $packet_id Packet id when a packet was built.
	 * @return void
	 */
	private static function journal_push( string $source, array $event, string $peer_uuid, string $outcome, string $message, string $packet_id = '' ): void {
		Event_Recorder::record_journal_entry(
			array(
				'event_type'      => $source . '_push',
				'direction'       => 'outbound',
				'canonical_uuid'  => (string) ( $event['canonical_uuid'] ?? '' ),
				'object_type'     => 'post',
				'local_object_id' => (int) ( $event['local_object_id'] ?? 0 ),
				'peer_uuid'       => $peer_uuid,
				'packet_id'       => $packet_id,
				'outcome'         => $outcome,
				'message'         => '' === $message ? null : $message,
				'consumed'        => Push_Queue::DONE,
			)
		);
	}

	/**
	 * Pull content the peer has but this site lacks (manual reconcile leg).
	 *
	 * Fetches the peer's digest (HMAC /sync/digest), finds canonical UUIDs the
	 * peer serves that have no local mapping yet, fetches them via the peer's
	 * /sync/pull, and ingests each packet through Rest_Push::handle_push()
	 * (the same materialize path a live peer push takes — conflict strategy,
	 * shadow drafts, adapters, journal rows all behave identically).
	 *
	 * WMC-08: the digest is swept with a PERSISTED PER-PEER KEYSET CURSOR
	 * (last_seen_id) instead of one limit-200 window — beyond that one window
	 * a larger source was invisible FOREVER. Sweep rules, per run:
	 *  - walk digest pages (PULL_DIGEST_PAGE) from the stored cursor,
	 *    classifying each item as mapped / missing, at most
	 *    PULL_MAX_SCAN_PER_RUN items per run;
	 *  - stop at MAX_PACKETS_PER_RUN missing entities (the run stays
	 *    bounded); the cursor remembers the last CLASSIFIED item so the
	 *    next run resumes right after it;
	 *  - a short page means the peer's catalog ended: the cursor wraps to 0,
	 *    so every pass eventually covers the WHOLE catalog (this also
	 *    catches entities whose status changed at a low ID, e.g. a draft
	 *    published later — keyset-by-ID alone would never revisit it);
	 *  - a failed pull call (transport error or an HTTP refusal such as 429)
	 *    rolls the cursor back to where the failed batch began, so the batch
	 *    is retried without waiting a full pass; a failed digest call fails
	 *    the run and leaves the cursor where it was.
	 * Entities whose ingest fails stay unmapped and are re-found on the next
	 * pass (the scan position still advances past them).
	 *
	 * @param array  $peer Peer row.
	 * @param string $source Audit-trail source label ('manual' | 'scheduled') — prefixes journal event types.
	 * @return array{pulled:int,failed:int,message:string}
	 */
	public static function pull_now( array $peer, string $source = 'manual' ): array {
		$peer_uuid  = (string) ( $peer['peer_uuid'] ?? '' );
		$cursor_key = self::pull_cursor_option( $peer_uuid );
		$cursor     = max( 0, (int) get_option( $cursor_key, 0 ) );

		$missing            = array();
		$scanned            = 0;
		$batch_start_cursor = $cursor; // Rollback point for a failed pull leg.
		$wrap               = false;

		while ( true ) {
			$digest  = self::remote_call( $peer, '/sync/digest', array( 'limit' => self::PULL_DIGEST_PAGE, 'last_seen_id' => $cursor ) );
			$failure = self::call_failure( $digest, '/sync/digest' );
			if ( '' !== $failure ) {
				// Transport failure or an HTTP refusal (rate limit, maintenance,
				// gateway): the operation itself failed, not "nothing to pull".
				// A refusal's error body has no items and would read as the
				// end of the peer's catalog, wrapping the sweep to 0. Surface
				// failed=1 so the UI takes the warning class instead of a green
				// "0 pulled, 0 failed". The cursor is untouched: nothing was
				// classified this run.
				self::record_pull_summary( $peer, 0, 1, 'Digest fetch failed: ' . $failure, true, $source );
				return array( 'pulled' => 0, 'failed' => 1, 'message' => $failure );
			}
			// Rest_Base::format_success wraps payloads as {success, data}.
			$items      = is_array( $digest['json']['data']['items'] ?? null ) ? $digest['json']['data']['items'] : array();
			$page_short = count( $items ) < self::PULL_DIGEST_PAGE;

			$hit_cap      = false;
			$unclassified = false; // Items the cap left unclassified in this page.
			$progress     = false;
			$remaining    = count( $items );
			foreach ( $items as $item ) {
				--$remaining;
				$uuid = (string) ( $item['canonical_uuid'] ?? '' );
				$id   = (int) ( $item['local_id'] ?? 0 );
				if ( '' === $uuid || $id <= $cursor ) {
					// Not keyset-addressable, or a peer that ignores
					// last_seen_id repeating IDs already classified: never
					// classify (or queue) the same entity twice in one run.
					continue;
				}
				if ( empty( $missing ) ) {
					$batch_start_cursor = $cursor;
				}
				if ( $id > $cursor ) {
					$cursor   = $id; // Classified: the sweep may resume after it.
					$progress = true;
				}
				$scanned++;
				if ( ! self::has_local_mapping( $uuid ) ) {
					$missing[] = $uuid;
					if ( count( $missing ) >= self::MAX_PACKETS_PER_RUN ) {
						$hit_cap      = true;
						$unclassified = $remaining > 0;
						break;
					}
				}
			}

			if ( $hit_cap || $page_short ) {
				// A short page is the end of the peer's catalog: wrap for a
				// fresh pass — unless the cap stopped the scan with items still
				// unclassified in it. Wrapping then would re-find the same
				// (possibly failing) entities first on every run and starve
				// the rest of a small catalog.
				$wrap = $page_short && ! $unclassified;
				break;
			}
			if ( ! $progress || $scanned >= self::PULL_MAX_SCAN_PER_RUN ) {
				break; // Peer violated the keyset contract, or the scan bound hit.
			}
		}

		// Cursor options are read only by this sweep: never autoload them.
		if ( empty( $missing ) ) {
			update_option( $cursor_key, $wrap ? 0 : $cursor, false );
			$message = sprintf( 'Nothing to pull: %d digest item(s) all already mapped locally.', $scanned );
			self::record_pull_summary( $peer, 0, 0, $message, false, $source );
			return array( 'pulled' => 0, 'failed' => 0, 'message' => $message );
		}

		$pull    = self::remote_call( $peer, '/sync/pull', array( 'canonical_uuids' => $missing ) );
		$failure = self::call_failure( $pull, '/sync/pull' );
		if ( '' !== $failure ) {
			// Roll the cursor back to where the failed batch began: the digest
			// scan worked but nothing was ingested (transport failure or an
			// HTTP refusal), and this batch must not wait a full sweep pass
			// for its retry.
			update_option( $cursor_key, $batch_start_cursor, false );
			self::record_pull_summary( $peer, 0, count( $missing ), $failure, true, $source );
			return array( 'pulled' => 0, 'failed' => count( $missing ), 'message' => $failure );
		}

		// Rest_Base::format_success wraps payloads as {success, data}.
		$packets = is_array( $pull['json']['data']['packets'] ?? null ) ? $pull['json']['data']['packets'] : array();
		$pulled  = 0;
		$failed  = 0;
		$message = '';

		// Ingest through the same handler a live peer push would take. A fresh
		// WP_REST_Request with the raw packet JSON body reuses the entire
		// materialize path (arbitration, adapters, journal) unchanged.
		$push_controller = new Rest_Push();
		foreach ( $packets as $packet ) {
			$result = $push_controller->handle_push( self::ingest_request( $peer, (string) wp_json_encode( $packet ) ) );

			if ( is_wp_error( $result ) ) {
				$failed++;
				$message = $result->get_error_message();
				continue;
			}
			$pulled++;
		}

		// Ingest failures leave their entities unmapped; the scan position
		// still advances (they are re-found on the next pass — freezing the
		// cursor on them would recreate the WMC-01 head-of-line starvation).
		update_option( $cursor_key, $wrap ? 0 : $cursor, false );

		$message = sprintf( 'Pulled %d, failed %d.', $pulled, $failed ) . ( '' !== $message ? ' Last error: ' . $message : '' );
		self::record_pull_summary( $peer, $pulled, $failed, $message, $failed > 0, $source );

		return array(
			'pulled' => $pulled,
			'failed' => $failed,
			'message' => $message,
		);
	}

	/**
	 * The request the pull leg hands to the push handler for one fetched packet.
	 *
	 * It names the peer the packet was fetched from, so the same origin binding
	 * and the same per-peer conflict policy apply as to a live push from that
	 * peer (WMC-04, WMC-14). It never crossed HTTP in this direction, so it has
	 * no HMAC envelope beyond that one header.
	 *
	 * @param array  $peer Peer row the packet was fetched from.
	 * @param string $raw  Raw packet JSON.
	 * @return \WP_REST_Request
	 */
	public static function ingest_request( array $peer, string $raw ): \WP_REST_Request {
		$req = new \WP_REST_Request( 'POST', '/wpmmcc/v1/sync/push' );
		$req->set_header( 'x-wpmmcc-site-uuid', (string) ( $peer['peer_uuid'] ?? '' ) );
		$req->set_body( $raw );
		return $req;
	}

	/**
	 * Why a remote_call() did not deliver: the transport error, or the peer's
	 * non-2xx answer ("HTTP 429 (wpmmcc_rate_limited) from peer /sync/pull").
	 *
	 * @param array|WP_Error $resp  Result of remote_call().
	 * @param string         $route Route the call went to.
	 * @return string '' when the call succeeded (2xx).
	 */
	private static function call_failure( $resp, string $route ): string {
		if ( is_wp_error( $resp ) ) {
			return $resp->get_error_message();
		}
		$status = (int) ( $resp['status'] ?? 0 );
		if ( $status >= 200 && $status < 300 ) {
			return '';
		}
		$code = (string) ( $resp['json']['code'] ?? '' );
		return sprintf( 'HTTP %d%s from peer %s', $status, '' !== $code ? ' (' . $code . ')' : '', $route );
	}

	/**
	 * Does this site already have a local mapping for the canonical UUID?
	 *
	 * @param string $canonical_uuid Canonical UUID.
	 * @return bool
	 */
	private static function has_local_mapping( string $canonical_uuid ): bool {
		global $wpdb;
		$cache_key = 'canonical_mapping_' . md5( $canonical_uuid );
		$row       = wp_cache_get( $cache_key, 'wpmmcc' );
		if ( false === $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table or connection command; WordPress has no replacement API
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wpmmcc_cross_mappings WHERE canonical_uuid = %s LIMIT 1", $canonical_uuid )
			);
			wp_cache_set( $cache_key, $row, 'wpmmcc', 300 );
		}
		return ! empty( $row );
	}

	/**
	 * Journal a manual pull summary (per-packet rows come from handle_push()).
	 *
	 * @param array  $peer Peer row.
	 * @param int    $pulled Pulled count.
	 * @param int    $failed Failed count.
	 * @param string $message Summary message.
	 * @param bool   $is_error Whether the summary outcome is failed.
	 * @return void
	 */
	private static function record_pull_summary( array $peer, int $pulled, int $failed, string $message, bool $is_error, string $source = 'manual' ): void {
		Event_Recorder::record_journal_entry(
			array(
				'event_type'      => $source . '_pull_summary',
				'direction'       => 'inbound',
				'object_type'     => 'system',
				'local_object_id' => 0,
				'peer_uuid'       => (string) ( $peer['peer_uuid'] ?? '' ),
				'outcome'         => $is_error ? 'failed' : 'success',
				'diff_summary'    => array( 'pulled' => $pulled, 'failed' => $failed ),
				'message'         => $message,
				'consumed'        => 1,
			)
		);
	}

	/**
	 * HMAC-signed outbound call to a peer REST endpoint.
	 *
	 * Envelope mirrors Rest_Middleware::verify_hmac() exactly: the signature
	 * covers METHOD / route / timestamp / nonce / our site UUID / sha256(body)
	 * with the peer's shared secret (restored from at-rest storage).
	 *
	 * @param array  $peer Peer row (endpoint_url/shared_secret/key_salt).
	 * @param string $path Route path AFTER the namespace root (e.g. '/sync/push').
	 * @param array  $body JSON body payload.
	 * @return array{status:int,json:array,response:array|\WP_Error}|WP_Error
	 */
	public static function remote_call( array $peer, string $path, array $body ) {
		$endpoint = rtrim( (string) ( $peer['endpoint_url'] ?? '' ), '/' );
		if ( '' === $endpoint ) {
			return new WP_Error( 'wpmmcc_peer_no_endpoint', 'Peer has no endpoint URL configured' );
		}

		$secret = Rest_Middleware::decrypt_shared_secret( (string) ( $peer['shared_secret'] ?? '' ), (string) ( $peer['key_salt'] ?? '' ) );
		if ( empty( $secret ) ) {
			return new WP_Error( 'wpmmcc_key_decryption_failed', 'Failed to decrypt the peer shared secret' );
		}

		$site_uuid = (string) get_option( 'wpmmcc_origin_uuid', '' );
		if ( '' === $site_uuid ) {
			return new WP_Error( 'wpmmcc_no_origin_uuid', 'Local origin UUID is not initialized' );
		}

		$raw     = wp_json_encode( $body );
		$route   = '/wpmmcc/v1' . $path;
		$ts      = time();
		$nonce   = bin2hex( random_bytes( 16 ) );
		$signature = Rest_Middleware::sign_request( 'POST', $route, $ts, $nonce, $site_uuid, $raw, $secret );

		$response = Peer_Http::post(
			$endpoint . $path,
			array(
				'timeout' => self::REMOTE_TIMEOUT,
				'limit_response_size' => 16777217,
				'headers' => array(
					'Content-Type'        => 'application/json',
					'X-WPMMCC-Site-UUID'  => $site_uuid,
					'X-WPMMCC-Timestamp'  => (string) $ts,
					'X-WPMMCC-Nonce'      => $nonce,
					'X-WPMMCC-Signature'  => $signature,
				),
				'body'    => $raw,
			)
		);

		if ( is_wp_error( $response ) ) {
			if ( 'wpmmcc_peer_url_blocked' === $response->get_error_code() ) {
				// Peer_Http supplies only static policy guidance, not URL,
				// DNS or transport exception values.
				return $response;
			}
			return new WP_Error( 'wpmmcc_peer_transport_failed', 'Peer transport failed; original packet retained.' );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$bytes  = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $bytes ) > 16777216 ) {
			return new WP_Error( 'wpmmcc_peer_response_too_large', 'Peer response exceeds 16 MiB; original packet retained.' );
		}
		if ( $status >= 200 && $status < 300 ) {
			$canonical = implode( "\n", array( 'WPMMCC-RESPONSE-v1', $nonce, (string) $ts,
				$site_uuid, 'POST', $route, (string) $status, hash( 'sha256', $bytes ) ) );
			$proof = (string) wp_remote_retrieve_header( $response, 'x-wpmmcc-response-signature' );
			if ( ! hash_equals( hash_hmac( 'sha256', $canonical, $secret ), $proof ) ) {
				return new WP_Error( 'wpmmcc_peer_response_unverified', 'Peer response proof differs; original packet retained.' );
			}
		}
		$json = json_decode( $bytes, true, Packet::MAX_RECURSION_DEPTH );
		if ( $status >= 200 && $status < 300 && ( ! is_array( $json )
			|| true !== ( $json['success'] ?? false ) || ! is_array( $json['data'] ?? null ) ) ) {
			return new WP_Error( 'wpmmcc_peer_response_invalid', 'Peer response has no successful data envelope; original packet retained.' );
		}

		return array(
			'status'   => $status,
			'json'    => is_array( $json ) ? $json : array(),
			'response' => array(),
		);
	}
}
