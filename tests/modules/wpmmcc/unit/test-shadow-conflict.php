<?php
/**
 * WPMMCC conflict-review shadow drafts stay local (WMC-11).
 *
 * A manual_review peer that pushes a version colliding with a local edit gets
 * its version staged as a private draft (the "shadow"), and the canonical
 * mapping is re-pointed at it. The shadow carries the entity's canonical uuid,
 * so before this fix every path that enumerates or resolves entities took it
 * for the entity itself: the digest listed it next to the real post, pull and
 * the live-entity verdict answered from it, a save / trash / delete of it was
 * queued for the peer (a tombstone that deleted the peer's REAL post), its
 * review meta rode along in exports and could be written by a packet, and the
 * peer's next packet overwrote it AND published it, bypassing the review.
 *
 * The scenarios build the conflict with the real Rest_Push (not by hand), then
 * drive each path. The post / meta stores are real in-memory state and
 * wp_posts / wp_postmeta are SQL views over them, so the SQL code paths and
 * the get_post() / get_post_meta() code paths see one truth; save, trash and
 * delete fire the recorder hooks the way WordPress core does.
 *
 * Scenarios (run one with:  php test-shadow-conflict.php --only=<name>):
 *   conflict_is_staged        baseline: the conflict yields a flagged draft with
 *                             the remote version, the mapping points at it, the
 *                             displaced local post is untouched.
 *   digest_skips_shadow       the digest offers the entity once (the real post)
 *                             and pages stay full when a shadow sits inside one.
 *   export_refuses_shadow     a shadow is never exported and exporting it has no
 *                             side effect; the real post still exports.
 *   no_cdc_for_shadow         saving / trashing / deleting a shadow queues
 *                             nothing (no clock bump, no tombstone); the real
 *                             post still queues.
 *   pull_serves_real_entity   pull answers a shadowed uuid from the displaced
 *                             post; with none recorded it answers nothing.
 *   verdict_uses_real_entity  the live-entity verdict compares against the real
 *                             post, also when the shadow has no recorded
 *                             counterpart.
 *   inbound_meta_local_only   a packet cannot write the review flag, the review
 *                             bookkeeping or another entity's identity.
 *   refresh_keeps_review      the peer's next revision refreshes the shadow; it
 *                             never publishes it, never touches the real post,
 *                             whatever the peer's strategy is by then.
 *   trash_hits_shadow_only    a peer's trash / delete lands on the shadow, not
 *                             on the displaced real post, and echoes nothing.
 *   accept_remote             the promoted shadow is ordinary content (digest,
 *                             export, CDC), the displaced post is kept but
 *                             detached, exactly one post answers to the uuid.
 *   keep_local                the mapping returns to the displaced post, the
 *                             shadow is gone without a tombstone.
 *   resolve_needs_a_shadow    the review actions refuse a post that is not a
 *                             shadow and never delete user content.
 *   accept_adopts_counterpart_status
 *                             the promoted draft takes the displaced post's
 *                             publish state instead of blanket-publishing.
 *   accept_detaches_only_matching_uuid
 *                             a post re-homed onto another entity while the
 *                             review was open keeps its new identity.
 *
 * oracle: L1
 *
 * @package WPMMCC\Tests\Unit
 */

// WordPress core fires save_post / wp_trash_post / delete_post from inside these
// functions and Event_Recorder::register_hooks() wires them to the recorder. The
// bootstrap doubles fire nothing, so an admin saving, trashing or deleting a
// shadow would never reach the recorder in a test. Declared first: the bootstrap
// only declares a function that is still missing.
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $postarr, $wp_error = false ) {
		global $wp_mock_posts;
		static $next = 1000;
		$id                   = ++$next;
		$now                  = gmdate( 'Y-m-d H:i:s' );
		$post                 = new WP_Post(
			array_merge(
				array(
					'post_title'        => '',
					'post_content'      => '',
					'post_excerpt'      => '',
					'post_status'       => 'publish',
					'post_name'         => '',
					'post_type'         => 'post',
					'post_date_gmt'     => $now,
					'post_modified_gmt' => $now,
				),
				$postarr,
				array( 'ID' => $id )
			)
		);
		$wp_mock_posts[ $id ]  = $post;
		$GLOBALS['sd_depth_log'][] = array( 'op' => 'insert', 'id' => $id, 'depth' => \WPMMCC\Sync\Event_Recorder::$depth );
		\WPMMCC\Sync\Event_Recorder::on_save_post( $id, $post, false );
		return $id;
	}
}
if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( $postarr = array(), $wp_error = false ) {
		global $wp_mock_posts;
		$id   = (int) ( $postarr['ID'] ?? 0 );
		$post = $wp_mock_posts[ $id ] ?? null;
		if ( ! $post ) {
			return $wp_error ? new WP_Error( 'invalid_post', 'Invalid post ID.' ) : 0;
		}
		foreach ( $postarr as $key => $value ) {
			if ( 'ID' !== $key ) {
				$post->{$key} = $value;
			}
		}
		$post->post_modified_gmt = gmdate( 'Y-m-d H:i:s' );
		$GLOBALS['sd_depth_log'][] = array( 'op' => 'update', 'id' => $id, 'depth' => \WPMMCC\Sync\Event_Recorder::$depth );
		\WPMMCC\Sync\Event_Recorder::on_save_post( $id, $post, true );
		return $id;
	}
}
if ( ! function_exists( 'wp_trash_post' ) ) {
	function wp_trash_post( $post_id = 0 ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}
		$GLOBALS['sd_depth_log'][] = array( 'op' => 'trash', 'id' => (int) $post_id, 'depth' => \WPMMCC\Sync\Event_Recorder::$depth );
		\WPMMCC\Sync\Event_Recorder::on_trash_post( (int) $post_id );
		$post->post_status = 'trash';
		return $post;
	}
}
if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( $post_id = 0, $force_delete = false ) {
		global $wp_mock_posts, $wp_mock_meta;
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}
		$GLOBALS['sd_depth_log'][] = array( 'op' => 'delete', 'id' => (int) $post_id, 'depth' => \WPMMCC\Sync\Event_Recorder::$depth );
		\WPMMCC\Sync\Event_Recorder::on_delete_post( (int) $post_id, $post );
		unset( $wp_mock_posts[ $post_id ], $wp_mock_meta[ $post_id ] );
		return $post;
	}
}

