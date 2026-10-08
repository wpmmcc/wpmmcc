<?php
/**
 * WPMMCC Conflict Arbiter Unit Test (WP-A-10, doc 12/ADR-6).
 *
 * Verifies conflict detection, LWW, Source/Target Dominance,
 * and Shadow Draft Review Mode for concurrent edits.
 *
 * @package WPMMCC\Tests\Unit
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

use WPMMCC\Sync\Arbiter;

echo "=== Running Conflict Arbiter Unit Test (WP-A-10) ===\n";

$origin_uuid = wp_generate_uuid4();
$now         = time();

$packet = array(
	'origin_context' => array(
		'origin_site_uuid'   => $origin_uuid,
		'vector_clock'       => array( $origin_uuid => 5 ),
		'dispatch_timestamp' => $now + 10,
	),
);

// 1. Initial Creation (no existing mapping)
$v1 = Arbiter::arbitrate( $packet, null, null, 'lww' );
if ( 'overwrite' !== $v1['action'] ) {
	echo "FAIL: Initial creation must return action 'overwrite'\n";
	exit( 1 );
}

// 2. Clean Progression (no local edits since sync)
$mapping_clean = (object) array(
	'vector_clock'   => 4,
	'last_synced_at' => gmdate( 'Y-m-d H:i:s', $now ),
);
$post_clean = (object) array(
	'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', $now - 60 ), // modified before sync
);

$v2 = Arbiter::arbitrate( $packet, $mapping_clean, $post_clean, 'lww' );
if ( 'overwrite' !== $v2['action'] ) {
	echo "FAIL: Clean progression must return action 'overwrite'\n";
	exit( 1 );
}

// 3. Concurrent Local Edit Detected (local modified 10s AFTER last sync)
$mapping_conflict = (object) array(
	'vector_clock'   => 4,
	'last_synced_at' => gmdate( 'Y-m-d H:i:s', $now - 100 ),
);
$post_conflict = (object) array(
	'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', $now - 50 ), // local drift!
);

// 3a: Source Dominant Policy
$v_src = Arbiter::arbitrate( $packet, $mapping_conflict, $post_conflict, 'source_dominant' );
if ( 'overwrite' !== $v_src['action'] || 'source_dominant_policy' !== $v_src['reason'] ) {
	echo "FAIL: source_dominant policy must overwrite\n";
	exit( 1 );
}

// 3b: Target Dominant Policy
$v_tgt = Arbiter::arbitrate( $packet, $mapping_conflict, $post_conflict, 'target_dominant' );
if ( 'skip' !== $v_tgt['action'] || 'target_edits_preserved' !== $v_tgt['reason'] ) {
	echo "FAIL: target_dominant policy must skip\n";
	exit( 1 );
}

// 3c: Manual Review Mode -> Shadow Draft
$v_rev = Arbiter::arbitrate( $packet, $mapping_conflict, $post_conflict, 'manual_review' );
if ( 'shadow_draft' !== $v_rev['action'] || 'conflict_divergence_created_shadow_draft' !== $v_rev['reason'] ) {
	echo "FAIL: manual_review policy must return 'shadow_draft'\n";
	exit( 1 );
}

// 3d: LWW when source is newer
$v_lww_src = Arbiter::arbitrate( $packet, $mapping_conflict, $post_conflict, 'lww' );
if ( 'overwrite' !== $v_lww_src['action'] ) {
	echo "FAIL: LWW must overwrite when source timestamp is newer\n";
	exit( 1 );
}

// 3e: LWW when target is newer
$packet_older = array(
	'origin_context' => array(
		'origin_site_uuid'   => $origin_uuid,
		'vector_clock'       => array( $origin_uuid => 3 ),
		'dispatch_timestamp' => $now - 80, // older than local edit at $now - 50
	),
);
$v_lww_tgt = Arbiter::arbitrate( $packet_older, $mapping_conflict, $post_conflict, 'lww' );
if ( 'skip' !== $v_lww_tgt['action'] ) {
	echo "FAIL: LWW must skip when target edit is newer\n";
	exit( 1 );
}

// 3f: Field-Ownership Merge (doc 03 §2.2, T-3.4) — verdict shape
$v_merge = Arbiter::arbitrate( $packet, $mapping_conflict, $post_conflict, 'merge' );
if ( 'merge' !== $v_merge['action'] || 'field_ownership_merge' !== $v_merge['reason'] ) {
	echo "FAIL: merge policy must return action 'merge' with reason 'field_ownership_merge'\n";
	exit( 1 );
}
if ( true !== $v_merge['local_drift'] ) {
	echo "FAIL: merge verdict must flag local_drift for the materializer\n";
	exit( 1 );
}

// 3g: All conflict verdicts carry local_drift=true; clean path false.
if ( true !== $v_src['local_drift'] || false !== $v2['local_drift'] ) {
	echo "FAIL: verdict local_drift flags are inconsistent\n";
	exit( 1 );
}

// 3h: X-1 (tasks/5.3falsh2/12 批 B) — canonical five-value vocabulary:
//     source_wins/target_wins reach the same verdicts as their legacy
//     twins, and normalize_strategy() maps every historical vocabulary.
$v_sw = Arbiter::arbitrate( $packet, $mapping_conflict, $post_conflict, 'source_wins' );
if ( 'overwrite' !== $v_sw['action'] || 'source_dominant_policy' !== $v_sw['reason'] ) {
	echo "FAIL: source_wins (canonical) must behave as source_dominant\n";
	exit( 1 );
}
$v_tw = Arbiter::arbitrate( $packet, $mapping_conflict, $post_conflict, 'target_wins' );
if ( 'skip' !== $v_tw['action'] || 'target_edits_preserved' !== $v_tw['reason'] ) {
	echo "FAIL: target_wins (canonical) must behave as target_dominant\n";
	exit( 1 );
}
if ( 'source_wins' !== Arbiter::normalize_strategy( 'source_dominant' )
	|| 'source_wins' !== Arbiter::normalize_strategy( 'origin_wins' )
	|| 'source_wins' !== Arbiter::normalize_strategy( ' Source_Wins ' )
	|| 'target_wins' !== Arbiter::normalize_strategy( 'target_dominant' )
	|| 'manual_review' !== Arbiter::normalize_strategy( 'manual' )
	|| 'lww' !== Arbiter::normalize_strategy( 'newest_wins' )
	|| 'lww' !== Arbiter::normalize_strategy( '' )
	|| 'merge' !== Arbiter::normalize_strategy( 'merge' ) ) {
	echo "FAIL: normalize_strategy must map all historical vocabularies onto the canonical five\n";
	exit( 1 );
}

// 4. Merge materialization rules (Rest_Push delta semantics, T-3.4):
//    changed price lands, untouched copy stays local, protected fields never
//    take remote writes even when declared as changed.
use WPMMCC\REST\Rest_Push;

$push = new Rest_Push();
$ref  = new ReflectionMethod( $push, 'materialize_entity' );
$ref->setAccessible( true );

// Local target: published product whose copy was edited locally after sync.
global $wp_mock_posts;
$local_id = wp_insert_post( array( 'post_title' => 'Local Title', 'post_content' => 'LOCAL EDITED COPY', 'post_status' => 'publish' ) );
update_post_meta( $local_id, '_price', '10.00' );
// Local edit happened AFTER the last sync (drift): 50s ago vs sync 100s ago.
$wp_mock_posts[ $local_id ]->post_modified_gmt = gmdate( 'Y-m-d H:i:s', $now - 50 );

$mapping_row = (object) array(
	'canonical_uuid'      => 'urn:uuid:merge-test-1',
	'local_object_type'   => 'post',
	'local_object_id'     => $local_id,
	'peer_uuid'           => $origin_uuid,
	'remote_object_id'    => 42,
	'remote_permalink'    => '',
	'content_checksum'    => str_repeat( 'a', 64 ),
	'vector_clock'        => 4,
	'last_sync_direction' => 'inbound',
	'last_synced_at'      => gmdate( 'Y-m-d H:i:s', $now - 100 ),
);

// Serve the mapping row for materialize's get_row lookup.
global $wpdb;
$wpdb->row_callback = function ( $sql ) use ( $mapping_row ) {
	if ( false !== strpos( (string) $sql, 'merge-test-1' ) ) {
		return $mapping_row;
	}
	return null;
};

$merge_packet = array(
	'packet_id'      => 'pkt_merge_test',
	'origin_context' => array(
		'origin_site_uuid'   => $origin_uuid,
		'origin_site_url'    => 'http://origin.example',
		'origin_blog_id'     => 1,
		'origin_lang'        => 'en_US',
		'vector_clock'       => array( $origin_uuid => 5 ),
		'hop_count'          => 1,
		'dispatch_timestamp' => $now + 10,
	),
	'action'         => 'upsert',
	'sync_mode'      => 'sync_only',
	'target_lang'    => 'zh_CN',
	'source_fingerprint' => str_repeat( 'b', 64 ),
	'entity'         => array(
		'guid'          => 'urn:uuid:merge-test-1',
		'object_type'   => 'post',
		'subtype'       => 'post',
		'source_id'     => 42,
		'slug'          => 'remote-slug',
		'status'        => 'draft',
		'core_fields'   => array(
			'post_title'   => 'Remote Title',
			'post_content' => 'REMOTE FULL COPY',
			'post_excerpt' => 'Remote excerpt',
		),
		'meta_fields'   => array( '_price' => '99.00' ),
		'changed_fields' => array( '_price' ),
	),
);

$merge_result = $ref->invoke( $push, $merge_packet, $merge_packet['entity'], 'upsert', 'urn:uuid:merge-test-1', $origin_uuid, 'merge' );
if ( is_wp_error( $merge_result ) ) {
	echo 'FAIL: merge materialization errored: ' . $merge_result->get_error_message() . "\n";
	exit( 1 );
}
if ( 'field_ownership_merge' !== ( $merge_result['reason'] ?? '' ) ) {
	echo "FAIL: merge materialization must report the field_ownership_merge reason\n";
	exit( 1 );
}
if ( (int) ( $merge_result['local_id'] ?? 0 ) !== $local_id ) {
	echo "FAIL: merge must update the mapped local post, not create a new one\n";
	exit( 1 );
}

$merged = get_post( $local_id );
// Price (declared changed) landed from remote:
if ( '99.00' !== (string) get_post_meta( $local_id, '_price', true ) ) {
	echo "FAIL: declared-changed meta field (_price) must take the remote value\n";
	exit( 1 );
}
// Copy (NOT declared changed, locally drifted) must survive:
if ( 'LOCAL EDITED COPY' !== $merged->post_content ) {
	echo "FAIL: undeclared field (post_content) must keep the local value under delta merge\n";
	exit( 1 );
}
// Title not declared -> local title preserved:
if ( 'Local Title' !== $merged->post_title ) {
	echo "FAIL: undeclared field (post_title) must keep the local value under delta merge\n";
	exit( 1 );
}
// post_status is protected by default -> stays publish even though remote said draft:
if ( 'publish' !== $merged->post_status ) {
	echo "FAIL: protected field (post_status) must never take a remote value under merge\n";
	exit( 1 );
}

echo "PASS: Conflict Arbiter unit tests passed (incl. field-ownership merge)!\n";
exit( 0 );
