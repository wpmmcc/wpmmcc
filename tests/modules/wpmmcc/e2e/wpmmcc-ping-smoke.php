<?php
/**
 * WPMMCC Ping Endpoint E2E Smoke Test (WP-A-04).
 *
 * Runs against a live WordPress instance (e.g. Docker container or local server)
 * and verifies that GET /wp-json/wpmmcc/v1/sync/ping fulfills Identity Contract v1.0.
 *
 * Usage:
 *   php wpmmcc-ping-smoke.php [TARGET_URL] [CLIENT_TOKEN]
 *
 * Example:
 *   php wpmmcc-ping-smoke.php http://127.0.0.1:9083
 *
 * @package WPMMCC\Tests\E2E
 */

$target_url   = $argv[1] ?? getenv( 'WP_TARGET_URL' ) ?: 'http://127.0.0.1:9083';
$client_token = $argv[2] ?? getenv( 'WP_CLIENT_TOKEN' ) ?: '';

$endpoint = rtrim( $target_url, '/' ) . '/wp-json/wpmmcc/v1/sync/ping';

echo "=== Running WPMMCC Ping Smoke Test ===\n";
echo "Target Endpoint: $endpoint\n";

$ch = curl_init( $endpoint );
curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
curl_setopt( $ch, CURLOPT_TIMEOUT, 10 );

$headers = array( 'Accept: application/json' );
if ( ! empty( $client_token ) ) {
	$headers[] = 'X-WPMMCC-Client-Token: ' . $client_token;
}
curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );

$response = curl_exec( $ch );
$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
$curl_err  = curl_error( $ch );
curl_close( $ch );

if ( false === $response ) {
	echo "ERROR: Failed to connect to $endpoint: $curl_err\n";
	exit( 2 );
}

echo "HTTP Status: $http_code\n";
echo "Response Body:\n$response\n";

if ( 200 !== $http_code ) {
	echo "FAIL: Expected HTTP 200, got $http_code\n";
	exit( 1 );
}

$json = json_decode( $response, true );
if ( ! is_array( $json ) || empty( $json['success'] ) || empty( $json['data'] ) ) {
	echo "FAIL: Invalid JSON response structure\n";
	exit( 1 );
}

$data = $json['data'];

// Identity Contract v1.0 assertions
if ( ( $data['plugin_identity'] ?? '' ) !== 'wpmmcc' ) {
	echo "FAIL: plugin_identity is not 'wpmmcc'\n";
	exit( 1 );
}

if ( empty( $data['plugin_version'] ) ) {
	echo "FAIL: plugin_version is empty\n";
	exit( 1 );
}

if ( (int) ( $data['protocol_min'] ?? 0 ) < 1 ) {
	echo "FAIL: protocol_min must be >= 1\n";
	exit( 1 );
}

if ( (int) ( $data['protocol_current'] ?? 0 ) < (int) $data['protocol_min'] ) {
	echo "FAIL: protocol_current must be >= protocol_min\n";
	exit( 1 );
}

if ( ! preg_match( '/^[a-f0-9]{64}$/', $data['install_signature'] ?? '' ) ) {
	echo "FAIL: install_signature must be a 64-hex lowercase string\n";
	exit( 1 );
}

echo "\nSUCCESS: WPMMCC Ping endpoint conforms to Identity Contract v1.0!\n";
exit( 0 );
