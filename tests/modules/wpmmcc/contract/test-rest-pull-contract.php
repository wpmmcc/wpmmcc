<?php
/**
 * WPMMCC REST Pull Route Contract Test (G-02, POST /wpmmcc/v1/sync/pull).
 *
 * Route-level coverage: verifies the route registers with the HMAC
 * permission gate, every HMAC failure branch returns the exact documented
 * WP_Error codes (rest/class-wpmmcc-rest-middleware.php), request
 * validation errors surface, and a correctly signed request is dispatched
 * to the handler and returns Sync Packets.
 *
 * @package WPMMCC\Tests\Contract
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/support/class-mini-sql-wpdb.php';

use WPMMCC\REST\Rest_Middleware;
use WPMMCC\REST\Rest_Pull;

echo "=== Running REST Pull Route Contract Test (G-02) ===\n";

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

/**
 * Install stateful peers, nonces and source-clock rows.
 *
 * @param object[] $peers   Peer fixture rows (objects with peer_uuid/shared_secret/key_salt/status).
 * @param object   $mapping Optional cross-mapping row returned for canonical_uuid lookups.
 */
function wpmmcc_mock_db( array $peers = array(), $mapping = null ) {
	global $wpdb;
	$wpdb = new Mini_SQL_wpdb();
	$wpdb->create_table( 'wp_wpmmcc_peers', array() );
	$wpdb->create_table( 'wp_wpmmcc_nonces', array() );
	$wpdb->create_table( 'wp_wpmmcc_journal', array() );
	$wpdb->create_table( 'wp_wpmmcc_cross_mappings', array( 'peer_uuid' => '' ) );
	foreach ( $peers as $peer ) {
		$wpdb->insert( 'wp_wpmmcc_peers', (array) $peer );
	}
	if ( null !== $mapping ) {
		$wpdb->insert( 'wp_wpmmcc_cross_mappings', (array) $mapping );
	}
}

/**
 * Build a peer fixture row with the secret encrypted exactly like a real pairing.
 *
 * @param string $peer_uuid Peer UUID.
 * @param string $secret   Raw shared secret.
 * @return object
 */
function wpmmcc_peer( string $peer_uuid, string $secret ): object {
	$salt = bin2hex( random_bytes( 8 ) );
	return (object) array(
		'peer_uuid'     => $peer_uuid,
		'shared_secret' => Rest_Middleware::encrypt_shared_secret( $secret, $salt ),
		'key_salt'      => $salt,
		'status'        => 'active',
	);
}

/**
 * Build an HMAC-signed request the way a real peer (or Rest_Middleware::sign_request) does.
 *
 * @param string $method   HTTP method.
 * @param string $route    Full route (must match the WP_REST_Request route).
 * @param string $body     Raw request body.
 * @param string $peer_uuid Caller peer UUID.
 * @param string $secret   Raw shared secret.
 * @param int    $ts       Unix timestamp to sign with.
 * @return WP_REST_Request
 */
function wpmmcc_signed_request( string $method, string $route, string $body, string $peer_uuid, string $secret, int $ts = 0 ): WP_REST_Request {
	$ts    = $ts ?: time();
	$nonce = bin2hex( random_bytes( 8 ) );
	$req   = new WP_REST_Request( $method, $route );
	$req->set_body( $body );
	$req->set_header( 'x-wpmmcc-site-uuid', $peer_uuid );
	$req->set_header( 'x-wpmmcc-timestamp', (string) $ts );
	$req->set_header( 'x-wpmmcc-nonce', $nonce );
	$req->set_header( 'x-wpmmcc-signature', Rest_Middleware::sign_request( $method, $route, $ts, $nonce, $peer_uuid, $body, $secret ) );
	return $req;
}

/**
 * Dispatch a captured route definition exactly like WP REST does:
 * permission gate first, handler only when the gate passes.
 *
 * @param array           $def     Route definition from register_rest_route capture.
 * @param WP_REST_Request $request Request.
 * @return mixed WP_Error from the gate, or the handler response.
 */
