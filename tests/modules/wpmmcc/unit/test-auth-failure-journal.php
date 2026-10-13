<?php
/**
 * WPMMCC Auth/Pairing Failure Journal Audit Unit Test (G-06).
 *
 * Verifies that every HMAC / token / pairing failure branch writes an
 * inbound AUDIT row into the wpmmcc_journal with consumed=1 (never entering
 * the CDC outbox), carrying route + machine-readable reason and never any
 * secret material.
 *
 * @package WPMMCC\Tests\Unit
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

use WPMMCC\REST\Rest_Middleware;
use WPMMCC\REST\Rest_Ping;
use WPMMCC\REST\Rest_Handshake;
use WPMMCC\REST\Rest_Base;

echo "=== Running Auth Failure Journal Audit Unit Test (G-06) ===\n";

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
 * Count journal insert rows captured by the Mock_wpdb so far.
 *
 * @return int
 */
function wpmmcc_journal_count(): int {
	global $wpdb;
	$n = 0;
	foreach ( $wpdb->queries as $q ) {
		if ( 0 === strpos( $q, 'INSERT INTO wp_wpmmcc_journal' ) ) {
			$n++;
		}
	}
	return $n;
}

/**
 * Decode the most recent journal insert row into an array.
 *
 * @return array|null
 */
function wpmmcc_last_journal_row(): ?array {
	global $wpdb;
	foreach ( array_reverse( $wpdb->queries ) as $q ) {
		if ( 0 === strpos( $q, 'INSERT INTO wp_wpmmcc_journal' ) ) {
			$json = substr( $q, strpos( $q, '{' ) );
			return json_decode( $json, true );
		}
	}
	return null;
}

function wpmmcc_assert_audit_row( $row, string $reason, string $expect_route ) {
	assert_test( is_array( $row ), "Journal row recorded for [{$reason}]" );
	assert_test( 'auth_failure' === $row['event_type'], "Row event_type is auth_failure [{$reason}]" );
	assert_test( 'inbound' === $row['direction'], "Row direction is inbound [{$reason}]" );
	assert_test( 'auth' === $row['object_type'], "Row object_type is auth [{$reason}]" );
	assert_test( 'error' === $row['outcome'], "Row outcome is error [{$reason}]" );
	assert_test( 1 === (int) $row['consumed'], "Row consumed=1 (audit-only, never CDC) [{$reason}]" );
	assert_test( $reason === $row['message'], "Row message is the reason key [{$reason}]" );
	$diff = is_string( $row['diff_summary'] ) ? json_decode( $row['diff_summary'], true ) : $row['diff_summary'];
	assert_test( is_array( $diff ) && $expect_route === $diff['route'], "diff_summary carries the route [{$reason}]" );
}

// -------------------------------------------------------------
// 1. HMAC middleware failure branches (verify_hmac)
// -------------------------------------------------------------
echo "\n--- HMAC Middleware Failure Branches ---\n";

$SECRET      = 'auth-journal-secret-do-not-leak';
$PEER_UUID   = wp_generate_uuid4();
$peer_salt   = bin2hex( random_bytes( 8 ) );
$peer_row    = (object) array(
	'peer_uuid'     => $PEER_UUID,
	'shared_secret' => Rest_Middleware::encrypt_shared_secret( $SECRET, $peer_salt ),
	'key_salt'      => $peer_salt,
	'status'        => 'active',
);
global $wpdb;
$wpdb->row_callback = function ( $sql ) use ( $peer_row ) {
	if ( false !== strpos( $sql, 'wpmmcc_peers' ) && false !== strpos( $sql, "'" . $peer_row->peer_uuid . "'" ) ) {
		return $peer_row;
	}
	return null;
};
$wpdb->var_callback  = function ( $sql ) {
	return null;
};

$ROUTE = '/wpmmcc/v1/sync/push';
$BODY  = '{"probe":"auth-journal"}';

