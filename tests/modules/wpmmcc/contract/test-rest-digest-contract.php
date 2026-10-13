<?php
/**
 * WPMMCC REST Digest Route Contract Test (G-02, POST /wpmmcc/v1/sync/digest).
 *
 * Route-level coverage: HMAC gate wiring, HMAC negative cases with the exact
 * middleware error codes, and the happy path — a correctly signed request is
 * dispatched to Reconciler::generate_digest with mapped limit/last_seen_id/
 * post_types arguments and the documented response shape.
 *
 * @package WPMMCC\Tests\Contract
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

use WPMMCC\REST\Rest_Middleware;
use WPMMCC\REST\Rest_Digest;

echo "=== Running REST Digest Route Contract Test (G-02) ===\n";

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

function wpmmcc_mock_db_digest( array $peers = array(), $digest_rows = null ) {
	global $wpdb;
	$wpdb->row_callback = function ( $sql ) use ( $peers ) {
		if ( false !== strpos( $sql, 'wpmmcc_peers' ) ) {
			foreach ( $peers as $p ) {
				if ( false !== strpos( $sql, "'" . $p->peer_uuid . "'" ) ) {
					return $p;
				}
			}
			return null;
		}
		return null;
	};
	$wpdb->var_callback  = function ( $sql ) {
		return null; // No existing canonical UUID / vector clock: exercise the creation path.
	};
	$GLOBALS['wpmmcc_digest_sql'] = array();
	$wpdb->results_callback       = function ( $sql ) use ( $digest_rows ) {
		$GLOBALS['wpmmcc_digest_sql'][] = $sql;
		$rows = is_array( $digest_rows ) ? $digest_rows : array();
		if ( preg_match( "/post_type = '([^']+)'/", $sql, $match ) ) {
			$type = $match[1];
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $type ) {
						return isset( $row->post_type ) && $row->post_type === $type;
					}
				)
			);
		}
		return $rows;
	};
}

function wpmmcc_peer_digest( string $peer_uuid, string $secret ): object {
	$salt = bin2hex( random_bytes( 8 ) );
	return (object) array(
		'peer_uuid'     => $peer_uuid,
		'shared_secret' => Rest_Middleware::encrypt_shared_secret( $secret, $salt ),
		'key_salt'      => $salt,
		'status'        => 'active',
	);
}

function wpmmcc_signed_request_digest( string $method, string $route, string $body, string $peer_uuid, string $secret, int $ts = 0 ): WP_REST_Request {
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

function wpmmcc_dispatch_digest( array $def, WP_REST_Request $request ) {
	$perm = call_user_func( $def['permission_callback'], $request );
	if ( is_wp_error( $perm ) ) {
		return $perm;
	}
	return call_user_func( $def['callback'], $request );
}

// -------------------------------------------------------------
// 1. Route registration
// -------------------------------------------------------------
echo "\n--- Route Registration ---\n";
$GLOBALS['wp_mock_rest_routes'] = array();
$digest = new Rest_Digest();
$digest->register_routes();

$def = $GLOBALS['wp_mock_rest_routes']['wpmmcc/v1/sync/digest'] ?? null;
assert_test( is_array( $def ), '/sync/digest route is registered under namespace wpmmcc/v1' );
assert_test( WP_REST_Server::CREATABLE === $def['methods'], '/sync/digest accepts POST (CREATABLE)' );
assert_test( array( 'WPMMCC\REST\Rest_Middleware', 'verify_hmac' ) === $def['permission_callback'], 'Permission gate is Rest_Middleware::verify_hmac' );

$ROUTE     = '/wpmmcc/v1/sync/digest';
$SECRET    = 'digest-contract-shared-secret';
$PEER_UUID = wp_generate_uuid4();
$BODY      = (string) wp_json_encode( array( 'limit' => 10 ) );

// -------------------------------------------------------------
// 2. HMAC negative matrix
// -------------------------------------------------------------
echo "\n--- HMAC Negative Matrix ---\n";
wpmmcc_mock_db_digest( array( wpmmcc_peer_digest( $PEER_UUID, $SECRET ) ) );

$unsigned = new WP_REST_Request( 'POST', $ROUTE );
$unsigned->set_body( $BODY );
$r = wpmmcc_dispatch_digest( $def, $unsigned );
assert_test( is_wp_error( $r ) && 'wpmmcc_missing_auth_headers' === $r->get_error_code(), 'Missing HMAC headers => wpmmcc_missing_auth_headers (401)' );

$drifted = wpmmcc_signed_request_digest( 'POST', $ROUTE, $BODY, $PEER_UUID, $SECRET, time() + 400 );
$r = wpmmcc_dispatch_digest( $def, $drifted );
assert_test( is_wp_error( $r ) && 'wpmmcc_clock_drift' === $r->get_error_code(), 'Future timestamp drift > 300s => wpmmcc_clock_drift (401)' );

$forged = wpmmcc_signed_request_digest( 'POST', $ROUTE, $BODY, $PEER_UUID, 'wrong-secret' );
$r = wpmmcc_dispatch_digest( $def, $forged );
assert_test( is_wp_error( $r ) && 'wpmmcc_signature_mismatch' === $r->get_error_code(), 'Signature mismatch => wpmmcc_signature_mismatch (401)' );

$ghost = wpmmcc_signed_request_digest( 'POST', $ROUTE, $BODY, wp_generate_uuid4(), $SECRET );
$r = wpmmcc_dispatch_digest( $def, $ghost );
assert_test( is_wp_error( $r ) && 'wpmmcc_peer_unauthorized' === $r->get_error_code(), 'Unknown peer => wpmmcc_peer_unauthorized (403)' );

// -------------------------------------------------------------
// 3. Happy path: signed request dispatched to Reconciler::generate_digest
// -------------------------------------------------------------
echo "\n--- Happy Path (signed request dispatched) ---\n";
$digest_rows = array(
	(object) array(
		'ID'                => 5,
		'post_title'        => 'Digest Contract Post',
		'post_content'      => 'Content of digest contract post',
		'post_status'       => 'publish',
		'post_modified_gmt' => '2026-09-16 00:00:00',
		'post_type'         => 'post',
	),
);
wpmmcc_mock_db_digest( array( wpmmcc_peer_digest( $PEER_UUID, $SECRET ) ), $digest_rows );
wp_cache_flush();

$request_body = (string) wp_json_encode(
	array(
		'limit'        => 500, // Must be clamped to MAX_LIMIT (200).
		'last_seen_id' => 0,
		'post_types'   => array( 'post', 'page' ),
	)
);
$signed = wpmmcc_signed_request_digest( 'POST', $ROUTE, $request_body, $PEER_UUID, $SECRET );
$r = wpmmcc_dispatch_digest( $def, $signed );

assert_test( ! is_wp_error( $r ), 'Valid HMAC + known peer passes the gate and reaches the handler' );
assert_test( $r instanceof WP_REST_Response, 'Handler returns a WP_REST_Response' );
assert_test( 200 === $r->get_status(), 'Digest response status is 200' );
$data = $r->get_data();
assert_test( true === $data['success'], 'Digest response success=true' );
$payload = $data['data'];
assert_test( 200 === $payload['limit'], 'Request limit=500 clamped to MAX_LIMIT 200 (arg mapping)' );
assert_test( 0 === $payload['last_seen_id'], 'last_seen_id cursor echoed back' );
assert_test( isset( $payload['server_time'] ) && abs( time() - (int) $payload['server_time'] ) <= 5, 'server_time present and current' );
assert_test( is_array( $payload['items'] ) && 1 === count( $payload['items'] ), 'One digest item returned for the scanned post' );

$item = $payload['items'][0];
assert_test( 5 === (int) $item['local_id'], 'Digest item local_id maps to the scanned post ID' );
assert_test( 'publish' === $item['post_status'] && 'post' === $item['post_type'], 'Digest item carries post_status and post_type' );
assert_test( ! empty( $item['canonical_uuid'] ) && 64 === strlen( (string) $item['source_fingerprint'] ), 'Digest item carries canonical_uuid + sha256 fingerprint' );

// -------------------------------------------------------------
// 4. Default post types: a pulling peer that names none is offered products too
//    (the pull leg sends no post_types, so the serving default IS its catalog).
// -------------------------------------------------------------
echo "\n--- Default post types ---\n";
$default_cases = array(
	'omitted'     => array( 'limit' => 10 ),
	'empty list'  => array( 'limit' => 10, 'post_types' => array() ),
	'not a list'  => array( 'limit' => 10, 'post_types' => 'post' ),
);
foreach ( $default_cases as $label => $body ) {
	wpmmcc_mock_db_digest( array( wpmmcc_peer_digest( $PEER_UUID, $SECRET ) ) );
	wp_cache_flush();
	$r = wpmmcc_dispatch_digest( $def, wpmmcc_signed_request_digest( 'POST', $ROUTE, (string) wp_json_encode( $body ), $PEER_UUID, $SECRET ) );
	assert_test( ! is_wp_error( $r ) && 200 === $r->get_status(), "post_types {$label}: request is served" );
	$scan = implode( "\n", $GLOBALS['wpmmcc_digest_sql'] );
	assert_test( false !== strpos( $scan, "'post'" ) && false !== strpos( $scan, "'page'" ) && false !== strpos( $scan, "'product'" ), "post_types {$label}: the scan covers post, page and product" );
}

wpmmcc_mock_db_digest( array( wpmmcc_peer_digest( $PEER_UUID, $SECRET ) ) );
wp_cache_flush();
$r    = wpmmcc_dispatch_digest( $def, wpmmcc_signed_request_digest( 'POST', $ROUTE, (string) wp_json_encode( array( 'limit' => 10, 'post_types' => array( 'page' ) ) ), $PEER_UUID, $SECRET ) );
$scan = implode( "\n", $GLOBALS['wpmmcc_digest_sql'] );
assert_test( false !== strpos( $scan, "'page'" ) && false === strpos( $scan, "'product'" ) && false === strpos( $scan, "'post'" ), 'an explicit post_types list is honoured exactly' );

echo "\n==================================================\n";
echo "PASS: All {$pass_count} REST Digest route contract tests passed!\n";
echo "==================================================\n";
exit( 0 );
