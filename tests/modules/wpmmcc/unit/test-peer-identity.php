<?php
/**
 * WPMMCC inbound peer identity: who delivered a packet, who it came from
 * (WMC-04, WMC-10, WMC-14 and the per-peer mapping row).
 *
 * A packet carries two identities. The HMAC envelope names the peer that
 * DELIVERED it (X-WPMMCC-Site-UUID, the only identity the gate verifies). The
 * body names the site it ORIGINATED on (origin_context.origin_site_uuid). They
 * are the same thing on a direct site-to-site push and different on a client
 * relay (path A): the standalone client is paired as its own peer and forwards
 * packets pulled from a source site with the origin context preserved and
 * hop_count advanced. The ingest used to read only the body, so:
 *
 *   WMC-04  a peer could file its writes under ANOTHER peer's uuid: that
 *           peer's conflict strategy, vector clock and mapping row.
 *   WMC-14  the conflict strategy chosen at pairing lives on the row of the
 *           peer that did the handshake (the client), but the ingest looked it
 *           up by the body origin, so every relayed packet arbitrated as 'lww'.
 *   WMC-10  hop_count was written and never read: nothing bounded a relay loop.
 *   --      the mapping row was fetched by uuid with LIMIT 1 while the unique
 *           key is (uuid, peer): with two peers mapped the row (and so the
 *           drift verdict) depended on storage order.
 *
 * The rule the tests pin: a FIRST-HAND packet (hop_count <= 1) must be
 * attributed to the peer that delivered it; a RELAYED packet (hop_count >= 2)
 * keeps its logical origin and is arbitrated by the delivering peer's policy.
 * A request without an envelope is an internal call (the pull leg), never an
 * external one: the route's permission gate guarantees the header.
 *
 * Scenarios (run one with:  php test-peer-identity.php --only=<name>):
 *   first_hand_is_bound_to_sender      a first-hand packet under another peer's
 *                                      uuid is refused 403, leaves the victim's
 *                                      row, the local post and the peer table
 *                                      alone, and is audited under the sender.
 *   binding_follows_the_legacy_header  the legacy X-WPMMCC-Origin-UUID alias is
 *                                      honoured the same way.
 *   relay_keeps_its_origin             a relayed packet (hop >= 2) under the
 *                                      relay's envelope is accepted and mapped
 *                                      under the logical origin.
 *   internal_call_is_not_an_attack     no envelope: nothing to bind against.
 *   strategy_follows_the_deliverer     the delivering peer's strategy arbitrates
 *                                      (skip / shadow review), a relayed
 *                                      conflict becomes reviewable, and a
 *                                      first-hand peer cannot borrow another
 *                                      peer's strategy.
 *   hop_limit                          hop_count above the limit is refused for
 *                                      every action before anything changes; the
 *                                      limit is filterable and never below 1.
 *   mapping_row_is_per_peer            the drift verdict uses the pushing peer's
 *                                      own mapping row, both ways round, and an
 *                                      unmapped peer gets a deterministic row.
 *   non_post_entities_are_refused      (WMC-05) term / user / attachment packets
 *                                      are refused 501 and audited, writing
 *                                      nothing; page / product / post go through
 *                                      as their own post type.
 *   mapping_holds_no_local_url         (WMC-15) the mapping row of a pushed post
 *                                      never carries this site's own permalink
 *                                      as the peer's; the ack still names it.
 *
 * oracle: L1
 *
 * @package WPMMCC\Tests\Unit
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/support/class-mini-sql-wpdb.php';

use WPMMCC\REST\Rest_Push;
use WPMMCC\Sync\Packet;

echo "=== Running Peer Identity Test (WMC-04 / WMC-10 / WMC-14 / WMC-15) ===\n";

$pass_count = 0;

function assert_test( $condition, $message ) {
	global $pass_count;
	if ( ! $condition ) {
		echo "FAIL: {$message}\n";
		exit( 1 );
	}
	$pass_count++;
	echo "  [PASS] {$message}\n";
}

const PI_UUID   = 'urn:uuid:entity-i';
const PI_CLIENT = 'urn:uuid:peer-client'; // The relay: a standalone client paired as its own peer.
const PI_SOURCE = 'urn:uuid:site-source'; // The site the relayed content came from (not a peer here).
const PI_A      = 'urn:uuid:peer-a';
const PI_B      = 'urn:uuid:peer-b';
const PI_LOCAL  = 3001;

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

function pi_posts_view(): array {
	global $wp_mock_posts;
	$rows = array();
	foreach ( $wp_mock_posts as $id => $post ) {
		$rows[] = array(
			'ID'                => (int) $id,
			'post_title'        => (string) ( $post->post_title ?? '' ),
			'post_content'      => (string) ( $post->post_content ?? '' ),
			'post_excerpt'      => (string) ( $post->post_excerpt ?? '' ),
			'post_date_gmt'     => (string) ( $post->post_date_gmt ?? '' ),
			'comment_status'    => (string) ( $post->comment_status ?? 'open' ),
			'ping_status'       => (string) ( $post->ping_status ?? 'open' ),
			'menu_order'        => (int) ( $post->menu_order ?? 0 ),
			'post_status'       => (string) ( $post->post_status ?? 'publish' ),
			'post_modified_gmt' => (string) ( $post->post_modified_gmt ?? '' ),
			'post_type'         => (string) ( $post->post_type ?? 'post' ),
		);
	}
	return $rows;
}

function pi_postmeta_view(): array {
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

function pi_reset(): void {
	global $wpdb, $wp_mock_posts, $wp_mock_meta, $wp_mock_options, $wp_mock_cache, $wp_mock_posts_strict, $wp_mock_filter_overrides;

	$wpdb = new Mini_SQL_wpdb();
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
	$wpdb->create_view( 'wp_posts', 'pi_posts_view' );
	$wpdb->create_view( 'wp_postmeta', 'pi_postmeta_view' );

	$wp_mock_posts            = array();
	$wp_mock_meta             = array();
	$wp_mock_cache            = array();
	$wp_mock_posts_strict     = true;
	$wp_mock_filter_overrides = array();
	$wp_mock_options          = array( 'wpmmcc_origin_uuid' => 'urn:uuid:site-self' );
}

function pi_peer( string $uuid, string $strategy = 'lww' ): void {
	global $wpdb;
	$wpdb->insert(
		'wp_wpmmcc_peers',
		array(
			'peer_uuid'         => $uuid,
			'peer_name'         => 'Peer ' . $uuid,
			'direction'         => 'bidirectional',
			'status'            => 'active',
			'conflict_strategy' => $strategy,
		)
	);
	wp_cache_delete( 'peer_strat_' . md5( $uuid ), 'wpmmcc' );
}

function pi_local_post( int $id, string $title, string $uuid, int $modified_ago = 600 ): void {
	global $wp_mock_posts;
	$wp_mock_posts[ $id ] = new WP_Post(
		array(
			'ID'                => $id,
			'post_title'        => $title,
			'post_content'      => 'Body of ' . $title,
			'post_status'       => 'publish',
			'post_type'         => 'post',
			'post_name'         => 'post-' . $id,
			'post_date_gmt'     => gmdate( 'Y-m-d H:i:s', time() - 86400 ),
			'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - $modified_ago ),
		)
	);
	update_post_meta( $id, '_wpmmcc_canonical_uuid', $uuid );
}

function pi_map( string $uuid, string $peer, int $local_id, int $clock = 3, int $synced_ago = 3600 ): void {
	global $wpdb;
	$wpdb->insert(
		'wp_wpmmcc_cross_mappings',
		array(
			'canonical_uuid'      => $uuid,
			'local_object_type'   => 'post',
			'local_object_id'     => $local_id,
			'peer_uuid'           => $peer,
			'remote_object_id'    => 500,
			'content_checksum'    => 'cs-old',
			'vector_clock'        => $clock,
			'last_sync_direction' => 'inbound',
			'last_synced_at'      => gmdate( 'Y-m-d H:i:s', time() - $synced_ago ),
		)
	);
}

function pi_mapping( string $uuid, string $peer ): ?array {
	global $wpdb;
	foreach ( $wpdb->rows( 'wp_wpmmcc_cross_mappings' ) as $row ) {
		if ( $uuid === $row['canonical_uuid'] && $peer === $row['peer_uuid'] ) {
			return $row;
		}
	}
	return null;
}

function pi_journal( string $event_type ): array {
	global $wpdb;
	return array_values(
		array_filter(
			$wpdb->rows( 'wp_wpmmcc_journal' ),
			static function ( $r ) use ( $event_type ) {
				return $event_type === $r['event_type'];
			}
		)
	);
}

/** A packet as a site would export it (built under that site's uuid). */
function pi_packet( string $from_uuid, string $title, int $hop = 1, array $over = array(), int $clock = 4 ): array {
	global $wp_mock_options;
	$self                                  = $wp_mock_options['wpmmcc_origin_uuid'];
	$wp_mock_options['wpmmcc_origin_uuid'] = $from_uuid;
	$packet                                = Packet::build(
		array_merge(
			array(
				'guid'         => PI_UUID,
				'object_type'  => 'post',
				'subtype'      => 'post',
				'source_id'    => 500,
				'slug'         => 'remote-post',
				'status'       => 'publish',
				'vector_clock' => $clock,
				'hop_count'    => $hop,
				'core_fields'  => array(
					'post_title'   => $title,
					'post_content' => 'Remote body of ' . $title,
				),
				'action'       => 'upsert',
			),
			$over
		)
	);
	$wp_mock_options['wpmmcc_origin_uuid'] = $self;
	return $packet;
}

