<?php
/**
 * Native WP REST dispatch, post hooks and SQL receipt/projection probe.
 * Not an HTTP, multisite, adapter matrix, or remote-provider acceptance claim.
 */
use WPMMCC\REST\Rest_Middleware;
use WPMMCC\Sync\Packet;

global $wpdb;
if ( ! preg_match( '/^owned[a-f0-9]{16}[ab]_$/D', $wpdb->prefix ) || ! ( $wpdb->dbh instanceof mysqli ) ) {
	throw new RuntimeException( 'Native delivery probe requires an owned mysqli fixture.' );
}
$passed = 0;
$check = static function ( bool $condition, string $name ) use ( &$passed ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $name );
	}
	++$passed;
	echo "PASS: {$name}\n";
};
$peer = wp_generate_uuid4();
$secret = random_bytes( 32 );
$salt = bin2hex( random_bytes( 16 ) );
$check( 1 === $wpdb->insert( $wpdb->prefix . 'wpmmcc_peers', array(
	'peer_uuid' => $peer, 'peer_name' => 'Owned native dispatch',
	'endpoint_url' => 'https://owned-native.invalid', 'shared_secret' => Rest_Middleware::encrypt_shared_secret( $secret, $salt ),
	'key_salt' => $salt, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
) ), 'owned peer authority saved encrypted' );
$call = static function ( string $action, array $body, bool $sign = true ) use ( $peer, $secret, $check ): WP_REST_Response {
	$route = '/wpmmcc/v1/sync/' . $action;
	$request = new WP_REST_Request( 'POST', $route );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( wp_json_encode( $body ) );
	$timestamp = time();
	$nonce = bin2hex( random_bytes( 16 ) );
	if ( $sign ) {
		$request->set_header( 'X-WPMMCC-Site-UUID', $peer );
		$request->set_header( 'X-WPMMCC-Timestamp', (string) $timestamp );
		$request->set_header( 'X-WPMMCC-Nonce', $nonce );
		$request->set_header( 'X-WPMMCC-Signature', Rest_Middleware::sign_request(
			'POST', $route, $timestamp, $nonce, $peer, $request->get_body(), $secret
		) );
	}
	$response = rest_do_request( $request );
	// Internal dispatch does not run the HTTP post-dispatch filter itself.
	$response = apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );
	if ( $sign ) {
		$headers = $response->get_headers();
		$canonical = implode( "\n", array(
			'WPMMCC-RESPONSE-v1', $nonce, (string) $timestamp, $peer, 'POST', $route,
			(string) $response->get_status(), hash( 'sha256', wp_json_encode( $response->get_data() ) ),
		) );
		$check( hash_equals( hash_hmac( 'sha256', $canonical, $secret ), (string) ( $headers['X-WPMMCC-Response-Signature'] ?? '' ) ),
			'native response HMAC binds request and exact reply for ' . $action );
	}
	return $response;
};
$source = wp_insert_post( array( 'post_title' => 'Owned source', 'post_content' => 'Owned content',
	'post_status' => 'publish', 'post_type' => 'post' ), true );
$check( ! is_wp_error( $source ) && $source > 0, 'native WordPress source save succeeds with real hooks' );
$source_packet = Packet::export_post( $source, array( 'target_lang' => 'zh_CN' ) );
$check( is_array( $source_packet ), 'native source export preserves tracked revision' );
$target_uuid = wp_generate_uuid4();
$target_root = 'https://owned-counterpart.invalid/subsite/wp-json/wpmmcc/v1';
$prepared = $call( 'prepare', array( 'packet' => $source_packet,
	'target_site_uuid' => $target_uuid, 'target_site_url' => $target_root ) );
$check( 200 === $prepared->get_status() && true === $prepared->get_data()['data']['prepared'], 'signed native prepare freezes original source authority' );
$key = hash( 'sha256', $peer . "\n" . $source_packet['packet_id'] );
$original = $wpdb->get_var( $wpdb->prepare( "SELECT intent_json FROM {$wpdb->prefix}wpmmcc_source_deliveries WHERE delivery_key=%s", $key ) );
$check( is_string( $original ) && false === strpos( $original, 'Owned content' ), 'source intent contains no plaintext paid packet' );
$receipt = array( 'committed' => true, 'status' => 'success', 'packet_id' => $source_packet['packet_id'],
	'canonical_uuid' => $source_packet['entity']['guid'], 'origin_site_uuid' => $source_packet['origin_context']['origin_site_uuid'],
	'fingerprint_ack' => $source_packet['source_fingerprint'], 'target_lang' => $source_packet['target_lang'],
	'target_site_uuid' => $target_uuid, 'target_id' => 77, 'target_url' => 'https://owned-counterpart.invalid/subsite/owned-target/' );
$foreign = $receipt;
$foreign['target_url'] = 'https://owned-counterpart.invalid/sibling/owned-target/';
$refused = $call( 'confirm', array( 'packet' => $source_packet, 'receipt' => $foreign ) );
$check( 409 === $refused->get_status(), 'native confirmation refuses sibling installation URL' );
$check( $original === $wpdb->get_var( $wpdb->prepare( "SELECT intent_json FROM {$wpdb->prefix}wpmmcc_source_deliveries WHERE delivery_key=%s", $key ) ),
	'foreign receipt refusal retains exact encrypted source authority' );
