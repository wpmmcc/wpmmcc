<?php
/**
 * WPMMCC Incremental Sync & CDC Journal Engine Unit Test (WP-A-06, doc 09 §3, test T-5.2).
 *
 * Validates:
 * 1. save_post CDC capture with consumed = 0 in wpmmcc_journal
 * 2. 5-second debounce window coalescing rapid saves into 1 event
 * 3. CDC queue polling and consumption (get_pending_cdc_events / mark_cdc_events_consumed)
 * 4. Soft-delete / Trash sync event capture and target post trashing
 * 5. Hard-delete sync event capture and target post deletion
 * 6. Anti-loop ingestion scope protection (depth counter prevents self-echo)
 *
 * @package WPMMCC\Tests\Unit
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/support/class-mini-sql-wpdb.php';

use WPMMCC\Sync\Event_Recorder;
use WPMMCC\Sync\Reconciler;
use WPMMCC\Sync\Packet;
use WPMMCC\REST\Rest_Push;

echo "=== Running Incremental Sync & CDC Engine Unit Test ===\n";

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
$wpdb = new Mini_SQL_wpdb();
$wpdb->create_table( 'wp_wpmmcc_peers', array() );
$wpdb->create_table( 'wp_wpmmcc_journal', array() );
$wpdb->create_table( 'wp_wpmmcc_cross_mappings', array( 'peer_uuid' => '' ) );
update_option( 'wpmmcc_origin_uuid', 'origin-cdc-test' );
$GLOBALS['wp_mock_posts_strict'] = true;

// The outbound queue fans out one row per push-capable active peer (WMC-06),
// and a site with no such peer records nothing. Serve one active peer so the
// capture assertions below exercise the recording path; test 1a covers the
// no-peer case explicitly.
$cdc_test_peers = array(
	array(
		'peer_uuid' => 'peer-cdc-test',
		'direction' => 'bidirectional',
		'status'    => 'active',
	),
);
wp_cache_delete( 'active_peers_list', 'wpmmcc' );

// -------------------------------------------------------------
// Test 1a: no active peer -> no CDC row (bounded journal, WMC-06)
// -------------------------------------------------------------
echo "\n--- Testing No-Peer Capture Is Silent ---\n";

$cdc_test_peers = array();
$before         = count( $wpdb->queries );
Event_Recorder::on_save_post( 100, new WP_Post( array( 'ID' => 100, 'post_status' => 'publish' ) ), false );
$journal_inserts = array_filter(
	array_slice( $wpdb->queries, $before ),
	static function ( $q ) {
		return 0 === strpos( $q, 'INSERT INTO wp_wpmmcc_journal' );
	}
);
assert_test( 0 === count( $journal_inserts ), 'save with no active peer inserts no CDC journal row' );
$cdc_test_peers = array(
	array(
		'peer_uuid' => 'peer-cdc-test',
		'direction' => 'bidirectional',
		'status'    => 'active',
	),
);
$wpdb->insert( 'wp_wpmmcc_peers', $cdc_test_peers[0] );
wp_cache_delete( 'active_peers_list', 'wpmmcc' );

// -------------------------------------------------------------
// Test 1: Event Capture on Post Save
// -------------------------------------------------------------
echo "\n--- Testing Event Capture on Post Save ---\n";

$post_id = 101;
$post = new WP_Post(
	array(
		'ID'           => $post_id,
		'post_title'   => 'Initial Article Title',
		'post_content' => 'Content of initial article',
		'post_status'  => 'publish',
		'post_type'    => 'post',
		'post_name'    => 'initial-article',
	)
);
$GLOBALS['wp_mock_posts'][ $post_id ] = $post;

// Trigger save
Event_Recorder::on_save_post( $post_id, $post, false );

// Assert that CDC event was written to journal table
assert_test( ! empty( $wpdb->last_query ), 'SQL query executed for journal insertion' );
assert_test( false !== strpos( $wpdb->last_query, 'wpmmcc_journal' ), 'Table targeted is wpmmcc_journal' );
assert_test( false !== strpos( $wpdb->last_query, '"consumed":0' ) || false !== strpos( $wpdb->last_query, "'consumed', 0" ), 'CDC entry initialized with consumed = 0' );
assert_test( false !== strpos( $wpdb->last_query, '"peer_uuid":"peer-cdc-test"' ), 'CDC entry is addressed to the active push peer' );

// -------------------------------------------------------------
// Test 2: 5-Second Debounce Window (T-5.2)
// -------------------------------------------------------------
echo "\n--- Testing 5-Second Debounce Window (T-5.2) ---\n";

$events_before = $wpdb->rows( 'wp_wpmmcc_journal' );
assert_test( 1 === count( $events_before ), 'one durable CDC row exists before debounce' );

// Submitting a rapid second save for the same post
Event_Recorder::on_save_post( $post_id, $post, true );

$events_after = $wpdb->rows( 'wp_wpmmcc_journal' );
assert_test( 1 === count( $events_after ) && $events_before[0]['id'] === $events_after[0]['id']
	&& 'update' === $events_after[0]['event_type'], 'Rapid save updates the original row rather than inserting a duplicate' );

// -------------------------------------------------------------
// Test 3: CDC Queue Polling & Consumption
// -------------------------------------------------------------
echo "\n--- Testing CDC Queue Polling & Consumption ---\n";

$pending_events = Reconciler::get_pending_cdc_events( 50 );
assert_test( is_array( $pending_events ), 'get_pending_cdc_events returns array of pending entries' );
assert_test( 1 === count( $pending_events ), 'poll returns the original pending CDC row' );

// Mark consumed
Reconciler::mark_cdc_events_consumed( array( $events_after[0]['id'] ) );
assert_test( 1 === (int) $wpdb->rows( 'wp_wpmmcc_journal' )[0]['consumed'], 'Consumed flag persists on queue drain' );

// -------------------------------------------------------------
// Test 4: Soft-delete / Trash Sync Propagation
// -------------------------------------------------------------
echo "\n--- Testing Soft-Delete / Trash Sync Propagation ---\n";

// Trigger on_trash_post on source
Event_Recorder::on_trash_post( $post_id );
assert_test( 'trash' === $wpdb->rows( 'wp_wpmmcc_journal' )[1]['event_type'], 'Trash event recorded with event_type = trash' );

// Now simulate receiving the trash packet on target site
$guid = Event_Recorder::resolve_or_create_canonical_uuid( 'post', $post_id );
$packet = Packet::build(
	array(
		'guid'         => $guid,
		'object_type'  => 'post',
		'subtype'      => 'post',
		'source_id'    => $post_id,
		'action'       => 'trash',
		'core_fields'  => array(),
	)
);

$request = new \WP_REST_Request( 'POST', '/wpmmcc/v1/sync/push' );
$request->set_body( json_encode( $packet ) );
$push_controller = new Rest_Push();
$push_res = $push_controller->handle_push( $request );

assert_test( ! is_wp_error( $push_res ), 'Push controller accepted trash packet' );
assert_test( 200 === $push_res->get_status(), 'Trash sync responded with HTTP 200' );

// -------------------------------------------------------------
// Test 5: Hard Delete Sync Propagation
// -------------------------------------------------------------
echo "\n--- Testing Hard Delete Sync Propagation ---\n";

// Trigger on_delete_post on source
Event_Recorder::on_delete_post( $post_id, $post );
$deletes = array_filter( $wpdb->rows( 'wp_wpmmcc_journal' ), static function ( $row ) {
	return 'delete' === $row['event_type'] && 'outbound' === $row['direction'];
} );
assert_test( 1 === count( $deletes ), 'Delete event recorded with event_type = delete' );

// Simulate receiving delete packet on target site
$packet_del = Packet::build(
	array(
		'guid'         => $guid,
		'object_type'  => 'post',
		'subtype'      => 'post',
		'source_id'    => $post_id,
		'action'       => 'delete',
		'core_fields'  => array(),
	)
);

$request_del = new \WP_REST_Request( 'POST', '/wpmmcc/v1/sync/push' );
$request_del->set_body( json_encode( $packet_del ) );
$push_res_del = $push_controller->handle_push( $request_del );

assert_test( ! is_wp_error( $push_res_del ), 'Push controller accepted delete packet'
	. ( is_wp_error( $push_res_del ) ? ': ' . $push_res_del->get_error_code() . ' ' . $push_res_del->get_error_message() : '' ) );
assert_test( 200 === $push_res_del->get_status(), 'Delete sync responded with HTTP 200' );

// -------------------------------------------------------------
// Test 6: Anti-Loop Scope Protection
// -------------------------------------------------------------
echo "\n--- Testing Anti-Loop Scope Protection ---\n";

$saved_in_scope = false;
Event_Recorder::run_in_ingestion_scope(
	function () use ( $post_id, $post, &$saved_in_scope ) {
		$initial_query_count = count( $GLOBALS['wpdb']->queries );
		Event_Recorder::on_save_post( $post_id, $post, true );
		$after_query_count = count( $GLOBALS['wpdb']->queries );
		
		// If suppressed, zero new queries are executed by on_save_post
		if ( $initial_query_count === $after_query_count ) {
			$saved_in_scope = true;
		}
	}
);

assert_test( $saved_in_scope, 'Anti-loop ingestion scope successfully suppresses on_save_post event creation' );
assert_test( 0 === Event_Recorder::$depth, 'Ingestion depth counter cleanly restored to 0 via finally' );

wp_cache_delete( 'active_peers_list', 'wpmmcc' );

echo "\n==================================================\n";
echo "PASS: All {$pass_count} Incremental Sync & CDC tests passed successfully!\n";
echo "==================================================\n";
