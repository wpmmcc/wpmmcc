<?php
/**
 * WPMMCC Identity Contract v1.0 Test (WP-A-04).
 *
 * Verifies that GET /wp-json/wpmmcc/v1/sync/ping response adheres 100%
 * to Identity Contract v1.0 (tasks/wpmmcc/contract/identity-v1/CONTRACT.md)
 * and matches the golden fixture wpmmcc-sync-ping.json.
 *
 * @package WPMMCC\Tests\Contract
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

use WPMMCC\REST\Rest_Ping;
use WPMMCC\REST\Rest_Middleware;

echo "=== Running Identity Contract v1.0 Test (WP-A-04) ===\n";

$fixture_path = dirname( __DIR__, 4 ) . '/tasks/wpmmcc/contract/identity-v1/fixtures/wpmmcc-sync-ping.json';
if ( ! file_exists( $fixture_path ) ) {
	$fixture_path = dirname( __DIR__ ) . '/fixtures/wpmmcc-sync-ping.json';
}
if ( ! file_exists( $fixture_path ) ) {
	echo "FAIL: Golden fixture not found at $fixture_path\n";
	exit( 1 );
}

$fixture_raw  = file_get_contents( $fixture_path );
$fixture_data = json_decode( $fixture_raw, true );
if ( ! is_array( $fixture_data ) ) {
	echo "FAIL: Golden fixture is not valid JSON\n";
	exit( 1 );
}

// The fixture IS the source of truth (17 §3.2 golden-fixture discipline):
// every asserted field name and the identity value come from this file.
$fixture_payload = $fixture_data['data'] ?? array();
if ( ! is_array( $fixture_payload ) || empty( $fixture_payload ) ) {
	echo "FAIL: Golden fixture is missing the data block\n";
	exit( 1 );
}
$expected_fields = array_keys( $fixture_payload );
$expected_identity = (string) ( $fixture_payload['plugin_identity'] ?? '' );

$controller = new Rest_Ping();
$request    = new WP_REST_Request( 'GET', '/wpmmcc/v1/sync/ping' );

// 1. Assert Permission Callback with no token (Initial Discovery)
$perm = $controller->check_permission( $request );
if ( true !== $perm ) {
	echo "FAIL: Initial discovery permission check should succeed when no token is stored\n";
	exit( 1 );
}

// 1b. X-3 (Identity Contract v1.1): the UNAUTHENTICATED discovery response
// is minimal. Pairing needs identity + protocol versions only;
// install_signature enables offline same-install probing and must not be
// disclosed before the caller is paired.
$discovery_response = $controller->get_ping( $request );
$discovery_data     = $discovery_response->get_data();
if ( 200 !== $discovery_response->get_status() || ! isset( $discovery_data['data'] ) || ! is_array( $discovery_data['data'] ) ) {
	echo "FAIL: Discovery ping must still return 200 with a data block\n";
	exit( 1 );
}
$discovery_payload = $discovery_data['data'];
if ( isset( $discovery_payload['install_signature'] ) ) {
	echo "FAIL (X-3): unauthenticated discovery must NOT disclose install_signature\n";
	exit( 1 );
}
foreach ( array( 'plugin_identity', 'plugin_version', 'protocol_min', 'protocol_current' ) as $discovery_field ) {
	if ( ! isset( $discovery_payload[ $discovery_field ] ) ) {
		echo "FAIL: Discovery ping must keep the pairing-required field '{$discovery_field}'\n";
		exit( 1 );
	}
}
$discovery_extra = array_diff( array_keys( $discovery_payload ), array( 'plugin_identity', 'plugin_version', 'protocol_min', 'protocol_current' ) );
if ( ! empty( $discovery_extra ) ) {
	echo 'FAIL: Discovery ping must not carry extra fields: ' . implode( ', ', $discovery_extra ) . "\n";
	exit( 1 );
}
echo "PASS: Unauthenticated discovery response is minimal (no install_signature, X-3 closed)\n";

// 2. Assert Permission Callback with token configured (Fail-closed test)
update_option( 'wpmmcc_client_token', 'valid-secret-token-12345' );

// 2a: Request with missing token -> expect 401
$bad_request = new WP_REST_Request( 'GET', '/wpmmcc/v1/sync/ping' );
$bad_perm    = $controller->check_permission( $bad_request );
if ( ! is_wp_error( $bad_perm ) || 401 !== $bad_perm->get_error_data()['status'] ) {
	echo "FAIL: Fail-closed check failed: missing token must return 401\n";
	exit( 1 );
}

// 2b: Request with wrong token -> expect 401
$wrong_request = new WP_REST_Request( 'GET', '/wpmmcc/v1/sync/ping' );
$wrong_request->set_header( 'x-wpmmcc-client-token', 'invalid-token' );
$wrong_perm = $controller->check_permission( $wrong_request );
if ( ! is_wp_error( $wrong_perm ) || 401 !== $wrong_perm->get_error_data()['status'] ) {
	echo "FAIL: Fail-closed check failed: invalid token must return 401\n";
	exit( 1 );
}

// 2c: Request with correct token -> expect true
$good_request = new WP_REST_Request( 'GET', '/wpmmcc/v1/sync/ping' );
$good_request->set_header( 'x-wpmmcc-client-token', 'valid-secret-token-12345' );
$good_perm = $controller->check_permission( $good_request );
if ( true !== $good_perm ) {
	echo "FAIL: Valid token check must return true\n";
	exit( 1 );
}

// 3. Test Response Payload Structure
$response = $controller->get_ping( $good_request );
$status   = $response->get_status();
$data     = $response->get_data();

if ( 200 !== $status ) {
	echo "FAIL: Ping response status expected 200, got $status\n";
	exit( 1 );
}

// Assert Top-Level Shape
if ( ! isset( $data['success'] ) || true !== $data['success'] ) {
	echo "FAIL: 'success' must be true\n";
	exit( 1 );
}

if ( ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
	echo "FAIL: 'data' object must be present\n";
	exit( 1 );
}

$payload = $data['data'];

// Golden Assertion 1 (fixture-driven): every fixture field is present in the
// response, with the exact same field set (no missing, no extra fields).
$missing_fields = array_diff( $expected_fields, array_keys( $payload ) );
if ( ! empty( $missing_fields ) ) {
	echo 'FAIL: Fixture fields missing from ping payload: ' . implode( ', ', $missing_fields ) . "\n";
	exit( 1 );
}

// Golden Assertion 2 (fixture-driven): plugin_identity is EXACTLY the
// contract value from the fixture (Contract §1, byte-exact).
if ( ! isset( $payload['plugin_identity'] ) || $expected_identity !== $payload['plugin_identity'] ) {
	echo "FAIL: 'plugin_identity' must be strictly '{$expected_identity}', got: " . var_export( $payload['plugin_identity'] ?? null, true ) . "\n";
	exit( 1 );
}

// Golden Assertion 3: plugin_version is string
if ( ! isset( $payload['plugin_version'] ) || ! is_string( $payload['plugin_version'] ) ) {
	echo "FAIL: 'plugin_version' must be a string\n";
	exit( 1 );
}

// Golden Assertion 4: protocol_min is int >= 1
if ( ! isset( $payload['protocol_min'] ) || ! is_int( $payload['protocol_min'] ) || $payload['protocol_min'] < 1 ) {
	echo "FAIL: 'protocol_min' must be an integer >= 1\n";
	exit( 1 );
}

// Golden Assertion 5: protocol_current is int >= protocol_min
if ( ! isset( $payload['protocol_current'] ) || ! is_int( $payload['protocol_current'] ) || $payload['protocol_current'] < $payload['protocol_min'] ) {
	echo "FAIL: 'protocol_current' must be an integer >= protocol_min\n";
	exit( 1 );
}

// Golden Assertion 6: install_signature is 64-char lowercase hex
if ( ! isset( $payload['install_signature'] ) || ! preg_match( '/^[a-f0-9]{64}$/', $payload['install_signature'] ) ) {
	echo "FAIL: 'install_signature' must be a 64-character lowercase hex string, got: " . ( $payload['install_signature'] ?? '' ) . "\n";
	exit( 1 );
}

// Verify install_signature calculation formula: sha256( site_url || '|' || network_id )
$expected_sig = hash( 'sha256', get_site_url() . '|' . get_current_network_id() );
if ( $payload['install_signature'] !== $expected_sig ) {
	echo "FAIL: 'install_signature' did not match formula sha256(site_url . '|' . network_id)\n";
	exit( 1 );
}

// Golden Assertion 7: response field set matches the fixture exactly —
// unauthorized additional fields are a contract violation (Contract §2).
$allowed_fields = $expected_fields;
$extra_fields   = array_diff( array_keys( $payload ), $allowed_fields );
if ( ! empty( $extra_fields ) ) {
	echo "FAIL: Unauthorized fields detected in ping payload: " . implode( ', ', $extra_fields ) . "\n";
	exit( 1 );
}

echo "PASS: All Identity Contract v1.0 golden assertions passed (fixture: " . basename( $fixture_path ) . ")!\n";

// -------------------------------------------------------------
// Rate limiting (WP-A-02, doc 14 §6): a caller exceeding its
// per-minute window budget receives 429 with a Retry-After hint.
// -------------------------------------------------------------
echo "=== Rate Limit Assertion (enforce_rate_limit) ===\n";

$rl_bucket = 'test|contract|' . uniqid( '', true );
for ( $i = 1; $i <= 3; $i++ ) {
	$rl = Rest_Middleware::enforce_rate_limit( $rl_bucket, 3 );
	if ( true !== $rl ) {
		echo "FAIL: Request {$i} within budget must pass, got: " . var_export( $rl, true ) . "\n";
		exit( 1 );
	}
}
$rl_fourth = Rest_Middleware::enforce_rate_limit( $rl_bucket, 3 );
if ( ! is_wp_error( $rl_fourth ) || 429 !== (int) $rl_fourth->get_error_data()['status'] ) {
	echo "FAIL: 4th request past the 3/min budget must be a 429 WP_Error\n";
	exit( 1 );
}
$retry_after = (int) ( $rl_fourth->get_error_data()['retry_after'] ?? 0 );
if ( $retry_after < 1 || $retry_after > 60 ) {
	echo "FAIL: 429 must carry a sane retry_after (1..60), got {$retry_after}\n";
	exit( 1 );
}
echo "PASS: Rate limiter enforces the window budget with 429 + retry_after\n";

exit( 0 );