/**
 * Deliver a packet through the real ingest.
 *
 * @param string|null $envelope_peer Peer the HMAC envelope is signed under (null = no envelope).
 * @param string      $header        Header that carries it.
 * @return array{ok:bool,code:string,status:int,data:array}
 */
function pi_push( array $packet, ?string $envelope_peer = null, string $header = 'x-wpmmcc-site-uuid' ): array {
	$request = new WP_REST_Request( 'POST', '/wpmmcc/v1/sync/push' );
	$request->set_body( (string) wp_json_encode( $packet ) );
	if ( null !== $envelope_peer ) {
		$request->set_header( $header, $envelope_peer );
	}
	$response = ( new Rest_Push() )->handle_push( $request );
	if ( is_wp_error( $response ) ) {
		$data = (array) $response->get_error_data();
		return array(
			'ok'     => false,
			'code'   => $response->get_error_code(),
			'status' => (int) ( $data['status'] ?? 0 ),
			'data'   => array(),
		);
	}
	$body = $response->get_data();
	return array(
		'ok'     => true,
		'code'   => '',
		'status' => (int) $response->get_status(),
		'data'   => (array) ( $body['data'] ?? $body ),
	);
}

function pi_titles(): array {
	global $wp_mock_posts;
	return array_values(
		array_map(
			static function ( $p ) {
				return (string) $p->post_title;
			},
			$wp_mock_posts
		)
	);
}

