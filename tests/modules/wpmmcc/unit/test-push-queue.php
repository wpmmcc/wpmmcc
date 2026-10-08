<?php
/**
 * WPMMCC outbound push queue behaviour (WMC-01 / WMC-06 / WMC-09-2).
 *
 * The outbound CDC queue lives in wpmmcc_journal (consumed=0, direction
 * 'outbound'). Before this suite the queue had exactly one behavioural test
 * (mock $wpdb, string matching): nothing proved WHAT REMAINS pending after a
 * push. These scenarios run the real Event_Recorder / Manual_Sync / Packet
 * code against a stateful in-memory $wpdb (tests/support) and a scripted
 * peer transport, so they assert queue outcomes, not SQL text.
 *
 * Scenarios (run one with:  php test-push-queue.php --only=<name>):
 *   wmc01_poison_head        12 events whose posts are gone + 3 good events:
 *                            the good events must reach the peer (pre-fix:
 *                            the 12 stay at the queue head forever).
 *   wmc01_peer_rejects       12 posts the peer rejects with HTTP 400 + 3 good:
 *                            good ones flow, rejected ones are retried a
 *                            bounded number of times then dead-lettered.
 *   wmc01_outage_not_charged peer outage (503 / transport / 401) never burns
 *                            an event's retry budget.
 *   wmc01_tombstones         deleted -> delete packet, trashed -> trash
 *                            packet, restored -> upsert (state-based plan).
 *   wmc01_dedupe             N pending rows of one entity ship as ONE packet.
 *   wmc06_per_peer           two peers each get every event; one peer's ack
 *                            does not consume the other's copy.
 *   wmc06_no_peers           no push-capable peer -> no CDC rows at all.
 *   wmc06_retention          default retention keeps audit, dead and pending.
 *   wmc06_retention_filters  only explicitly confirmed, eligible peer audit
 *                            rows can expire; zero and invalid values retain.
 *   wmc06_untracked_deletes  revisions / never-tracked posts emit no CDC.
 *   wmc09_requeue            dead-lettered rows can be requeued.
 *   wmc01_export_exception   an adapter that throws is one failed entity, not a
 *                            dead run.
 *   wmc01_server_error_attribution  HTTP 500 is charged to an entity only when
 *                            the peer proved healthy in the same run; peer-wide
 *                            error codes never charge.
 *   wmc01_dedupe_window      an entity with more rows than the row window is
 *                            still one delivery; a save landing mid-delivery
 *                            stays queued.
 *   wmc01_retry_share        failing entities keep a guaranteed share of the run
 *                            while a fresh backlog is larger than the run.
 *   wmc01_unshippable_rows   rows that can never ship (non-post, no uuid, no
 *                            object id) are dead-lettered, not left at the head.
 *   wmc06_legacy_rows        pre-fan-out rows (peer_uuid '') still ship.
 *   wmc06_debounce           rapid saves fold into one row per peer; an aged row
 *                            no longer absorbs.
 *   wmc06_scheduled_prune    hourly reconcile never silently expires evidence.
 *   wmc08_pull_beyond_window pull_now sweeps past the 200-item digest window
 *                            (per-peer keyset cursor, wrap at catalog end).
 *   wmc08_pull_continuation   bounded runs continue the sweep; nothing is
 *                            pulled twice.
 *   wmc08_pull_poison_no_starvation  entities whose ingest keeps failing do
 *                            not starve the rest of a small catalog.
 *   wmc08_pull_digest_failure_keeps_cursor  a digest transport failure is a
 *                            failed run and leaves the stored cursor alone.
 *   wmc08_pull_cursor_ignoring_peer  a peer that ignores last_seen_id cannot
 *                            make the sweep spin.
 *   wmc08_pull_scan_bound    one pull run classifies a bounded number of digest
 *                            items; the next run continues.
 *   wmc08_pull_transport_rollback  a pull transport failure rolls the cursor
 *                            back to the failed batch, not past it.
 *   wmc08_pull_http_failures  an HTTP refusal (429 / 5xx) of the digest or pull
 *                            call is a failed leg like a dead transport: no
 *                            wrap to 0, no cursor move past the lost batch.
 *   wmc08_reconcile_sweep    the scheduled reconcile leg covers the whole
 *                            catalog in per-peer windows, not just the first
 *                            100 entities.
 *   wmc08_reconcile_post_types  the sweep covers posts, pages and products
 *                            (the types the digest route offers), not others.
 *   wmc08_reconcile_outage_holds_window  a window the peer never answered is
 *                            retried, not skipped until the next full pass.
 *   wmc08_reconcile_cap_continuation  drift beyond the per-run push cap
 *                            continues in the next run.
 *   wmc08_reconcile_failure_classes  entity rejections skip (no head-of-line
 *                            stall); a dead transport holds the cursor.
 *   wmc08_reconcile_push_outage  a drift push answered 429 / gateway / auth
 *                            trouble / a peer-wide code is an outage, not an
 *                            entity verdict: the walk stops, the cursor holds.
 *   wmc08_reconcile_attempt_cap  a peer that rejects every entity still costs
 *                            at most the per-run cap of push requests.
 *   wmc08_reconcile_export_exception  an adapter that throws on a drifted
 *                            entity is one failed entity, not an aborted run.
 *   wmc08_cursor_hygiene     cursor options are never autoloaded, dropped
 *                            with a disconnected peer, removed on uninstall.
 *
 * Catalog evidence (tests/catalog; read by tests/infra/tools/catalog-coverage.py):
 *   catalog: WP-HOOK-wpmmcc-journal-retention-days
 *   catalog: WP-HOOK-wpmmcc-cdc-pending-ttl-days
 *   oracle: L1
 *
 * @package WPMMCC\Tests\Unit
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/support/class-mini-sql-wpdb.php';
require_once dirname( __DIR__ ) . '/support/peer-dns-stub.php';

use WPMMCC\REST\Rest_Middleware;
use WPMMCC\Sync\Event_Recorder;
use WPMMCC\Sync\Manual_Sync;
use WPMMCC\Sync\Packet;
use WPMMCC\Sync\Push_Queue;
use WPMMCC\Sync\Reconciler;

echo "=== Running Push Queue Behaviour Test (WMC-01 / WMC-06) ===\n";

$pass_count = 0;

function assert_test( $condition, $message ) {
	global $pass_count;
	if ( ! $condition ) {
		echo "FAIL: {$message}\n";
		foreach ( $GLOBALS['wpdb']->rows( 'wp_wpmmcc_journal' ) as $row ) {
			if ( 'failed' === ( $row['outcome'] ?? '' ) ) {
				echo '  retained failure: ' . ( $row['message'] ?? '' ) . "\n";
			}
		}
		foreach ( array_slice( $GLOBALS['pq_remote']['wire_results'] ?? array(), -3 ) as $reply ) {
			echo '  fixture reply: ' . json_encode( $reply ) . "\n";
		}
		exit( 1 );
	}
	$pass_count++;
	echo "  [PASS] {$message}\n";
}

// ---------------------------------------------------------------------------
// Scripted WordPress HTTP layer (Manual_Sync::remote_call -> Peer_Http ->
// wp_safe_remote_post)
// ---------------------------------------------------------------------------

global $pq_remote;
$pq_remote = array(
	'calls'   => array(),
	'handler' => null,
	'receipts' => array(),
);

/** Complete, request-bound peer proof. Custom malformed bodies stay malformed. */
function pq_wire_response( int $status, string $body, array $headers, string $route ): array {
	$GLOBALS['pq_remote']['wire_results'][] = array( 'route' => $route, 'status' => $status, 'json' => json_decode( $body, true ) );
	$canonical = implode( "\n", array(
		'WPMMCC-RESPONSE-v1', $headers['X-WPMMCC-Nonce'], $headers['X-WPMMCC-Timestamp'],
		$headers['X-WPMMCC-Site-UUID'], 'POST', '/wpmmcc/v1' . $route,
		(string) $status, hash( 'sha256', $body ),
	) );
	return array( 'response' => array( 'code' => $status ), 'body' => $body,
		'headers' => array( 'x-wpmmcc-response-signature' => hash_hmac( 'sha256', $canonical, 'pq-shared-secret' ) ) );
}

function pq_packet_receipt( array $packet, string $peer, bool $committed ): array {
	return array(
		'committed' => $committed, 'status' => 'success', 'phase' => $committed ? 'applied' : 'absent',
		'packet_id' => $packet['packet_id'], 'fingerprint_ack' => $packet['source_fingerprint'],
		'canonical_uuid' => $packet['entity']['guid'], 'origin_site_uuid' => $packet['origin_context']['origin_site_uuid'],
		'target_site_uuid' => $peer, 'target_lang' => $packet['target_lang'],
		'target_id' => 'upsert' === $packet['action'] ? 10000 + $packet['entity']['source_id'] : 0,
		'target_url' => 'upsert' === $packet['action'] ? 'https://' . $peer . '.example.test/?p=' . ( 10000 + $packet['entity']['source_id'] ) : '',
	);
}