function wpmmcc_dispatch( array $def, WP_REST_Request $request ) {
	$perm = call_user_func( $def['permission_callback'], $request );
	if ( is_wp_error( $perm ) ) {
		return $perm;
	}
	return call_user_func( $def['callback'], $request );
}

// -------------------------------------------------------------
// 1. Route registration & permission gate wiring
// -------------------------------------------------------------
echo "\n--- Route Registration ---\n";
$GLOBALS['wp_mock_rest_routes'] = array();
$pull = new Rest_Pull();
$pull->register_routes();

$def = $GLOBALS['wp_mock_rest_routes']['wpmmcc/v1/sync/pull'] ?? null;
assert_test( is_array( $def ), '/sync/pull route is registered under namespace wpmmcc/v1' );
assert_test( WP_REST_Server::CREATABLE === $def['methods'], '/sync/pull accepts POST (CREATABLE)' );
assert_test( is_callable( $def['permission_callback'] ), '/sync/pull wires a permission gate' );
assert_test( array( 'WPMMCC\REST\Rest_Middleware', 'verify_hmac' ) === $def['permission_callback'], 'Permission gate is Rest_Middleware::verify_hmac' );

$ROUTE     = '/wpmmcc/v1/sync/pull';
$SECRET    = 'pull-contract-shared-secret';
$PEER_UUID = wp_generate_uuid4();
$BODY      = (string) wp_json_encode( array( 'canonical_uuids' => array( 'urn:uuid:11111111-2222-3333-4444-555555555555' ) ) );
update_option( 'wpmmcc_origin_uuid', wp_generate_uuid4() );

// -------------------------------------------------------------
// 2. HMAC negative matrix (exact error codes from the middleware)
// -------------------------------------------------------------
echo "\n--- HMAC Negative Matrix ---\n";
wpmmcc_mock_db( array( wpmmcc_peer( $PEER_UUID, $SECRET ) ) );

// 2a. Missing signature headers -> 401 wpmmcc_missing_auth_headers
$unsigned = new WP_REST_Request( 'POST', $ROUTE );
$unsigned->set_body( $BODY );
$r = wpmmcc_dispatch( $def, $unsigned );
assert_test( is_wp_error( $r ) && 'wpmmcc_missing_auth_headers' === $r->get_error_code(), 'Missing HMAC headers => wpmmcc_missing_auth_headers' );
assert_test( 401 === ( $r->get_error_data()['status'] ?? 0 ), 'Missing HMAC headers => HTTP 401' );

// 2b. Timestamp drift beyond ±300s -> 401 wpmmcc_clock_drift
$drifted = wpmmcc_signed_request( 'POST', $ROUTE, $BODY, $PEER_UUID, $SECRET, time() - 350 );
$r = wpmmcc_dispatch( $def, $drifted );
assert_test( is_wp_error( $r ) && 'wpmmcc_clock_drift' === $r->get_error_code(), 'Timestamp drift > 300s => wpmmcc_clock_drift' );
assert_test( 401 === ( $r->get_error_data()['status'] ?? 0 ), 'Clock drift => HTTP 401' );

// 2c. Nonce replay -> 403 wpmmcc_nonce_replayed
$replayed = wpmmcc_signed_request( 'POST', $ROUTE, $BODY, $PEER_UUID, $SECRET );
$r1 = wpmmcc_dispatch( $def, $replayed );
assert_test( ! is_wp_error( $r1 ), 'First pull request with valid HMAC passes the gate' );
// A replay is a second HTTP request: identical bytes, distinct request object
// (the gate memoizes its verdict per request object within one dispatch).
$r2 = wpmmcc_dispatch( $def, clone $replayed ); // same nonce reused
assert_test( is_wp_error( $r2 ) && 'wpmmcc_nonce_replayed' === $r2->get_error_code(), 'Replayed nonce => wpmmcc_nonce_replayed' );
assert_test( 403 === ( $r2->get_error_data()['status'] ?? 0 ), 'Nonce replay => HTTP 403' );