// ---------------------------------------------------------------------------
// Scenarios
// ---------------------------------------------------------------------------

function pi_scenario_first_hand_is_bound_to_sender(): void {
	echo "\n--- first_hand_is_bound_to_sender: a peer cannot write under another peer's uuid ---\n";
	pi_reset();
	pi_peer( PI_A, 'lww' );
	pi_peer( PI_B, 'source_wins' );
	pi_local_post( PI_LOCAL, 'Local of B', PI_UUID, 600 );
	pi_map( PI_UUID, PI_B, PI_LOCAL, 10, 3600 );
	$victim_before = pi_mapping( PI_UUID, PI_B );
	$peers_before  = count( $GLOBALS['wpdb']->rows( 'wp_wpmmcc_peers' ) );

	// Baseline: the honest first-hand push (envelope = origin) goes through.
	$ok = pi_push( pi_packet( PI_A, 'Honest from A', 1, array( 'guid' => 'urn:uuid:entity-own' ) ), PI_A );
	assert_test( $ok['ok'] && 'success' === ( $ok['data']['status'] ?? '' ), 'a first-hand packet whose origin is the authenticated peer is accepted' );
	assert_test( null !== pi_mapping( 'urn:uuid:entity-own', PI_A ), 'and it is mapped under that peer' );

	// Peer A authenticates as A but files its write under B.
	$res = pi_push( pi_packet( PI_B, 'Forged as B', 1 ), PI_A );
	assert_test( ! $res['ok'] && 'wpmmcc_origin_peer_mismatch' === $res['code'], 'a first-hand packet under another peer\'s uuid is refused with wpmmcc_origin_peer_mismatch (got ' . ( $res['code'] ?: 'accepted' ) . ')' );
	assert_test( 403 === $res['status'], 'the refusal is a 403' );

	assert_test( 'Local of B' === get_post( PI_LOCAL )->post_title, 'the local post is untouched (B\'s source_wins strategy was not borrowed)' );
	assert_test( ! in_array( 'Forged as B', pi_titles(), true ), 'nothing was created from the forged packet' );
	$victim_after = pi_mapping( PI_UUID, PI_B );
	assert_test( $victim_before === $victim_after, 'the victim\'s mapping row (clock, checksum, sync time) is byte-identical' );
	assert_test( count( $GLOBALS['wpdb']->rows( 'wp_wpmmcc_peers' ) ) === $peers_before, 'the peer table is untouched' );

	$rows = pi_journal( 'origin_peer_mismatch' );
	assert_test( 1 === count( $rows ), 'the refusal is audited once' );
	assert_test( PI_A === $rows[0]['peer_uuid'] && 'inbound' === $rows[0]['direction'] && 'error' === $rows[0]['outcome'] && 1 === (int) $rows[0]['consumed'], 'the audit row is an inbound error filed under the peer that actually sent it' );
	assert_test( false !== strpos( (string) $rows[0]['message'], PI_B ) && false !== strpos( (string) $rows[0]['message'], PI_A ), 'the audit message names both uuids' );
	assert_test( PI_UUID === $rows[0]['canonical_uuid'], 'the audit row carries the entity uuid' );

	// Missing hop authority is invalid, not an implied first-hand packet.
	$bare = pi_packet( PI_B, 'Forged, no hop', 1 );
	unset( $bare['origin_context']['hop_count'] );
	$res = pi_push( $bare, PI_A );
	assert_test( ! $res['ok'] && 'wpmmcc_invalid_packet_scope' === $res['code'], 'a packet that omits hop_count is refused before origin binding' );
}