$base_count = wpmmcc_journal_count();

// 1a. Missing auth headers
$req = new WP_REST_Request( 'POST', $ROUTE );
$req->set_body( $BODY );
$r = Rest_Middleware::verify_hmac( $req );
assert_test( is_wp_error( $r ) && 'wpmmcc_missing_auth_headers' === $r->get_error_code(), 'Missing headers branch returns wpmmcc_missing_auth_headers' );
assert_test( wpmmcc_journal_count() === $base_count + 1, 'Missing headers failure writes exactly one journal row' );
wpmmcc_assert_audit_row( wpmmcc_last_journal_row(), 'missing_auth_headers', $ROUTE );

// 1b. Clock drift beyond ±300s
$req = new WP_REST_Request( 'POST', $ROUTE );
$req->set_body( $BODY );
$req->set_header( 'x-wpmmcc-site-uuid', $PEER_UUID );
$req->set_header( 'x-wpmmcc-timestamp', (string) ( time() - 999 ) );
$req->set_header( 'x-wpmmcc-nonce', bin2hex( random_bytes( 8 ) ) );
$req->set_header( 'x-wpmmcc-signature', 'f' . str_repeat( '0', 63 ) );
$r = Rest_Middleware::verify_hmac( $req );
assert_test( is_wp_error( $r ) && 'wpmmcc_clock_drift' === $r->get_error_code(), 'Clock drift branch returns wpmmcc_clock_drift' );
wpmmcc_assert_audit_row( wpmmcc_last_journal_row(), 'clock_drift', $ROUTE );

// 1c. Nonce replay (transient branch)
$nonce = bin2hex( random_bytes( 8 ) );
set_transient( 'wpmmcc_n_' . substr( md5( $nonce ), 0, 20 ), 1, 600 );
$req = new WP_REST_Request( 'POST', $ROUTE );
$req->set_body( $BODY );
$req->set_header( 'x-wpmmcc-site-uuid', $PEER_UUID );
$req->set_header( 'x-wpmmcc-timestamp', (string) time() );
$req->set_header( 'x-wpmmcc-nonce', $nonce );
$req->set_header( 'x-wpmmcc-signature', 'f' . str_repeat( '0', 63 ) );
$r = Rest_Middleware::verify_hmac( $req );
assert_test( is_wp_error( $r ) && 'wpmmcc_nonce_replayed' === $r->get_error_code(), 'Nonce replay branch returns wpmmcc_nonce_replayed' );
wpmmcc_assert_audit_row( wpmmcc_last_journal_row(), 'nonce_replayed', $ROUTE );

// 1d. Unknown peer
$ghost_uuid = wp_generate_uuid4();
$req = new WP_REST_Request( 'POST', $ROUTE );
$req->set_body( $BODY );
$req->set_header( 'x-wpmmcc-site-uuid', $ghost_uuid );
$req->set_header( 'x-wpmmcc-timestamp', (string) time() );
$req->set_header( 'x-wpmmcc-nonce', bin2hex( random_bytes( 8 ) ) );
$req->set_header( 'x-wpmmcc-signature', 'f' . str_repeat( '0', 63 ) );
$r = Rest_Middleware::verify_hmac( $req );
assert_test( is_wp_error( $r ) && 'wpmmcc_peer_unauthorized' === $r->get_error_code(), 'Unknown peer branch returns wpmmcc_peer_unauthorized' );
$row = wpmmcc_last_journal_row();
wpmmcc_assert_audit_row( $row, 'peer_unauthorized', $ROUTE );
assert_test( $ghost_uuid === $row['peer_uuid'], 'Unknown peer row carries the claimed origin UUID (not a secret)' );

