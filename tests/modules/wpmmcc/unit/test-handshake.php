<?php
/**
 * WPMMCC Handshake & HKDF Key Derivation Unit Test (WP-A-03).
 *
 * Verifies one-time pairing code validation, ADR-5 same-installation rejection,
 * direction negotiation, HKDF session key derivation symmetry, and X-1
 * conflict_strategy carriage (default lww + legacy synonym normalization).
 *
 * @package WPMMCC\Tests\Unit
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

use WPMMCC\REST\Rest_Handshake;
use WPMMCC\REST\Rest_Base;

echo "=== Running Handshake & HKDF Unit Test (WP-A-03) ===\n";

$controller = new Rest_Handshake();

// 1. Test Missing or Expired Pairing Code
$req1  = new WP_REST_Request( 'POST', '/wpmmcc/v1/sync/handshake' );
$req1->set_body( json_encode( array( 'pairing_code' => 'invalid-code' ) ) );

$res1 = $controller->handle_handshake( $req1 );
if ( ! is_wp_error( $res1 ) || 'wpmmcc_invalid_pairing_code' !== $res1->get_error_code() ) {
	echo "FAIL: Invalid pairing code must return wpmmcc_invalid_pairing_code\n";
	exit( 1 );
}

// 2. Set Active Pairing Code
$valid_code = 'test-pairing-code-xyz123';
set_transient( 'wpmmcc_active_pairing_code', $valid_code, 600 );

// 3. Test ADR-5 Same-Installation Rejection
$local_sig = Rest_Base::compute_install_signature();

$req_same_install = new WP_REST_Request( 'POST', '/wpmmcc/v1/sync/handshake' );
$req_same_install->set_body(
	json_encode(
		array(
			'pairing_code'      => $valid_code,
			'origin_uuid'       => wp_generate_uuid4(),
			'origin_url'        => 'https://site-a.example.com',
			'install_signature' => $local_sig, // Same signature!
		)
	)
);

$res_same = $controller->handle_handshake( $req_same_install );
if ( ! is_wp_error( $res_same ) || 'wpmmcc_same_installation_rejected' !== $res_same->get_error_code() ) {
	echo "FAIL: Same installation pairing must be rejected with wpmmcc_same_installation_rejected\n";
	exit( 1 );
}

// 4. Test Successful Handshake & Key Derivation
set_transient( 'wpmmcc_active_pairing_code', $valid_code, 600 ); // restore code

$remote_uuid = wp_generate_uuid4();
$remote_sig  = hash( 'sha256', 'https://site-remote.example.com|1' );

$req_success = new WP_REST_Request( 'POST', '/wpmmcc/v1/sync/handshake' );
$req_success->set_body(
	json_encode(
		array(
			'pairing_code'        => $valid_code,
			'origin_uuid'         => $remote_uuid,
			'origin_url'          => 'https://site-remote.example.com',
			'origin_name'         => 'Remote Site B',
			'install_signature'   => $remote_sig,
			'requested_direction' => 'push_only',
		)
	)
);

$res_success = $controller->handle_handshake( $req_success );
if ( is_wp_error( $res_success ) ) {
	echo "FAIL: Handshake should succeed, got: " . $res_success->get_error_message() . "\n";
	exit( 1 );
}

$data = $res_success->get_data()['data'];

if ( 'paired' !== $data['status'] ) {
	echo "FAIL: Expected status 'paired', got {$data['status']}\n";
	exit( 1 );
}

// Pairing code must be consumed (single-use)
if ( false !== get_transient( 'wpmmcc_active_pairing_code' ) ) {
	echo "FAIL: Pairing code should have been deleted after successful handshake\n";
	exit( 1 );
}

// Assert HKDF Symmetry: Calculate HKDF from remote side perspective
$local_uuid     = get_option( 'wpmmcc_origin_uuid' );
$pairing_secret = $data['pairing_secret'];

$salt_local  = min( $remote_uuid, $local_uuid ) . '|1';
$salt_remote = min( $local_uuid, $remote_uuid ) . '|1';

$key_local  = hash_hkdf( 'sha256', $pairing_secret, 32, 'wpmmcc-peer-hmac-v1', $salt_local );
$key_remote = hash_hkdf( 'sha256', $pairing_secret, 32, 'wpmmcc-peer-hmac-v1', $salt_remote );

if ( $key_local !== $key_remote ) {
	echo "FAIL: HKDF derived keys do not match symmetrically\n";
	exit( 1 );
}

// Direction negotiation check: if remote requested push_only, receiver negotiated pull_only
if ( 'pull_only' !== $data['negotiated_direction'] ) {
	echo "FAIL: Expected negotiated direction pull_only, got {$data['negotiated_direction']}\n";
	exit( 1 );
}

// 5. X-1 (tasks/5.3falsh2/12 批 B): the origin-declared conflict strategy
//    rides the handshake; an absent field keeps the historical 'lww' default
//    (older clients stay compatible), and the peers row feeds it to the
//    arbiter via rest-push.
if ( 'lww' !== ( $data['conflict_strategy'] ?? null ) ) {
	echo "FAIL: handshake without conflict_strategy must default to 'lww'\n";
	exit( 1 );
}

// 6. A declared legacy synonym normalizes onto the canonical vocabulary
//    before it reaches the peers row ('source_dominant' -> 'source_wins').
set_transient( 'wpmmcc_active_pairing_code', 'test-pairing-code-strategy-456', 600 );
$remote_uuid_strategy = wp_generate_uuid4();
$req_strategy = new WP_REST_Request( 'POST', '/wpmmcc/v1/sync/handshake' );
$req_strategy->set_body(
	json_encode(
		array(
			'pairing_code'        => 'test-pairing-code-strategy-456',
			'origin_uuid'         => $remote_uuid_strategy,
			'origin_url'          => 'https://site-strategy.example.com',
			'origin_name'         => 'Remote Site C',
			'requested_direction' => 'push_only',
			'conflict_strategy'   => 'source_dominant',
		)
	)
);
$res_strategy = $controller->handle_handshake( $req_strategy );
if ( is_wp_error( $res_strategy ) ) {
	echo "FAIL: Strategy handshake should succeed, got: " . $res_strategy->get_error_message() . "\n";
	exit( 1 );
}
$data_strategy = $res_strategy->get_data()['data'];
if ( 'source_wins' !== ( $data_strategy['conflict_strategy'] ?? null ) ) {
	echo "FAIL: legacy 'source_dominant' must normalize to canonical 'source_wins'\n";
	exit( 1 );
}

echo "PASS: Handshake & HKDF unit tests passed!\n";
exit( 0 );