function pi_scenario_binding_follows_the_legacy_header(): void {
	echo "\n--- binding_follows_the_legacy_header: X-WPMMCC-Origin-UUID names the peer too ---\n";
	pi_reset();
	pi_peer( PI_A );
	pi_peer( PI_B );

	$res = pi_push( pi_packet( PI_B, 'Forged as B', 1 ), PI_A, 'x-wpmmcc-origin-uuid' );
	assert_test( ! $res['ok'] && 'wpmmcc_origin_peer_mismatch' === $res['code'], 'a mismatch under the legacy header is refused' );

	$res = pi_push( pi_packet( PI_A, 'Honest from A', 1 ), PI_A, 'x-wpmmcc-origin-uuid' );
	assert_test( $res['ok'], 'a match under the legacy header is accepted' );

	// The gate reads the site header first; so does the binding.
	$request = new WP_REST_Request( 'POST', '/wpmmcc/v1/sync/push' );
	$request->set_header( 'x-wpmmcc-site-uuid', PI_A );
	$request->set_header( 'x-wpmmcc-origin-uuid', PI_B );
	assert_test( PI_A === \WPMMCC\REST\Rest_Middleware::requesting_peer_uuid( $request ), 'the site header wins over the legacy alias' );
	$request = new WP_REST_Request( 'POST', '/wpmmcc/v1/sync/push' );
	assert_test( '' === \WPMMCC\REST\Rest_Middleware::requesting_peer_uuid( $request ), 'no header, no peer' );
}

function pi_scenario_relay_keeps_its_origin(): void {
	echo "\n--- relay_keeps_its_origin: the client relays on behalf of a source site ---\n";
	pi_reset();
	pi_peer( PI_CLIENT );

	$res = pi_push( pi_packet( PI_SOURCE, 'Relayed post', 2 ), PI_CLIENT );
	assert_test( $res['ok'] && 'success' === ( $res['data']['status'] ?? '' ), 'a relayed packet (hop 2) under the relay\'s envelope is accepted' );
	assert_test( in_array( 'Relayed post', pi_titles(), true ), 'and materialised' );
	assert_test( null !== pi_mapping( PI_UUID, PI_SOURCE ), 'the mapping belongs to the logical origin, not to the relay' );
	assert_test( null === pi_mapping( PI_UUID, PI_CLIENT ), 'the relay owns no mapping row for content it only carried' );
	assert_test( array() === pi_journal( 'origin_peer_mismatch' ), 'no mismatch is recorded for a relay' );
	$inbound = array_values(
		array_filter(
			$GLOBALS['wpdb']->rows( 'wp_wpmmcc_journal' ),
			static function ( $r ) {
				return 'inbound' === $r['direction'] && 'upsert' === $r['event_type'];
			}
		)
	);
	assert_test( 1 === count( $inbound ) && PI_SOURCE === $inbound[0]['peer_uuid'], 'the inbound row stays filed under the logical origin' );
}

function pi_scenario_internal_call_is_not_an_attack(): void {
	echo "\n--- internal_call_is_not_an_attack: no envelope, nothing to bind ---\n";
	pi_reset();
	pi_peer( PI_A );

	$res = pi_push( pi_packet( PI_A, 'From the pull leg', 1 ), null );
	assert_test( $res['ok'] && 'success' === ( $res['data']['status'] ?? '' ), 'a request without an envelope is ingested' );
	assert_test( null !== pi_mapping( PI_UUID, PI_A ), 'and mapped under the packet origin' );

	// The pull leg names the peer it fetched from, so a peer that returns
	// packets attributed to someone else is refused there as well.
	$peer = array( 'peer_uuid' => PI_A );
	$req  = \WPMMCC\Sync\Manual_Sync::ingest_request( $peer, (string) wp_json_encode( pi_packet( PI_B, 'Fetched, forged', 1 ) ) );
	assert_test( PI_A === $req->get_header( 'x-wpmmcc-site-uuid' ), 'the pull leg builds its ingest request under the peer it pulled from' );
	$response = ( new Rest_Push() )->handle_push( $req );
	assert_test( is_wp_error( $response ) && 'wpmmcc_origin_peer_mismatch' === $response->get_error_code(), 'a pulled packet attributed to another peer is refused' );
}