if ( ! function_exists( 'wp_safe_remote_post' ) ) {
	/**
	 * Records every call and answers through $pq_remote['handler'].
	 * Handler receives (route, decoded_packet, peer_uuid) and returns an int
	 * status, a WP_Error (transport failure) or array('status'=>int,'body'=>string).
	 * peer_uuid is the peer the call is addressed to (its host is <uuid>.example.test).
	 */
	function wp_safe_remote_post( $url, $args = array() ) {
		global $pq_remote;
		$base   = '/wp-json/wpmmcc/v1';
		$route  = (string) substr( (string) $url, (int) strpos( (string) $url, $base ) + strlen( $base ) );
		$packet = json_decode( (string) ( $args['body'] ?? '' ), true );
		$peer   = (string) preg_replace( '/\.example\.test$/', '', (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		$pq_remote['calls'][] = array(
			'url'     => $url,
			'route'   => $route,
			'packet'  => $packet,
			'peer'    => $peer,
			'headers' => $args['headers'] ?? array(),
		);
		$key = $peer . ':' . ( $packet['packet_id'] ?? '' );
		if ( '/sync/receipt' === $route ) {
			$data = $pq_remote['receipts'][ $key ] ?? pq_packet_receipt( $packet, $peer, false );
			return pq_wire_response( 200, wp_json_encode( array( 'success' => true, 'data' => $data ) ), $args['headers'], $route );
		}
		$handler = $pq_remote['handler'];
		$res     = is_callable( $handler ) ? $handler( $route, $packet, $peer ) : 200;
		if ( $res instanceof WP_Error ) {
			return $res;
		}
		if ( is_array( $res ) ) {
			return pq_wire_response( (int) $res['status'], (string) ( $res['body'] ?? '' ), $args['headers'], $route );
		}
		$data = array();
		if ( '/sync/push' === $route && (int) $res >= 200 && (int) $res < 300 ) {
			$data = pq_packet_receipt( $packet, $peer, true );
			$pq_remote['receipts'][ $key ] = $data;
		}
		return pq_wire_response( (int) $res, wp_json_encode( array( 'success' => true, 'data' => $data ) ), $args['headers'], $route );
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	/** A peer request must go through Peer_Http (WMC-09); the unchecked transport is a regression. */
	function wp_remote_post( $url, $args = array() ) {
		assert_test( false, "a peer request went out through wp_remote_post() to {$url}; it must go through Peer_Http" );
	}
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/** wp_postmeta as a view over the in-memory meta store. */
function pq_postmeta_view(): array {
	global $wp_mock_meta;
	$rows = array();
	$n    = 0;
	foreach ( $wp_mock_meta as $post_id => $meta ) {
		foreach ( $meta as $key => $value ) {
			$rows[] = array(
				'meta_id'    => ++$n,
				'post_id'    => (int) $post_id,
				'meta_key'   => (string) $key,
				'meta_value' => is_scalar( $value ) ? (string) $value : serialize( $value ),
			);
		}
	}
	return $rows;
}

/** Fresh DB + WP state for one scenario. */
function pq_reset(): void {
	global $wpdb, $wp_mock_posts, $wp_mock_meta, $wp_mock_options, $wp_mock_option_autoload, $wp_mock_posts_strict, $wp_mock_cache, $pq_remote;

	$wp_mock_option_autoload = array();
	$wpdb                    = new Mini_SQL_wpdb();
	$wpdb->create_table(
		'wp_wpmmcc_journal',
		array(
			'occurred_at'        => '',
			'event_type'         => '',
			'direction'          => '',
			'canonical_uuid'     => '',
			'object_type'        => 'post',
			'local_object_id'    => 0,
			'peer_uuid'          => '',
			'remote_object_id'   => 0,
			'packet_id'          => '',
			'source_fingerprint' => '',
			'outcome'            => '',
			'duration_ms'        => 0,
			'payload_digest'     => '',
			'diff_summary'       => null,
			'message'            => null,
			'consumed'           => 0,
		)
	);
	$wpdb->create_table(
		'wp_wpmmcc_peers',
		array(
			'peer_uuid'         => '',
			'peer_name'         => '',
			'endpoint_url'      => '',
			'shared_secret'     => '',
			'key_salt'          => '',
			'direction'         => 'bidirectional',
			'status'            => 'active',
			'conflict_strategy' => 'lww',
		)
	);
	$wpdb->create_table(
		'wp_wpmmcc_cross_mappings',
		array(
			'canonical_uuid'      => '',
			'local_object_type'   => 'post',
			'local_object_id'     => 0,
			'peer_uuid'           => '',
			'remote_object_id'    => 0,
			'remote_permalink'    => null,
			'content_checksum'    => '',
			'vector_clock'        => 1,
			'last_sync_direction' => 'outbound',
			'last_synced_at'      => '',
		)
	);

	// Real table for Reconciler::generate_digest (it queries wp_posts rows
	// directly; everything else reads the WP_Post mocks).
	$wpdb->create_table(
		'wp_posts',
		array(
			'ID'                => 0,
			'post_title'        => '',
			'post_content'      => '',
			'post_excerpt'      => '',
			'post_date_gmt'     => '',
			'comment_status'    => 'open',
			'ping_status'       => 'open',
			'menu_order'        => 0,
			'post_status'       => 'publish',
			'post_modified_gmt' => '',
			'post_type'         => 'post',
		)
	);
	// The digest excludes conflict-review drafts through a postmeta subquery
	// (WMC-11); a view keeps that SQL and get_post_meta() on one truth.
	$wpdb->create_view( 'wp_postmeta', 'pq_postmeta_view' );
	$wp_mock_posts         = array();
	$wp_mock_meta          = array();
	$wp_mock_cache         = array();
	$wp_mock_posts_strict  = true; // real WP: a missing post is null.
	// Wholesale reset: a stale pull/reconcile sweep cursor option from a
	// previous scenario (same peer uuid) must not leak into this one.
	$wp_mock_options       = array( 'wpmmcc_origin_uuid' => 'urn:uuid:origin-self' );
	$pq_remote             = array(
		'calls'   => array(),
		'handler' => null,
		'receipts' => array(),
	);
}

/** Register an active peer whose shared secret really decrypts. */
function pq_peer( string $uuid, string $direction = 'bidirectional', string $status = 'active' ): array {
	global $wpdb;
	$salt = substr( str_pad( 'pqsalt' . $uuid, 32, 'x' ), 0, 32 );
	$row  = array(
		'peer_uuid'     => $uuid,
		'peer_name'     => 'Peer ' . $uuid,
		'endpoint_url'  => 'https://' . $uuid . '.example.test/wp-json/wpmmcc/v1',
		'shared_secret' => Rest_Middleware::encrypt_shared_secret( 'pq-shared-secret', $salt ),
		'key_salt'      => $salt,
		'direction'     => $direction,
		'status'        => $status,
	);
	$wpdb->insert( 'wp_wpmmcc_peers', $row );
	wp_cache_delete( 'active_peers_list', 'wpmmcc' );
	// Peer_Http vets the host, so the name has to resolve to a routable address (the documentation ranges are refused like the other reserved ones).
	$GLOBALS['wpmmcc_test_dns'][ $uuid . '.example.test' ] = array( '93.184.216.34' );
	return $row;
}

/** A live, canonical-uuid-bearing post. */
function pq_post( int $id, string $status = 'publish', string $type = 'post' ): WP_Post {
	global $wp_mock_posts;
	$post = new WP_Post(
		array(
			'ID'           => $id,
			'post_title'   => "Post {$id}",
			'post_content' => "Content {$id}",
			'post_status'  => $status,
			'post_type'    => $type,
			'post_name'    => "post-{$id}",
		)
	);
	$wp_mock_posts[ $id ] = $post;
	update_post_meta( $id, '_wpmmcc_canonical_uuid', pq_uuid( $id ) );
	return $post;
}

function pq_uuid( int $post_id ): string {
	return 'urn:uuid:post-' . $post_id;
}

/** Insert a pending outbound CDC row exactly as Event_Recorder shapes it. */
function pq_event( int $post_id, string $type = 'update', string $peer_uuid = '', array $extra = array() ): int {
	global $wpdb;
	$wpdb->insert(
		'wp_wpmmcc_journal',
		array_merge(
			array(
				'occurred_at'     => current_time( 'mysql' ),
				'event_type'      => $type,
				'direction'       => 'outbound',
				'canonical_uuid'  => pq_uuid( $post_id ),
				'object_type'     => 'post',
				'local_object_id' => $post_id,
				'peer_uuid'       => $peer_uuid,
				'outcome'         => 'success',
				'consumed'        => 0,
			),
			$extra
		)
	);
	return (int) $wpdb->insert_id;
}

/** Journal rows that are still CDC-pending for a peer scope. */
function pq_pending( string $peer_uuid = '' ): array {
	global $wpdb;
	return array_values(
		array_filter(
			$wpdb->rows( 'wp_wpmmcc_journal' ),
			static function ( $r ) use ( $peer_uuid ) {
				return 'outbound' === $r['direction'] && 0 === (int) $r['consumed'] && ( '' === $peer_uuid || in_array( $r['peer_uuid'], array( '', $peer_uuid ), true ) );
			}
		)
	);
}

function pq_rows_where( callable $pred ): array {
	global $wpdb;
	return array_values( array_filter( $wpdb->rows( 'wp_wpmmcc_journal' ), $pred ) );
}

/** Packets delivered to /sync/push so far, as [guid => [actions...]]. */
function pq_delivered(): array {
	global $pq_remote;
	$out = array();
	foreach ( $pq_remote['calls'] as $call ) {
		if ( '/sync/push' !== $call['route'] ) {
			continue;
		}
		$guid          = (string) ( $call['packet']['entity']['guid'] ?? '' );
		$out[ $guid ][] = (string) ( $call['packet']['action'] ?? '' );
	}
	return $out;
}

/** Scripted peer: status by canonical uuid, default 200. */
function pq_peer_script( array $by_uuid, $default = 200 ): void {
	global $pq_remote;
	$pq_remote['handler'] = static function ( $route, $packet ) use ( $by_uuid, $default ) {
		$guid = (string) ( $packet['entity']['guid'] ?? '' );
		return $by_uuid[ $guid ] ?? $default;
	};
}

/** Does a local cross-mappings row exist for the canonical uuid? */
function pq_mapped( string $uuid ): bool {
	global $wpdb;
	foreach ( $wpdb->rows( 'wp_wpmmcc_cross_mappings' ) as $r ) {
		if ( $uuid === (string) $r['canonical_uuid'] ) {
			return true;
		}
	}
	return false;
}

/**
 * Make a post visible BOTH to Reconciler::generate_digest (which reads real
 * wp_posts rows, keyset-paginated) and to export/get_post (WP_Post mocks).
 */
function pq_digest_post( int $id, string $status = 'publish', string $type = 'post' ): void {
	global $wpdb;
	$post = pq_post( $id, $status, $type );
	$wpdb->insert(
		'wp_posts',
		(array) $post
	);
}

/**
 * Build a packet the way the remote site `$peer_uuid` would have: its own uuid
 * as the origin, which is what the inbound side holds a first-hand packet to.
 */
function pq_packet_from( string $peer_uuid, array $args ): array {
	global $wp_mock_options;
	$mine = $wp_mock_options['wpmmcc_origin_uuid'] ?? '';
	if ( '' !== $peer_uuid ) {
		$wp_mock_options['wpmmcc_origin_uuid'] = $peer_uuid;
	}
	try {
		return Packet::build( $args );
	} finally {
		$wp_mock_options['wpmmcc_origin_uuid'] = $mine;
	}
}

/**
 * Script a pull SOURCE peer: serves a synthetic digest (keyset-paginated on
 * local_id, 200 per page like Rest_Digest) and real /sync/pull packets, so
 * Manual_Sync::pull_now can be driven end to end through the real ingest.
 *
 * @param array    $items         Digest items (each needs canonical_uuid + local_id).
 * @param bool|int $pull_ok       true = serve packets; false = /sync/pull dies at
 *                                the transport level; an int = the peer answers
 *                                that HTTP status (e.g. 429) with an error body.
 * @param int[]    $poison_ids    Entities served as packets the ingest rejects
 *                                (unsupported object type), so they stay unmapped.
 * @param int      $digest_status HTTP status of /sync/digest; non-2xx answers
 *                                with an error body and no items.
 */
function pq_pull_source( array $items, $pull_ok = true, array $poison_ids = array(), int $digest_status = 200 ): void {
	global $pq_remote;
	$refusal              = static function ( int $status ) {
		return array(
			'status' => $status,
			'body'   => wp_json_encode( array( 'code' => 'wpmmcc_refused', 'message' => 'Peer refused the request' ) ),
		);
	};
	$pq_remote['handler'] = static function ( $route, $body, $peer_uuid = '' ) use ( $items, $pull_ok, $poison_ids, $digest_status, $refusal ) {
		if ( '/sync/digest' === $route ) {
			if ( $digest_status < 200 || $digest_status >= 300 ) {
				return $refusal( $digest_status );
			}
			$after = (int) ( $body['last_seen_id'] ?? 0 );
			$page  = array();
			foreach ( $items as $item ) {
				if ( (int) $item['local_id'] > $after ) {
					$page[] = $item;
					if ( count( $page ) >= 200 ) {
						break;
					}
				}
			}
			return array(
				'status' => 200,
				'body'   => wp_json_encode( array( 'success' => true, 'data' => array( 'items' => $page ) ) ),
			);
		}
		if ( '/sync/pull' === $route ) {
			if ( false === $pull_ok ) {
				return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
			}
			if ( is_int( $pull_ok ) ) {
				return $refusal( $pull_ok );
			}
			$packets = array();
			foreach ( (array) ( $body['canonical_uuids'] ?? array() ) as $uuid ) {
				$id = (int) substr( (string) $uuid, strlen( 'urn:uuid:post-' ) );
				$packet = pq_packet_from(
					$peer_uuid,
					array(
						'guid'        => (string) $uuid,
						'source_id'  => $id,
						'slug'       => "pulled-{$id}",
						'core_fields' => array(
							'post_title'   => "Pulled {$id}",
							'post_content' => "<p>Pulled body {$id}</p>",
						),
					)
				);
				if ( in_array( $id, $poison_ids, true ) ) {
					$packet['entity']['object_type'] = 'unsupported_kind';
				}
				$packets[] = $packet;
			}
			return array(
				'status' => 200,
				'body'   => wp_json_encode( array( 'success' => true, 'data' => array( 'packets' => $packets ) ) ),
			);
		}
		return 200;
	};
}

/**
 * Script a RECONCILE peer. /sync/reconcile-digest answers verdict_of(n) for
 * every submitted fingerprint (n = the post number inside pq_uuid()),
 * /sync/push answers $state->push_reply[n] (default 200) and an empty
 * /sync/digest keeps the pull leg quiet. The returned state records what the
 * peer saw, so scenarios can assert window coverage and drift delivery.
 *
 * @param callable $verdict_of fn(int $n): string — in_sync|missing|source_newer|local_edited.
 * @return \stdClass {windows:int[][], pushed:int[], digest_reply:null|int|WP_Error, push_reply:array<int,int|WP_Error>}
 */
function pq_reconcile_peer( callable $verdict_of ): \stdClass {
	global $pq_remote;
	$state               = new \stdClass();
	$state->windows      = array();
	$state->pushed       = array();
	$state->digest_reply = null;
	$state->push_reply   = array();

	$number_of = static function ( string $uuid ): int {
		return (int) substr( $uuid, strlen( 'urn:uuid:post-' ) );
	};

	$pq_remote['handler'] = static function ( $route, $body ) use ( $state, $verdict_of, $number_of ) {
		if ( '/sync/reconcile-digest' === $route ) {
			$numbers  = array();
			$verdicts = array();
			foreach ( (array) ( $body['fingerprints'] ?? array() ) as $fp ) {
				$uuid              = (string) ( $fp['canonical_uuid'] ?? '' );
				$numbers[]         = $number_of( $uuid );
				$verdicts[ $uuid ] = $verdict_of( $number_of( $uuid ) );
			}
			$state->windows[] = $numbers;
			if ( null !== $state->digest_reply ) {
				return $state->digest_reply;
			}
			return array(
				'status' => 200,
				'body'   => wp_json_encode( array( 'success' => true, 'data' => array( 'verdicts' => $verdicts ) ) ),
			);
		}
		if ( '/sync/digest' === $route ) {
			return array( 'status' => 200, 'body' => wp_json_encode( array( 'success' => true, 'data' => array( 'items' => array() ) ) ) );
		}
		if ( '/sync/push' === $route ) {
			$n     = $number_of( (string) ( $body['entity']['guid'] ?? '' ) );
			$reply = $state->push_reply[ $n ] ?? 200;
			if ( is_int( $reply ) && $reply >= 200 && $reply < 300 ) {
				$state->pushed[] = $n;
			}
			return $reply;
		}
		return 200;
	};
	return $state;
}

/**
 * wpdb double for uninstall.php: answers get_col() LIKE lookups over the mock
 * options table (honouring esc_like'd wildcards) and swallows the DDL/DELETE
 * statements it cannot model.
 */
class PQ_Uninstall_wpdb extends Mini_SQL_wpdb {
	public $options  = 'wp_options';
	public $sitemeta = 'wp_sitemeta';
	/** @var array<string,array> Prepared query => its arguments. */
	private $prepared = array();

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	public function prepare( $query, ...$args ) {
		$this->prepared[ $query ] = $args;
		return $query;
	}

	public function query( $sql ) {
		return 0;
	}

	public function get_col( $sql = null, $x = 0 ) {
		global $wp_mock_options;
		$patterns = array();
		foreach ( (array) ( $this->prepared[ $sql ] ?? array() ) as $like ) {
			$regex = '';
			$chars = str_split( (string) $like );
			for ( $i = 0, $n = count( $chars ); $i < $n; $i++ ) {
				if ( '\\' === $chars[ $i ] && isset( $chars[ $i + 1 ] ) ) {
					$regex .= preg_quote( $chars[ ++$i ], '/' );
				} elseif ( '%' === $chars[ $i ] ) {
					$regex .= '.*';
				} elseif ( '_' === $chars[ $i ] ) {
					$regex .= '.';
				} else {
					$regex .= preg_quote( $chars[ $i ], '/' );
				}
			}
			$patterns[] = '/^' . $regex . '$/s';
		}
		$out = array();
		foreach ( array_keys( (array) $wp_mock_options ) as $name ) {
			foreach ( $patterns as $p ) {
				if ( preg_match( $p, (string) $name ) ) {
					$out[] = (string) $name;
					break;
				}
			}
		}
		return $out;
	}
}

/** Adapter whose export always throws (a poison entity). */
if ( ! class_exists( 'PQ_Poison_Adapter' ) ) {
	class PQ_Poison_Adapter implements \WPMMCC\Adapters\Adapter_Interface {
		public function get_slug(): string {
			return 'pq_poison';
		}
		public function get_supported_post_types(): array {
			return array( 'pq_poison_cpt' );
		}
		public function is_plugin_active(): bool {
			return true;
		}
		public function export_custom_payload( int $post_id, $post ): array {
			throw new \RuntimeException( 'adapter exploded on post ' . $post_id );
		}
		public function import_custom_payload( int $target_post_id, array $payload, array $context = array() ): bool {
			return true;
		}
		public function get_translatable_fields( string $post_type ): array {
			return array();
		}
	}
}

/** Adapter whose export throws for 'page', one of the post types the reconcile digest covers. */
if ( ! class_exists( 'PQ_Poison_Page_Adapter' ) ) {
	class PQ_Poison_Page_Adapter implements \WPMMCC\Adapters\Adapter_Interface {
		public function get_slug(): string {
			return 'pq_poison_page';
		}
		public function get_supported_post_types(): array {
			return array( 'page' );
		}
		public function is_plugin_active(): bool {
			return true;
		}
		public function export_custom_payload( int $post_id, $post ): array {
			throw new \RuntimeException( 'adapter exploded on page ' . $post_id );
		}
		public function import_custom_payload( int $target_post_id, array $payload, array $context = array() ): bool {
			return true;
		}
		public function get_translatable_fields( string $post_type ): array {
			return array();
		}
	}
}

/**
 * Put an adapter AHEAD of every other registered one and return the callback
 * that takes it out again. The registry matches post types in registration
 * order, and init() registers the page-builder adapters (which claim 'page')
 * before anything a scenario adds, so a plain register_adapter() would make
 * the scenario depend on whether init() has already run.
 */
function pq_adapter_first( \WPMMCC\Adapters\Adapter_Interface $adapter ): callable {
	\WPMMCC\Adapters\Adapter_Registry::init();
	$prop = new \ReflectionProperty( \WPMMCC\Adapters\Adapter_Registry::class, 'adapters' );
	$prop->setAccessible( true );
	$before = $prop->getValue();
	$prop->setValue( null, array_merge( array( $adapter->get_slug() => $adapter ), $before ) );
	return static function () use ( $prop, $before ): void {
		$prop->setValue( null, $before );
	};
}

// ---------------------------------------------------------------------------
// Scenarios
// ---------------------------------------------------------------------------

/**
 * WMC-01 (P0): events whose main post is gone sat at the queue head forever
 * (export_post -> wpmmcc_post_not_found, never consumed), starving every
 * good event behind them once >= MAX_PACKETS_PER_RUN of them accumulated.
 */
function pq_scenario_wmc01_poison_head(): void {
	echo "\n--- WMC-01: 12 events for deleted posts ahead of 3 good events ---\n";
	global $wpdb;
	pq_reset();
	$peer = pq_peer( 'peer-b' );

	// 12 "main post already deleted" events (6 delete + 6 stale update).
	for ( $i = 101; $i <= 112; $i++ ) {
		pq_event( $i, $i % 2 ? 'delete' : 'update', 'peer-b' );
	}
	// 3 good events behind them.
	for ( $i = 201; $i <= 203; $i++ ) {
		pq_post( $i );
		pq_event( $i, 'update', 'peer-b' );
	}
	assert_test( 15 === count( pq_pending( 'peer-b' ) ), 'seeded 15 pending events (12 poison + 3 good)' );

	for ( $run = 1; $run <= 3; $run++ ) {
		Manual_Sync::push_now( $peer );
	}

	$delivered = pq_delivered();
	foreach ( array( 201, 202, 203 ) as $id ) {
		assert_test(
			isset( $delivered[ pq_uuid( $id ) ] ) && in_array( 'upsert', $delivered[ pq_uuid( $id ) ], true ),
			"good event for post {$id} reached the peer within 3 runs (was blocked forever)"
		);
	}
	assert_test( 0 === count( pq_pending( 'peer-b' ) ), 'queue fully drained after 3 runs (no event left blocking)' );

	// The gone posts' deletes must actually reach the peer as tombstones.
	foreach ( array( 101, 102, 111, 112 ) as $id ) {
		assert_test(
			isset( $delivered[ pq_uuid( $id ) ] ) && in_array( 'delete', $delivered[ pq_uuid( $id ) ], true ),
			"post {$id} is gone locally -> a delete tombstone was shipped to the peer"
		);
	}

	$not_found = pq_rows_where(
		static function ( $r ) {
			return false !== strpos( (string) $r['message'], 'Post not found' );
		}
	);
	assert_test( 0 === count( $not_found ), 'no "Post not found" export-failure noise rows are journaled any more' );
}

/**
 * WMC-01 (rejected-packet flavour): the peer answers 4xx for specific packets
 * (e.g. WMC-03-style gate). Rejected events must not starve good ones, and
 * must stop being retried after a bounded number of attempts.
 */
function pq_scenario_wmc01_peer_rejects(): void {
	echo "\n--- WMC-01: peer rejects 12 packets with HTTP 400, 3 good behind them ---\n";
	global $pq_remote;
	pq_reset();
	$peer = pq_peer( 'peer-b' );

	$script = array();
	for ( $i = 301; $i <= 312; $i++ ) {
		pq_post( $i );
		pq_event( $i, 'update', 'peer-b' );
		$script[ pq_uuid( $i ) ] = 400;
	}
	for ( $i = 401; $i <= 403; $i++ ) {
		pq_post( $i );
		pq_event( $i, 'update', 'peer-b' );
	}
	pq_peer_script( $script );

	Manual_Sync::push_now( $peer );
	Manual_Sync::push_now( $peer );
	$delivered = pq_delivered();
	foreach ( array( 401, 402, 403 ) as $id ) {
		$ok = false;
		foreach ( $pq_remote['calls'] as $c ) {
			if ( pq_uuid( $id ) === ( $c['packet']['entity']['guid'] ?? '' ) ) {
				$ok = true;
			}
		}
		assert_test( $ok, "good post {$id} was attempted within 2 runs despite 12 rejected packets ahead" );
	}

	// Keep pushing: rejected events die after MAX_ATTEMPTS, then stop costing calls.
	for ( $run = 0; $run < 10; $run++ ) {
		Manual_Sync::push_now( $peer );
	}
	$dead = pq_rows_where(
		static function ( $r ) {
			return 'outbound' === $r['direction'] && Push_Queue::DEAD === (int) $r['consumed'];
		}
	);
	assert_test( 12 === count( $dead ), 'the 12 rejected events were dead-lettered (consumed=2), got ' . count( $dead ) );
	assert_test( 0 === count( pq_pending( 'peer-b' ) ), 'nothing left pending after the rejected events died' );

	$per_uuid = array();
	foreach ( $pq_remote['calls'] as $c ) {
		if ( '/sync/push' !== $c['route'] ) {
			continue;
		}
		$g = (string) ( $c['packet']['entity']['guid'] ?? '' );
		$per_uuid[ $g ] = ( $per_uuid[ $g ] ?? 0 ) + 1;
	}
	assert_test( Push_Queue::MAX_ATTEMPTS === $per_uuid[ pq_uuid( 301 ) ], 'a rejected packet is attempted exactly MAX_ATTEMPTS times (' . Push_Queue::MAX_ATTEMPTS . '), got ' . $per_uuid[ pq_uuid( 301 ) ] );

	$before = count( $pq_remote['calls'] );
	Manual_Sync::push_now( $peer );
	assert_test( count( $pq_remote['calls'] ) === $before, 'dead-lettered events cost no further peer calls' );
}

/** An outage (or bad auth) says nothing about any single event: never charge it. */
function pq_scenario_wmc01_outage_not_charged(): void {
	echo "\n--- WMC-01: outages must not burn retry budgets ---\n";
	global $pq_remote;
	pq_reset();
	$peer = pq_peer( 'peer-b' );
	pq_post( 501 );
	pq_post( 502 );
	pq_event( 501, 'update', 'peer-b' );
	pq_event( 502, 'update', 'peer-b' );

	$shapes = array(
		'transport error' => new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ),
		'HTTP 503'        => 503,
		'HTTP 401'        => 401,
		'HTTP 403'        => 403,
		'HTTP 404'        => 404,
		'HTTP 429'        => 429,
	);
	foreach ( $shapes as $label => $shape ) {
		$pq_remote['handler'] = static function () use ( $shape ) {
			return $shape;
		};
		for ( $i = 0; $i < Push_Queue::MAX_ATTEMPTS + 2; $i++ ) {
			Manual_Sync::push_now( $peer );
		}
		$rows = pq_pending( 'peer-b' );
		assert_test( 2 === count( $rows ), "{$label}: both events still pending after " . ( Push_Queue::MAX_ATTEMPTS + 2 ) . ' failed runs' );
		$charged = array_filter(
			$rows,
			static function ( $r ) {
				return Push_Queue::attempts_of( $r ) > 0;
			}
		);
		assert_test( 0 === count( $charged ), "{$label}: no event was charged an attempt" );
	}

	// Peer recovers -> everything is delivered.
	$pq_remote['handler'] = null;
	Manual_Sync::push_now( $peer );
	assert_test( 0 === count( pq_pending( 'peer-b' ) ), 'after recovery both events are delivered' );
}

/** State-based plan: what the peer must do is decided by the post NOW. */
function pq_scenario_wmc01_tombstones(): void {
	echo "\n--- WMC-01: tombstone / upsert selection follows current post state ---\n";
	pq_reset();
	$peer = pq_peer( 'peer-b' );

	pq_post( 601, 'trash' );        // trashed, still exists.
	pq_event( 601, 'trash', 'peer-b' );
	// 602: deleted (no post) with a stale 'update' event.
	pq_event( 602, 'update', 'peer-b' );
	pq_post( 603 );                 // trash event, but restored since.
	pq_event( 603, 'trash', 'peer-b' );
	pq_post( 604 );                 // delete event fired but the post survived.
	pq_event( 604, 'delete', 'peer-b' );

	Manual_Sync::push_now( $peer );
	$delivered = pq_delivered();

	assert_test( array( 'trash' ) === ( $delivered[ pq_uuid( 601 ) ] ?? null ), 'trashed post ships a trash tombstone' );
	assert_test( array( 'delete' ) === ( $delivered[ pq_uuid( 602 ) ] ?? null ), 'vanished post ships a delete tombstone even from a stale update event' );
	assert_test( array( 'upsert' ) === ( $delivered[ pq_uuid( 603 ) ] ?? null ), 'restored post ships an upsert, not a trash' );
	assert_test( array( 'upsert' ) === ( $delivered[ pq_uuid( 604 ) ] ?? null ), 'a delete event for a surviving post ships its live state' );

	global $pq_remote;
	foreach ( $pq_remote['calls'] as $c ) {
		if ( in_array( $c['packet']['action'], array( 'trash', 'delete' ), true ) ) {
			assert_test( array() === ( $c['packet']['entity']['core_fields'] ?? null ), 'tombstone carries no content fields (' . $c['packet']['action'] . ')' );
			assert_test( 'urn:uuid:origin-self' === ( $c['packet']['origin_context']['origin_site_uuid'] ?? '' ), 'tombstone is stamped with the origin site uuid' );
		}
	}

	// Tombstones must survive the receiving side's parser (round trip).
	$tomb = Packet::export_tombstone( pq_uuid( 999 ), 999, 'delete' );
	$parsed = Packet::parse( wp_json_encode( $tomb ) );
	assert_test( is_array( $parsed ) && 'delete' === $parsed['action'], 'Packet::parse accepts a delete tombstone' );
}

/** Five saves of one entity are one delivery, not five. */
function pq_scenario_wmc01_dedupe(): void {
	echo "\n--- WMC-01: several pending rows of one entity ship once ---\n";
	global $pq_remote;
	pq_reset();
	$peer = pq_peer( 'peer-b' );
	pq_post( 701 );
	pq_post( 702 );
	for ( $i = 0; $i < 5; $i++ ) {
		pq_event( 701, 'update', 'peer-b' );
	}
	pq_event( 702, 'update', 'peer-b' );

	$r = Manual_Sync::push_now( $peer );
	$delivered = pq_delivered();
	assert_test( 1 === count( $delivered[ pq_uuid( 701 ) ] ?? array() ), '5 pending rows of post 701 -> exactly 1 packet' );
	assert_test( 2 === $r['pushed'], 'pushed counts packets (2), not rows' );
	assert_test( 0 === count( pq_pending( 'peer-b' ) ), 'all 6 rows consumed' );
}

/** WMC-06 part 2: consumed was global, so a second peer lost every event. */
function pq_scenario_wmc06_per_peer(): void {
	echo "\n--- WMC-06: per-peer independent progress ---\n";
	global $wpdb, $pq_remote;
	pq_reset();
	$peer_a = pq_peer( 'peer-a' );
	$peer_b = pq_peer( 'peer-b' );
	pq_peer( 'peer-c', 'pull_only' );

	$post = pq_post( 801 );
	Event_Recorder::on_save_post( 801, $post, false );

	$rows = pq_pending();
	assert_test( 2 === count( $rows ), 'one save queues one row per push-capable peer (a, b; not the pull_only c), got ' . count( $rows ) );

	Manual_Sync::push_now( $peer_a );
	assert_test( 1 === count( pq_pending( 'peer-b' ) ), "peer-a's ack leaves peer-b's copy pending" );
	assert_test( 0 === count( pq_pending( 'peer-a' ) ), "peer-a's own copy is consumed" );

	$before = count( $pq_remote['calls'] );
	Manual_Sync::push_now( $peer_b );
	assert_test( count( $pq_remote['calls'] ) > $before, 'peer-b still receives the event after peer-a already got it' );
	assert_test( 0 === count( pq_pending() ), 'both peers drained independently' );

	$urls = array_unique( array_column( $pq_remote['calls'], 'url' ) );
	assert_test( 2 === count( $urls ), 'each peer endpoint was called (no shared consumption)' );
}

/** WMC-06 part 1: a site with nobody to push to must not accumulate CDC rows. */
function pq_scenario_wmc06_no_peers(): void {
	echo "\n--- WMC-06: no peers -> no unbounded CDC accumulation ---\n";
	global $wpdb;
	pq_reset();

	for ( $i = 901; $i <= 950; $i++ ) {
		$post = pq_post( $i );
		Event_Recorder::on_save_post( $i, $post, false );
	}
	$outbound = pq_rows_where(
		static function ( $r ) {
			return 'outbound' === $r['direction'];
		}
	);
	assert_test( 0 === count( $outbound ), '50 saves with no peer queue 0 CDC rows, got ' . count( $outbound ) );

	// Identity bookkeeping (uuid + clock) must still happen so a later
	// pairing can reconcile through the digest.
	$maps = $wpdb->rows( 'wp_wpmmcc_cross_mappings' );
	assert_test( count( $maps ) >= 50, 'saves still assign canonical uuid + vector clock rows (digest readiness), got ' . count( $maps ) );

	// Only pull_only / inactive peers -> still nothing to push to.
	pq_peer( 'peer-pull', 'pull_only' );
	pq_peer( 'peer-off', 'bidirectional', 'inactive' );
	$post = pq_post( 960 );
	Event_Recorder::on_save_post( 960, $post, false );
	assert_test( 0 === count( pq_pending() ), 'pull_only / inactive peers are not push targets' );
}

/** Default retention keeps paid, completed, dead and pending evidence. */
function pq_scenario_wmc06_retention(): void {
	echo "\n--- WMC-06: journal retention ---\n";
	global $wpdb;
	pq_reset();
	pq_peer( 'peer-b' );

	$old    = gmdate( 'Y-m-d H:i:s', time() - 45 * 86400 );
	$older  = gmdate( 'Y-m-d H:i:s', time() - 100 * 86400 );
	$recent = gmdate( 'Y-m-d H:i:s', time() - 2 * 86400 );

	$mk = static function ( array $over ) use ( $wpdb, $recent ) {
		$wpdb->insert(
			'wp_wpmmcc_journal',
			array_merge(
				array(
					'occurred_at' => $recent,
					'event_type'  => 'manual_push',
					'direction'   => 'outbound',
					'outcome'     => 'success',
					'consumed'    => 1,
				),
				$over
			)
		);
		return (int) $wpdb->insert_id;
	};
	$audit_old     = $mk( array( 'occurred_at' => $old ) );
	$audit_recent  = $mk( array() );
	$dead_old      = $mk( array( 'occurred_at' => $old, 'consumed' => 2, 'outcome' => 'failed' ) );
	$dead_recent   = $mk( array( 'consumed' => 2, 'outcome' => 'failed' ) );
	$pending_old   = $mk( array( 'occurred_at' => $old, 'consumed' => 0, 'event_type' => 'update', 'canonical_uuid' => 'u-old', 'local_object_id' => 5, 'peer_uuid' => 'peer-b' ) );
	$pending_stale = $mk( array( 'occurred_at' => $older, 'consumed' => 0, 'event_type' => 'update', 'canonical_uuid' => 'u-stale', 'local_object_id' => 6, 'peer_uuid' => 'peer-b' ) );
	$pending_new   = $mk( array( 'consumed' => 0, 'event_type' => 'update', 'canonical_uuid' => 'u-new', 'local_object_id' => 7, 'peer_uuid' => 'peer-b' ) );
	$inbound_old   = $mk( array( 'occurred_at' => $older, 'direction' => 'inbound' ) );

	$res = Push_Queue::prune();

	$by_id = array();
	foreach ( $wpdb->rows( 'wp_wpmmcc_journal' ) as $r ) {
		$by_id[ (int) $r['id'] ] = $r;
	}
	assert_test( isset( $by_id[ $audit_old ] ), 'old consumed audit evidence stays retained by default' );
	assert_test( isset( $by_id[ $inbound_old ] ), 'old inbound delivery evidence stays retained' );
	assert_test( isset( $by_id[ $dead_old ] ), 'old dead-lettered evidence stays retained' );
	assert_test( isset( $by_id[ $audit_recent ] ), 'recent audit row survives' );
	assert_test( isset( $by_id[ $dead_recent ] ), 'recent dead row survives (operator can still requeue it)' );
	assert_test( isset( $by_id[ $pending_old ] ) && 0 === (int) $by_id[ $pending_old ]['consumed'], '45-day-old PENDING row is still pending (not yet stale)' );
	assert_test( isset( $by_id[ $pending_new ] ) && 0 === (int) $by_id[ $pending_new ]['consumed'], 'recent pending row is untouched' );
	assert_test( isset( $by_id[ $pending_stale ] ) && Push_Queue::PENDING === (int) $by_id[ $pending_stale ]['consumed'], '100-day-old pending row is not expired by age' );
	assert_test( 8 === count( $by_id ), 'default prune keeps every original row' );
	assert_test( array( 'deleted' => 0, 'expired' => 0 ) === $res, 'default prune reports no deletion or expiry' );
}

/**
 * Audit expiry needs a valid policy and explicit operator confirmation.
 * Zero disables expiry. Pending TTL filters cannot authorize lost evidence.
 */
function pq_scenario_wmc06_retention_filters(): void {
	echo "\n--- WMC-06: optional confirmed peer-audit retention ---\n";
	global $wp_mock_filter_overrides;
	$ago = static function ( float $days ): string {
		return gmdate( 'Y-m-d H:i:s', time() - (int) round( $days * 86400 ) );
	};
	// pq_reset() swaps in a fresh $wpdb per case: read the global at call time.
	$mk  = static function ( array $over ): int {
		global $wpdb;
		$wpdb->insert(
			'wp_wpmmcc_journal',
			array_merge(
				array(
					'event_type' => 'manual_push',
					'direction'  => 'outbound',
					'outcome'    => 'success',
					'consumed'   => 1,
				),
				$over
			)
		);
		return (int) $wpdb->insert_id;
	};
	$has = static function ( int $id ): bool {
		global $wpdb;
		foreach ( $wpdb->rows( 'wp_wpmmcc_journal' ) as $r ) {
			if ( (int) $r['id'] === $id ) {
				return true;
			}
		}
		return false;
	};
	$state = static function ( int $id ): ?array {
		global $wpdb;
		foreach ( $wpdb->rows( 'wp_wpmmcc_journal' ) as $r ) {
			if ( (int) $r['id'] === $id ) {
				return $r;
			}
		}
		return null;
	};
	$pending = static function ( float $age_days, string $uuid ) use ( $mk, $ago ): int {
		return $mk( array( 'occurred_at' => $ago( $age_days ), 'consumed' => 0, 'event_type' => 'update', 'canonical_uuid' => $uuid, 'local_object_id' => 9, 'peer_uuid' => 'peer-b' ) );
	};

	try {
		// A window alone is not authority to delete.
		pq_reset();
		pq_peer( 'peer-b' );
		$wp_mock_filter_overrides = array(
			'wpmmcc_journal_retention_days' => 7,
			'wpmmcc_cdc_pending_ttl_days'   => 20,
		);
		$audit_10d   = $mk( array( 'occurred_at' => $ago( 10 ) ) );
		$audit_5d    = $mk( array( 'occurred_at' => $ago( 5 ) ) );
		$pending_25d = $pending( 25, 'u-25d' );
		$pending_15d = $pending( 15, 'u-15d' );
		$eligible = $mk( array( 'occurred_at' => $ago( 10 ), 'object_type' => 'peer', 'event_type' => 'peer_disconnect' ) );
		Push_Queue::prune();
		assert_test( $has( $audit_10d ) && $has( $eligible ), 'a positive retention filter without explicit confirmation deletes nothing' );
		update_option( 'wpmmcc_audit_retention_confirmed', true );
		$result = Push_Queue::prune();
		assert_test( ! $has( $eligible ) && 1 === $result['deleted'], 'explicitly confirmed policy removes only unreferenced eligible peer audit' );
		assert_test( $has( $audit_10d ), 'completed content-delivery audit is never eligible' );
		assert_test( $has( $audit_5d ), 'and a 5-day-old audit row survives' );
		assert_test( $has( $pending_25d ) && Push_Queue::PENDING === (int) $state( $pending_25d )['consumed'], 'pending TTL filter does not expire original pending deliveries' );
		assert_test( $has( $pending_15d ) && 0 === (int) $state( $pending_15d )['consumed'], 'a 15-day-old pending row is still pending' );

		// Zero explicitly means retain, not a one-day floor.
		pq_reset();
		pq_peer( 'peer-b' );
		$wp_mock_filter_overrides = array( 'wpmmcc_journal_retention_days' => 0 );
		$audit_2d  = $mk( array( 'occurred_at' => $ago( 2 ) ) );
		$audit_12h = $mk( array( 'occurred_at' => $ago( 0.5 ) ) );
		$eligible = $mk( array( 'occurred_at' => $ago( 100 ), 'object_type' => 'peer', 'event_type' => 'peer_connect' ) );
		update_option( 'wpmmcc_audit_retention_confirmed', true );
		Push_Queue::prune();
		assert_test( $has( $audit_2d ) && $has( $eligible ), 'retention zero keeps old evidence including otherwise eligible peer audit' );
		assert_test( $has( $audit_12h ), 'but a 12-hour-old audit row survives (a zero window must not wipe the journal)' );

		// Invalid policies also retain. Pending rows never age-expire.
		pq_reset();
		pq_peer( 'peer-b' );
		$wp_mock_filter_overrides = array(
			'wpmmcc_journal_retention_days' => 10,
			'wpmmcc_cdc_pending_ttl_days'   => 1,
		);
		$pending_5d  = $pending( 5, 'u-5d' );
		$pending_12d = $pending( 12, 'u-12d' );
		Push_Queue::prune();
		assert_test( $has( $pending_5d ) && 0 === (int) $state( $pending_5d )['consumed'], 'pending TTL = 1 day is raised to the 10-day retention: a 5-day-old pending row is not expired' );
		assert_test( $has( $pending_12d ) && Push_Queue::PENDING === (int) $state( $pending_12d )['consumed'], 'older pending deliveries also remain pending' );
		update_option( 'wpmmcc_audit_retention_confirmed', true );
		foreach ( array( -1, '7', 1.5, 36501, null ) as $invalid ) {
			$wp_mock_filter_overrides = array( 'wpmmcc_journal_retention_days' => $invalid );
			assert_test( array( 'deleted' => 0, 'expired' => 0 ) === Push_Queue::prune(), 'invalid retention policy keeps all evidence: ' . var_export( $invalid, true ) );
		}
	} finally {
		$wp_mock_filter_overrides = array();
	}
}

/** Deletes of revisions / never-tracked posts are noise, not sync events. */
function pq_scenario_wmc06_untracked_deletes(): void {
	echo "\n--- WMC-06: delete/trash hooks ignore untracked entities ---\n";
	global $wpdb;
	pq_reset();
	pq_peer( 'peer-b' );

	// A revision being pruned by WordPress.
	$rev = pq_post( 1001, 'inherit', 'revision' );
	Event_Recorder::on_delete_post( 1001, $rev );
	// A plain post that was never tracked (no canonical uuid anywhere).
	global $wp_mock_posts;
	$wp_mock_posts[1002] = new WP_Post( array( 'ID' => 1002, 'post_status' => 'publish', 'post_type' => 'post' ) );
	Event_Recorder::on_trash_post( 1002 );
	Event_Recorder::on_delete_post( 1002, $wp_mock_posts[1002] );

	assert_test( 0 === count( pq_pending() ), 'revision delete + untracked trash/delete queue nothing, got ' . count( pq_pending() ) );
	assert_test( 0 === count( $wpdb->rows( 'wp_wpmmcc_cross_mappings' ) ), 'and do not mint mapping/clock rows for them' );
	assert_test( '' === (string) get_post_meta( 1002, '_wpmmcc_canonical_uuid', true ), 'and do not mint canonical uuids for them' );

	// A tracked post being trashed and deleted still queues.
	$tracked = pq_post( 1003 );
	Event_Recorder::on_save_post( 1003, $tracked, false );
	Event_Recorder::on_trash_post( 1003 );
	Event_Recorder::on_delete_post( 1003, $tracked );
	$types = array_column( pq_pending( 'peer-b' ), 'event_type' );
	assert_test( array( 'insert', 'trash', 'delete' ) === $types, 'tracked post queues insert/trash/delete for the peer, got ' . implode( ',', $types ) );
}

/** A dead-lettered event can be put back (operator recovery). */
function pq_scenario_wmc09_requeue(): void {
	echo "\n--- WMC-09: dead-letter requeue + counts ---\n";
	global $wpdb;
	pq_reset();
	$peer = pq_peer( 'peer-b' );
	$other = pq_peer( 'peer-c' );
	pq_post( 1101 );
	pq_event( 1101, 'update', 'peer-b' );
	pq_event( 1101, 'update', 'peer-c' );
	pq_post( 1102 );
	pq_event( 1102, 'update', 'peer-b' );
	pq_peer_script( array( pq_uuid( 1101 ) => 400 ) );

	for ( $i = 0; $i < Push_Queue::MAX_ATTEMPTS + 1; $i++ ) {
		$last = Manual_Sync::push_now( $peer );
	}
	$c = Push_Queue::counts( 'peer-b' );
	assert_test( 1 === $c['dead'] && 0 === $c['pending'] && 0 === $c['retrying'], 'counts: 1 dead, 0 pending, 0 retrying for peer-b, got ' . json_encode( $c ) );
	assert_test( 0 === Push_Queue::counts( 'peer-c' )['dead'], "peer-c's copy is not dead" );

	$n = Push_Queue::requeue_dead( 'peer-b' );
	assert_test( 1 === $n, 'requeue_dead reports 1 requeued row, got ' . var_export( $n, true ) );
	$c = Push_Queue::counts( 'peer-b' );
	assert_test( 1 === $c['pending'] && 0 === $c['dead'] && 0 === $c['retrying'], 'requeued row is a fresh pending row again, got ' . json_encode( $c ) );
	$row = pq_pending( 'peer-b' )[0];
	assert_test( 0 === Push_Queue::attempts_of( $row ), 'requeue resets the attempt budget' );

	// Disconnecting parks queue rows, retaining both delivery and audit.
	$wpdb->insert( 'wp_wpmmcc_journal', array( 'event_type' => 'manual_push', 'direction' => 'outbound', 'peer_uuid' => 'peer-b', 'outcome' => 'success', 'consumed' => 1, 'occurred_at' => current_time( 'mysql' ) ) );
	$gone = Push_Queue::forget_peer( 'peer-b' );
	assert_test( $gone >= 1, 'forget_peer parked original peer queue rows' );
	assert_test( 0 === count( pq_pending( 'peer-b' ) ), 'disconnected peer has no runnable pending rows' );
	$parked = pq_rows_where( static function ( $r ) {
		return 'peer-b' === $r['peer_uuid'] && Push_Queue::DEAD === (int) $r['consumed'];
	} );
	assert_test( count( $parked ) >= 1, 'original disconnected rows and their retry evidence remain available' );
	$audit = pq_rows_where(
		static function ( $r ) {
			return 'manual_push' === $r['event_type'] && 'peer-b' === $r['peer_uuid'] && 1 === (int) $r['consumed'];
		}
	);
	assert_test( count( $audit ) >= 1, "forget_peer keeps the peer's audit rows" );
	assert_test( 1 === count( pq_pending( 'peer-c' ) ), "and does not touch peer-c's queue" );
}

/** A throwing adapter is a failed entity; the run and the queue carry on. */
function pq_scenario_wmc01_export_exception(): void {
	echo "\n--- WMC-01: an adapter that throws must not take the run down ---\n";
	pq_reset();
	\WPMMCC\Adapters\Adapter_Registry::register_adapter( new PQ_Poison_Adapter() );
	$peer = pq_peer( 'peer-b' );
	pq_post( 1201, 'publish', 'pq_poison_cpt' );
	pq_event( 1201, 'update', 'peer-b' );
	pq_post( 1202 );
	pq_event( 1202, 'update', 'peer-b' );
	pq_post( 1203 );
	pq_event( 1203, 'update', 'peer-b' );

	try {
		$r = Manual_Sync::push_now( $peer );
	} catch ( \Throwable $e ) {
		assert_test( false, 'push_now must not propagate an adapter exception, got: ' . $e->getMessage() );
	}
	assert_test( 2 === $r['pushed'] && 1 === $r['failed'], 'the two healthy entities shipped, the poison one failed (pushed=2 failed=1), got ' . json_encode( $r ) );
	$delivered = pq_delivered();
	assert_test( isset( $delivered[ pq_uuid( 1202 ) ], $delivered[ pq_uuid( 1203 ) ] ) && ! isset( $delivered[ pq_uuid( 1201 ) ] ), 'healthy entities reached the peer; nothing was sent for the poison entity' );

	$row = pq_rows_where(
		static function ( $r ) {
			return 'outbound' === $r['direction'] && 'update' === $r['event_type'] && pq_uuid( 1201 ) === $r['canonical_uuid'];
		}
	)[0];
	assert_test( 1 === Push_Queue::attempts_of( $row ), 'the poison entity was charged one attempt' );
	assert_test( false !== strpos( (string) $row['message'], 'export failed before delivery intent' )
		&& false === strpos( (string) $row['message'], 'adapter exploded' ), 'the row records the safe pre-intent export failure, not raw adapter exception text' );

	for ( $i = 0; $i < Push_Queue::MAX_ATTEMPTS; $i++ ) {
		Manual_Sync::push_now( $peer );
	}
	assert_test( 1 === Push_Queue::counts( 'peer-b' )['dead'], 'the poison entity ends up dead-lettered after MAX_ATTEMPTS' );
}

/** 5xx is ambiguous: it charges an entity only when the peer proved healthy. */
function pq_scenario_wmc01_server_error_attribution(): void {
	echo "\n--- WMC-01: attributing HTTP 500 / peer-wide errors ---\n";
	global $pq_remote;

	// Part 1: the peer answers 500 for one entity and accepts its neighbours.
	pq_reset();
	$peer = pq_peer( 'peer-b' );
	pq_post( 1301 );
	pq_event( 1301, 'update', 'peer-b' );
	pq_peer_script( array( pq_uuid( 1301 ) => 500 ) );
	for ( $run = 1; $run <= Push_Queue::MAX_ATTEMPTS; $run++ ) {
		$neighbour = 1310 + $run;
		pq_post( $neighbour );
		pq_event( $neighbour, 'update', 'peer-b' );
		Manual_Sync::push_now( $peer );
	}
	$c = Push_Queue::counts( 'peer-b' );
	assert_test( 1 === $c['dead'] && 0 === $c['pending'] && 0 === $c['retrying'], 'an entity the peer 500s on while accepting others dies after MAX_ATTEMPTS, got ' . json_encode( $c ) );
	$delivered = pq_delivered();
	assert_test( 5 === count( array_filter( array_keys( $delivered ), static function ( $g ) { return 0 === strpos( $g, 'urn:uuid:post-131' ); } ) ), 'all 5 healthy neighbours were delivered meanwhile' );

	// Part 2: everything answers 500 (peer broken): nobody is charged.
	pq_reset();
	$peer = pq_peer( 'peer-b' );
	foreach ( array( 1401, 1402 ) as $id ) {
		pq_post( $id );
		pq_event( $id, 'update', 'peer-b' );
	}
	$pq_remote['handler'] = static function () {
		return 500;
	};
	for ( $i = 0; $i < Push_Queue::MAX_ATTEMPTS + 2; $i++ ) {
		Manual_Sync::push_now( $peer );
	}
	$rows    = pq_pending( 'peer-b' );
	$charged = array_filter(
		$rows,
		static function ( $r ) {
			return Push_Queue::attempts_of( $r ) > 0;
		}
	);
	assert_test( 2 === count( $rows ) && 0 === count( $charged ), 'HTTP 500 for every packet (peer down) charges nothing and drops nothing' );

	// Part 3: peer-wide error codes, even on a 4xx status, never charge.
	foreach ( array( 'wpmmcc_incompatible_schema', 'wpmmcc_key_decryption_failed', 'wpmmcc_clock_drift' ) as $code ) {
		pq_reset();
		$peer = pq_peer( 'peer-b' );
		foreach ( array( 1501, 1502 ) as $id ) {
			pq_post( $id );
			pq_event( $id, 'update', 'peer-b' );
		}
		$pq_remote['handler'] = static function () use ( $code ) {
			return array(
				'status' => 400,
				'body'   => wp_json_encode( array( 'code' => $code ) ),
			);
		};
		for ( $i = 0; $i < Push_Queue::MAX_ATTEMPTS + 2; $i++ ) {
			Manual_Sync::push_now( $peer );
		}
		$rows    = pq_pending( 'peer-b' );
		$charged = array_filter(
			$rows,
			static function ( $r ) {
				return Push_Queue::attempts_of( $r ) > 0;
			}
		);
		assert_test( 2 === count( $rows ) && 0 === count( $charged ), "peer-wide error {$code} charges nothing" );
	}
}

/** An entity is one delivery however many rows it has, even beyond the row window. */
function pq_scenario_wmc01_dedupe_window(): void {
	echo "\n--- WMC-01: one delivery per entity beyond the row window ---\n";
	global $pq_remote;
	pq_reset();
	$peer = pq_peer( 'peer-b' );
	pq_post( 1601 );
	pq_post( 1602 );
	$rows_of_a = Manual_Sync::MAX_PACKETS_PER_RUN * Push_Queue::ROW_WINDOW + 5;
	for ( $i = 0; $i < $rows_of_a; $i++ ) {
		pq_event( 1601, 'update', 'peer-b' );
	}
	pq_event( 1602, 'update', 'peer-b' );

	Manual_Sync::push_now( $peer );
	$delivered = pq_delivered();
	assert_test( 1 === count( $delivered[ pq_uuid( 1601 ) ] ?? array() ), "{$rows_of_a} rows of one entity ship as 1 packet" );
	assert_test( 1 === count( pq_pending( 'peer-b' ) ), 'every row of that entity was consumed; only the other entity is left' );
	Manual_Sync::push_now( $peer );
	assert_test( 1 === count( pq_delivered()[ pq_uuid( 1601 ) ] ) && isset( pq_delivered()[ pq_uuid( 1602 ) ] ), 'the entity is not shipped again; the next run ships the other one' );

	// A save that lands while the packet is in flight must stay queued.
	pq_reset();
	$peer = pq_peer( 'peer-b' );
	pq_post( 1611 );
	pq_event( 1611, 'update', 'peer-b' );
	pq_event( 1611, 'update', 'peer-b' );
	$fired                = false;
	$pq_remote['handler'] = static function () use ( &$fired ) {
		if ( ! $fired ) {
			$fired = true;
			pq_event( 1611, 'update', 'peer-b' ); // an editor saves mid-delivery.
		}
		return 200;
	};
	Manual_Sync::push_now( $peer );
	assert_test( 1 === count( pq_pending( 'peer-b' ) ), 'the mid-delivery save is still pending (it is newer than what shipped)' );
	Manual_Sync::push_now( $peer );
	assert_test( 0 === count( pq_pending( 'peer-b' ) ), 'and ships on the next run' );
}

/** Failing entities keep a guaranteed share of a run, so they die instead of lingering. */
function pq_scenario_wmc01_retry_share(): void {
	echo "\n--- WMC-01: retry entities keep a share of a saturated run ---\n";
	global $pq_remote;
	pq_reset();
	$peer   = pq_peer( 'peer-b' );
	$script = array();
	foreach ( array( 1701, 1702, 1703 ) as $id ) {
		pq_post( $id );
		pq_event(
			$id,
			'update',
			'peer-b',
			array(
				'outcome'      => 'failed',
				'diff_summary' => wp_json_encode( array( 'attempts' => 1 ) ),
			)
		);
		$script[ pq_uuid( $id ) ] = 400;
	}
	for ( $i = 0; $i < 40; $i++ ) {
		$id = 1800 + $i;
		pq_post( $id );
		pq_event( $id, 'update', 'peer-b' );
	}
	pq_peer_script( $script );

	Manual_Sync::push_now( $peer );
	$calls = $pq_remote['calls'];
	assert_test( 10 === count( $calls ), 'a saturated run makes exactly 10 calls (MAX_PACKETS_PER_RUN), got ' . count( $calls ) );
	$retried = 0;
	foreach ( $calls as $c ) {
		if ( isset( $script[ $c['packet']['entity']['guid'] ] ) ) {
			$retried++;
		}
	}
	// Literal on purpose: one slot in four of a 10-packet run is 2. Deriving the
	// expectation from Push_Queue::RETRY_SHARE would pass for any value of it.
	assert_test( 2 === $retried, 'the retry lane got its reserved share (2 of 10) even though 40 fresh rows waited, got ' . $retried );
}

/** Rows that can never be shipped leave the queue at once instead of blocking it. */
function pq_scenario_wmc01_unshippable_rows(): void {
	echo "\n--- WMC-01: unshippable rows are dead-lettered up front ---\n";
	global $pq_remote;
	pq_reset();
	$peer = pq_peer( 'peer-b' );
	for ( $i = 0; $i < 12; $i++ ) {
		if ( 0 === $i % 3 ) {
			pq_event( 1900 + $i, 'update', 'peer-b', array( 'object_type' => 'term' ) );
		} elseif ( 1 === $i % 3 ) {
			pq_event( 1900 + $i, 'update', 'peer-b', array( 'canonical_uuid' => '' ) );
		} else {
			pq_event( 0, 'update', 'peer-b' );
		}
	}
	pq_post( 1950 );
	pq_event( 1950, 'update', 'peer-b' );

	Manual_Sync::push_now( $peer );
	assert_test( isset( pq_delivered()[ pq_uuid( 1950 ) ] ), 'the good event ships on the very first run' );
	assert_test( 1 === count( $pq_remote['calls'] ), 'no peer call was wasted on the 12 unshippable rows' );
	$dead = pq_rows_where(
		static function ( $r ) {
			return Push_Queue::DEAD === (int) $r['consumed'];
		}
	);
	assert_test( 12 === count( $dead ), 'all 12 unshippable rows are dead-lettered, got ' . count( $dead ) );
	assert_test( false !== strpos( (string) $dead[0]['message'], 'cannot ship' ), 'and say why: ' . $dead[0]['message'] );
}

/** Rows queued before fan-out (peer_uuid '') must still ship after an upgrade. */
function pq_scenario_wmc06_legacy_rows(): void {
	echo "\n--- WMC-06: pre-fan-out rows still ship ---\n";
	global $pq_remote;
	pq_reset();
	$peer_a = pq_peer( 'peer-a' );
	$peer_b = pq_peer( 'peer-b' );
	pq_post( 2001 );
	pq_event( 2001, 'update', '' );

	Manual_Sync::push_now( $peer_a );
	assert_test( isset( pq_delivered()[ pq_uuid( 2001 ) ] ), 'a legacy row is delivered to the first peer that runs' );
	assert_test( 0 === count( pq_pending() ), 'and consumed (legacy semantics: consumed once)' );
	$before = count( $pq_remote['calls'] );
	Manual_Sync::push_now( $peer_b );
	assert_test( count( $pq_remote['calls'] ) === $before, 'the legacy row is not shipped a second time' );
}

/** Debounce: folds rapid saves per peer; a row older than the window is left alone. */
function pq_scenario_wmc06_debounce(): void {
	echo "\n--- WMC-06: save debounce ---\n";
	global $wpdb;
	pq_reset();
	pq_peer( 'peer-a' );
	pq_peer( 'peer-b' );
	$post = pq_post( 2101 );

	Event_Recorder::on_save_post( 2101, $post, false );
	$post->post_content = 'edited once';
	Event_Recorder::on_save_post( 2101, $post, true );
	$rows = pq_pending();
	assert_test( 2 === count( $rows ), 'two rapid saves keep one row per peer (2), got ' . count( $rows ) );
	assert_test( 'update' === $rows[0]['event_type'] && 'update' === $rows[1]['event_type'], 'the folded row carries the latest event type' );
	assert_test( hash( 'sha256', 'Post 2101|edited once|publish' ) === $rows[0]['payload_digest'], 'and the latest payload digest' );

	// Age the rows past the debounce window.
	foreach ( $wpdb->tables['wp_wpmmcc_journal']['rows'] as $id => $row ) {
		$wpdb->tables['wp_wpmmcc_journal']['rows'][ $id ]['occurred_at'] = gmdate( 'Y-m-d H:i:s', time() - 60 );
	}
	$post->post_content = 'edited later';
	Event_Recorder::on_save_post( 2101, $post, true );
	assert_test( 4 === count( pq_pending() ), 'a save after the window queues new rows (4 = 2 old + 2 new), got ' . count( pq_pending() ) );
}

/** The hourly reconcile does not authorize deletion by elapsed time. */
function pq_scenario_wmc06_scheduled_prune(): void {
	echo "\n--- WMC-06: scheduled reconcile prunes ---\n";
	global $wpdb;
	pq_reset();
	$wpdb->insert(
		'wp_wpmmcc_journal',
		array(
			'occurred_at' => gmdate( 'Y-m-d H:i:s', time() - 100 * 86400 ),
			'event_type'  => 'reconcile_summary',
			'direction'   => 'local',
			'outcome'     => 'success',
			'consumed'    => 1,
		)
	);
	$wpdb->insert(
		'wp_wpmmcc_journal',
		array(
			'occurred_at' => current_time( 'mysql' ),
			'event_type'  => 'reconcile_summary',
			'direction'   => 'local',
			'outcome'     => 'success',
			'consumed'    => 1,
		)
	);
	Reconciler::run_scheduled_reconcile();
	assert_test( 2 === count( $wpdb->rows( 'wp_wpmmcc_journal' ) ), 'old and fresh reconciliation evidence both survive default scheduled prune' );
}

/**
 * WMC-08 [A]: pull_now asked the peer for one digest page (limit 200, no
 * cursor), so entity #201+ of a larger source was invisible FOREVER — the
 * page always restarted at the top of the catalog. The sweep keeps a
 * per-peer keyset cursor and wraps once the end of the catalog is reached.
 */
function pq_scenario_wmc08_pull_beyond_window(): void {
	echo "\n--- WMC-08: pull_now sweeps past the 200-item digest window ---\n";
	global $wpdb, $pq_remote;
	pq_reset();
	$peer = pq_peer( 'peer-w' );

	$items = array();
	for ( $i = 1; $i <= 210; $i++ ) {
		$items[] = array(
			'canonical_uuid'     => pq_uuid( $i ),
			'local_id'          => $i,
			'source_fingerprint' => 'fp-' . $i,
			'vector_clock'       => 1,
			'post_status'        => 'publish',
			'post_type'         => 'post',
		);
		if ( $i <= 200 ) {
			// Already pulled in an earlier pass: only the tail is missing.
			$wpdb->insert(
				'wp_wpmmcc_cross_mappings',
				array(
					'canonical_uuid'    => pq_uuid( $i ),
					'local_object_type' => 'post',
					'local_object_id'   => $i,
				)
			);
		}
	}
	pq_pull_source( $items );

	$r = Manual_Sync::pull_now( $peer );
	assert_test( 10 === (int) $r['pulled'], 'one run pulls the 10 entities beyond the old 200-item window, got ' . json_encode( $r ) );
	foreach ( array( 201, 205, 210 ) as $id ) {
		assert_test( pq_mapped( pq_uuid( $id ) ), "entity {$id} (beyond the old window) now has a local mapping" );
	}

	// The sweep must actually have asked the peer for the page after ID 200.
	$cursors = array();
	foreach ( $pq_remote['calls'] as $call ) {
		if ( '/sync/digest' === $call['route'] ) {
			$cursors[] = (int) ( $call['packet']['last_seen_id'] ?? -1 );
		}
	}
	assert_test( in_array( 200, $cursors, true ), 'the digest was asked for the page after ID 200 (keyset cursor used)' );

	// Catalog end reached -> wrap so the next pass starts fresh.
	assert_test( 0 === (int) get_option( 'wpmmcc_pull_cursor_' . md5( 'peer-w' ), -1 ), 'cursor wrapped to 0 after the pass completed' );

	$r = Manual_Sync::pull_now( $peer );
	assert_test( 0 === (int) $r['pulled'] && 0 === (int) $r['failed'], 'a wrapped pass over the now fully mapped source pulls nothing' );
	assert_test( false !== strpos( $r['message'], 'Nothing to pull: 210' ), 'the nothing-to-pull message counts the whole scanned catalog: ' . $r['message'] );
}

/**
 * WMC-08: a run stays bounded (10 packets); successive runs continue the
 * sweep instead of rescanning, and no entity is ever pulled twice.
 */
function pq_scenario_wmc08_pull_continuation(): void {
	echo "\n--- WMC-08: bounded runs continue the sweep without double pulls ---\n";
	global $pq_remote;
	pq_reset();
	$peer = pq_peer( 'peer-w' );

	$items = array();
	for ( $i = 1; $i <= 25; $i++ ) {
		$items[] = array(
			'canonical_uuid'     => pq_uuid( $i ),
			'local_id'          => $i,
			'source_fingerprint' => 'fp-' . $i,
			'vector_clock'       => 1,
			'post_status'        => 'publish',
			'post_type'         => 'post',
		);
	}
	pq_pull_source( $items );

	$total  = 0;
	$passes = 0;
	while ( $passes < 10 ) {
		$r = Manual_Sync::pull_now( $peer );
		$passes++;
		$total += (int) $r['pulled'];
		if ( 0 === (int) $r['pulled'] && 0 === (int) $r['failed'] ) {
			break;
		}
	}
	assert_test( 25 === $total, "the bounded runs pulled all 25 entities across passes ({$total} total)" );

	$requested   = array();
	$duplicates  = 0;
	foreach ( $pq_remote['calls'] as $call ) {
		if ( '/sync/pull' !== $call['route'] ) {
			continue;
		}
		foreach ( (array) ( $call['packet']['canonical_uuids'] ?? array() ) as $uuid ) {
			if ( isset( $requested[ $uuid ] ) ) {
				$duplicates++;
			}
			$requested[ $uuid ] = true;
		}
	}
	assert_test( 0 === $duplicates && 25 === count( $requested ), "no entity was pulled twice ({$duplicates} duplicates, " . count( $requested ) . ' distinct)' );
	assert_test( 0 === (int) get_option( 'wpmmcc_pull_cursor_' . md5( 'peer-w' ), -1 ), 'the cursor wrapped to 0 once the whole source was covered' );
}

/**
 * WMC-08: entities whose ingest keeps failing must not starve the rest of a
 * small catalog. A cap-limited run inside the catalog's last page keeps its
 * scan position instead of wrapping — wrapping would re-find the same failing
 * entities first on every run (the WMC-01 head-of-line problem again).
 */
function pq_scenario_wmc08_pull_poison_no_starvation(): void {
	echo "\n--- WMC-08: failing entities do not starve the rest of the catalog ---\n";
	pq_reset();
	$peer = pq_peer( 'peer-p' );
	$key  = 'wpmmcc_pull_cursor_' . md5( 'peer-p' );

	$items = array();
	for ( $i = 1; $i <= 25; $i++ ) {
		$items[] = array(
			'canonical_uuid'     => pq_uuid( $i ),
			'local_id'          => $i,
			'source_fingerprint' => 'fp-' . $i,
			'vector_clock'       => 1,
			'post_status'        => 'publish',
			'post_type'         => 'post',
		);
	}
	// Entities 1..10 are served as packets the ingest rejects.
	pq_pull_source( $items, true, range( 1, 10 ) );

	$r = Manual_Sync::pull_now( $peer );
	assert_test( 0 === (int) $r['pulled'] && 10 === (int) $r['failed'], 'run 1: the 10 poison entities fail to ingest, got ' . json_encode( $r ) );
	assert_test( 10 === (int) get_option( $key, -1 ), 'the cursor moved past the failing batch instead of wrapping to the start' );

	$r = Manual_Sync::pull_now( $peer );
	assert_test( 10 === (int) $r['pulled'], 'run 2 reaches the healthy entities behind the poison batch, got ' . json_encode( $r ) );
	assert_test( 20 === (int) get_option( $key, -1 ), 'the cursor sits after the last classified entity' );

	$r = Manual_Sync::pull_now( $peer );
	assert_test( 5 === (int) $r['pulled'], 'run 3 pulls the rest, got ' . json_encode( $r ) );
	assert_test( 0 === (int) get_option( $key, -1 ), 'the catalog end was reached: the cursor wrapped' );

	$r = Manual_Sync::pull_now( $peer );
	assert_test( 0 === (int) $r['pulled'] && 10 === (int) $r['failed'], 'the next pass retries the still-unmapped poison entities, got ' . json_encode( $r ) );
}

/**
 * WMC-08: a digest page that cannot be fetched is a FAILED run (not a green
 * no-op) and the stored cursor stays where it was — including when the
 * failure hits page 2 after page 1 was already classified in memory.
 */
function pq_scenario_wmc08_pull_digest_failure_keeps_cursor(): void {
	echo "\n--- WMC-08: a digest transport failure leaves the cursor untouched ---\n";
	global $wpdb, $pq_remote;
	pq_reset();
	$peer = pq_peer( 'peer-d' );
	$key  = 'wpmmcc_pull_cursor_' . md5( 'peer-d' );
	update_option( $key, 40 );

	// (1) The very first digest page dies.
	$pq_remote['handler'] = static function () {
		return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
	};
	$r = Manual_Sync::pull_now( $peer );
	assert_test( 0 === (int) $r['pulled'] && 1 === (int) $r['failed'], 'a dead digest is a failed run, not a clean no-op, got ' . json_encode( $r ) );
	assert_test( 40 === (int) get_option( $key, -1 ), 'the stored cursor stays where it was' );

	// (2) Page 1 (IDs 41..240, all already mapped) is fine, page 2 dies.
	$page = array();
	for ( $i = 41; $i <= 240; $i++ ) {
		$page[] = array(
			'canonical_uuid'     => pq_uuid( $i ),
			'local_id'          => $i,
			'source_fingerprint' => 'fp-' . $i,
			'vector_clock'       => 1,
			'post_status'        => 'publish',
			'post_type'         => 'post',
		);
		$wpdb->insert(
			'wp_wpmmcc_cross_mappings',
			array(
				'canonical_uuid'    => pq_uuid( $i ),
				'local_object_type' => 'post',
				'local_object_id'   => $i,
			)
		);
	}
	$digest_calls         = 0;
	$pq_remote['handler'] = static function ( $route ) use ( &$digest_calls, $page ) {
		if ( '/sync/digest' !== $route ) {
			return 200;
		}
		++$digest_calls;
		if ( 1 === $digest_calls ) {
			return array(
				'status' => 200,
				'body'   => wp_json_encode( array( 'success' => true, 'data' => array( 'items' => $page ) ) ),
			);
		}
		return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
	};
	$r = Manual_Sync::pull_now( $peer );
	assert_test( 2 === $digest_calls, 'the run did reach page 2 (' . $digest_calls . ' digest calls)' );
	assert_test( 1 === (int) $r['failed'] && 0 === (int) $r['pulled'], 'a mid-run digest failure fails the run, got ' . json_encode( $r ) );
	assert_test( 40 === (int) get_option( $key, -1 ), 'page 1 was classified in memory only: the stored cursor did not move' );
}

/**
 * WMC-08: a peer that ignores last_seen_id (always answers with the first
 * page) must not make the sweep spin — the keyset contract is verified by
 * progress, not trusted.
 */
function pq_scenario_wmc08_pull_cursor_ignoring_peer(): void {
	echo "\n--- WMC-08: a peer that ignores the keyset cursor cannot spin the sweep ---\n";
	global $wpdb, $pq_remote;
	pq_reset();
	$peer = pq_peer( 'peer-i' );

	$page = array();
	for ( $i = 1; $i <= Manual_Sync::PULL_DIGEST_PAGE; $i++ ) {
		$page[] = array(
			'canonical_uuid'     => pq_uuid( $i ),
			'local_id'          => $i,
			'source_fingerprint' => 'fp-' . $i,
			'vector_clock'       => 1,
			'post_status'        => 'publish',
			'post_type'         => 'post',
		);
		$wpdb->insert(
			'wp_wpmmcc_cross_mappings',
			array(
				'canonical_uuid'    => pq_uuid( $i ),
				'local_object_type' => 'post',
				'local_object_id'   => $i,
			)
		);
	}
	$pq_remote['handler'] = static function ( $route ) use ( $page ) {
		if ( '/sync/digest' === $route ) {
			return array(
				'status' => 200,
				'body'   => wp_json_encode( array( 'success' => true, 'data' => array( 'items' => $page ) ) ),
			);
		}
		return 200;
	};

	$r     = Manual_Sync::pull_now( $peer );
	$pages = 0;
	foreach ( $pq_remote['calls'] as $call ) {
		if ( '/sync/digest' === $call['route'] ) {
			++$pages;
		}
	}
	assert_test( 2 >= $pages, "the run stops after the first repeated page ({$pages} digest call(s))" );
	assert_test( 0 === (int) $r['pulled'] && 0 === (int) $r['failed'], 'a stuck peer yields a clean no-op, got ' . json_encode( $r ) );
	assert_test( false !== strpos( $r['message'], 'Nothing to pull: 200 digest' ), 'the repeated page is not classified a second time: ' . $r['message'] );
}

/**
 * WMC-08: one pull run classifies a bounded number of digest items
 * (PULL_MAX_SCAN_PER_RUN), so a huge, already fully mapped peer catalog cannot
 * turn a single admin request into an unbounded page walk. The next run
 * continues where this one stopped.
 */
function pq_scenario_wmc08_pull_scan_bound(): void {
	echo "\n--- WMC-08: one pull run scans a bounded number of digest items ---\n";
	global $wpdb, $pq_remote;
	pq_reset();
	$peer  = pq_peer( 'peer-s' );
	$bound = Manual_Sync::PULL_MAX_SCAN_PER_RUN;
	$total = $bound + 250;
	$key   = 'wpmmcc_pull_cursor_' . md5( 'peer-s' );

	$items = array();
	for ( $i = 1; $i <= $total; $i++ ) {
		$items[] = array(
			'canonical_uuid'     => pq_uuid( $i ),
			'local_id'          => $i,
			'source_fingerprint' => 'fp-' . $i,
			'vector_clock'       => 1,
			'post_status'        => 'publish',
			'post_type'         => 'post',
		);
		$wpdb->insert(
			'wp_wpmmcc_cross_mappings',
			array(
				'canonical_uuid'    => pq_uuid( $i ),
				'local_object_type' => 'post',
				'local_object_id'   => $i,
			)
		);
	}
	pq_pull_source( $items );

	$r     = Manual_Sync::pull_now( $peer );
	$pages = 0;
	foreach ( $pq_remote['calls'] as $call ) {
		if ( '/sync/digest' === $call['route'] ) {
			++$pages;
		}
	}
	assert_test( (int) ceil( $bound / Manual_Sync::PULL_DIGEST_PAGE ) === $pages, "run 1 walked only {$pages} digest page(s), not the whole catalog" );
	assert_test( $bound === (int) get_option( $key, 0 ), 'the cursor stops at the last classified item' );
	assert_test( false !== strpos( $r['message'], "Nothing to pull: {$bound} digest" ), 'the run reports the bounded scan: ' . $r['message'] );

	$r = Manual_Sync::pull_now( $peer );
	assert_test( 0 === (int) get_option( $key, -1 ), 'the next run finishes the catalog and wraps' );
	assert_test( false !== strpos( $r['message'], 'Nothing to pull: 250 digest' ), 'and scans only the remainder: ' . $r['message'] );
}

/**
 * WMC-08: when the pull transport dies after a successful digest scan, the
 * cursor must roll back to the start of the failed batch so it is retried
 * without waiting a full sweep pass.
 */
function pq_scenario_wmc08_pull_transport_rollback(): void {
	echo "\n--- WMC-08: pull transport failure does not skip the batch ---\n";
	global $wpdb, $pq_remote;
	pq_reset();
	$peer = pq_peer( 'peer-w' );

	$items = array();
	for ( $i = 1; $i <= 30; $i++ ) {
		$items[] = array(
			'canonical_uuid'     => pq_uuid( $i ),
			'local_id'          => $i,
			'source_fingerprint' => 'fp-' . $i,
			'vector_clock'       => 1,
			'post_status'        => 'publish',
			'post_type'         => 'post',
		);
	}
	// The first 5 are already mapped: the failing batch starts mid-catalog.
	for ( $i = 1; $i <= 5; $i++ ) {
		$wpdb->insert(
			'wp_wpmmcc_cross_mappings',
			array(
				'canonical_uuid'    => pq_uuid( $i ),
				'local_object_type' => 'post',
				'local_object_id'   => $i,
			)
		);
	}
	pq_pull_source( $items, false ); // /sync/pull times out.

	$r = Manual_Sync::pull_now( $peer );
	assert_test( 0 === (int) $r['pulled'] && 10 === (int) $r['failed'], 'the transport failure fails the batch (0 pulled / 10 failed), got ' . json_encode( $r ) );
	assert_test( 5 === (int) get_option( 'wpmmcc_pull_cursor_' . md5( 'peer-w' ), -1 ), 'the cursor rolled back to the last mapped item (5), not past the failed batch' );
	foreach ( array( 6, 15 ) as $id ) {
		assert_test( ! pq_mapped( pq_uuid( $id ) ), "entity {$id} was not ingested by the failed run" );
	}

	// Peer recovers: the batch is retried from the rollback point.
	pq_pull_source( $items, true );
	$r = Manual_Sync::pull_now( $peer );
	assert_test( 10 === (int) $r['pulled'], 'the recovered run pulls the batch it previously failed, got ' . json_encode( $r ) );
	$last_pull = null;
	foreach ( $pq_remote['calls'] as $call ) {
		if ( '/sync/pull' === $call['route'] ) {
			$last_pull = $call;
		}
	}
	$uuids = array_map( 'strval', (array) ( $last_pull['packet']['canonical_uuids'] ?? array() ) );
	assert_test( array( pq_uuid( 6 ), pq_uuid( 7 ), pq_uuid( 8 ), pq_uuid( 9 ), pq_uuid( 10 ), pq_uuid( 11 ), pq_uuid( 12 ), pq_uuid( 13 ), pq_uuid( 14 ), pq_uuid( 15 ) ) === $uuids, 'the retried batch is exactly the entities after the rollback point' );
}

/**
 * WMC-08: the peer refusing a call at the HTTP level (rate limit, maintenance,
 * gateway error) is a failed leg exactly like a dead transport. Reading the
 * refusal's empty body as "nothing to pull" wrapped the sweep to 0 and showed
 * a green no-op; reading a refused /sync/pull as "0 pulled" moved the cursor
 * past a batch that was never delivered (live: the peer rate-limits bursts).
 */
function pq_scenario_wmc08_pull_http_failures(): void {
	echo "\n--- WMC-08: an HTTP refusal of the digest / pull call fails the leg, it is not a no-op ---\n";
	global $wpdb;
	foreach ( array( 429, 503, 500 ) as $status ) {
		// (1) The digest itself is refused.
		pq_reset();
		$peer = pq_peer( 'peer-x' );
		$key  = 'wpmmcc_pull_cursor_' . md5( 'peer-x' );
		update_option( $key, 40 );
		pq_pull_source( array(), true, array(), $status );
		$r = Manual_Sync::pull_now( $peer );
		assert_test( 0 === (int) $r['pulled'] && 1 === (int) $r['failed'], "digest HTTP {$status}: a refused digest is a failed run, not 'nothing to pull', got " . json_encode( $r ) );
		assert_test( false !== strpos( (string) $r['message'], (string) $status ) && false !== strpos( (string) $r['message'], 'wpmmcc_refused' ), "digest HTTP {$status}: the message names the status and the peer's error code: " . $r['message'] );
		assert_test( 40 === (int) get_option( $key, -1 ), "digest HTTP {$status}: the stored cursor stays where it was (no wrap to 0)" );
		$failed_summary = pq_rows_where(
			static function ( $row ) {
				return 'manual_pull_summary' === $row['event_type'] && 'failed' === $row['outcome'];
			}
		);
		assert_test( 1 === count( $failed_summary ), "digest HTTP {$status}: the audit trail records a failed pull summary" );

		// (2) The digest is fine, /sync/pull is refused: roll back to the failed batch.
		pq_reset();
		$peer  = pq_peer( 'peer-x' );
		$items = array();
		for ( $i = 1; $i <= 30; $i++ ) {
			$items[] = array(
				'canonical_uuid'     => pq_uuid( $i ),
				'local_id'          => $i,
				'source_fingerprint' => 'fp-' . $i,
				'vector_clock'       => 1,
				'post_status'        => 'publish',
				'post_type'         => 'post',
			);
		}
		for ( $i = 1; $i <= 5; $i++ ) {
			$wpdb->insert(
				'wp_wpmmcc_cross_mappings',
				array(
					'canonical_uuid'    => pq_uuid( $i ),
					'local_object_type' => 'post',
					'local_object_id'   => $i,
				)
			);
		}
		pq_pull_source( $items, $status );
		$r = Manual_Sync::pull_now( $peer );
		assert_test( 0 === (int) $r['pulled'] && 10 === (int) $r['failed'], "pull HTTP {$status}: the refused batch counts as failed (0 pulled / 10 failed), got " . json_encode( $r ) );
		assert_test( false !== strpos( (string) $r['message'], (string) $status ), "pull HTTP {$status}: the message names the status: " . $r['message'] );
		assert_test( 5 === (int) get_option( 'wpmmcc_pull_cursor_' . md5( 'peer-x' ), -1 ), "pull HTTP {$status}: the cursor rolled back to the batch start, not past the undelivered batch" );
		assert_test( ! pq_mapped( pq_uuid( 6 ) ), "pull HTTP {$status}: nothing was ingested" );
		$failed_summary = pq_rows_where(
			static function ( $row ) {
				return 'manual_pull_summary' === $row['event_type'] && 'failed' === $row['outcome'];
			}
		);
		assert_test( 1 === count( $failed_summary ), "pull HTTP {$status}: the audit trail records a failed pull summary" );

		pq_pull_source( $items, true );
		$r = Manual_Sync::pull_now( $peer );
		assert_test( 10 === (int) $r['pulled'], "pull HTTP {$status}: the recovered run delivers the batch that was refused, got " . json_encode( $r ) );
	}
}

/**
 * WMC-08 [B]: the scheduled reconcile leg compared only the FIRST 100 local
 * entities — drift in a larger catalog was never reconciled. The sweep now
 * submits a per-peer keyset window per run and wraps at the catalog end.
 */
function pq_scenario_wmc08_reconcile_sweep(): void {
	echo "\n--- WMC-08: scheduled reconcile sweeps the whole catalog in windows ---\n";
	pq_reset();
	pq_peer( 'peer-r' );

	// 150 local entities visible to generate_digest.
	for ( $i = 1; $i <= 150; $i++ ) {
		pq_digest_post( $i );
	}

	// Tail verdicts: 141..145 missing on the peer (e.g. pruned there),
	// 146..148 older on the peer, 149 edited on the peer (must be left alone,
	// the arbiter/review flow owns it), 150 in sync.
	$peer = pq_reconcile_peer(
		static function ( int $n ): string {
			if ( $n >= 141 && $n <= 145 ) {
				return 'missing';
			}
			if ( $n >= 146 && $n <= 148 ) {
				return 'source_newer';
			}
			return 149 === $n ? 'local_edited' : 'in_sync';
		}
	);

	Reconciler::run_scheduled_reconcile();
	Reconciler::run_scheduled_reconcile();

	assert_test( 2 === count( $peer->windows ), 'two runs submit two windows, got ' . count( $peer->windows ) );
	assert_test( range( 1, 100 ) === ( $peer->windows[0] ?? null ), 'run 1 submits exactly the first window (entities 1..100)' );
	assert_test( range( 101, 150 ) === ( $peer->windows[1] ?? null ), 'run 2 submits the remainder, including entity 150 beyond the old first-100 window' );
	assert_test( range( 141, 148 ) === $peer->pushed, 'missing + source_newer entities were pushed as drift, in order; local_edited and in_sync were left alone' );
	assert_test( 0 === (int) get_option( 'wpmmcc_reconcile_cursor_' . md5( 'peer-r' ), -1 ), 'the reconcile cursor wrapped to 0 after covering the catalog' );
}

/**
 * The scheduled reconcile scans the post types a pulling peer is offered by the
 * digest route (Reconciler::DEFAULT_POST_TYPES). The two sides used to carry
 * separate lists, and a one-line drift between them left a type that one leg
 * served and the other never verified.
 */
function pq_scenario_wmc08_reconcile_post_types(): void {
	echo "\n--- WMC-08: the reconcile sweep covers posts, pages and products, and only those ---\n";
	pq_reset();
	pq_peer( 'peer-r' );

	pq_digest_post( 1, 'publish', 'post' );
	pq_digest_post( 2, 'publish', 'product' );
	pq_digest_post( 3, 'publish', 'page' );
	pq_digest_post( 4, 'publish', 'course' );
	$peer = pq_reconcile_peer(
		static function ( int $n ): string {
			return 'in_sync';
		}
	);

	Reconciler::run_scheduled_reconcile();

	assert_test( array( array( 1, 2, 3 ) ) === $peer->windows, 'the window holds the post, the product and the page, not the course, got ' . json_encode( $peer->windows ) );
	assert_test( array( 'post', 'page', 'product' ) === Reconciler::DEFAULT_POST_TYPES, 'the default list is post, page, product' );
}

/**
 * WMC-08 [B]: the sweep cursor moves only past entities that were actually
 * reconciled. A window the peer never answered (outage, HTTP 5xx) is
 * re-submitted by the next run, not skipped until the next full pass.
 */
function pq_scenario_wmc08_reconcile_outage_holds_window(): void {
	echo "\n--- WMC-08: a window the peer never answered is retried, not skipped ---\n";
	$failures = array(
		'transport error' => new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ),
		'HTTP 503'        => 503,
	);
	foreach ( $failures as $label => $reply ) {
		pq_reset();
		pq_peer( 'peer-o' );
		for ( $i = 1; $i <= 150; $i++ ) {
			pq_digest_post( $i );
		}
		$peer = pq_reconcile_peer(
			static function ( int $n ): string {
				return 'in_sync';
			}
		);
		$key  = 'wpmmcc_reconcile_cursor_' . md5( 'peer-o' );

		$peer->digest_reply = $reply;
		$summary            = Reconciler::run_scheduled_reconcile();
		assert_test( 0 === (int) get_option( $key, 0 ), "{$label}: the cursor did not move past the unanswered window" );
		assert_test( ! empty( $summary['errors'] ), "{$label}: the failure is reported in the run summary" );

		$peer->digest_reply = null;
		Reconciler::run_scheduled_reconcile();
		assert_test( range( 1, 100 ) === ( $peer->windows[1] ?? null ), "{$label}: the retry re-submits the same first window" );

		Reconciler::run_scheduled_reconcile();
		assert_test( range( 101, 150 ) === ( $peer->windows[2] ?? null ), "{$label}: the sweep then continues with the remainder" );
		assert_test( 0 === (int) get_option( $key, -1 ), "{$label}: and wraps once the catalog is covered" );
	}
}

/**
 * WMC-08 [B]: drift beyond the per-run push cap continues in the next run
 * instead of being skipped together with the rest of the window (a fresh peer
 * lacks EVERY entity — only 20 of the first 100 used to get pushed per pass).
 */
function pq_scenario_wmc08_reconcile_cap_continuation(): void {
	echo "\n--- WMC-08: drift beyond the per-run cap continues in the next run ---\n";
	pq_reset();
	pq_peer( 'peer-c' );
	$total = 130;
	for ( $i = 1; $i <= $total; $i++ ) {
		pq_digest_post( $i );
	}
	$peer = pq_reconcile_peer(
		static function ( int $n ): string {
			return 'missing';
		}
	);
	$key  = 'wpmmcc_reconcile_cursor_' . md5( 'peer-c' );
	$cap  = Reconciler::MAX_DRIFT_PUSHES_PER_PEER;

	Reconciler::run_scheduled_reconcile();
	assert_test( range( 1, $cap ) === $peer->pushed, "run 1 pushes the first {$cap} drifted entities, in order" );
	assert_test( $cap === (int) get_option( $key, 0 ), 'the cursor stops at the last entity that was actually reconciled' );

	$runs = 1;
	while ( $runs < 12 && count( $peer->pushed ) < $total ) {
		Reconciler::run_scheduled_reconcile();
		++$runs;
	}
	assert_test( range( 1, $total ) === $peer->pushed, "every entity of a peer-lacks-everything catalog was pushed exactly once, in order (runs: {$runs})" );
	assert_test( (int) ceil( $total / $cap ) === $runs, 'the catalog took ceil(total/cap) runs, no wasted passes' );
	assert_test( 0 === (int) get_option( $key, -1 ), 'the cursor wrapped after the last drifted entity was pushed' );
}

/**
 * WMC-08 [B]: failure classes during the drift push. An entity the peer
 * rejects is reported and skipped (it must not stall the sweep — head-of-line
 * blocking, cf. WMC-01); a dead transport stops the walk and the cursor holds
 * right before the first entity the peer could not take.
 */
function pq_scenario_wmc08_reconcile_failure_classes(): void {
	echo "\n--- WMC-08: entity rejections skip, a dead transport holds the sweep ---\n";
	$drifted = static function ( int $n ): string {
		return ( $n >= 5 && $n <= 10 ) ? 'missing' : 'in_sync';
	};

	// (1) Entity 5 is rejected (HTTP 422): report it, push around it.
	pq_reset();
	pq_peer( 'peer-f' );
	for ( $i = 1; $i <= 30; $i++ ) {
		pq_digest_post( $i );
	}
	$peer                = pq_reconcile_peer( $drifted );
	$key                 = 'wpmmcc_reconcile_cursor_' . md5( 'peer-f' );
	$peer->push_reply[5] = 422;
	$summary             = Reconciler::run_scheduled_reconcile();
	assert_test( array( 6, 7, 8, 9, 10 ) === $peer->pushed, 'the rest of the drift is pushed around the rejected entity' );
	assert_test( 0 === (int) get_option( $key, -1 ), 'an entity-level rejection does not hold the sweep (no head-of-line stall)' );
	$reported = array_filter(
		(array) $summary['errors'],
		static function ( $e ) {
			return false !== strpos( (string) $e, 'HTTP 422' );
		}
	);
	assert_test( ! empty( $reported ), 'the rejection is reported in the run summary' );

	// (2) The peer dies mid-drift: stop, hold before the first unpushed entity.
	pq_reset();
	pq_peer( 'peer-f' );
	for ( $i = 1; $i <= 30; $i++ ) {
		pq_digest_post( $i );
	}
	$peer                = pq_reconcile_peer( $drifted );
	$key                 = 'wpmmcc_reconcile_cursor_' . md5( 'peer-f' );
	$peer->push_reply[8] = new WP_Error( 'http_request_failed', 'cURL error 7: connection refused' );
	Reconciler::run_scheduled_reconcile();
	assert_test( array( 5, 6, 7 ) === $peer->pushed, 'pushing stops at the transport failure' );
	assert_test( 7 === (int) get_option( $key, -1 ), 'the cursor holds right before the entity the peer could not take' );

	unset( $peer->push_reply[8] );
	Reconciler::run_scheduled_reconcile();
	assert_test( range( 8, 30 ) === ( $peer->windows[1] ?? null ), 'after recovery the sweep resumes at the held entity' );
	assert_test( array( 5, 6, 7, 8, 9, 10 ) === $peer->pushed, 'the held drift is delivered once the peer is back, nothing twice' );
	assert_test( 0 === (int) get_option( $key, -1 ), 'and the sweep wraps after the catalog is covered' );

	// (3) A plain 500 is ambiguous, but this very run's reconcile-digest
	// already proved the peer alive: it settles like a rejection (reported,
	// retried on the next pass) instead of freezing the sweep on one entity.
	pq_reset();
	pq_peer( 'peer-f' );
	for ( $i = 1; $i <= 30; $i++ ) {
		pq_digest_post( $i );
	}
	$peer                = pq_reconcile_peer( $drifted );
	$key                 = 'wpmmcc_reconcile_cursor_' . md5( 'peer-f' );
	$peer->push_reply[5] = 500;
	$summary             = Reconciler::run_scheduled_reconcile();
	assert_test( array( 6, 7, 8, 9, 10 ) === $peer->pushed, 'a 500 on one entity does not stop the drift behind it' );
	assert_test( 0 === (int) get_option( $key, -1 ), 'a 500 on one entity does not freeze the sweep either' );
	$reported = array_filter(
		(array) $summary['errors'],
		static function ( $e ) {
			return false !== strpos( (string) $e, 'HTTP 500' );
		}
	);
	assert_test( ! empty( $reported ), 'the 500 is reported in the run summary' );
}

/**
 * WMC-08 [B]: an answer that says "the peer path is unavailable" — rate limit,
 * timeout, bad gateway, maintenance, auth or path trouble, a peer-wide error
 * code — is an outage, exactly like a dead transport: it says nothing about
 * the entity. The walk must stop and hold the cursor in front of it; it used
 * to treat every non-2xx as a per-entity rejection and march on, so a peer
 * that rate-limited a push burst made the sweep skip every entity it refused
 * (live: HTTP 429 advanced the cursor past 9 undelivered entities).
 */
function pq_scenario_wmc08_reconcile_push_outage(): void {
	echo "\n--- WMC-08: a drift push answered 429 / gateway / auth trouble holds the sweep ---\n";
	$drifted = static function ( int $n ): string {
		return ( $n >= 5 && $n <= 10 ) ? 'missing' : 'in_sync';
	};
	$outages = array(
		'HTTP 429'       => 429,
		'HTTP 408'       => 408,
		'HTTP 502'       => 502,
		'HTTP 503'       => 503,
		'HTTP 504'       => 504,
		'HTTP 401'       => 401,
		'HTTP 403'       => 403,
		'HTTP 404'       => 404,
		'HTTP 302'       => 302,
		'peer-wide code' => array(
			'status' => 400,
			'body'   => wp_json_encode( array( 'code' => 'wpmmcc_clock_drift', 'message' => 'Clock drift' ) ),
		),
	);
	foreach ( $outages as $label => $reply ) {
		pq_reset();
		pq_peer( 'peer-w' );
		for ( $i = 1; $i <= 30; $i++ ) {
			pq_digest_post( $i );
		}
		$peer                = pq_reconcile_peer( $drifted );
		$key                 = 'wpmmcc_reconcile_cursor_' . md5( 'peer-w' );
		$peer->push_reply[8] = $reply;

		$summary = Reconciler::run_scheduled_reconcile();
		assert_test( array( 5, 6, 7 ) === $peer->pushed, "{$label}: pushing stops at the answer that says the peer is unavailable" );
		assert_test( 7 === (int) get_option( $key, -1 ), "{$label}: the cursor holds right before the entity the peer could not take" );
		$reported = array_filter(
			(array) $summary['errors'],
			static function ( $e ) {
				return false !== strpos( (string) $e, 'drift push HTTP' );
			}
		);
		assert_test( ! empty( $reported ), "{$label}: the refusal is reported in the run summary" );

		unset( $peer->push_reply[8] );
		Reconciler::run_scheduled_reconcile();
		assert_test( range( 8, 30 ) === ( $peer->windows[1] ?? null ), "{$label}: after recovery the sweep resumes at the held entity" );
		assert_test( array( 5, 6, 7, 8, 9, 10 ) === $peer->pushed, "{$label}: the held drift is delivered once the peer is back, nothing twice" );
		assert_test( 0 === (int) get_option( $key, -1 ), "{$label}: and the sweep wraps after the catalog is covered" );
	}

	// An outage at the FIRST drifted entity leaves the whole window for the retry.
	pq_reset();
	pq_peer( 'peer-w' );
	for ( $i = 1; $i <= 30; $i++ ) {
		pq_digest_post( $i );
	}
	$peer                = pq_reconcile_peer( $drifted );
	$key                 = 'wpmmcc_reconcile_cursor_' . md5( 'peer-w' );
	$peer->push_reply[5] = 429;
	Reconciler::run_scheduled_reconcile();
	assert_test( array() === $peer->pushed, 'a rate limit on the first drifted entity delivers nothing' );
	assert_test( 4 === (int) get_option( $key, -1 ), 'and the cursor stops right before it, not at the window end' );
}

/**
 * WMC-08 [B]: the per-run push cap bounds REQUESTS, not just accepted pushes.
 * A peer that rejects every entity (422) used to cost one request per drifted
 * entity of the whole 100-entity window on every run; it is now the cap, and
 * the rejected entities are still stepped over so the sweep keeps moving.
 */
function pq_scenario_wmc08_reconcile_attempt_cap(): void {
	echo "\n--- WMC-08: a peer that rejects everything costs at most the cap per run ---\n";
	pq_reset();
	pq_peer( 'peer-a' );
	$total = 100;
	for ( $i = 1; $i <= $total; $i++ ) {
		pq_digest_post( $i );
	}
	$peer = pq_reconcile_peer(
		static function ( int $n ): string {
			return 'missing';
		}
	);
	for ( $i = 1; $i <= $total; $i++ ) {
		$peer->push_reply[ $i ] = 422;
	}
	$key = 'wpmmcc_reconcile_cursor_' . md5( 'peer-a' );
	$cap = Reconciler::MAX_DRIFT_PUSHES_PER_PEER;

	$requests = static function (): int {
		global $pq_remote;
		$n = 0;
		foreach ( $pq_remote['calls'] as $call ) {
			if ( '/sync/push' === $call['route'] ) {
				++$n;
			}
		}
		return $n;
	};

	Reconciler::run_scheduled_reconcile();
	assert_test( $cap === $requests(), "run 1 sends at most {$cap} push requests even though none was accepted, got " . $requests() );
	assert_test( $cap === (int) get_option( $key, -1 ), 'the rejected entities are stepped over: the cursor sits after the last one handled' );

	Reconciler::run_scheduled_reconcile();
	assert_test( 2 * $cap === $requests(), 'run 2 handles the next slice of the window' );
	assert_test( 2 * $cap === (int) get_option( $key, -1 ), 'and the sweep keeps moving' );
}

/**
 * WMC-08 [B]: one poison entity (an adapter that throws on export) must not
 * abort the scheduled run. The exception used to escape the drift push, so
 * the cursor was never persisted (the same window — the same poison entity —
 * came back every hour) and no later peer or leg was ever reached.
 */
function pq_scenario_wmc08_reconcile_export_exception(): void {
	echo "\n--- WMC-08: an adapter that throws on a drifted entity must not abort the run ---\n";
	pq_reset();
	$unregister = pq_adapter_first( new PQ_Poison_Page_Adapter() );
	try {
		pq_peer( 'peer-e' );
		pq_peer( 'peer-e2' ); // A second peer: the run must still reach it.
		for ( $i = 1; $i <= 10; $i++ ) {
			pq_digest_post( $i, 'publish', 6 === $i ? 'page' : 'post' );
		}
		$peer = pq_reconcile_peer(
			static function ( int $n ): string {
				return 'missing';
			}
		);
		$key = 'wpmmcc_reconcile_cursor_' . md5( 'peer-e' );

		$summary = null;
		try {
			$summary = Reconciler::run_scheduled_reconcile();
		} catch ( \Throwable $e ) {
			assert_test( false, 'run_scheduled_reconcile must not propagate an adapter exception, got: ' . $e->getMessage() );
		}
		assert_test( 2 === count( $peer->windows ), 'both peers were reconciled: the poison entity did not end the run' );
		assert_test( array( 1, 2, 3, 4, 5, 7, 8, 9, 10 ) === array_values( array_unique( $peer->pushed ) ), 'every healthy drifted entity was pushed around the poison one, got ' . json_encode( $peer->pushed ) );
		assert_test( 0 === (int) get_option( $key, -1 ), 'the sweep stepped over the poison entity and wrapped (the cursor is not stuck on it)' );
		$reported = array_filter(
			(array) $summary['errors'],
			static function ( $e ) {
				return false !== strpos( (string) $e, 'export failed before delivery intent' );
			}
		);
		assert_test( ! empty( $reported ), 'the failure is reported in the run summary' );
	} finally {
		$unregister();
	}
}

/**
 * WMC-08 hygiene: the per-peer cursor options are read only by their own
 * sweep (never autoloaded on every request), are dropped with a disconnected
 * peer, and are removed by uninstall (their names embed a peer hash, so no
 * static option list can name them).
 */
function pq_scenario_wmc08_cursor_hygiene(): void {
	echo "\n--- WMC-08: sweep cursors are not autoloaded, forgotten on disconnect, removed on uninstall ---\n";
	global $wp_mock_options, $wp_mock_option_autoload, $wpdb;
	pq_reset();
	$peer     = pq_peer( 'peer-h' );
	$pull_key = Manual_Sync::pull_cursor_option( 'peer-h' );
	$rec_key  = Reconciler::reconcile_cursor_option( 'peer-h' );
	assert_test( 'wpmmcc_pull_cursor_' . md5( 'peer-h' ) === $pull_key && 'wpmmcc_reconcile_cursor_' . md5( 'peer-h' ) === $rec_key, 'the option names are the documented wpmmcc_*_cursor_<md5(peer)> keys' );

	$items = array();
	for ( $i = 1; $i <= 6; $i++ ) {
		$items[] = array(
			'canonical_uuid'     => pq_uuid( $i ),
			'local_id'          => $i,
			'source_fingerprint' => 'fp-' . $i,
			'vector_clock'       => 1,
			'post_status'        => 'publish',
			'post_type'         => 'post',
		);
	}

	// (a) Never autoloaded: every write path of both sweeps passes false.
	$wp_mock_option_autoload[ $pull_key ] = 'unset';
	pq_pull_source( array_slice( $items, 0, 3 ) );
	Manual_Sync::pull_now( $peer ); // Tail write (3 pulled).
	assert_test( false === $wp_mock_option_autoload[ $pull_key ], 'pull cursor written after an ingest is not autoloaded' );

	$wp_mock_option_autoload[ $pull_key ] = 'unset';
	Manual_Sync::pull_now( $peer ); // Nothing left: empty-branch write.
	assert_test( false === $wp_mock_option_autoload[ $pull_key ], 'pull cursor written by a nothing-to-pull run is not autoloaded' );

	$wp_mock_option_autoload[ $pull_key ] = 'unset';
	pq_pull_source( $items, false );
	Manual_Sync::pull_now( $peer ); // Entities 4..6 missing, /sync/pull dies: rollback write.
	assert_test( false === $wp_mock_option_autoload[ $pull_key ], 'pull cursor rolled back after a failed pull is not autoloaded' );

	for ( $i = 1; $i <= 3; $i++ ) {
		pq_digest_post( $i );
	}
	pq_reconcile_peer(
		static function ( int $n ): string {
			return 'in_sync';
		}
	);
	$wp_mock_option_autoload[ $rec_key ] = 'unset';
	Reconciler::run_scheduled_reconcile();
	assert_test( false === $wp_mock_option_autoload[ $rec_key ], 'reconcile cursor is not autoloaded' );

	// (b) A disconnected peer takes only its own cursors with it.
	update_option( $pull_key, 7, false );
	update_option( $rec_key, 8, false );
	update_option( Manual_Sync::pull_cursor_option( 'peer-k' ), 9, false );
	update_option( Reconciler::reconcile_cursor_option( 'peer-k' ), 10, false );
	Reconciler::forget_peer_cursors( 'peer-h' );
	assert_test( ! array_key_exists( $pull_key, $wp_mock_options ) && ! array_key_exists( $rec_key, $wp_mock_options ), 'forget_peer_cursors removes both cursors of the disconnected peer' );
	assert_test( 9 === (int) get_option( Manual_Sync::pull_cursor_option( 'peer-k' ), -1 ) && 10 === (int) get_option( Reconciler::reconcile_cursor_option( 'peer-k' ), -1 ), "another peer's cursors are untouched" );

	// (c) Uninstall sweeps the dynamic cursor options (and only those).
	update_option( 'wpmmcc_pull_cursorXdecoy', 'keep', false ); // '_' is a LIKE wildcard: the patterns must be esc_like'd.
	update_option( 'wpmmcc_reconcile_cursorXdecoy', 'keep', false );
	update_option( 'wpmmcc_version', '1.0.0' );
	update_option( 'blogname', 'Unrelated' );
	$wpdb = new PQ_Uninstall_wpdb();
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', true );
	}
	( static function () {
		include wpmmcc_test_plugin_path( 'uninstall.php' );
	} )();
	assert_test( ! array_key_exists( Manual_Sync::pull_cursor_option( 'peer-k' ), $wp_mock_options ) && ! array_key_exists( Reconciler::reconcile_cursor_option( 'peer-k' ), $wp_mock_options ), 'uninstall removes the per-peer cursor options' );
	assert_test( ! array_key_exists( 'wpmmcc_version', $wp_mock_options ), 'uninstall still removes the statically listed options' );
	assert_test( 'keep' === get_option( 'wpmmcc_pull_cursorXdecoy', null ) && 'keep' === get_option( 'wpmmcc_reconcile_cursorXdecoy', null ) && 'Unrelated' === get_option( 'blogname', null ), 'uninstall leaves look-alike and unrelated options alone' );
}

// ---------------------------------------------------------------------------
// Runner
// ---------------------------------------------------------------------------

$scenarios = array(
	'wmc01_poison_head',
	'wmc01_peer_rejects',
	'wmc01_outage_not_charged',
	'wmc01_tombstones',
	'wmc01_dedupe',
	'wmc06_per_peer',
	'wmc06_no_peers',
	'wmc06_retention',
	'wmc06_retention_filters',
	'wmc06_untracked_deletes',
	'wmc09_requeue',
	'wmc01_export_exception',
	'wmc01_server_error_attribution',
	'wmc01_dedupe_window',
	'wmc01_retry_share',
	'wmc01_unshippable_rows',
	'wmc06_legacy_rows',
	'wmc06_debounce',
	'wmc06_scheduled_prune',
	'wmc08_pull_beyond_window',
	'wmc08_pull_continuation',
	'wmc08_pull_poison_no_starvation',
	'wmc08_pull_digest_failure_keeps_cursor',
	'wmc08_pull_cursor_ignoring_peer',
	'wmc08_pull_scan_bound',
	'wmc08_pull_transport_rollback',
	'wmc08_pull_http_failures',
	'wmc08_reconcile_sweep',
	'wmc08_reconcile_post_types',
	'wmc08_reconcile_outage_holds_window',
	'wmc08_reconcile_cap_continuation',
	'wmc08_reconcile_failure_classes',
	'wmc08_reconcile_push_outage',
	'wmc08_reconcile_attempt_cap',
	'wmc08_reconcile_export_exception',
	'wmc08_cursor_hygiene',
);

$only = null;
foreach ( array_slice( $argv ?? array(), 1 ) as $arg ) {
	if ( 0 === strpos( $arg, '--only=' ) ) {
		$only = substr( $arg, 7 );
	}
}
foreach ( $scenarios as $name ) {
	if ( null !== $only && $only !== $name ) {
		continue;
	}
	call_user_func( 'pq_scenario_' . $name );
}

echo "\n==================================================\n";
echo "PASS: All {$pass_count} Push Queue tests passed successfully!\n";
echo "==================================================\n";
