<?php
/**
 * WPMMCC HMAC & Security Middleware Unit Test (WP-A-02).
 *
 * Verifies HMAC-SHA256 signature generation, clock drift enforcement,
 * anti-replay nonce caching, and AES-256-GCM secret encryption at rest.
 *
 * @package WPMMCC\Tests\Unit
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

use WPMMCC\REST\Rest_Middleware;

echo "=== Running HMAC & Security Middleware Unit Test (WP-A-02) ===\n";

// 1. Test AES-256-GCM secret encryption and decryption at rest
$raw_secret = 'test-secret-key-32-bytes-long!';
$key_salt   = bin2hex( random_bytes( 16 ) );

$encrypted = Rest_Middleware::encrypt_shared_secret( $raw_secret, $key_salt );
if ( empty( $encrypted ) || $encrypted === $raw_secret ) {
	echo "FAIL: Encrypted secret must not be empty or plain text\n";
	exit( 1 );
}

$decrypted = Rest_Middleware::decrypt_shared_secret( $encrypted, $key_salt );
if ( $decrypted !== $raw_secret ) {
	echo "FAIL: Decrypted secret does not match original\n";
	exit( 1 );
}

// 2. Test Request Signing
$method    = 'POST';
$uri       = '/wp-json/wpmmcc/v1/sync/push';
$timestamp = time();
$nonce     = bin2hex( random_bytes( 8 ) );
$site_uuid = wp_generate_uuid4();
$body      = '{"hello":"world"}';

$signature = Rest_Middleware::sign_request( $method, $uri, $timestamp, $nonce, $site_uuid, $body, $raw_secret );
if ( ! preg_match( '/^[a-f0-9]{64}$/', $signature ) ) {
	echo "FAIL: Signature must be 64-character lowercase hex\n";
	exit( 1 );
}

// 3. Test Clock Drift Rejection (> 300 seconds)
$expired_request = new WP_REST_Request( 'POST', $uri );
$expired_request->set_header( 'x-wpmmcc-site-uuid', $site_uuid );
$expired_request->set_header( 'x-wpmmcc-timestamp', (string) ( $timestamp - 350 ) ); // 350s drift
$expired_request->set_header( 'x-wpmmcc-nonce', $nonce );
$expired_request->set_header( 'x-wpmmcc-signature', $signature );
$expired_request->set_body( $body );

$drift_result = Rest_Middleware::verify_hmac( $expired_request );
if ( ! is_wp_error( $drift_result ) || 401 !== $drift_result->get_error_data()['status'] ) {
	echo "FAIL: Clock drift > 300s must return 401\n";
	exit( 1 );
}

// 4. Test Missing Headers
$empty_request = new WP_REST_Request( 'POST', $uri );
$miss_result   = Rest_Middleware::verify_hmac( $empty_request );
if ( ! is_wp_error( $miss_result ) || 'wpmmcc_missing_auth_headers' !== $miss_result->get_error_code() ) {
	echo "FAIL: Missing headers must return wpmmcc_missing_auth_headers\n";
	exit( 1 );
}

// 5. Test Nonce Replay Protection
$test_nonce    = bin2hex( random_bytes( 8 ) );
$transient_key = 'wpmmcc_n_' . substr( md5( $test_nonce ), 0, 20 );
set_transient( $transient_key, 1, 600 ); // simulate existing nonce

$replay_request = new WP_REST_Request( 'POST', $uri );
$replay_request->set_header( 'x-wpmmcc-site-uuid', $site_uuid );
$replay_request->set_header( 'x-wpmmcc-timestamp', (string) $timestamp );
$replay_request->set_header( 'x-wpmmcc-nonce', $test_nonce );
$replay_request->set_header( 'x-wpmmcc-signature', $signature );
$replay_request->set_body( $body );

$replay_result = Rest_Middleware::verify_hmac( $replay_request );
if ( ! is_wp_error( $replay_result ) || 403 !== $replay_result->get_error_data()['status'] ) {
	echo "FAIL: Replayed nonce must return 403\n";
	exit( 1 );
}

echo "PASS: HMAC & Security Middleware unit tests passed!\n";
exit( 0 );