// 2d. Unknown / inactive peer -> 403 wpmmcc_peer_unauthorized
$ghost = wpmmcc_signed_request( 'POST', $ROUTE, $BODY, wp_generate_uuid4(), $SECRET );
$r = wpmmcc_dispatch( $def, $ghost );
assert_test( is_wp_error( $r ) && 'wpmmcc_peer_unauthorized' === $r->get_error_code(), 'Unknown peer => wpmmcc_peer_unauthorized' );
assert_test( 403 === ( $r->get_error_data()['status'] ?? 0 ), 'Unknown peer => HTTP 403' );

// 2e. Signature mismatch -> 401 wpmmcc_signature_mismatch
$forged = wpmmcc_signed_request( 'POST', $ROUTE, $BODY, $PEER_UUID, 'wrong-secret-entirely' );
$r = wpmmcc_dispatch( $def, $forged );
assert_test( is_wp_error( $r ) && 'wpmmcc_signature_mismatch' === $r->get_error_code(), 'Bad signature => wpmmcc_signature_mismatch' );
assert_test( 401 === ( $r->get_error_data()['status'] ?? 0 ), 'Signature mismatch => HTTP 401' );

// -------------------------------------------------------------
// 3. Handler request validation (behind a VALID HMAC gate)
// -------------------------------------------------------------
echo "\n--- Request Validation ---\n";
$empty = wpmmcc_signed_request( 'POST', $ROUTE, (string) wp_json_encode( array( 'canonical_uuids' => array() ) ), $PEER_UUID, $SECRET );
$r = wpmmcc_dispatch( $def, $empty );
assert_test( is_wp_error( $r ) && 'wpmmcc_missing_uuids' === $r->get_error_code(), 'Empty canonical_uuids => wpmmcc_missing_uuids' );
assert_test( 400 === ( $r->get_error_data()['status'] ?? 0 ), 'Empty canonical_uuids => HTTP 400' );

// -------------------------------------------------------------
// 4. Happy path: known peer + valid HMAC -> dispatched, packets returned
// -------------------------------------------------------------
echo "\n--- Happy Path (signed request dispatched) ---\n";
$CANONICAL = 'urn:uuid:11111111-2222-3333-4444-555555555555';
wpmmcc_mock_db(
	array( wpmmcc_peer( $PEER_UUID, $SECRET ) ),
	(object) array(
		'canonical_uuid'    => $CANONICAL,
		'local_object_type' => 'post',
		'local_object_id'   => 5,
		'vector_clock'      => 3,
	)
);
wp_cache_flush();

$happy = wpmmcc_signed_request( 'POST', $ROUTE, (string) wp_json_encode( array( 'canonical_uuids' => array( $CANONICAL ) ) ), $PEER_UUID, $SECRET );
$r = wpmmcc_dispatch( $def, $happy );

assert_test( ! is_wp_error( $r ), 'Valid HMAC + known peer passes the gate and reaches the handler' );
assert_test( $r instanceof WP_REST_Response, 'Handler returns a WP_REST_Response' );
assert_test( 200 === $r->get_status(), 'Pull response status is 200' );
$data = $r->get_data();
assert_test( true === $data['success'], 'Pull response success=true' );
$packets = $data['data']['packets'] ?? null;
assert_test( is_array( $packets ) && 1 === count( $packets ), 'One Sync Packet returned for the requested canonical UUID' );
assert_test( 'wpmmcc-sync-v1.0' === $packets[0]['schema_version'], 'Packet schema_version is wpmmcc-sync-v1.0' );
assert_test( (int) $packets[0]['entity']['source_id'] === 5, 'Packet entity maps to the mapped local_object_id 5' );

echo "\n==================================================\n";
echo "PASS: All {$pass_count} REST Pull route contract tests passed!\n";
echo "==================================================\n";
exit( 0 );