function pi_scenario_strategy_follows_the_deliverer(): void {
	echo "\n--- strategy_follows_the_deliverer: the delivering peer's policy arbitrates ---\n";

	// a) A relayed conflict is arbitrated by the relay's strategy. The origin is
	// not a peer here, so before the fix this was always 'lww' and overwrote.
	pi_reset();
	pi_peer( PI_CLIENT, 'target_wins' );
	pi_local_post( PI_LOCAL, 'Local edit', PI_UUID, 600 );
	pi_map( PI_UUID, PI_SOURCE, PI_LOCAL, 3, 3600 );
	$res = pi_push( pi_packet( PI_SOURCE, 'Relayed edit', 2 ), PI_CLIENT );
	assert_test( $res['ok'] && 'skipped' === ( $res['data']['status'] ?? '' ), 'target_wins chosen at pairing protects the local edit on a relayed packet (got ' . ( $res['data']['status'] ?? $res['code'] ) . ')' );
	assert_test( 'Local edit' === get_post( PI_LOCAL )->post_title, 'the local post keeps its edit' );

	// b) manual_review on the relay: the relayed conflict is staged for review.
	pi_reset();
	pi_peer( PI_CLIENT, 'manual_review' );
	pi_local_post( PI_LOCAL, 'Local edit', PI_UUID, 600 );
	pi_map( PI_UUID, PI_SOURCE, PI_LOCAL, 3, 3600 );
	$res    = pi_push( pi_packet( PI_SOURCE, 'Relayed edit', 2 ), PI_CLIENT );
	$shadow = (int) ( $res['data']['target_id'] ?? 0 );
	assert_test( $res['ok'] && 'conflict' === ( $res['data']['status'] ?? '' ) && $shadow > 0 && PI_LOCAL !== $shadow, 'manual_review on the relay stages a review draft for a relayed conflict' );
	assert_test( 'Local edit' === get_post( PI_LOCAL )->post_title, 'the displaced post is untouched' );
	assert_test( PI_SOURCE === get_post_meta( $shadow, '_wpmmcc_conflict_origin_uuid', true ), 'the draft records the logical origin of the content' );

	// c) source_wins on the relay overwrites, whatever the origin's own row says.
	pi_reset();
	pi_peer( PI_CLIENT, 'source_wins' );
	pi_peer( PI_SOURCE, 'target_wins' );
	pi_local_post( PI_LOCAL, 'Local edit', PI_UUID, 600 );
	pi_map( PI_UUID, PI_SOURCE, PI_LOCAL, 3, 3600 );
	$res = pi_push( pi_packet( PI_SOURCE, 'Relayed edit', 2 ), PI_CLIENT );
	assert_test( $res['ok'] && 'success' === ( $res['data']['status'] ?? '' ) && 'Relayed edit' === get_post( PI_LOCAL )->post_title, 'the delivering peer\'s source_wins applies even if the origin is also a peer with target_wins' );

	// d) No envelope: the origin's own row decides (internal call, as before).
	pi_reset();
	pi_peer( PI_A, 'target_wins' );
	pi_local_post( PI_LOCAL, 'Local edit', PI_UUID, 600 );
	pi_map( PI_UUID, PI_A, PI_LOCAL, 3, 3600 );
	$res = pi_push( pi_packet( PI_A, 'Edit from A', 1 ), null );
	assert_test( $res['ok'] && 'skipped' === ( $res['data']['status'] ?? '' ), 'without an envelope the origin peer\'s own strategy applies' );

	// e) A first-hand peer cannot borrow a stricter or looser peer's strategy.
	pi_reset();
	pi_peer( PI_A, 'target_wins' );
	pi_peer( PI_B, 'source_wins' );
	pi_local_post( PI_LOCAL, 'Local edit', PI_UUID, 600 );
	pi_map( PI_UUID, PI_A, PI_LOCAL, 3, 3600 );
	$res = pi_push( pi_packet( PI_A, 'Edit from A', 1 ), PI_A );
	assert_test( $res['ok'] && 'skipped' === ( $res['data']['status'] ?? '' ), 'a first-hand push is arbitrated by the sender\'s own strategy' );
	$res = pi_push( pi_packet( PI_B, 'A as B', 1 ), PI_A );
	assert_test( ! $res['ok'], 'and claiming B\'s source_wins to get around it is refused' );
	assert_test( 'Local edit' === get_post( PI_LOCAL )->post_title, 'the local edit survives both attempts' );

	// f) Two peers with different strategies in one request lifetime: neither
	// inherits the other's (the looked-up strategy is remembered per peer).
	pi_reset();
	pi_peer( PI_A, 'target_wins' );
	pi_peer( PI_B, 'source_wins' );
	pi_local_post( PI_LOCAL, 'Local edit', PI_UUID, 600 );
	pi_map( PI_UUID, PI_A, PI_LOCAL, 3, 3600 );
	pi_map( PI_UUID, PI_B, PI_LOCAL, 3, 3600 );
	$res = pi_push( pi_packet( PI_A, 'Edit from A', 1 ), PI_A );
	assert_test( $res['ok'] && 'skipped' === ( $res['data']['status'] ?? '' ), 'A (target_wins) is held back' );
	$res = pi_push( pi_packet( PI_B, 'Edit from B', 1 ), PI_B );
	assert_test( $res['ok'] && 'success' === ( $res['data']['status'] ?? '' ) && 'Edit from B' === get_post( PI_LOCAL )->post_title, 'B (source_wins) right after it overwrites: B does not inherit A\'s strategy' );
	$res = pi_push( pi_packet( PI_A, 'Second edit from A', 1, array(), 12 ), PI_A );
	assert_test( $res['ok'] && 'skipped' === ( $res['data']['status'] ?? '' ), 'and A is still held back afterwards' );
}

