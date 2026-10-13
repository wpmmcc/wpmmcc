<?php
/**
 * WPMMCC REST Media Routes Contract Test (G-02, media-chunk + media-download).
 *
 * Route-level coverage:
 * - POST /sync/media-chunk: HMAC gate wiring, HMAC negative cases, and the
 *   chunk payload/quota validation errors surfaced behind a valid gate, plus
 *   a full single-chunk upload happy path through the route.
 * - GET /sync/media-download: the time-limited asset_token transient path —
 *   missing token, expired/unknown token, valid token with missing file,
 *   and a valid token streaming the physical file bytes (subprocess).
 *
 * @package WPMMCC\Tests\Contract
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/support/class-mini-sql-wpdb.php';

use WPMMCC\REST\Rest_Middleware;
use WPMMCC\REST\Rest_Media;

echo "=== Running REST Media Routes Contract Test (G-02) ===\n";

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

function wpmmcc_mock_db_media( array $peers = array() ) {
	global $wpdb;
	$wpdb = new Mini_SQL_wpdb();
	$wpdb->create_table( 'wp_wpmmcc_peers', array() );
	$wpdb->create_table( 'wp_wpmmcc_nonces', array() );
	$wpdb->create_table( 'wp_wpmmcc_asset_registry', array() );
	$wpdb->create_table( 'wp_wpmmcc_journal', array() );
	foreach ( $peers as $peer ) { $wpdb->insert( 'wp_wpmmcc_peers', (array) $peer ); }
}

function wpmmcc_peer_media( string $peer_uuid, string $secret ): object {
	$salt = bin2hex( random_bytes( 8 ) );
	return (object) array(
		'peer_uuid'     => $peer_uuid,
		'shared_secret' => Rest_Middleware::encrypt_shared_secret( $secret, $salt ),
		'key_salt'      => $salt,
		'status'        => 'active',
	);
}

function wpmmcc_signed_request_media( string $method, string $route, string $body, string $peer_uuid, string $secret, int $ts = 0 ): WP_REST_Request {
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

function wpmmcc_dispatch_media( array $def, WP_REST_Request $request ) {
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
$media = new Rest_Media();
$media->register_routes();

$chunk_def = $GLOBALS['wp_mock_rest_routes']['wpmmcc/v1/sync/media-chunk'] ?? null;
$dl_def    = $GLOBALS['wp_mock_rest_routes']['wpmmcc/v1/sync/media-download'] ?? null;
assert_test( is_array( $chunk_def ), '/sync/media-chunk route is registered' );
assert_test( WP_REST_Server::CREATABLE === $chunk_def['methods'], '/sync/media-chunk accepts POST' );
assert_test( array( 'WPMMCC\REST\Rest_Middleware', 'verify_hmac' ) === $chunk_def['permission_callback'], '/sync/media-chunk gate is Rest_Middleware::verify_hmac' );
assert_test( is_array( $dl_def ), '/sync/media-download route is registered' );
assert_test( WP_REST_Server::READABLE === $dl_def['methods'], '/sync/media-download accepts GET' );
assert_test(
	is_array( $dl_def['permission_callback'] )
	&& $dl_def['permission_callback'][0] instanceof Rest_Media
	&& 'permission_download' === $dl_def['permission_callback'][1],
	'/sync/media-download auth checks the asset token and does not consume it'
);

$CHUNK_ROUTE = '/wpmmcc/v1/sync/media-chunk';
$DL_ROUTE    = '/wpmmcc/v1/sync/media-download';
$SECRET      = 'media-contract-shared-secret';
$PEER_UUID   = wp_generate_uuid4();
wpmmcc_mock_db_media( array( wpmmcc_peer_media( $PEER_UUID, $SECRET ) ) );
$owned_uploads = sys_get_temp_dir() . '/wpmmcc-media-contract-' . bin2hex( random_bytes( 8 ) );
mkdir( $owned_uploads, 0700 );
$GLOBALS['wp_mock_upload_dir'] = $owned_uploads;

// -------------------------------------------------------------
// 2. media-chunk: HMAC negatives
// -------------------------------------------------------------
echo "\n--- media-chunk: HMAC Negatives ---\n";
$valid_body = (string) wp_json_encode(
	array(
		'file_uuid'   => 'chunkfileunit01',
		'chunk_index' => 0,
		'total'       => 1,
		'bytes'       => base64_encode( 'hello' ),
		'filename' => 'hello.txt', 'mime_type' => 'text/plain',
		'expected_sha256' => hash( 'sha256', 'hello' ), 'expected_size' => 5,
	)
);

$unsigned = new WP_REST_Request( 'POST', $CHUNK_ROUTE );
$unsigned->set_body( $valid_body );
$r = wpmmcc_dispatch_media( $chunk_def, $unsigned );
assert_test( is_wp_error( $r ) && 'wpmmcc_missing_auth_headers' === $r->get_error_code(), 'Unsigned chunk upload => wpmmcc_missing_auth_headers (401)' );

$forged = wpmmcc_signed_request_media( 'POST', $CHUNK_ROUTE, $valid_body, $PEER_UUID, 'wrong-secret' );
$r = wpmmcc_dispatch_media( $chunk_def, $forged );
assert_test( is_wp_error( $r ) && 'wpmmcc_signature_mismatch' === $r->get_error_code(), 'Forged chunk signature => wpmmcc_signature_mismatch (401)' );

$ghost = wpmmcc_signed_request_media( 'POST', $CHUNK_ROUTE, $valid_body, wp_generate_uuid4(), $SECRET );
$r = wpmmcc_dispatch_media( $chunk_def, $ghost );
assert_test( is_wp_error( $r ) && 'wpmmcc_peer_unauthorized' === $r->get_error_code(), 'Unknown peer chunk upload => wpmmcc_peer_unauthorized (403)' );

$replayed = wpmmcc_signed_request_media( 'POST', $CHUNK_ROUTE, $valid_body, $PEER_UUID, $SECRET );
$r1 = wpmmcc_dispatch_media( $chunk_def, $replayed );
// A replay is a second HTTP request: identical bytes, distinct request object
// (the gate memoizes its verdict per request object within one dispatch).
$r2 = wpmmcc_dispatch_media( $chunk_def, clone $replayed );
assert_test( is_wp_error( $r2 ) && 'wpmmcc_nonce_replayed' === $r2->get_error_code(), 'Replayed nonce chunk upload => wpmmcc_nonce_replayed (403)' );

// -------------------------------------------------------------
// 3. media-chunk: payload & quota validation behind a valid gate
// -------------------------------------------------------------
echo "\n--- media-chunk: Payload & Quota Validation ---\n";

$no_uuid = wpmmcc_signed_request_media( 'POST', $CHUNK_ROUTE, (string) wp_json_encode( array( 'bytes' => base64_encode( 'x' ) ) ), $PEER_UUID, $SECRET );
$r = wpmmcc_dispatch_media( $chunk_def, $no_uuid );
assert_test( is_wp_error( $r ) && 'wpmmcc_invalid_chunk_payload' === $r->get_error_code(), 'Missing file_uuid => wpmmcc_invalid_chunk_payload (400)' );

$quota = wpmmcc_signed_request_media(
	'POST',
	$CHUNK_ROUTE,
(string) wp_json_encode( array_merge( json_decode( $valid_body, true ), array( 'file_uuid' => 'chunkfileunit02', 'total' => 9999 ) ) ),
	$PEER_UUID,
	$SECRET
);
$r = wpmmcc_dispatch_media( $chunk_def, $quota );
assert_test( is_wp_error( $r ) && 'wpmmcc_invalid_chunk_payload' === $r->get_error_code(), 'Chunk count out of bounds => wpmmcc_invalid_chunk_payload (400)' );

$huge  = str_repeat( 'A', 6 * 1024 * 1024 ); // > 4MB after base64 decode allowance
$too_big = wpmmcc_signed_request_media(
	'POST',
	$CHUNK_ROUTE,
(string) wp_json_encode( array_merge( json_decode( $valid_body, true ), array( 'file_uuid' => 'chunkfileunit03', 'bytes' => base64_encode( $huge ) ) ) ),
	$PEER_UUID,
	$SECRET
);
$r = wpmmcc_dispatch_media( $chunk_def, $too_big );
assert_test( is_wp_error( $r ) && 'wpmmcc_chunk_too_large' === $r->get_error_code(), 'Chunk > 4MB => wpmmcc_chunk_too_large (413)' );

// -------------------------------------------------------------
// 4. media-chunk: full single-chunk upload happy path through the route
// -------------------------------------------------------------
echo "\n--- media-chunk: Single-Chunk Upload Happy Path ---\n";
$file_uuid = 'chunkfileunit' . bin2hex( random_bytes( 4 ) );
$payload   = (string) wp_json_encode(
	array(
		'file_uuid' => $file_uuid,
		'chunk_index' => 0,
		'total'     => 1,
		'bytes'     => base64_encode( 'wpmmcc-media-contract-payload' ),
		'filename'  => 'wpmmcc-media-contract.txt',
		'mime_type' => 'text/plain',
		'expected_sha256' => hash( 'sha256', 'wpmmcc-media-contract-payload' ),
		'expected_size' => strlen( 'wpmmcc-media-contract-payload' ),
	)
);
$signed = wpmmcc_signed_request_media( 'POST', $CHUNK_ROUTE, $payload, $PEER_UUID, $SECRET );
$r = wpmmcc_dispatch_media( $chunk_def, $signed );

assert_test( ! is_wp_error( $r ), 'Signed chunk upload passes the gate and reaches the handler' );
$chunk_data = $r->get_data();
assert_test( true === $chunk_data['success'], 'Chunk upload response success=true' );
assert_test( 200 === $r->get_status(), 'Chunk upload response status 200' );
$body = $chunk_data['data'];
assert_test( true === $body['chunk_acked'] && true === $body['completed'], 'Single-chunk upload acked and completed assembly' );
assert_test( 64 === strlen( (string) $body['assembled_sha256'] ), 'Assembled content SHA-256 returned' );
assert_test( (int) $body['attachment_id'] > 0, 'Chunk upload materialized a local attachment' );

// Retain the owned encrypted intent, chunks and attachment as failure evidence.
echo "MEDIA_EVIDENCE_ROOT={$owned_uploads}\n";

// -------------------------------------------------------------
// 5. media-download: time-limited asset_token transient path
// -------------------------------------------------------------
echo "\n--- media-download: Asset Token Path ---\n";

// 5a. Missing token -> 401 wpmmcc_missing_asset_token
$req = new WP_REST_Request( 'GET', $DL_ROUTE );
$r = wpmmcc_dispatch_media( $dl_def, $req );
assert_test( is_wp_error( $r ) && 'wpmmcc_missing_asset_token' === $r->get_error_code(), 'Missing asset token => wpmmcc_missing_asset_token (401)' );

// 5b. Unknown / expired token -> 401 wpmmcc_expired_asset_token
$req = new WP_REST_Request( 'GET', $DL_ROUTE );
$req->set_param( 'asset_token', 'expired-token-' . bin2hex( random_bytes( 4 ) ) );
$r = wpmmcc_dispatch_media( $dl_def, $req );
assert_test( is_wp_error( $r ) && 'wpmmcc_expired_asset_token' === $r->get_error_code(), 'Unknown/expired asset token => wpmmcc_expired_asset_token (401)' );

// 5c. Expired transient -> 401 wpmmcc_expired_asset_token (transient TTL enforced)
$tok = 'tok-' . bin2hex( random_bytes( 6 ) );
set_transient( 'wpmmcc_at_' . $tok, 55, -1 ); // already expired
$req = new WP_REST_Request( 'GET', $DL_ROUTE );
$req->set_param( 'asset_token', $tok );
$r = wpmmcc_dispatch_media( $dl_def, $req );
assert_test( is_wp_error( $r ) && 'wpmmcc_expired_asset_token' === $r->get_error_code(), 'Expired transient asset token => wpmmcc_expired_asset_token (401)' );

// 5d. Valid token but no physical file -> 404 wpmmcc_media_not_found
// (shim get_attached_file() points at a non-existent /tmp path)
$tok = 'tok-' . bin2hex( random_bytes( 6 ) );
set_transient( 'wpmmcc_at_' . $tok, 55, 300 );
$req = new WP_REST_Request( 'GET', $DL_ROUTE );
$req->set_param( 'asset_token', $tok );
$r = wpmmcc_dispatch_media( $dl_def, $req );
assert_test( is_wp_error( $r ) && 'wpmmcc_media_not_found' === $r->get_error_code(), 'Valid token + missing physical file => wpmmcc_media_not_found (404)' );

// 5e. Valid token + real file -> route streams the file bytes.
// The handler terminates the request with exit after echoing, so run it
// in a subprocess and compare the emitted bytes with the file content.
echo "\n--- media-download: Streaming Happy Path (subprocess) ---\n";
$media_file     = sys_get_temp_dir() . '/wpmmcc-dl-media-' . bin2hex( random_bytes( 4 ) ) . '.jpg';
$media_bytes    = 'WPMMCC-BINARY-MEDIA-BYTES-' . bin2hex( random_bytes( 8 ) );
file_put_contents( $media_file, $media_bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions
$tok            = 'tok-' . bin2hex( random_bytes( 6 ) );
$attachment_id  = 55;

$bootstrap_path = dirname( __DIR__ ) . '/bootstrap.php';
$script         = sys_get_temp_dir() . '/wpmmcc-dl-test-' . bin2hex( random_bytes( 4 ) ) . '.php';
$script_body    = <<<'PHP'
<?php
// Define get_attached_file BEFORE bootstrap so it wins the function_exists guard.
$GLOBALS['wpmmcc_test_media_file'] = __MEDIA_FILE__;
function get_attached_file( $attachment_id, $unfiltered = false ) {
	return $GLOBALS['wpmmcc_test_media_file'];
}
require __BOOTSTRAP__;
set_transient( 'wpmmcc_at_' . __TOKEN__, __ATTACHMENT_ID__, 300 );
$GLOBALS['wp_mock_rest_routes'] = array();
$media = new WPMMCC\REST\Rest_Media();
$media->register_routes();
$def = $GLOBALS['wp_mock_rest_routes']['wpmmcc/v1/sync/media-download'];
$req = new WP_REST_Request( 'GET', '/wpmmcc/v1/sync/media-download' );
$req->set_param( 'asset_token', __TOKEN__ );
$perm = call_user_func( $def['permission_callback'], $req );
if ( is_wp_error( $perm ) ) { echo 'PERM_ERROR:' . $perm->get_error_code(); exit( 1 ); }
$res = call_user_func( $def['callback'], $req );
if ( is_wp_error( $res ) ) { echo 'HANDLER_ERROR:' . $res->get_error_code(); exit( 1 ); }
exit( 0 );
PHP;
// Interpolate the literals safely (token appears inside a quoted string concat).
$script_body = str_replace(
	array( '__MEDIA_FILE__', '__BOOTSTRAP__', '__ATTACHMENT_ID__' ),
	array( var_export( $media_file, true ), var_export( $bootstrap_path, true ), (string) $attachment_id ),
	$script_body
);
$script_body = str_replace( '__TOKEN__', var_export( $tok, true ), $script_body );
file_put_contents( $script, $script_body ); // phpcs:ignore WordPress.WP.AlternativeFunctions

exec( 'php ' . escapeshellarg( $script ) . ' 2>&1', $out_lines, $out_code );
$out = implode( "\n", $out_lines );
assert_test( 0 === $out_code, 'Valid-token download subprocess exits 0' );
assert_test( false === strpos( $out, 'PERM_ERROR' ) && false === strpos( $out, 'HANDLER_ERROR' ) && false === strpos( $out, 'Fatal' ), 'Streaming download subprocess produced no errors' );
assert_test( $media_bytes === $out, 'Valid asset token streams the exact physical file bytes' );

@unlink( $media_file );
@unlink( $script );

echo "\n==================================================\n";
echo "PASS: All {$pass_count} REST Media routes contract tests passed!\n";
echo "==================================================\n";
exit( 0 );
