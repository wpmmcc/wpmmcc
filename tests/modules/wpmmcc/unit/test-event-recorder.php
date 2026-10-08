<?php
/**
 * WPMMCC Event Recorder & Anti-Loop Unit Test (WP-A-06).
 *
 * Verifies that the request-scoped depth counter strictly inhibits
 * recursive loops during sync ingestion, and that journal entries
 * conform to the dual-role CDC and audit log specification.
 *
 * @package WPMMCC\Tests\Unit
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/support/class-mini-sql-wpdb.php';

use WPMMCC\Sync\Event_Recorder;

echo "=== Running Event Recorder & Anti-Loop Unit Test (WP-A-06) ===\n";

global $wpdb;
$wpdb = new Mini_SQL_wpdb();
$wpdb->create_table( 'wp_wpmmcc_journal', array() );
$wpdb->create_table( 'wp_wpmmcc_cross_mappings', array(
	'canonical_uuid' => '', 'local_object_type' => 'post', 'local_object_id' => 0,
	'peer_uuid' => '', 'vector_clock' => 1,
) );

// 1. Initial State: Depth should be 0
if ( 0 !== Event_Recorder::$depth ) {
	echo "FAIL: Initial depth should be 0, got " . Event_Recorder::$depth . "\n";
	exit( 1 );
}

// 2. Test Ingestion Scope Depth Management
Event_Recorder::run_in_ingestion_scope(
	function () {
		if ( 1 !== Event_Recorder::$depth ) {
			echo "FAIL: Expected depth 1 inside scope, got " . Event_Recorder::$depth . "\n";
			exit( 1 );
		}

		// Nested scope
		Event_Recorder::run_in_ingestion_scope(
			function () {
				if ( 2 !== Event_Recorder::$depth ) {
					echo "FAIL: Expected depth 2 inside nested scope, got " . Event_Recorder::$depth . "\n";
					exit( 1 );
				}
			}
		);

		if ( 1 !== Event_Recorder::$depth ) {
			echo "FAIL: Expected depth to revert to 1 after nested scope, got " . Event_Recorder::$depth . "\n";
			exit( 1 );
		}
	}
);

// Verify depth reset back to 0
if ( 0 !== Event_Recorder::$depth ) {
	echo "FAIL: Depth should return to 0 after exiting ingestion scope, got " . Event_Recorder::$depth . "\n";
	exit( 1 );
}

// 3. Test Ingestion Scope Exception Resilience
try {
	Event_Recorder::run_in_ingestion_scope(
		function () {
			throw new RuntimeException( 'Simulated ingestion failure' );
		}
	);
} catch ( RuntimeException $e ) {
	// Expected
}

if ( 0 !== Event_Recorder::$depth ) {
	echo "FAIL: Depth should reset to 0 even after exception, got " . Event_Recorder::$depth . "\n";
	exit( 1 );
}

// 4. Test Journal Entry Recording
$journal_id = Event_Recorder::record_journal_entry(
	array(
		'event_type'      => 'update',
		'direction'       => 'outbound',
		'canonical_uuid'  => 'urn:uuid:test-entity-1234',
		'object_type'     => 'post',
		'local_object_id' => 99,
		'outcome'         => 'success',
		'consumed'        => 0,
	)
);

if ( $journal_id <= 0 ) {
	echo "FAIL: Journal insert failed\n";
	exit( 1 );
}

// 5. FL-14 (doc 24 §四.5): source-side vector clock upsert semantics.
// 5a. Bump mode, no existing row → self-row INSERT at max(1,0)+1 = 2 and
// vclock cache invalidation.
$wpdb->queries         = array();
wp_cache_set( 'vclock_77', 1, 'wpmmcc' ); // stale digest cache
$clock = Event_Recorder::upsert_source_clock( 'post', 77, 'urn:uuid:clock-77' );
if ( 2 !== $clock ) {
	echo "FAIL: 5a — bump with no row must return 2, got {$clock}\n";
	exit( 1 );
}
$found_self_insert = false;
foreach ( $wpdb->queries as $q ) {
	if ( 0 === strpos( $q, 'INSERT INTO' ) && strpos( $q, 'wpmmcc_cross_mappings' ) !== false && strpos( $q, '"vector_clock":2' ) !== false && strpos( $q, '"peer_uuid":""' ) !== false ) {
		$found_self_insert = true;
	}
}
if ( ! $found_self_insert ) {
	echo "FAIL: 5a — expected self-row INSERT with vector_clock 2 and empty peer_uuid\n";
	exit( 1 );
}
if ( false !== wp_cache_get( 'vclock_77', 'wpmmcc' ) ) {
	echo "FAIL: 5a — vclock cache must be invalidated after the upsert\n";
	exit( 1 );
}
echo "PASS: 5a — bump creates self row at clock 2, cache invalidated\n";

// 5b. Bump mode, existing row at 3 → UPDATE to 4.
$wpdb->queries      = array();
$wpdb->update( 'wp_wpmmcc_cross_mappings', array( 'vector_clock' => 3 ), array( 'local_object_id' => 77 ) );
$clock = Event_Recorder::upsert_source_clock( 'post', 77, 'urn:uuid:clock-77' );
if ( 4 !== $clock ) {
	echo "FAIL: 5b — bump from 3 must return 4, got {$clock}\n";
	exit( 1 );
}
$found_bump_update = false;
foreach ( $wpdb->queries as $q ) {
	if ( 0 === strpos( $q, 'UPDATE' ) && strpos( $q, 'wpmmcc_cross_mappings' ) !== false && strpos( $q, '"vector_clock":4' ) !== false ) {
		$found_bump_update = true;
	}
}
if ( ! $found_bump_update ) {
	echo "FAIL: 5b — expected mapping UPDATE to vector_clock 4\n";
	exit( 1 );
}
echo "PASS: 5b — bump updates existing row 3 → 4\n";

// 5c. Set mode (persist shipped clock 5) against existing row 4 →
// GREATEST-guarded update (a racing bump is never lowered).
$wpdb->queries      = array();
$clock = Event_Recorder::upsert_source_clock( 'post', 77, 'urn:uuid:clock-77', 5 );
if ( 5 !== $clock ) {
	echo "FAIL: 5c — set mode must return the shipped clock 5, got {$clock}\n";
	exit( 1 );
}
$found_greatest = false;
foreach ( $wpdb->queries as $q ) {
	if ( strpos( $q, "GREATEST(vector_clock, '5')" ) !== false && strpos( $q, 'wpmmcc_cross_mappings' ) !== false ) {
		$found_greatest = true;
	}
}
if ( ! $found_greatest ) {
	echo "FAIL: 5c — expected GREATEST-guarded persist update\n";
	exit( 1 );
}
echo "PASS: 5c — set mode persists shipped clock via GREATEST guard\n";

// 5d. Set mode with no row → self-row INSERT carrying the shipped clock.
$wpdb->queries      = array();
$clock = Event_Recorder::upsert_source_clock( 'post', 78, 'urn:uuid:clock-78', 7 );
if ( 7 !== $clock ) {
	echo "FAIL: 5d — set mode with no row must return 7, got {$clock}\n";
	exit( 1 );
}
$found_set_insert = false;
foreach ( $wpdb->queries as $q ) {
	if ( 0 === strpos( $q, 'INSERT INTO' ) && strpos( $q, 'wpmmcc_cross_mappings' ) !== false && strpos( $q, '"vector_clock":7' ) !== false ) {
		$found_set_insert = true;
	}
}
if ( ! $found_set_insert ) {
	echo "FAIL: 5d — expected self-row INSERT with vector_clock 7\n";
	exit( 1 );
}
echo "PASS: 5d — set mode creates self row with shipped clock\n";

// Set mode may never lower a newer local revision.
$clock = Event_Recorder::upsert_source_clock( 'post', 78, 'urn:uuid:clock-78', 2 );
if ( 7 !== $clock || 7 !== (int) $wpdb->get_var( "SELECT vector_clock FROM wp_wpmmcc_cross_mappings WHERE local_object_id = 78" ) ) {
	echo "FAIL: 5e — receipt projection lowered a newer source revision\n";
	exit( 1 );
}
echo "PASS: 5e — receipt projection preserves the actual newer source clock\n";

echo "PASS: Event Recorder & Anti-Loop unit tests passed!\n";
exit( 0 );