function pi_scenario_hop_limit(): void {
	echo "\n--- hop_limit: a relay chain is bounded ---\n";
	global $wp_mock_filter_overrides;

	pi_reset();
	pi_peer( PI_CLIENT );
	$limit = Rest_Push::DEFAULT_MAX_HOP_COUNT;
	assert_test( 8 === $limit, 'the default limit is 8 hops' );

	$res = pi_push( pi_packet( PI_SOURCE, 'At the limit', $limit ), PI_CLIENT );
	assert_test( $res['ok'], 'a packet at the limit is accepted' );

	pi_reset();
	pi_peer( PI_CLIENT );
	$res = pi_push( pi_packet( PI_SOURCE, 'Over the limit', $limit + 1 ), PI_CLIENT );
	assert_test( ! $res['ok'] && 'wpmmcc_hop_limit_exceeded' === $res['code'] && 400 === $res['status'], 'one hop over the limit is refused with wpmmcc_hop_limit_exceeded (400)' );
	assert_test( array() === pi_titles(), 'nothing was materialised' );
	assert_test( null === pi_mapping( PI_UUID, PI_SOURCE ), 'and no mapping was written' );
	$rows = pi_journal( 'hop_limit_exceeded' );
	assert_test( 1 === count( $rows ) && 'error' === $rows[0]['outcome'] && 'inbound' === $rows[0]['direction'] && 1 === (int) $rows[0]['consumed'], 'the refusal is audited as an inbound error' );
	assert_test( PI_SOURCE === $rows[0]['peer_uuid'] && false !== strpos( (string) $rows[0]['message'], (string) ( $limit + 1 ) ), 'filed under the packet origin, message carries the hop count' );

	// Every action is bounded, not only upserts: a looping tombstone is as bad.
	pi_reset();
	pi_peer( PI_CLIENT );
	pi_local_post( PI_LOCAL, 'Keep me', PI_UUID, 600 );
	pi_map( PI_UUID, PI_SOURCE, PI_LOCAL, 3, 3600 );
	$res = pi_push( pi_packet( PI_SOURCE, 'Gone', $limit + 1, array( 'action' => 'trash' ) ), PI_CLIENT );
	assert_test( ! $res['ok'] && 'wpmmcc_hop_limit_exceeded' === $res['code'], 'a trash past the limit is refused' );
	assert_test( 'publish' === get_post( PI_LOCAL )->post_status, 'and the post is not trashed' );
	$res = pi_push( pi_packet( PI_SOURCE, 'Gone', $limit + 1, array( 'action' => 'delete' ) ), PI_CLIENT );
	assert_test( ! $res['ok'] && 'wpmmcc_hop_limit_exceeded' === $res['code'] && null !== get_post( PI_LOCAL ), 'a delete past the limit is refused and the post survives' );

	// The limit can be tuned, and a nonsense answer cannot disable relays or
	// reject first-hand traffic.
	pi_reset();
	pi_peer( PI_CLIENT );
	$wp_mock_filter_overrides['wpmmcc_max_hop_count'] = 2;
	$res = pi_push( pi_packet( PI_SOURCE, 'Two hops', 2 ), PI_CLIENT );
	assert_test( $res['ok'], 'the filter can lower the limit: hop 2 passes a limit of 2' );
	$res = pi_push( pi_packet( PI_SOURCE, 'Three hops', 3, array( 'guid' => 'urn:uuid:entity-3' ) ), PI_CLIENT );
	assert_test( ! $res['ok'] && 'wpmmcc_hop_limit_exceeded' === $res['code'], 'and hop 3 fails it' );

	foreach ( array( 0, -5, 'abc', null ) as $garbage ) {
		pi_reset();
		pi_peer( PI_A );
		$wp_mock_filter_overrides['wpmmcc_max_hop_count'] = $garbage;
		$res = pi_push( pi_packet( PI_A, 'First hand', 1 ), PI_A );
		assert_test( $res['ok'], 'a filter answer of ' . var_export( $garbage, true ) . ' is clamped to 1: first-hand traffic still flows' );
	}
	pi_reset();
	pi_peer( PI_CLIENT );
	$wp_mock_filter_overrides['wpmmcc_max_hop_count'] = 0;
	$res = pi_push( pi_packet( PI_SOURCE, 'Relayed', 2 ), PI_CLIENT );
	assert_test( ! $res['ok'] && 'wpmmcc_hop_limit_exceeded' === $res['code'], 'and a limit clamped to 1 refuses any relay' );

	// Missing hop authority is rejected before the relay-limit check.
	pi_reset();
	pi_peer( PI_A );
	$bare = pi_packet( PI_A, 'No hop field', 1 );
	unset( $bare['origin_context']['hop_count'] );
	$res = pi_push( $bare, PI_A );
	assert_test( ! $res['ok'] && 'wpmmcc_invalid_packet_scope' === $res['code'], 'a packet without hop_count is refused as an invalid scope' );
}

