<?php
/**
 * WPMMCC REST Preflight Route Contract Test (G-02, POST /wpmmcc/v1/sync/preflight).
 *
 * Route-level coverage of the REST wrapper only (the underlying
 * Sync\Preflight::run_verification is covered by unit/test-preflight.php):
 * HMAC gate wiring + negative cases, request -> service arg mapping
 * (probe_id, shadow_write), and the documented response shape.
 *
 * @package WPMMCC\Tests\Contract
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

use WPMMCC\REST\Rest_Middleware;
use WPMMCC\REST\Rest_Preflight;

echo "=== Running REST Preflight Route Contract Test (G-02) ===\n";

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

function wpmmcc_mock_db_preflight( array $peers = array() ) {
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
		return null;
	};
}

function wpmmcc_peer_preflight( string $peer_uuid, string $secret ): object {
	$salt = bin2hex( random_bytes( 8 ) );
	return (object) array(
		'peer_uuid'     => $peer_uuid,
		'shared_secret' => Rest_Middleware::encrypt_shared_secret( $secret, $salt ),
		'key_salt'      => $salt,
		'status'        => 'active',
	);
}

function wpmmcc_signed_request_preflight( string $method, string $route, string $body, string $peer_uuid, string $secret, int $ts = 0 ): WP_REST_Request {
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

function wpmmcc_dispatch_preflight( array $def, WP_REST_Request $request ) {
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
$preflight = new Rest_Preflight();
$preflight->register_routes();

$def = $GLOBALS['wp_mock_rest_routes']['wpmmcc/v1/sync/preflight'] ?? null;
assert_test( is_array( $def ), '/sync/preflight route is registered under namespace wpmmcc/v1' );
assert_test( WP_REST_Server::CREATABLE === $def['methods'], '/sync/preflight accepts POST (CREATABLE)' );
assert_test( array( 'WPMMCC\REST\Rest_Middleware', 'verify_hmac' ) === $def['permission_callback'], 'Permission gate is Rest_Middleware::verify_hmac' );

$ROUTE     = '/wpmmcc/v1/sync/preflight';
$SECRET    = 'preflight-contract-shared-secret';
$PEER_UUID = wp_generate_uuid4();
$BODY      = (string) wp_json_encode( array( 'probe_id' => 'probe_contract_1', 'shadow_write' => true ) );

// -------------------------------------------------------------
// 2. HMAC gate negatives (REST wrapper enforces auth before probing)
// -------------------------------------------------------------
echo "\n--- HMAC Gate Negatives ---\n";
wpmmcc_mock_db_preflight( array( wpmmcc_peer_preflight( $PEER_UUID, $SECRET ) ) );

$unsigned = new WP_REST_Request( 'POST', $ROUTE );
$unsigned->set_body( $BODY );
$r = wpmmcc_dispatch_preflight( $def, $unsigned );
assert_test( is_wp_error( $r ) && 'wpmmcc_missing_auth_headers' === $r->get_error_code(), 'Unsigned preflight probe => wpmmcc_missing_auth_headers (401)' );

$forged = wpmmcc_signed_request_preflight( 'POST', $ROUTE, $BODY, $PEER_UUID, 'wrong-secret' );
$r = wpmmcc_dispatch_preflight( $def, $forged );
assert_test( is_wp_error( $r ) && 'wpmmcc_signature_mismatch' === $r->get_error_code(), 'Forged preflight signature => wpmmcc_signature_mismatch (401)' );

$ghost = wpmmcc_signed_request_preflight( 'POST', $ROUTE, $BODY, wp_generate_uuid4(), $SECRET );
$r = wpmmcc_dispatch_preflight( $def, $ghost );
assert_test( is_wp_error( $r ) && 'wpmmcc_peer_unauthorized' === $r->get_error_code(), 'Unknown peer preflight probe => wpmmcc_peer_unauthorized (403)' );

// -------------------------------------------------------------
// 3. Happy path: signed request dispatched; arg mapping + response shape
// -------------------------------------------------------------
echo "\n--- Happy Path (signed request dispatched) ---\n";
$probe_id = 'probe_contract_' . bin2hex( random_bytes( 4 ) );
$request_body = (string) wp_json_encode(
	array(
		'probe_id'     => $probe_id,
		'shadow_write' => true,
	)
);
$signed = wpmmcc_signed_request_preflight( 'POST', $ROUTE, $request_body, $PEER_UUID, $SECRET );
$r = wpmmcc_dispatch_preflight( $def, $signed );

assert_test( ! is_wp_error( $r ), 'Valid HMAC + known peer passes the gate and reaches the handler' );
assert_test( $r instanceof WP_REST_Response, 'Handler returns a WP_REST_Response' );
assert_test( 200 === $r->get_status(), 'Preflight response status is 200' );
$data = $r->get_data();
assert_test( true === $data['success'], 'Preflight response success=true' );
$payload = $data['data'];
assert_test( $probe_id === $payload['probe_id'], 'probe_id request arg maps into the response verbatim' );
assert_test( true === $payload['uploads_writable'], 'uploads_writable=true in the harness uploads dir' );
assert_test( true === $payload['shadow_cleaned'], 'shadow_write=true arg enables and cleans the shadow probe file' );
assert_test( isset( $payload['disk_free_bytes'] ) && is_int( $payload['disk_free_bytes'] ), 'disk_free_bytes is reported as an integer' );
assert_test( isset( $payload['server_time'] ) && abs( time() - (int) $payload['server_time'] ) <= 5, 'server_time present and current' );

// shadow_write omitted -> no shadow probe performed
$no_shadow = wpmmcc_signed_request_preflight( 'POST', $ROUTE, (string) wp_json_encode( array( 'probe_id' => $probe_id ) ), $PEER_UUID, $SECRET );
$r = wpmmcc_dispatch_preflight( $def, $no_shadow );
assert_test( ! is_wp_error( $r ) && false === $r->get_data()['data']['shadow_cleaned'], 'shadow_write omitted => shadow_cleaned=false' );

echo "\n==================================================\n";
echo "PASS: All {$pass_count} REST Preflight route contract tests passed!\n";
echo "==================================================\n";
exit( 0 );