// 1e. Signature mismatch (valid known peer, wrong signature)
$req = new WP_REST_Request( 'POST', $ROUTE );
$req->set_body( $BODY );
$req->set_header( 'x-wpmmcc-site-uuid', $PEER_UUID );
$req->set_header( 'x-wpmmcc-timestamp', (string) time() );
$req->set_header( 'x-wpmmcc-nonce', bin2hex( random_bytes( 8 ) ) );
$req->set_header( 'x-wpmmcc-signature', 'f' . str_repeat( '0', 63 ) );
$r = Rest_Middleware::verify_hmac( $req );
assert_test( is_wp_error( $r ) && 'wpmmcc_signature_mismatch' === $r->get_error_code(), 'Signature mismatch branch returns wpmmcc_signature_mismatch' );
wpmmcc_assert_audit_row( wpmmcc_last_journal_row(), 'signature_mismatch', $ROUTE );

// 1f. Attack-noise throttle: identical route+reason+peer within the window
//     must not write a second journal row.
$mismatch_rows = wpmmcc_journal_count();
$req = new WP_REST_Request( 'POST', $ROUTE );
$req->set_body( $BODY );
$req->set_header( 'x-wpmmcc-site-uuid', $PEER_UUID );
$req->set_header( 'x-wpmmcc-timestamp', (string) time() );
$req->set_header( 'x-wpmmcc-nonce', bin2hex( random_bytes( 8 ) ) );
$req->set_header( 'x-wpmmcc-signature', 'f' . str_repeat( '0', 63 ) );
$r = Rest_Middleware::verify_hmac( $req );
assert_test( is_wp_error( $r ) && 'wpmmcc_signature_mismatch' === $r->get_error_code(), 'Second mismatch still returns the WP_Error' );
assert_test( wpmmcc_journal_count() === $mismatch_rows, 'Throttle suppresses duplicate journal rows for the same failure key within 60s' );

// 1g. No secret material ever lands in the journal
$all_journal_sql = '';
global $wpdb;
foreach ( $wpdb->queries as $q ) {
	if ( 0 === strpos( $q, 'INSERT INTO wp_wpmmcc_journal' ) ) {
		$all_journal_sql .= $q;
	}
}
assert_test( false === strpos( $all_journal_sql, $SECRET ), 'Raw shared secret never appears in journal rows' );
assert_test( false === strpos( $all_journal_sql, 'f' . str_repeat( '0', 63 ) ), 'Presented signature never appears in journal rows' );

// -------------------------------------------------------------
// 2. Ping client-token failure
// -------------------------------------------------------------
echo "\n--- Ping Client Token Failure ---\n";
update_option( 'wpmmcc_client_token', 'ping-secret-token-do-not-leak' );
$ping = new Rest_Ping();

$before = wpmmcc_journal_count();
$req = new WP_REST_Request( 'GET', '/wpmmcc/v1/sync/ping' );
$r = $ping->check_permission( $req );
assert_test( is_wp_error( $r ) && 'wpmmcc_unauthorized' === $r->get_error_code(), 'Missing ping token returns wpmmcc_unauthorized' );
assert_test( wpmmcc_journal_count() === $before + 1, 'Missing ping token writes one journal row' );
wpmmcc_assert_audit_row( wpmmcc_last_journal_row(), 'client_token_missing', '/wpmmcc/v1/sync/ping' );

$before = wpmmcc_journal_count();
$req = new WP_REST_Request( 'GET', '/wpmmcc/v1/sync/ping' );
$req->set_header( 'x-wpmmcc-client-token', 'wrong-token-attempt' );
$r = $ping->check_permission( $req );
assert_test( is_wp_error( $r ) && 'wpmmcc_unauthorized' === $r->get_error_code(), 'Wrong ping token returns wpmmcc_unauthorized' );
assert_test( wpmmcc_journal_count() === $before + 1, 'Wrong ping token writes one journal row' );
wpmmcc_assert_audit_row( wpmmcc_last_journal_row(), 'client_token_mismatch', '/wpmmcc/v1/sync/ping' );
$row_json = json_encode( wpmmcc_last_journal_row() );
assert_test( false === strpos( $row_json, 'ping-secret-token-do-not-leak' ) && false === strpos( $row_json, 'wrong-token-attempt' ), 'Ping token values never appear in journal rows' );