function pi_scenario_mapping_row_is_per_peer(): void {
	echo "\n--- mapping_row_is_per_peer: the drift verdict uses the pushing peer's own row ---\n";

	// Peer 2 is clean (synced after the local edit), peer 1 is not. Whichever
	// row came back first used to decide.
	pi_reset();
	pi_peer( PI_A, 'lww' );
	pi_peer( PI_B, 'target_wins' );
	pi_local_post( PI_LOCAL, 'Local edit', PI_UUID, 600 );
	pi_map( PI_UUID, PI_A, PI_LOCAL, 3, 7200 ); // Local edit is newer than this sync: drift for A.
	pi_map( PI_UUID, PI_B, PI_LOCAL, 3, 60 );    // Synced after the edit: clean for B.
	$res = pi_push( pi_packet( PI_B, 'Edit from B', 1 ), PI_B );
	assert_test( $res['ok'] && 'success' === ( $res['data']['status'] ?? '' ) && 'Edit from B' === get_post( PI_LOCAL )->post_title, 'a clean peer is not blocked by another peer\'s stale sync time (overwrite)' );

	// Mirror: the pushing peer is the one with drift, the other row is clean.
	pi_reset();
	pi_peer( PI_A, 'lww' );
	pi_peer( PI_B, 'target_wins' );
	pi_local_post( PI_LOCAL, 'Local edit', PI_UUID, 600 );
	pi_map( PI_UUID, PI_A, PI_LOCAL, 3, 60 );    // Clean for A, inserted first.
	pi_map( PI_UUID, PI_B, PI_LOCAL, 3, 7200 );  // Drift for B.
	$res = pi_push( pi_packet( PI_B, 'Edit from B', 1 ), PI_B );
	assert_test( $res['ok'] && 'skipped' === ( $res['data']['status'] ?? '' ) && 'Local edit' === get_post( PI_LOCAL )->post_title, 'a peer with real drift is not waved through by another peer\'s fresh sync time (skip)' );

	// A peer that never synced the entity gets the most recent sync point,
	// whatever order the rows are stored in.
	foreach ( array( 'older row first', 'newer row first' ) as $order ) {
		pi_reset();
		pi_peer( PI_A, 'lww' );
		pi_peer( PI_B, 'lww' );
		pi_peer( 'urn:uuid:peer-c', 'target_wins' );
		pi_local_post( PI_LOCAL, 'Local edit', PI_UUID, 600 );
		if ( 'older row first' === $order ) {
			pi_map( PI_UUID, PI_A, PI_LOCAL, 3, 7200 );
			pi_map( PI_UUID, PI_B, PI_LOCAL, 3, 60 );
		} else {
			pi_map( PI_UUID, PI_B, PI_LOCAL, 3, 60 );
			pi_map( PI_UUID, PI_A, PI_LOCAL, 3, 7200 );
		}
		$res = pi_push( pi_packet( 'urn:uuid:peer-c', 'First from C', 1 ), 'urn:uuid:peer-c' );
		assert_test( $res['ok'] && 'success' === ( $res['data']['status'] ?? '' ), 'an unmapped peer is judged against the most recent sync point (' . $order . ')' );
		assert_test( array( 'First from C' ) === pi_titles() && PI_LOCAL === (int) ( $res['data']['target_id'] ?? 0 ), 'it updates the post the other peers already mapped, it does not create a copy (' . $order . ')' );
	}

	// With only stale sync points on record the same peer is held back: the
	// verdict comes from the row that was picked, not from nothing.
	pi_reset();
	pi_peer( PI_A, 'lww' );
	pi_peer( 'urn:uuid:peer-c', 'target_wins' );
	pi_local_post( PI_LOCAL, 'Local edit', PI_UUID, 600 );
	pi_map( PI_UUID, PI_A, PI_LOCAL, 3, 7200 );
	$res = pi_push( pi_packet( 'urn:uuid:peer-c', 'First from C', 1 ), 'urn:uuid:peer-c' );
	assert_test( $res['ok'] && 'skipped' === ( $res['data']['status'] ?? '' ) && 'Local edit' === get_post( PI_LOCAL )->post_title, 'an unmapped peer is held back when the most recent sync point predates the local edit' );

	// The pushing peer's row is written back, the other peers' rows are not.
	pi_reset();
	pi_peer( PI_A, 'lww' );
	pi_peer( PI_B, 'lww' );
	pi_local_post( PI_LOCAL, 'Local edit', PI_UUID, 600 );
	pi_map( PI_UUID, PI_A, PI_LOCAL, 3, 7200 );
	pi_map( PI_UUID, PI_B, PI_LOCAL, 3, 7200 );
	$a_before = pi_mapping( PI_UUID, PI_A );
	$res      = pi_push( pi_packet( PI_B, 'Edit from B', 1, array(), 9 ), PI_B );
	assert_test( $res['ok'], 'setup: B pushes' );
	assert_test( 9 === (int) pi_mapping( PI_UUID, PI_B )['vector_clock'], 'B\'s row carries B\'s new clock' );
	assert_test( $a_before === pi_mapping( PI_UUID, PI_A ), 'A\'s row is not touched by B\'s push' );
}