$confirmed = $call( 'confirm', array( 'packet' => $source_packet, 'receipt' => $receipt ) );
$check( 200 === $confirmed->get_status() && true === $confirmed->get_data()['data']['confirmed'],
	'native exact receipt commits source projection' );
$mapping = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM {$wpdb->prefix}wpmmcc_cross_mappings WHERE canonical_uuid=%s AND peer_uuid=%s",
	$source_packet['entity']['guid'], $target_uuid
), ARRAY_A );
$check( is_array( $mapping ) && $mapping['remote_permalink'] === $receipt['target_url']
	&& (int) $mapping['remote_object_id'] === 77, 'native reciprocal mapping readback matches original receipt' );
$repeat = $call( 'confirm', array( 'packet' => $source_packet, 'receipt' => $receipt ) );
$check( $repeat->get_data() === $confirmed->get_data(), 'same native confirmation replays its exact result' );

$packet = Packet::build( array( 'guid' => wp_generate_uuid4(), 'source_id' => 77,
	'core_fields' => array( 'post_title' => 'Owned inbound', 'post_content' => 'Owned inbound content' ) ) );
$packet['origin_context']['origin_site_uuid'] = $peer;
$packet['origin_context']['origin_site_url'] = 'https://owned-native.invalid';
$packet['origin_context']['origin_permalink'] = 'https://owned-native.invalid/source/';
$packet['origin_context']['vector_clock'] = array( $peer => 1 );
$unauthorized = $call( 'push', $packet, false );
$check( $unauthorized->get_status() >= 400, 'unsigned native push never enters materialization' );
$absent = $call( 'receipt', $packet );
$check( 200 === $absent->get_status() && false === $absent->get_data()['data']['committed']
	&& 'absent' === $absent->get_data()['data']['phase'], 'signed native absence proves this exact packet has no effect' );
$pushed = $call( 'push', $packet );
$data = $pushed->get_data()['data'] ?? array();
$check( 200 === $pushed->get_status() && true === ( $data['committed'] ?? false ) && ( $data['target_id'] ?? 0 ) > 0,
	'native signed push materializes a real WordPress post and commits its receipt' );
$check( 'Owned inbound' === get_post( $data['target_id'] )->post_title, 'native committed post contains the original packet fields' );
$replayed = $call( 'push', $packet );
$check( $replayed->get_data() === $pushed->get_data(), 'native same-packet replay does not rematerialize' );
$lookup = $call( 'receipt', $packet );
$check( $lookup->get_data() === $pushed->get_data(), 'native receipt lookup returns the exact committed result' );
$packet['entity']['core_fields']['post_title'] = 'Forbidden replacement';
$changed = $call( 'push', $packet );
$check( 409 === $changed->get_status() && 'Owned inbound' === get_post( $data['target_id'] )->post_title,
	'changed packet under original identity is refused without replacing committed fields' );
$content = 'owned native media ' . wp_generate_uuid4();
$scope = array( 'file_uuid' => wp_generate_uuid4(), 'total' => 2, 'filename' => 'owned-native.txt',
	'mime_type' => 'text/plain', 'expected_sha256' => hash( 'sha256', $content ), 'expected_size' => strlen( $content ) );
$media_absent = $call( 'media-receipt', $scope );
$check( 200 === $media_absent->get_status() && 'absent' === $media_absent->get_data()['data']['phase'],
	'native media absence is scoped to this original session' );
$cut = intdiv( strlen( $content ), 2 );
$chunk0 = array_merge( $scope, array( 'chunk_index' => 0, 'bytes' => base64_encode( substr( $content, 0, $cut ) ) ) );
$chunk1 = array_merge( $scope, array( 'chunk_index' => 1, 'bytes' => base64_encode( substr( $content, $cut ) ) ) );
$accepted0 = $call( 'media-chunk', $chunk0 );
$check( 200 === $accepted0->get_status(), 'native first media chunk is durably accepted' );
$partial = $call( 'media-receipt', $scope );
$check( 200 === $partial->get_status() && array( 1 ) === $partial->get_data()['data']['missing_chunks'],
	'native media lookup requests only the missing original chunk' );
$accepted1 = $call( 'media-chunk', $chunk1 );
$media = $accepted1->get_data()['data'] ?? array();
$check( 200 === $accepted1->get_status() && ( $media['attachment_id'] ?? 0 ) > 0,
	'native final media chunk materializes a real WordPress attachment' );
$check( $content === file_get_contents( get_attached_file( $media['attachment_id'] ) ),
	'native attachment preserves the exact original media bytes' );
$media_done = $call( 'media-receipt', $scope );
$check( 200 === $media_done->get_status() && 'applied' === $media_done->get_data()['data']['phase']
	&& $media_done->get_data()['data']['attachment_id'] === $media['attachment_id'],
	'native media receipt authenticates the original committed attachment' );
$media_replay = $call( 'media-chunk', $chunk1 );
$check( 200 === $media_replay->get_status() && $media_replay->get_data()['data']['attachment_id'] === $media['attachment_id'],
	'native repeated original media chunk never creates a second attachment' );
$foreign_scope = $scope;
$foreign_scope['filename'] = 'forbidden-replacement.txt';
$media_refused = $call( 'media-receipt', $foreign_scope );
$check( 409 === $media_refused->get_status() && $content === file_get_contents( get_attached_file( $media['attachment_id'] ) ),
	'native changed media scope cannot replace original materialization' );
echo "RESULT {$passed} passed, 0 failed (native REST dispatch/hooks/SQL; not HTTP or multisite acceptance)\n";