// -------------------------------------------------------------
// 3. Handshake pairing failures
// -------------------------------------------------------------
echo "\n--- Handshake Pairing Failures ---\n";
$handshake = new Rest_Handshake();

// 3a. Invalid / expired pairing code
$before = wpmmcc_journal_count();
$origin_a = wp_generate_uuid4();
$req = new WP_REST_Request( 'POST', '/wpmmcc/v1/sync/handshake' );
$req->set_body( (string) wp_json_encode( array( 'pairing_code' => 'stale-code-xyz', 'origin_uuid' => $origin_a, 'origin_url' => 'https://remote.example.com' ) ) );
$r = $handshake->handle_handshake( $req );
assert_test( is_wp_error( $r ) && 'wpmmcc_invalid_pairing_code' === $r->get_error_code(), 'Invalid pairing code returns wpmmcc_invalid_pairing_code' );
assert_test( wpmmcc_journal_count() === $before + 1, 'Invalid pairing code writes one journal row' );
$row = wpmmcc_last_journal_row();
wpmmcc_assert_audit_row( $row, 'invalid_pairing_code', '/wpmmcc/v1/sync/handshake' );
assert_test( $origin_a === $row['peer_uuid'], 'Invalid-code row carries the claimed origin UUID' );

// 3b. ADR-5 same-installation rejection
$code = bin2hex( random_bytes( 16 ) );
set_transient( 'wpmmcc_active_pairing_code', $code, 600 );
$origin_b = wp_generate_uuid4();
$req = new WP_REST_Request( 'POST', '/wpmmcc/v1/sync/handshake' );
$req->set_body( (string) wp_json_encode( array( 'pairing_code' => $code, 'origin_uuid' => $origin_b, 'origin_url' => 'https://site-a.example.com', 'install_signature' => Rest_Base::compute_install_signature() ) ) );
$r = $handshake->handle_handshake( $req );
assert_test( is_wp_error( $r ) && 'wpmmcc_same_installation_rejected' === $r->get_error_code(), 'Same install returns wpmmcc_same_installation_rejected' );
$row = wpmmcc_last_journal_row();
wpmmcc_assert_audit_row( $row, 'same_installation_rejected', '/wpmmcc/v1/sync/handshake' );
assert_test( false === strpos( json_encode( $row ), $code ), 'Pairing code never appears in journal rows' );

// 3c. Self-UUID rejection
$code2 = bin2hex( random_bytes( 16 ) );
set_transient( 'wpmmcc_active_pairing_code', $code2, 600 );
$local_uuid = (string) get_option( 'wpmmcc_origin_uuid', '' );
if ( '' === $local_uuid ) {
	$local_uuid = wp_generate_uuid4();
	update_option( 'wpmmcc_origin_uuid', $local_uuid );
}
$req = new WP_REST_Request( 'POST', '/wpmmcc/v1/sync/handshake' );
$req->set_body( (string) wp_json_encode( array( 'pairing_code' => $code2, 'origin_uuid' => $local_uuid, 'origin_url' => 'https://remote-different.example.com' ) ) );
$r = $handshake->handle_handshake( $req );
assert_test( is_wp_error( $r ) && 'wpmmcc_self_pairing_prohibited' === $r->get_error_code(), 'Self UUID returns wpmmcc_self_pairing_prohibited' );
wpmmcc_assert_audit_row( wpmmcc_last_journal_row(), 'self_pairing_prohibited', '/wpmmcc/v1/sync/handshake' );

echo "\n==================================================\n";
echo "PASS: All {$pass_count} Auth failure journal audit tests passed!\n";
echo "==================================================\n";
exit( 0 );