function pi_scenario_non_post_entities_are_refused(): void {
	echo "\n--- non_post_entities_are_refused: v1 ingests post-class entities only (WMC-05) ---\n";
	pi_reset();
	pi_peer( PI_A, 'lww' );

	// The README says what v1 takes: post-class entities (any post type), with
	// the rest answered 501. Both halves are pinned so the text cannot drift.
	foreach ( array( 'term', 'user', 'attachment' ) as $type ) {
		$guid = 'urn:uuid:entity-' . $type;
		$res  = pi_push( pi_packet( PI_A, 'A sends a ' . $type, 1, array( 'guid' => $guid, 'object_type' => $type ) ), PI_A );
		assert_test( ! $res['ok'] && 'wpmmcc_unsupported_object_type' === $res['code'] && 501 === $res['status'], 'a ' . $type . ' entity packet is refused 501 wpmmcc_unsupported_object_type (got ' . ( $res['code'] ?: 'accepted' ) . ')' );
		assert_test( null === pi_mapping( $guid, PI_A ), 'and leaves no mapping row for the ' . $type );
	}
	assert_test( array() === pi_titles(), 'no post was created by any of them' );

	$rows = array_values(
		array_filter(
			$GLOBALS['wpdb']->rows( 'wp_wpmmcc_journal' ),
			static function ( $r ) {
				return 'error' === $r['outcome'] && 'inbound' === $r['direction'];
			}
		)
	);
	$refused = array_column( $rows, 'object_type' );
	sort( $refused );
	assert_test( 3 === count( $rows ), 'each refusal is audited as an inbound error' );
	assert_test( array( 'attachment', 'term', 'user' ) === $refused, 'the audit rows record which entity type was refused' );

	// Post-class entities of any post type do go through.
	foreach ( array( 'page', 'product', 'post' ) as $subtype ) {
		$guid = 'urn:uuid:entity-ok-' . $subtype;
		$res  = pi_push( pi_packet( PI_A, 'A sends a ' . $subtype, 1, array( 'guid' => $guid, 'subtype' => $subtype ) ), PI_A );
		assert_test( $res['ok'] && 'success' === ( $res['data']['status'] ?? '' ), 'a ' . $subtype . ' packet is ingested' );
		assert_test( $subtype === get_post( (int) $res['data']['target_id'] )->post_type, 'as that post type (' . $subtype . ')' );
	}
}

function pi_scenario_mapping_holds_no_local_url(): void {
	echo "\n--- mapping_holds_no_local_url: remote_permalink is never this site's own URL (WMC-15) ---\n";
	pi_reset();
	pi_peer( PI_A );

	// The ingest answers with the URL of the post it made (the ack the sender
	// gets) and used to file that same URL as the PEER's permalink. hreflang
	// reads the column back as the other site's alternate, so every page
	// advertised itself as its own translation.
	$res = pi_push( pi_packet( PI_A, 'From A', 1 ), PI_A );
	assert_test( $res['ok'] && 'success' === ( $res['data']['status'] ?? '' ), 'setup: a first-hand push is ingested' );
	$local_id  = (int) $res['data']['target_id'];
	$local_url = get_permalink( $local_id );
	assert_test( $local_url === ( $res['data']['target_url'] ?? '' ), 'the sender is still told the URL of the post that was created (that is the ack, and it is true)' );

	$row = pi_mapping( PI_UUID, PI_A );
	assert_test( null !== $row && $local_id === (int) $row['local_object_id'], 'the pushing peer has a mapping row for the new post' );
	assert_test( $local_url !== (string) $row['remote_permalink'], 'its remote_permalink is not this site\'s own permalink' );
	assert_test( '' === (string) $row['remote_permalink'], 'with no permalink of the origin\'s post in the packet nothing true is stored, so there is no alternate to advertise' );

	// The row is REPLACEd on every push; a later push must not bring it back.
	$res = pi_push( pi_packet( PI_A, 'From A, edited', 1, array(), 7 ), PI_A );
	assert_test( $res['ok'] && 'success' === ( $res['data']['status'] ?? '' ), 'setup: the same peer pushes an edit' );
	$row = pi_mapping( PI_UUID, PI_A );
	assert_test( 7 === (int) $row['vector_clock'], 'the row was rewritten by that push' );
	assert_test( '' === (string) $row['remote_permalink'], 'and still holds no local URL' );
}

// ---------------------------------------------------------------------------
// Runner
// ---------------------------------------------------------------------------

$scenarios = array(
	'first_hand_is_bound_to_sender',
	'binding_follows_the_legacy_header',
	'relay_keeps_its_origin',
	'internal_call_is_not_an_attack',
	'strategy_follows_the_deliverer',
	'hop_limit',
	'mapping_row_is_per_peer',
	'non_post_entities_are_refused',
	'mapping_holds_no_local_url',
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
	call_user_func( 'pi_scenario_' . $name );
}

echo "\n==================================================\n";
echo "PASS: All {$pass_count} Peer Identity tests passed successfully!\n";
echo "==================================================\n";
exit( 0 );
