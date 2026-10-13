<?php
/**
 * WPMMCC Reconciler & CDC Engine Unit Test (WP-A-06, doc 09 §4, doc 14 §6).
 *
 * Verifies fingerprint drift reconciliation verdicts (in_sync, missing,
 * source_newer, local_edited) and CDC queue state transitions.
 *
 * @package WPMMCC\Tests\Unit
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/support/class-mini-sql-wpdb.php';

use WPMMCC\Sync\Reconciler;

echo "=== Running Reconciler & CDC Engine Unit Test ===\n";

global $wpdb, $uuid_insync, $fp_insync, $uuid_drift, $fp_local_old, $peer_uuid;

$peer_uuid = wp_generate_uuid4();

// Mock mappings table rows in WPDB mock
$mappings_table = $wpdb->prefix . 'wpmmcc_cross_mappings';

$uuid_insync    = 'urn:uuid:insync-1111';
$fp_insync      = hash( 'sha256', 'content_insync' );

$uuid_drift     = 'urn:uuid:drift-2222';
$fp_source_new  = hash( 'sha256', 'content_source_new' );
$fp_local_old   = hash( 'sha256', 'content_local_old' );

$wpdb = new Mini_SQL_wpdb();
$wpdb->create_table( $mappings_table, array(
	'local_object_type' => 'post', 'local_object_id' => 0, 'peer_uuid' => '',
) );
$wpdb->insert( $mappings_table, array(
	'canonical_uuid' => $uuid_insync, 'peer_uuid' => $peer_uuid,
	'content_checksum' => $fp_insync, 'vector_clock' => 2,
) );
$wpdb->insert( $mappings_table, array(
	'canonical_uuid' => $uuid_drift, 'peer_uuid' => $peer_uuid,
	'content_checksum' => $fp_local_old, 'vector_clock' => 2,
) );

$fingerprints = array(
	array(
		'canonical_uuid'     => $uuid_insync,
		'source_fingerprint' => $fp_insync,
		'vector_clock'       => 2,
	),
	array(
		'canonical_uuid'     => $uuid_drift,
		'source_fingerprint' => $fp_source_new,
		'vector_clock'       => 3, // remote ahead
	),
	array(
		'canonical_uuid'     => 'urn:uuid:missing-9999',
		'source_fingerprint' => 'nonexistent_fp',
		'vector_clock'       => 1,
	),
);

$verdicts = Reconciler::reconcile_fingerprints( $fingerprints, $peer_uuid );

if ( 'in_sync' !== $verdicts[ $uuid_insync ] ) {
	echo "FAIL: Expected 'in_sync' for matching checksum, got " . ( $verdicts[ $uuid_insync ] ?? 'null' ) . "\n";
	exit( 1 );
}

if ( 'source_newer' !== $verdicts[ $uuid_drift ] ) {
	echo "FAIL: Expected 'source_newer' for remote clock 3 > local clock 2, got " . ( $verdicts[ $uuid_drift ] ?? 'null' ) . "\n";
	exit( 1 );
}

if ( 'missing' !== $verdicts['urn:uuid:missing-9999'] ) {
	echo "FAIL: Expected 'missing' for unmapped uuid, got " . ( $verdicts['urn:uuid:missing-9999'] ?? 'null' ) . "\n";
	exit( 1 );
}

// ============================================================================
// FL-14/FL-15/FL-16 regression (doc 24 §四.5): honest vector clock + unified
// fingerprint + mediated-topology fallback, proven as one lifecycle sequence.
//
//   save → ship → in_sync → edit → source_newer → re-ship → in_sync →
//   delete → missing
//
// Pre-fix this sequence is impossible on real sites: no save hook bumped the
// source clock (FL-14), digest fingerprints used a 2-key hash while packets
// used 7 core fields (FL-15), and reconcile matched mapping rows by the
// client-mediator uuid which never exists (FL-16 → everything 'missing').
// ============================================================================

echo "--- FL-14/15/16 lifecycle sequence ---\n";

global $wp_mock_posts, $wp_mock_meta, $wp_mock_options, $wp_mock_cache;

$seq_uuid = 'urn:uuid:seq-9001';

$wp_mock_posts[9001] = new WP_Post(
	array(
		'ID'             => 9001,
		'post_title'     => 'Sequence Post',
		'post_content'   => 'Content v1',
		'post_excerpt'   => 'Excerpt v1',
		'post_status'    => 'publish',
		'post_type'      => 'post',
		'post_name'      => 'sequence-post',
		'post_date_gmt'  => '2026-09-18 00:00:00',
		'menu_order'     => 0,
		'comment_status' => 'open',
		'ping_status'    => 'open',
	)
);
$wp_mock_meta[9001]         = array( '_wpmmcc_canonical_uuid' => $seq_uuid );
$wp_mock_options['wpmmcc_origin_uuid'] = 'urn:uuid:origin-seq';

$wpdb = new Mini_SQL_wpdb();
$wpdb->create_table( $mappings_table, array(
	'local_object_type' => 'post', 'local_object_id' => 0, 'peer_uuid' => '',
) );
$wpdb->create_table( 'wp_wpmmcc_journal', array() );
$wpdb->create_table( 'wp_wpmmcc_peers', array() );
$wpdb->insert( 'wp_wpmmcc_peers', array(
	'peer_uuid' => 'target-sequence', 'direction' => 'bidirectional', 'status' => 'active',
) );
wp_cache_flush();

function sequence_clock(): int {
	global $wpdb;
	return (int) $wpdb->get_var( "SELECT vector_clock FROM wp_wpmmcc_cross_mappings WHERE local_object_id = 9001 LIMIT 1" );
}

// Phase 1 — tracked save creates the self row (FL-14 bump, insert arm):
// no row → next = max(1,0)+1 = 2, canonical uuid resolved from the mapping.
WPMMCC\Sync\Event_Recorder::on_save_post( 9001, $wp_mock_posts[9001], true );
if ( 2 !== sequence_clock() ) {
	echo 'FAIL: Phase 1 — save must persist the self-row clock at 2 (got ' . sequence_clock() . ")\n";
	exit( 1 );
}
echo "PASS: Phase 1 — save creates self row at clock 2\n";

// Phase 2 — reads ship the tracked revision, never invent a mutation.
$packet1 = WPMMCC\Sync\Packet::export_post( 9001 );
if ( is_wp_error( $packet1 ) ) {
	echo 'FAIL: Phase 2 — export_post failed: ' . $packet1->get_error_message() . "\n";
	exit( 1 );
}
$shipped_clock = (int) ( $packet1['origin_context']['vector_clock']['urn:uuid:origin-seq'] ?? 0 );
if ( 2 !== $shipped_clock ) {
	echo "FAIL: Phase 2 — expected shipped clock 2, got {$shipped_clock}\n";
	exit( 1 );
}
if ( 2 !== sequence_clock() ) {
	echo "FAIL: Phase 2 — export_post changed the source revision\n";
	exit( 1 );
}
$packet_again = WPMMCC\Sync\Packet::export_post( 9001 );
if ( is_wp_error( $packet_again ) || $packet_again['source_fingerprint'] !== $packet1['source_fingerprint'] || 2 !== sequence_clock() ) {
	echo "FAIL: Phase 2 — repeated reads must preserve the source revision and fingerprint\n";
	exit( 1 );
}
echo "PASS: Phase 2 — repeated exports preserve clock 2 and fingerprint\n";

// Phase 3 — mediated reconcile rotation right after the ship: the client
// knows the shipped fingerprint; the source recomputes its CURRENT one with
// the packet-identical construction (FL-15) via the live-entity fallback
// (FL-16) → in_sync.
$known_fp = $packet1['source_fingerprint'];
$verdicts = Reconciler::reconcile_fingerprints(
	array(
		array(
			'canonical_uuid'     => $seq_uuid,
			'source_fingerprint' => $known_fp,
			'vector_clock'       => 2,
		),
	),
	'urn:uuid:client-mediator'
);
if ( 'in_sync' !== ( $verdicts[ $seq_uuid ] ?? '' ) ) {
	echo 'FAIL: Phase 3 — post-ship reconcile must be in_sync (got ' . ( $verdicts[ $seq_uuid ] ?? 'null' ) . ")\n";
	exit( 1 );
}
echo "PASS: Phase 3 — post-ship reconcile verdicts in_sync\n";

// Phase 4 — out-of-band edit (second save coalesces the CDC journal entry;
// the clock bump must fire BEFORE the debounce return — FL-14 placement).
$wp_mock_posts[9001]->post_content = 'Content v2';
WPMMCC\Sync\Event_Recorder::on_save_post( 9001, $wp_mock_posts[9001], true );
if ( 3 !== sequence_clock() || 1 !== count( $wpdb->rows( 'wp_wpmmcc_journal' ) ) ) {
	echo 'FAIL: Phase 4 — coalesced save must bump the clock to 3 without duplicating the queue row (got ' . sequence_clock() . ")\n";
	exit( 1 );
}
echo "PASS: Phase 4 — coalesced edit save still bumps clock to 3\n";

// Phase 5 — reconcile with the STALE client fingerprint: content and clock
// both moved → source_newer (the propagation verdict; pre-fix this was
// 'missing' → mass tombstones).
$verdicts = Reconciler::reconcile_fingerprints(
	array(
		array(
			'canonical_uuid'     => $seq_uuid,
			'source_fingerprint' => $known_fp,
			'vector_clock'       => 2,
		),
	),
	'urn:uuid:client-mediator'
);
if ( 'source_newer' !== ( $verdicts[ $seq_uuid ] ?? '' ) ) {
	echo 'FAIL: Phase 5 — edited entity must verdict source_newer (got ' . ( $verdicts[ $seq_uuid ] ?? 'null' ) . ")\n";
	exit( 1 );
}
echo "PASS: Phase 5 — edit verdicts source_newer\n";

// Phase 6 — client re-pull preserves the edited revision at clock 3.
$packet2 = WPMMCC\Sync\Packet::export_post( 9001 );
if ( is_wp_error( $packet2 ) ) {
	echo 'FAIL: Phase 6 — export_post failed: ' . $packet2->get_error_message() . "\n";
	exit( 1 );
}
if ( 3 !== (int) ( $packet2['origin_context']['vector_clock']['urn:uuid:origin-seq'] ?? 0 ) ) {
	echo "FAIL: Phase 6 — expected re-ship clock 3\n";
	exit( 1 );
}
if ( 3 !== sequence_clock() ) {
	echo 'FAIL: Phase 6 — re-ship must preserve clock 3 (got ' . sequence_clock() . ")\n";
	exit( 1 );
}
echo "PASS: Phase 6 — re-ship carries clock 3 without advancing it\n";

// Phase 7 — next rotation with the refreshed fingerprint → in_sync (loop
// convergence: source_newer re-pulls must not repeat forever).
$verdicts = Reconciler::reconcile_fingerprints(
	array(
		array(
			'canonical_uuid'     => $seq_uuid,
			'source_fingerprint' => $packet2['source_fingerprint'],
			'vector_clock'       => 3,
		),
	),
	'urn:uuid:client-mediator'
);
if ( 'in_sync' !== ( $verdicts[ $seq_uuid ] ?? '' ) ) {
	echo 'FAIL: Phase 7 — post-reconcile-ship rotation must converge to in_sync (got ' . ( $verdicts[ $seq_uuid ] ?? 'null' ) . ")\n";
	exit( 1 );
}
echo "PASS: Phase 7 — rotation converges to in_sync\n";

// Phase 8 — source deletes the post: fallback resolves the id, get_post
// misses (strict mock mode = real WordPress null) → 'missing' → the client
// tombstones at the target (deletion path).
global $wp_mock_posts_strict;
$wp_mock_posts_strict = true;
unset( $wp_mock_posts[9001] );
WPMMCC\Sync\Event_Recorder::on_delete_post( 9001 );
$verdicts = Reconciler::reconcile_fingerprints(
	array(
		array(
			'canonical_uuid'     => $seq_uuid,
			'source_fingerprint' => $packet2['source_fingerprint'],
			'vector_clock'       => 3,
		),
	),
	'urn:uuid:client-mediator'
);
if ( 'missing' !== ( $verdicts[ $seq_uuid ] ?? '' ) ) {
	echo 'FAIL: Phase 8 — deleted entity must verdict missing (got ' . ( $verdicts[ $seq_uuid ] ?? 'null' ) . ")\n";
	exit( 1 );
}
echo "PASS: Phase 8 — deletion verdicts missing\n";

echo "PASS: Reconciler unit tests passed!\n";
exit( 0 );
