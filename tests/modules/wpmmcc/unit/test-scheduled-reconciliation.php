<?php
/**
 * WPMMCC Scheduled Reconciliation & Self-Healing Unit Test (WP-A-09, doc 09 §4).
 *
 * Validates:
 * 1. WordPress Cron registration (wpmmcc_cron_reconcile_check)
 * 2. Keyset pagination and digest generation (Reconciler::generate_digest)
 * 3. 4-state drift classification: in_sync, missing, source_newer, local_edited
 * 4. Automatic self-healing reconciliation loop (Reconciler::run_scheduled_reconcile)
 * 5. Journal logging of reconciliation summaries
 * 6. Deactivator cleanup of scheduled cron event
 *
 * @package WPMMCC\Tests\Unit
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

use WPMMCC\Sync\Reconciler;
use WPMMCC\Sync\Event_Recorder;
use WPMMCC\Activator;
use WPMMCC\Deactivator;
use WPMMCC\REST\Rest_Reconcile;

echo "=== Running Scheduled Reconciliation & Self-Healing Unit Test ===\n";

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

global $wpdb;

// -------------------------------------------------------------
// Test 1: Cron Registration & Activation
// -------------------------------------------------------------
echo "\n--- Testing Cron Registration & Activation ---\n";

// Run activation
Activator::activate();

$scheduled_time = wp_next_scheduled( 'wpmmcc_cron_reconcile_check' );
assert_test( false !== $scheduled_time, 'wpmmcc_cron_reconcile_check is scheduled upon plugin activation' );

// -------------------------------------------------------------
// Test 2: Keyset Cursor Digest Generation
// -------------------------------------------------------------
echo "\n--- Testing Keyset Cursor Digest Generation ---\n";

// Mock wpdb results for posts
$mock_posts = array(
	(object) array(
		'ID'                => 10,
		'post_title'        => 'Product Alpha',
		'post_content'      => 'Content of Product Alpha',
		'post_status'       => 'publish',
		'post_type'         => 'product',
		'post_modified_gmt' => '2026-09-16 02:00:00',
	),
	(object) array(
		'ID'                => 20,
		'post_title'        => 'Course Beta',
		'post_content'      => 'Content of Course Beta',
		'post_status'       => 'publish',
		'post_type'         => 'course',
		'post_modified_gmt' => '2026-09-16 03:00:00',
	),
	(object) array(
		'ID'                => 30,
		'post_title'        => 'Old Article',
		'post_content'      => 'Content of Old Article',
		'post_status'       => 'trash',
		'post_type'         => 'post',
		'post_modified_gmt' => '2026-09-16 01:00:00',
	),
);

$wpdb->results_callback = function ( $query ) use ( $mock_posts ) {
	if ( strpos( $query, 'SELECT ID, post_title' ) !== false ) {
		if ( preg_match( "/post_type = '([^']+)'/", $query, $match ) ) {
			$type = $match[1];
			return array_values(
				array_filter(
					$mock_posts,
					static function ( $row ) use ( $type ) {
						return isset( $row->post_type ) && $row->post_type === $type;
					}
				)
			);
		}
		return $mock_posts;
	}
	if ( strpos( $query, 'wpmmcc_peers' ) !== false ) {
		return array(
			array(
				'peer_uuid'         => 'peer-target-uuid-001',
				'peer_url'          => 'https://site-b.example.com',
				'status'            => 'active',
				'conflict_strategy' => 'lww',
			),
		);
	}
	return array();
};

$digest = Reconciler::generate_digest( 10, 0, array( 'post', 'product', 'course' ) );

assert_test( 3 === count( $digest ), 'Digest generation returned 3 entities' );
assert_test( ! empty( $digest[0]['canonical_uuid'] ), 'Digest item 0 has canonical_uuid' );
assert_test( ! empty( $digest[0]['source_fingerprint'] ), 'Digest item 0 has source_fingerprint' );
assert_test( 64 === strlen( $digest[0]['source_fingerprint'] ), 'Source fingerprint is a valid 64-char SHA256' );
assert_test( 'trash' === $digest[2]['post_status'], 'Trashed post correctly identified in digest' );

// -------------------------------------------------------------
// Test 3: 4-State Drift Classification
// -------------------------------------------------------------
echo "\n--- Testing 4-State Drift Classification ---\n";

$target_peer_uuid = 'peer-target-uuid-001';

// Setup row callback to simulate local cross_mappings. The mappings belong to
// the target peer: a lookup under any other peer finds nothing.
$wpdb->row_callback = function ( $query ) use ( $target_peer_uuid ) {
	if ( false === strpos( $query, "'" . $target_peer_uuid . "'" ) ) {
		return null;
	}
	if ( strpos( $query, 'uuid_in_sync' ) !== false ) {
		return (object) array(
			'canonical_uuid'   => 'uuid_in_sync',
			'content_checksum' => 'fp_match_123',
			'vector_clock'     => 5,
		);
	}
	if ( strpos( $query, 'uuid_drifted_remote_newer' ) !== false ) {
		return (object) array(
			'canonical_uuid'   => 'uuid_drifted_remote_newer',
			'content_checksum' => 'fp_old_local',
			'vector_clock'     => 2, // Remote will be 4
		);
	}
	if ( strpos( $query, 'uuid_local_edited' ) !== false ) {
		return (object) array(
			'canonical_uuid'   => 'uuid_local_edited',
			'content_checksum' => 'fp_local_diverged',
			'vector_clock'     => 5, // Remote is 3
		);
	}
	// uuid_missing returns null
	return null;
};

$remote_batch = array(
	array(
		'canonical_uuid'     => 'uuid_in_sync',
		'source_fingerprint' => 'fp_match_123',
		'vector_clock'       => 5,
	),
	array(
		'canonical_uuid'     => 'uuid_drifted_remote_newer',
		'source_fingerprint' => 'fp_new_remote',
		'vector_clock'       => 4,
	),
	array(
		'canonical_uuid'     => 'uuid_local_edited',
		'source_fingerprint' => 'fp_remote_diverged',
		'vector_clock'       => 3,
	),
	array(
		'canonical_uuid'     => 'uuid_missing_entity',
		'source_fingerprint' => 'fp_unmapped',
		'vector_clock'       => 1,
	),
);

$verdicts = Reconciler::reconcile_fingerprints( $remote_batch, $target_peer_uuid );

assert_test( 'in_sync' === ( $verdicts['uuid_in_sync'] ?? '' ), 'Matching checksum classified as in_sync' );
assert_test( 'source_newer' === ( $verdicts['uuid_drifted_remote_newer'] ?? '' ), 'Higher remote clock classified as source_newer' );
assert_test( 'local_edited' === ( $verdicts['uuid_local_edited'] ?? '' ), 'Local diverged entity classified as local_edited (ADR-4)' );
assert_test( 'missing' === ( $verdicts['uuid_missing_entity'] ?? '' ), 'Unmapped remote entity classified as missing' );

// -------------------------------------------------------------
// Test 4: REST Reconcile Digest Controller
// -------------------------------------------------------------
echo "\n--- Testing REST Reconcile Digest Controller ---\n";

$reconcile_controller = new Rest_Reconcile();
$req = new \WP_REST_Request( 'POST', '/wpmmcc/v1/sync/reconcile-digest' );
$req->set_header( 'x-wpmmcc-site-uuid', $target_peer_uuid );
$req->set_body( json_encode( array( 'fingerprints' => $remote_batch ) ) );

$resp = $reconcile_controller->handle_reconcile( $req );
assert_test( ! is_wp_error( $resp ), 'REST Reconcile controller processed request successfully' );
assert_test( 200 === $resp->get_status(), 'REST Reconcile controller returned HTTP 200' );
$resp_data = $resp->get_data();
assert_test( isset( $resp_data['data']['verdicts'] ), 'Response contains verdicts mapping' );
assert_test( 4 === count( $resp_data['data']['verdicts'] ), 'Response verdicts contains all 4 checked entities' );
// The route judges against the mappings of the peer named in the request
// envelope, so its answer is the one Reconciler gives for that peer.
assert_test( $verdicts === $resp_data['data']['verdicts'], 'The route answers with the verdicts of the peer named in the envelope' );

// -------------------------------------------------------------
// Test 5: Full Scheduled Reconcile & Self-Healing Execution
// -------------------------------------------------------------
echo "\n--- Testing Full Scheduled Reconcile Execution ---\n";

$summary = Reconciler::run_scheduled_reconcile();

assert_test( is_array( $summary ), 'run_scheduled_reconcile returns structured summary' );
assert_test( $summary['peers_scanned'] >= 1, 'Reconciliation scanned active peers' );
assert_test( $summary['trashed_synced'] >= 1, 'Trashed posts counted for trash alignment' );
assert_test( false !== strpos( $wpdb->last_query, 'reconcile_summary' ), 'Summary journal record inserted' );

// -------------------------------------------------------------
// Test 6: Deactivation Cleanup
// -------------------------------------------------------------
echo "\n--- Testing Deactivation Cleanup ---\n";

Deactivator::deactivate();
$scheduled_after = wp_next_scheduled( 'wpmmcc_cron_reconcile_check' );
assert_test( false === $scheduled_after, 'Scheduled reconciliation cron is unscheduled on plugin deactivation' );

// Reset callbacks
$wpdb->row_callback = null;
$wpdb->results_callback = null;

echo "\n==================================================\n";
echo "PASS: All {$pass_count} Scheduled Reconciliation tests passed successfully!\n";
echo "==================================================\n";