require_once dirname( __DIR__ ) . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/support/class-mini-sql-wpdb.php';

use WPMMCC\REST\Rest_Pull;
use WPMMCC\REST\Rest_Push;
use WPMMCC\Sync\Event_Recorder;
use WPMMCC\Sync\Packet;
use WPMMCC\Sync\Reconciler;

echo "=== Running Shadow Conflict Draft Test (WMC-11) ===\n";

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

const SD_PREFIX = '[Sync Conflict Review] ';
const SD_FLAG   = '_wpmmcc_shadow_conflict';
const SD_UUID   = 'urn:uuid:entity-g';
const SD_PEER   = 'urn:uuid:peer-b';
const SD_LOCAL  = 2001; // Above every shadow id the fake wp_insert_post hands out.

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/** wp_posts as a view over the in-memory post store. */
function sd_posts_view(): array {
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

/** wp_postmeta as a view over the in-memory meta store. */
function sd_postmeta_view(): array {
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

/** Fresh DB + WordPress state for one scenario. */
function sd_reset(): void {
	global $wpdb, $wp_mock_posts, $wp_mock_meta, $wp_mock_options, $wp_mock_cache, $wp_mock_posts_strict;

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
	$wpdb->create_view( 'wp_posts', 'sd_posts_view' );
	$wpdb->create_view( 'wp_postmeta', 'sd_postmeta_view' );

	$wp_mock_posts        = array();
	$wp_mock_meta         = array();
	$wp_mock_cache        = array();
	$wp_mock_posts_strict = true;
	$wp_mock_options      = array( 'wpmmcc_origin_uuid' => 'urn:uuid:site-self' );
	Event_Recorder::$depth    = 0;
	$GLOBALS['sd_depth_log'] = array();
}

function sd_peer( string $uuid, string $strategy = 'lww', string $direction = 'bidirectional' ): void {
	global $wpdb;
	$wpdb->insert(
		'wp_wpmmcc_peers',
		array(
			'peer_uuid'         => $uuid,
			'peer_name'         => 'Peer ' . $uuid,
			'direction'         => $direction,
			'status'            => 'active',
			'conflict_strategy' => $strategy,
		)
	);
	wp_cache_delete( 'active_peers_list', 'wpmmcc' );
	wp_cache_delete( 'peer_strat_' . md5( $uuid ), 'wpmmcc' );
}

function sd_set_strategy( string $uuid, string $strategy ): void {
	global $wpdb;
	$wpdb->update( 'wp_wpmmcc_peers', array( 'conflict_strategy' => $strategy ), array( 'peer_uuid' => $uuid ) );
	wp_cache_delete( 'peer_strat_' . md5( $uuid ), 'wpmmcc' );
}

/** A local post that already carries a canonical uuid. */
function sd_local_post( int $id, string $title, string $uuid, int $modified_ago = 600, string $status = 'publish' ): WP_Post {
	global $wp_mock_posts;
	$wp_mock_posts[ $id ] = new WP_Post(
		array(
			'ID'                => $id,
			'post_title'        => $title,
			'post_content'      => 'Body of ' . $title,
			'post_status'       => $status,
			'post_type'         => 'post',
			'post_name'         => 'post-' . $id,
			'post_date_gmt'     => gmdate( 'Y-m-d H:i:s', time() - 86400 ),
			'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() - $modified_ago ),
		)
	);
	update_post_meta( $id, '_wpmmcc_canonical_uuid', $uuid );
	return $wp_mock_posts[ $id ];
}

function sd_map( string $uuid, string $peer, int $local_id, int $clock = 3, int $synced_ago = 3600, string $checksum = 'cs-old' ): void {
	global $wpdb;
	$wpdb->insert(
		'wp_wpmmcc_cross_mappings',
		array(
			'canonical_uuid'      => $uuid,
			'local_object_type'   => 'post',
			'local_object_id'     => $local_id,
			'peer_uuid'           => $peer,
			'remote_object_id'    => 500,
			'content_checksum'    => $checksum,
			'vector_clock'        => $clock,
			'last_sync_direction' => 'inbound',
			'last_synced_at'      => gmdate( 'Y-m-d H:i:s', time() - $synced_ago ),
		)
	);
}

/** The mapping row for (uuid, peer), or null. */
function sd_mapping( string $uuid, string $peer ): ?array {
	global $wpdb;
	foreach ( $wpdb->rows( 'wp_wpmmcc_cross_mappings' ) as $row ) {
		if ( $uuid === $row['canonical_uuid'] && $peer === $row['peer_uuid'] ) {
			return $row;
		}
	}
	return null;
}

/** Every mapping row that still points at a local post. */
function sd_mappings_for_local( int $local_id ): array {
	global $wpdb;
	return array_values(
		array_filter(
			$wpdb->rows( 'wp_wpmmcc_cross_mappings' ),
			static function ( $r ) use ( $local_id ) {
				return (int) $r['local_object_id'] === $local_id;
			}
		)
	);
}

/** Depth of the ingestion scope recorded by the post-writing doubles. */
function sd_depths_for( string $op, int $id ): array {
	return array_map(
		static function ( $e ) {
			return (int) $e['depth'];
		},
		array_filter(
			(array) $GLOBALS['sd_depth_log'],
			static function ( $e ) use ( $op, $id ) {
				return $op === $e['op'] && $id === $e['id'];
			}
		)
	);
}

/** A packet as a peer would send it (built under that peer's identity). */
function sd_packet( string $guid, string $from_uuid, string $title, string $content, int $clock, array $over = array() ): array {
	global $wp_mock_options;
	$self                                  = $wp_mock_options['wpmmcc_origin_uuid'];
	$wp_mock_options['wpmmcc_origin_uuid'] = $from_uuid;
	$packet                                = Packet::build(
		array_merge(
			array(
				'guid'         => $guid,
				'object_type'  => 'post',
				'subtype'      => 'post',
				'source_id'    => 500,
				'slug'         => 'remote-post',
				'status'       => 'publish',
				'vector_clock' => $clock,
				'core_fields'  => array(
					'post_title'   => $title,
					'post_content' => $content,
				),
				'action'       => 'upsert',
			),
			$over
		)
	);
	$wp_mock_options['wpmmcc_origin_uuid'] = $self;
	return $packet;
}

/** Deliver a packet through the real ingest; returns the response data. */
function sd_push( array $packet ): array {
	$request = new WP_REST_Request( 'POST', '/wpmmcc/v1/sync/push' );
	$request->set_body( (string) wp_json_encode( $packet ) );
	$response = ( new Rest_Push() )->handle_push( $request );
	if ( is_wp_error( $response ) ) {
		return array( 'error' => $response->get_error_code() );
	}
	$data = $response->get_data();
	return (array) ( $data['data'] ?? $data );
}

function sd_flagged( int $post_id ): bool {
	return 1 === (int) get_post_meta( $post_id, SD_FLAG, true );
}

/** Outbound CDC rows (the queue a peer is served from). */
function sd_queue(): array {
	global $wpdb;
	return array_values(
		array_filter(
			$wpdb->rows( 'wp_wpmmcc_journal' ),
			static function ( $r ) {
				return 'outbound' === $r['direction'] && in_array( $r['event_type'], array( 'insert', 'update', 'trash', 'delete' ), true );
			}
		)
	);
}

function sd_journal( string $event_type ): array {
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

/**
 * The conflict, built by the real ingest: the local post was edited after the
 * last sync, the manual_review peer then pushes a different version.
 *
 * @return array{uuid:string,peer:string,local:int,shadow:int,packet:array}
 */
function sd_conflict(): array {
	sd_reset();
	sd_peer( SD_PEER, 'manual_review' );
	sd_local_post( SD_LOCAL, 'Local edit', SD_UUID, 600 );
	sd_map( SD_UUID, SD_PEER, SD_LOCAL, 3, 3600 );

	$packet = sd_packet( SD_UUID, SD_PEER, 'Remote edit', 'Remote body', 4 );
	$res    = sd_push( $packet );
	$shadow = (int) ( $res['target_id'] ?? 0 );

	assert_test( 'conflict' === ( $res['status'] ?? '' ), 'setup: the colliding push is arbitrated into a conflict (got ' . ( $res['status'] ?? ( $res['error'] ?? '?' ) ) . ')' );
	assert_test( $shadow > 0 && SD_LOCAL !== $shadow, 'setup: the remote version landed in a new post, not on the local one' );
	return array(
		'uuid'   => SD_UUID,
		'peer'   => SD_PEER,
		'local'  => SD_LOCAL,
		'shadow' => $shadow,
		'packet' => $packet,
	);
}

// ---------------------------------------------------------------------------
// Scenarios
// ---------------------------------------------------------------------------

function sd_scenario_conflict_is_staged(): void {
	echo "\n--- conflict_is_staged: the review draft and the displaced post ---\n";
	$c      = sd_conflict();
	$shadow = get_post( $c['shadow'] );
	$local  = get_post( $c['local'] );

	assert_test( 0 === strpos( $shadow->post_title, SD_PREFIX ) && 'Remote edit' === substr( $shadow->post_title, strlen( SD_PREFIX ) ), 'the shadow carries the remote title behind the review prefix' );
	assert_test( 'draft' === $shadow->post_status, 'the shadow is a draft, whatever status the peer sent' );
	assert_test( 'Remote body' === $shadow->post_content, 'the shadow holds the remote content' );
	assert_test( sd_flagged( $c['shadow'] ), 'the shadow is flagged as a review draft' );
	assert_test( $c['local'] === (int) get_post_meta( $c['shadow'], '_wpmmcc_shadow_local_id', true ), 'the shadow records the displaced local post' );
	assert_test( SD_PEER === get_post_meta( $c['shadow'], '_wpmmcc_conflict_origin_uuid', true ), 'the shadow records the peer whose version it holds' );
	assert_test( 'Local edit' === $local->post_title && 'publish' === $local->post_status, 'the displaced local post is untouched' );
	assert_test( ! sd_flagged( $c['local'] ), 'the displaced local post is not flagged' );
	assert_test( $c['shadow'] === (int) sd_mapping( $c['uuid'], $c['peer'] )['local_object_id'], 'the mapping points at the shadow while the review is open' );
	assert_test( array() === sd_queue(), 'staging the shadow queued nothing for any peer' );
}

function sd_scenario_digest_skips_shadow(): void {
	echo "\n--- digest_skips_shadow: the catalog offers the entity once ---\n";
	$c = sd_conflict();

	$digest = Reconciler::generate_digest( 100, 0, array( 'post' ) );
	$for_g  = array_values(
		array_filter(
			$digest,
			static function ( $d ) use ( $c ) {
				return $c['uuid'] === $d['canonical_uuid'];
			}
		)
	);
	assert_test( 1 === count( $for_g ), 'the entity appears once in the digest (got ' . count( $for_g ) . ')' );
	assert_test( $c['local'] === (int) ( $for_g[0]['local_id'] ?? 0 ), 'the digest entry is the real post, not the shadow' );
	assert_test( ! in_array( $c['shadow'], array_map( 'intval', array_column( $digest, 'local_id' ) ), true ), 'the shadow id is not in the digest at all' );

	// Pages must stay full when a shadow sits inside one: a short page reads as
	// "end of catalog" to the client and the rest of it would never be offered.
	sd_local_post( SD_LOCAL + 1, 'Second', 'urn:uuid:entity-2' );
	sd_local_post( SD_LOCAL + 2, 'Third', 'urn:uuid:entity-3' );
	sd_local_post( SD_LOCAL + 3, 'Fourth', 'urn:uuid:entity-4' );
	wp_cache_flush();
	$page1                    = Reconciler::generate_digest( 2, 0, array( 'post' ) );
	assert_test( array( SD_LOCAL, SD_LOCAL + 1 ) === array_map( 'intval', array_column( $page1, 'local_id' ) ), 'page 1 is full (limit 2) although the shadow id sorts inside it' );
	$page2 = Reconciler::generate_digest( 2, SD_LOCAL + 1, array( 'post' ) );
	assert_test( array( SD_LOCAL + 2, SD_LOCAL + 3 ) === array_map( 'intval', array_column( $page2, 'local_id' ) ), 'page 2 continues from the keyset cursor' );
}

function sd_scenario_export_refuses_shadow(): void {
	echo "\n--- export_refuses_shadow: a review draft never leaves the site ---\n";
	$c        = sd_conflict();
	$rows_was = sd_mapping( $c['uuid'], $c['peer'] );
	$count    = count( $GLOBALS['wpdb']->rows( 'wp_wpmmcc_cross_mappings' ) );

	$res = Packet::export_post( $c['shadow'] );
	assert_test( is_wp_error( $res ), 'exporting a shadow is refused' );
	assert_test( 'wpmmcc_shadow_not_exportable' === $res->get_error_code(), 'the refusal has its own error code (got ' . $res->get_error_code() . ')' );
	assert_test( 409 === ( $res->get_error_data()['status'] ?? 0 ), 'the refusal is a 409' );
	assert_test( $count === count( $GLOBALS['wpdb']->rows( 'wp_wpmmcc_cross_mappings' ) ) && $rows_was === sd_mapping( $c['uuid'], $c['peer'] ), 'the refused export wrote no clock or mapping row' );

	$ok = Packet::export_post( $c['local'] );
	assert_test( ! is_wp_error( $ok ) && $c['uuid'] === $ok['entity']['guid'] && $c['local'] === (int) $ok['entity']['source_id'], 'the displaced real post still exports under the entity uuid' );
	assert_test( 'Local edit' === $ok['entity']['core_fields']['post_title'], 'the exported content is the local version' );
	assert_test( ! isset( $ok['entity']['meta_fields'][ SD_FLAG ] ), 'no review flag rides along in the real post\'s export' );
}

function sd_scenario_no_cdc_for_shadow(): void {
	echo "\n--- no_cdc_for_shadow: editing or discarding a review draft is silent ---\n";
	$c      = sd_conflict();
	$clock  = (int) sd_mapping( $c['uuid'], $c['peer'] )['vector_clock'];
	$shadow = get_post( $c['shadow'] );

	// An admin edits the shadow in the editor, trashes it, deletes it: all
	// outside an ingestion scope, the way a person does it.
	wp_update_post( array( 'ID' => $c['shadow'], 'post_content' => 'Merged by hand' ) );
	assert_test( array() === sd_queue(), 'saving a shadow queues nothing' );
	assert_test( $clock === (int) sd_mapping( $c['uuid'], $c['peer'] )['vector_clock'], 'saving a shadow does not advance the entity\'s clock' );

	wp_trash_post( $c['shadow'] );
	assert_test( array() === sd_queue(), 'trashing a shadow queues no trash tombstone (it would trash the peer\'s real post)' );

	wp_delete_post( $c['shadow'], true );
	assert_test( array() === sd_queue(), 'deleting a shadow queues no delete tombstone (it would delete the peer\'s real post)' );

	$c2 = sd_conflict();
	wp_update_post( array( 'ID' => $c2['local'], 'post_content' => 'Edited again' ) );
	$queued = sd_queue();
	assert_test( 1 === count( $queued ) && $c2['uuid'] === $queued[0]['canonical_uuid'] && $c2['local'] === (int) $queued[0]['local_object_id'], 'the displaced real post still queues its own edits' );
	assert_test( $shadow instanceof WP_Post, 'setup: the shadow object was a post' );
}

function sd_scenario_pull_serves_real_entity(): void {
	echo "\n--- pull_serves_real_entity: a peer pulling the uuid gets the real post ---\n";
	$c = sd_conflict();

	$pull = static function ( string $uuid ): array {
		$request = new WP_REST_Request( 'POST', '/wpmmcc/v1/sync/pull' );
		$request->set_body( (string) wp_json_encode( array( 'canonical_uuids' => array( $uuid ) ) ) );
		$response = ( new Rest_Pull() )->handle_pull( $request );
		$data     = $response->get_data();
		return (array) ( $data['data']['packets'] ?? array() );
	};

	$packets = $pull( $c['uuid'] );
	assert_test( 1 === count( $packets ), 'one packet is served for the uuid (got ' . count( $packets ) . ')' );
	assert_test( $c['local'] === (int) ( $packets[0]['entity']['source_id'] ?? 0 ), 'the packet is the displaced real post' );
	assert_test( 'Local edit' === ( $packets[0]['entity']['core_fields']['post_title'] ?? '' ), 'the pulled content is the local version, not the review draft' );
	assert_test( 'publish' === ( $packets[0]['entity']['status'] ?? '' ), 'the pulled status is the real post\'s' );

	// A legacy shadow has no recorded counterpart: the real post is still
	// found through its uuid meta and served, never the draft itself.
	update_post_meta( $c['shadow'], '_wpmmcc_shadow_local_id', 0 );
	wp_cache_flush();
	$packets = $pull( $c['uuid'] );
	assert_test( 1 === count( $packets ) && $c['local'] === (int) ( $packets[0]['entity']['source_id'] ?? 0 ), 'a legacy shadow still resolves to the real post through its uuid' );

	// And when the real post carries no uuid any more either, there is nothing
	// real to serve — and the draft must not be.
	delete_post_meta( $c['local'], '_wpmmcc_canonical_uuid' );
	wp_cache_flush();
	assert_test( array() === $pull( $c['uuid'] ), 'with no real post to serve, nothing is served' );
}

function sd_scenario_verdict_uses_real_entity(): void {
	echo "\n--- verdict_uses_real_entity: the live-entity verdict ignores the draft ---\n";
	$c        = sd_conflict();
	$mediator = 'urn:uuid:mediator';
	$local    = get_post( $c['local'] );
	$fp_local = Reconciler::current_fingerprint_for_post( $local, $c['local'], 1 );
	$ask      = static function ( string $fp ) use ( $c, $mediator ) {
		$verdicts = Reconciler::reconcile_fingerprints(
			array(
				array(
					'canonical_uuid'     => $c['uuid'],
					'source_fingerprint' => $fp,
					'vector_clock'       => 1,
				),
			),
			$mediator
		);
		return $verdicts[ $c['uuid'] ] ?? '';
	};

	assert_test( 'in_sync' === $ask( $fp_local ), 'a caller that holds the real post\'s state is in_sync (the draft is not compared)' );
	assert_test( 'source_newer' === $ask( str_repeat( 'a', 64 ) ), 'a caller holding some other state is told the source is newer' );

	// Legacy shadow: no counterpart recorded, the real post is found through its uuid meta.
	update_post_meta( $c['shadow'], '_wpmmcc_shadow_local_id', 0 );
	assert_test( 'in_sync' === $ask( $fp_local ), 'without a recorded counterpart the real post is still found by its uuid, never the draft' );

	// Nothing real left: the entity is gone as far as this site can tell.
	delete_post_meta( $c['local'], '_wpmmcc_canonical_uuid' );
	assert_test( 'missing' === $ask( $fp_local ), 'with only the draft left the entity is missing, not "in sync with the draft"' );
}

function sd_scenario_inbound_meta_local_only(): void {
	echo "\n--- inbound_meta_local_only: a packet cannot write this site's review state ---\n";
	sd_reset();
	sd_peer( SD_PEER, 'lww' );

	$packet = sd_packet(
		'urn:uuid:entity-new',
		SD_PEER,
		'Plain post',
		'Plain body',
		2,
		array(
			'meta_fields' => array(
				SD_FLAG                        => 1,
				'_wpmmcc_conflict_origin_uuid' => 'urn:uuid:forged-origin',
				'_wpmmcc_shadow_local_id'      => 7,
				'_wpmmcc_canonical_uuid'       => 'urn:uuid:hijacked',
				'plain_custom_key'             => 'kept',
			),
		)
	);
	$res    = sd_push( $packet );
	$id     = (int) ( $res['target_id'] ?? 0 );

	assert_test( $id > 0, 'setup: the packet created a post' );
	assert_test( ! sd_flagged( $id ), 'the review flag is not written from a packet (the post would vanish from the catalog)' );
	assert_test( '' === (string) get_post_meta( $id, '_wpmmcc_conflict_origin_uuid', true ), 'the review origin is not written from a packet' );
	assert_test( '' === (string) get_post_meta( $id, '_wpmmcc_shadow_local_id', true ), 'the review counterpart is not written from a packet' );
	assert_test( 'urn:uuid:entity-new' === get_post_meta( $id, '_wpmmcc_canonical_uuid', true ), 'the post\'s identity is the packet guid, not a meta value' );
	assert_test( 'kept' === get_post_meta( $id, 'plain_custom_key', true ), 'ordinary meta still applies' );

	// The same peer's next revision is a clean progression: it rewrites the
	// SAME post and never creates a duplicate.
	$count = count( $GLOBALS['wp_mock_posts'] );
	$res2  = sd_push( sd_packet( 'urn:uuid:entity-new', SD_PEER, 'Plain post v2', 'Plain body v2', 3 ) );
	assert_test( 'success' === ( $res2['status'] ?? '' ) && $id === (int) ( $res2['target_id'] ?? 0 ), 'a clean progression updates the same post in place' );
	assert_test( 'Plain post v2' === get_post( $id )->post_title && 'Plain body v2' === get_post( $id )->post_content, 'the progression applies the new content' );
	assert_test( $count === count( $GLOBALS['wp_mock_posts'] ), 'no duplicate post is created' );
}

function sd_scenario_refresh_keeps_review(): void {
	echo "\n--- refresh_keeps_review: the peer's next revision cannot skip the review ---\n";
	$c = sd_conflict();

	$next = sd_packet( $c['uuid'], $c['peer'], 'Remote edit v2', 'Remote body v2', 5 );
	$res  = sd_push( $next );
	assert_test( 'conflict' === ( $res['status'] ?? '' ), 'the refresh is reported as a still-open conflict (got ' . ( $res['status'] ?? '?' ) . ')' );
	assert_test( $c['shadow'] === (int) ( $res['target_id'] ?? 0 ), 'it lands on the same shadow, no second draft is created' );

	$shadow = get_post( $c['shadow'] );
	assert_test( 'draft' === $shadow->post_status, 'the shadow is still a draft (it was published by the peer\'s next packet)' );
	assert_test( SD_PREFIX . 'Remote edit v2' === $shadow->post_title, 'the shadow shows the newest remote title, still behind the prefix' );
	assert_test( 'Remote body v2' === $shadow->post_content, 'the shadow holds the newest remote content' );
	assert_test( sd_flagged( $c['shadow'] ), 'the shadow is still in the review queue' );
	assert_test( $c['local'] === (int) get_post_meta( $c['shadow'], '_wpmmcc_shadow_local_id', true ), 'the recorded counterpart is still the local post, not the shadow itself' );
	assert_test( SD_PEER === get_post_meta( $c['shadow'], '_wpmmcc_conflict_origin_uuid', true ), 'the recorded origin is kept' );
	assert_test( 'Local edit' === get_post( $c['local'] )->post_title && 'publish' === get_post( $c['local'] )->post_status, 'the displaced local post is still untouched' );
	assert_test( $c['shadow'] === (int) sd_mapping( $c['uuid'], $c['peer'] )['local_object_id'], 'the mapping still points at the shadow' );
	assert_test( $next['source_fingerprint'] === sd_mapping( $c['uuid'], $c['peer'] )['content_checksum'], 'the mapping records the newest revision (the peer\'s next digest is in_sync)' );
	$flagged = array_filter( array_keys( $GLOBALS['wp_mock_posts'] ), 'sd_flagged' );
	assert_test( 1 === count( $flagged ), 'exactly one review draft exists' );
	assert_test( array() === sd_queue(), 'the refresh queued nothing' );

	// The peer's strategy changed since the shadow was staged: an open review
	// is still an open review.
	foreach ( array( 'source_wins', 'lww', 'merge', 'target_wins' ) as $i => $strategy ) {
		sd_set_strategy( $c['peer'], $strategy );
		$res = sd_push( sd_packet( $c['uuid'], $c['peer'], 'Rev ' . $strategy, 'Body ' . $strategy, 6 + $i ) );
		assert_test( 'draft' === get_post( $c['shadow'] )->post_status && sd_flagged( $c['shadow'] ), "strategy {$strategy}: the shadow stays a flagged draft" );
		assert_test( 'Local edit' === get_post( $c['local'] )->post_title, "strategy {$strategy}: the displaced local post is not overwritten" );
		assert_test( SD_PREFIX . 'Rev ' . $strategy === get_post( $c['shadow'] )->post_title, "strategy {$strategy}: the review draft follows the peer (got '" . get_post( $c['shadow'] )->post_title . "')" );
	}

	// A title that already carries the prefix (a legacy export that round-tripped) is not double-prefixed.
	sd_push( sd_packet( $c['uuid'], $c['peer'], SD_PREFIX . 'Echoed', 'Body', 20 ) );
	assert_test( SD_PREFIX . 'Echoed' === get_post( $c['shadow'] )->post_title, 'the prefix is applied once' );
}

function sd_scenario_trash_hits_shadow_only(): void {
	echo "\n--- trash_hits_shadow_only: the peer's trash / delete does not reach the real post ---\n";
	$c = sd_conflict();

	$res = sd_push( sd_packet( $c['uuid'], $c['peer'], 'x', 'x', 5, array( 'action' => 'trash', 'core_fields' => array() ) ) );
	assert_test( 'success' === ( $res['status'] ?? '' ), 'the trash packet is accepted' );
	assert_test( 'trash' === get_post( $c['shadow'] )->post_status, 'the review draft is trashed (the open review is moot)' );
	assert_test( 'publish' === get_post( $c['local'] )->post_status && 'Local edit' === get_post( $c['local'] )->post_title, 'the displaced real post is not trashed' );
	assert_test( array() === sd_queue(), 'the inbound trash echoed nothing to any peer' );

	$c = sd_conflict();
	sd_push( sd_packet( $c['uuid'], $c['peer'], 'x', 'x', 5, array( 'action' => 'delete', 'core_fields' => array() ) ) );
	assert_test( null === get_post( $c['shadow'] ), 'the review draft is deleted' );
	assert_test( null !== get_post( $c['local'] ) && 'Local edit' === get_post( $c['local'] )->post_title, 'the displaced real post is not deleted' );
	assert_test( array() === sd_queue(), 'the inbound delete echoed nothing to any peer' );
}

function sd_scenario_accept_remote(): void {
	echo "\n--- accept_remote: the promoted draft is ordinary content ---\n";
	$c = sd_conflict();

	// A second peer had mapped the same entity onto the local post before the
	// review opened; acceptance must not leave that stale row behind.
	sd_map( $c['uuid'], 'urn:uuid:peer-c', $c['local'] );

	$res = \WPMMCC\Sync\Shadow_Draft::accept_remote( $c['shadow'] );
	assert_test( ! is_wp_error( $res ), 'Accept Remote succeeds' );
	assert_test( array() !== sd_depths_for( 'update', $c['shadow'] ) && min( sd_depths_for( 'update', $c['shadow'] ) ) >= 1, 'the promotion update runs inside the ingestion scope' );

	$promoted = get_post( $c['shadow'] );
	assert_test( 'Remote edit' === $promoted->post_title, 'the review prefix is gone' );
	assert_test( 'publish' === $promoted->post_status, 'the promoted post adopts the displaced post\'s publish state' );
	assert_test( ! sd_flagged( $c['shadow'] ) && '' === (string) get_post_meta( $c['shadow'], '_wpmmcc_shadow_local_id', true ) && '' === (string) get_post_meta( $c['shadow'], '_wpmmcc_conflict_origin_uuid', true ), 'all review meta is cleared' );
	assert_test( array() === sd_queue(), 'the promotion itself echoed nothing (the content came from the peer)' );
	assert_test( 1 === count( sd_journal( 'conflict_accept_remote' ) ), 'the decision is journaled' );

	$local = get_post( $c['local'] );
	assert_test( null !== $local && 'Local edit' === $local->post_title && 'Body of Local edit' === $local->post_content, 'the displaced local post is kept, content untouched' );
	assert_test( '' === (string) get_post_meta( $c['local'], '_wpmmcc_canonical_uuid', true ), 'the displaced post no longer claims the entity uuid' );
	assert_test( array() === sd_mappings_for_local( $c['local'] ), 'no mapping row points at the displaced post any more (the second peer\'s included)' );

	wp_cache_flush();
	$digest                   = Reconciler::generate_digest( 100, 0, array( 'post' ) );
	$claims                   = array_values(
		array_filter(
			$digest,
			static function ( $d ) use ( $c ) {
				return $c['uuid'] === $d['canonical_uuid'];
			}
		)
	);
	assert_test( 1 === count( $claims ) && $c['shadow'] === (int) $claims[0]['local_id'], 'exactly one post answers to the entity uuid: the promoted one' );
	$others = array_values(
		array_filter(
			$digest,
			static function ( $d ) use ( $c ) {
				return $c['local'] === (int) $d['local_id'];
			}
		)
	);
	assert_test( 1 === count( $others ) && $c['uuid'] !== $others[0]['canonical_uuid'], 'the displaced post is catalogued as a separate entity of its own' );

	$packet = Packet::export_post( $c['shadow'] );
	assert_test( ! is_wp_error( $packet ) && $c['uuid'] === $packet['entity']['guid'], 'the promoted post exports under the entity uuid' );

	wp_update_post( array( 'ID' => $c['shadow'], 'post_content' => 'Edited after acceptance' ) );
	$queued = sd_queue();
	assert_test( 1 === count( $queued ) && $c['uuid'] === $queued[0]['canonical_uuid'], 'an edit of the promoted post is queued for the peers like any content' );
}

function sd_scenario_keep_local(): void {
	global $wpdb;
	echo "\n--- keep_local: the displaced post is the entity again ---\n";
	$c = sd_conflict();

	// Time has passed since the staging (the mapping was stamped then); the
	// resolution itself must become the new synced state.
	$wpdb->update(
		'wp_wpmmcc_cross_mappings',
		array( 'last_synced_at' => gmdate( 'Y-m-d H:i:s', time() - 7200 ) ),
		array(
			'canonical_uuid' => $c['uuid'],
			'peer_uuid'      => $c['peer'],
		)
	);

	$res = \WPMMCC\Sync\Shadow_Draft::keep_local( $c['shadow'] );
	assert_test( ! is_wp_error( $res ), 'Keep Local succeeds' );
	assert_test( null === get_post( $c['shadow'] ), 'the review draft is deleted' );
	assert_test( array() !== sd_depths_for( 'delete', $c['shadow'] ) && min( sd_depths_for( 'delete', $c['shadow'] ) ) >= 1, 'the staging draft is discarded inside the ingestion scope' );
	$map = sd_mapping( $c['uuid'], $c['peer'] );
	assert_test( $c['local'] === (int) $map['local_object_id'], 'the mapping points at the displaced post again' );
	assert_test( 4 === (int) $map['vector_clock'] && '' !== $map['content_checksum'], 'the mapping keeps the revision that was turned down (clock 4)' );
	assert_test( strtotime( (string) $map['last_synced_at'] ) >= time() - 30, 'the resolution time is stamped as the synced state (the kept edit is not drift)' );
	assert_test( array() === sd_queue(), 'discarding the draft queued no tombstone for the peer\'s post' );
	assert_test( 1 === count( sd_journal( 'conflict_keep_local' ) ), 'the decision is journaled' );
	assert_test( 'Local edit' === get_post( $c['local'] )->post_title && $c['uuid'] === get_post_meta( $c['local'], '_wpmmcc_canonical_uuid', true ), 'the local post is intact and still carries the uuid' );

	wp_cache_flush();
	$digest                   = Reconciler::generate_digest( 100, 0, array( 'post' ) );
	assert_test( 1 === count( $digest ) && $c['uuid'] === $digest[0]['canonical_uuid'] && $c['local'] === (int) $digest[0]['local_id'], 'the digest lists the local post under the entity uuid' );
}

function sd_scenario_resolve_needs_a_shadow(): void {
	echo "\n--- resolve_needs_a_shadow: the review actions cannot be aimed at ordinary posts ---\n";
	sd_reset();
	sd_local_post( SD_LOCAL, 'Ordinary', SD_UUID );

	foreach ( array( 'accept_remote', 'keep_local' ) as $action ) {
		$res = call_user_func( array( '\WPMMCC\Sync\Shadow_Draft', $action ), SD_LOCAL );
		assert_test( is_wp_error( $res ) && 'wpmmcc_not_a_shadow' === $res->get_error_code(), "{$action} refuses an ordinary post" );
		$res = call_user_func( array( '\WPMMCC\Sync\Shadow_Draft', $action ), 999999 );
		assert_test( is_wp_error( $res ) && 'wpmmcc_not_a_post' === $res->get_error_code(), "{$action} refuses a post that does not exist" );
	}
	assert_test( 'Ordinary' === get_post( SD_LOCAL )->post_title && null !== get_post( SD_LOCAL ), 'the ordinary post was not touched' );

	// A zero flag is not a review marker: the post stays an ordinary post.
	update_post_meta( SD_LOCAL, SD_FLAG, 0 );
	assert_test( ! sd_flagged( SD_LOCAL ), 'a zero flag is not a shadow marker' );
	$res = \WPMMCC\Sync\Shadow_Draft::accept_remote( SD_LOCAL );
	assert_test( is_wp_error( $res ) && 'wpmmcc_not_a_shadow' === $res->get_error_code(), 'a zero-flagged post cannot be resolved through the review actions' );
	delete_post_meta( SD_LOCAL, SD_FLAG );

	// Counterpart bookkeeping is defensive: a counterpart that is the shadow
	// itself, no longer exists, or is itself flagged resolves to "none".
	$c = sd_conflict();
	update_post_meta( $c['shadow'], '_wpmmcc_shadow_local_id', $c['shadow'] );
	assert_test( 0 === \WPMMCC\Sync\Shadow_Draft::entity_post_id( $c['shadow'] ), 'a counterpart that is the shadow itself resolves to none' );
	update_post_meta( $c['shadow'], '_wpmmcc_shadow_local_id', 999999 );
	assert_test( 0 === \WPMMCC\Sync\Shadow_Draft::entity_post_id( $c['shadow'] ), 'a counterpart that no longer exists resolves to none' );
	update_post_meta( $c['shadow'], '_wpmmcc_shadow_local_id', $c['local'] );
	update_post_meta( $c['local'], SD_FLAG, 1 );
	assert_test( 0 === \WPMMCC\Sync\Shadow_Draft::entity_post_id( $c['shadow'] ), 'a counterpart that is itself flagged resolves to none' );

	// Keep Local of a legacy shadow (no counterpart recorded) changes nothing.
	$c = sd_conflict();
	update_post_meta( $c['shadow'], '_wpmmcc_shadow_local_id', 0 );
	$res = \WPMMCC\Sync\Shadow_Draft::keep_local( $c['shadow'] );
	assert_test( is_wp_error( $res ) && 'wpmmcc_no_counterpart' === $res->get_error_code(), 'Keep Local of a legacy shadow is refused' );
	assert_test( null !== get_post( $c['shadow'] ) && $c['shadow'] === (int) sd_mapping( $c['uuid'], $c['peer'] )['local_object_id'], 'and deletes / re-points nothing' );

	// Accept Remote of a legacy shadow publishes it and detaches nothing.
	$res = \WPMMCC\Sync\Shadow_Draft::accept_remote( $c['shadow'] );
	assert_test( ! is_wp_error( $res ) && 'publish' === get_post( $c['shadow'] )->post_status, 'Accept Remote of a legacy shadow publishes it' );
	assert_test( $c['uuid'] === get_post_meta( $c['local'], '_wpmmcc_canonical_uuid', true ), 'a legacy shadow has no recorded counterpart to detach' );
}

function sd_scenario_accept_adopts_counterpart_status(): void {
	echo "\n--- accept_adopts_counterpart_status: the promoted draft takes the displaced post's state ---\n";
	sd_reset();
	sd_peer( SD_PEER, 'manual_review' );
	sd_local_post( SD_LOCAL, 'Local draft', SD_UUID, 600, 'draft' );
	sd_map( SD_UUID, SD_PEER, SD_LOCAL, 3, 3600 );

	$res    = sd_push( sd_packet( SD_UUID, SD_PEER, 'Remote edit', 'Remote body', 4 ) );
	$shadow = (int) ( $res['target_id'] ?? 0 );
	assert_test( 'conflict' === ( $res['status'] ?? '' ) && $shadow > 0 && SD_LOCAL !== $shadow, 'setup: the conflict is staged against a local draft' );

	$accepted = \WPMMCC\Sync\Shadow_Draft::accept_remote( $shadow );
	assert_test( ! is_wp_error( $accepted ), 'Accept Remote succeeds' );
	assert_test( 'draft' === get_post( $shadow )->post_status, 'the promoted post stays a draft like the displaced post, not blanket-published' );
	assert_test( 'draft' === get_post( SD_LOCAL )->post_status, 'the displaced draft is untouched' );
}

function sd_scenario_accept_detaches_only_matching_uuid(): void {
	echo "\n--- accept_detaches_only_matching_uuid: a re-homed post keeps its new identity ---\n";
	$c = sd_conflict();

	// The displaced post was re-homed onto another entity while the review was
	// open; acceptance must not strip an identity it no longer owns.
	update_post_meta( $c['local'], '_wpmmcc_canonical_uuid', 'urn:uuid:entity-elsewhere' );

	$res = \WPMMCC\Sync\Shadow_Draft::accept_remote( $c['shadow'] );
	assert_test( ! is_wp_error( $res ), 'Accept Remote succeeds' );
	assert_test( 'urn:uuid:entity-elsewhere' === (string) get_post_meta( $c['local'], '_wpmmcc_canonical_uuid', true ), 'the re-homed identity is not stripped' );
	assert_test( $c['uuid'] === (string) get_post_meta( $c['shadow'], '_wpmmcc_canonical_uuid', true ) && ! sd_flagged( $c['shadow'] ), 'the promoted post answers to the entity uuid' );
}

// ---------------------------------------------------------------------------
// Runner
// ---------------------------------------------------------------------------

$scenarios = array(
	'conflict_is_staged',
	'digest_skips_shadow',
	'export_refuses_shadow',
	'no_cdc_for_shadow',
	'pull_serves_real_entity',
	'verdict_uses_real_entity',
	'inbound_meta_local_only',
	'refresh_keeps_review',
	'trash_hits_shadow_only',
	'accept_remote',
	'keep_local',
	'resolve_needs_a_shadow',
	'accept_adopts_counterpart_status',
	'accept_detaches_only_matching_uuid',
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
	call_user_func( 'sd_scenario_' . $name );
}

echo "\n==================================================\n";
echo "PASS: All {$pass_count} Shadow Conflict tests passed successfully!\n";
echo "==================================================\n";
exit( 0 );
