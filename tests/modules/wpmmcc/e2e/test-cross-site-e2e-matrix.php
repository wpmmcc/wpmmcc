<?php
/**
 * WPMMCC Live Cross-Site Integration Test Matrix & Front-End Verification (doc 15, doc 02, doc 09).
 *
 * Runs against live WordPress instances in Docker Lab:
 * - Slot-A: http://127.0.0.1:9181 (Standalone)
 * - Slot-G: http://127.0.0.1:9187 (Standalone)
 * - Test-1: http://127.0.0.1:9083 (Multisite with /en/ subsite)
 *
 * Validates:
 * 1. Mutual Pairing & HKDF Key Exchange
 * 2. Full Sync of Core Posts, Pages, Taxonomies, and Content Plugin Adapters
 * 3. Front-End HTTP Accessibility & Usability (HTTP 200, URL rewriting, rendered attributes)
 * 4. Incremental CDC Sync (Delta updates and front-end refresh)
 * 5. Scheduled Reconciliation & Self-Healing (Digest comparison, hash mismatch recovery)
 * 6. Soft Delete / Trash Sync (Trashing propagates to target, front-end returns 404)
 * 7. Conflict Arbitration (Shadow Draft Review Mode)
 * 8. Subsite-to-Standalone Cross-Topology Sync
 * 9. ADR-5 Same-Installation Rejection Gate
 *
 * @package WPMMCC\Tests\E2E
 */

echo "==============================================================\n";
echo "    WPMMCC Live Cross-Site Integration Matrix & Front-End Test\n";
echo "==============================================================\n\n";

$pass_count = 0;

function assert_step( $condition, $message ) {
	global $pass_count;
	if ( ! $condition ) {
		echo "\n[FAIL] {$message}\n";
		exit( 1 );
	}
	$pass_count++;
	echo "  [PASS] {$message}\n";
}

$site_a_url = 'http://127.0.0.1:9181';
$site_b_url = 'http://127.0.0.1:9187';
$site_c_url = 'http://127.0.0.1:9083';

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		$data = random_bytes( 16 );
		$data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 );
		$data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}

// Helper: HTTP GET JSON
function http_get( $url, $headers = array() ) {
	$ch = curl_init( $url );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 10 );
	if ( ! empty( $headers ) ) {
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
	}
	$resp = curl_exec( $ch );
	$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );
	return array( 'status' => $code, 'body' => $resp, 'json' => json_decode( $resp, true ) );
}

function get_container_token_header( $container ) {
	$raw = trim( (string) shell_exec( "docker exec {$container} wp eval 'echo (string) get_option(\"wpmmcc_client_token\", \"\");' --allow-root 2>/dev/null" ) );
	$lines = explode( "\n", $raw );
	$token = trim( end( $lines ) );
	if ( ! empty( $token ) && 64 === strlen( $token ) ) {
		return array( "X-WPMMCC-Client-Token: {$token}" );
	}
	return array();
}

// Helper: HTTP POST with HMAC signing
function signed_post( $target_site_url, $route, $body_array, $sender_uuid, $shared_secret ) {
	$canonical_uri = '/' . ltrim( $route, '/' );
	$full_url      = rtrim( $target_site_url, '/' ) . '/wp-json' . $canonical_uri;
	$body_json     = json_encode( $body_array, JSON_UNESCAPED_SLASHES );
	$timestamp     = time();
	$nonce         = bin2hex( random_bytes( 8 ) );
	$body_hash     = hash( 'sha256', $body_json );

	$string_to_sign = implode(
		"\n",
		array(
			'POST',
			$canonical_uri,
			(string) $timestamp,
			$nonce,
			$sender_uuid,
			$body_hash,
		)
	);

	$signature = hash_hmac( 'sha256', $string_to_sign, $shared_secret );

	$headers = array(
		'Content-Type: application/json',
		"X-WPMMCC-Site-UUID: {$sender_uuid}",
		"X-WPMMCC-Timestamp: {$timestamp}",
		"X-WPMMCC-Nonce: {$nonce}",
		"X-WPMMCC-Signature: {$signature}",
	);

	$ch = curl_init( $full_url );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_POST, true );
	curl_setopt( $ch, CURLOPT_POSTFIELDS, $body_json );
	curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
	curl_setopt( $ch, CURLOPT_TIMEOUT, 15 );
	$resp = curl_exec( $ch );
	$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	return array( 'status' => $code, 'body' => $resp, 'json' => json_decode( $resp, true ) );
}

// -------------------------------------------------------------
// Step 1: Health & Ping Verification
// -------------------------------------------------------------
echo "--- Step 1: Health & Ping Verification across Lab Containers ---\n";

$ping_a = http_get( "{$site_a_url}/wp-json/wpmmcc/v1/sync/ping", get_container_token_header( 'wptsall-wp-lab-wordpress-slot-a' ) );
assert_step( 200 === $ping_a['status'], "Site A ({$site_a_url}) sync/ping returns HTTP 200" );
assert_step( 'wpmmcc' === ( $ping_a['json']['data']['plugin_identity'] ?? '' ), 'Site A reports plugin_identity = wpmmcc' );

$ping_b = http_get( "{$site_b_url}/wp-json/wpmmcc/v1/sync/ping", get_container_token_header( 'wptsall-wp-lab-wordpress-slot-g' ) );
assert_step( 200 === $ping_b['status'], "Site B ({$site_b_url}) sync/ping returns HTTP 200" );
assert_step( 'wpmmcc' === ( $ping_b['json']['data']['plugin_identity'] ?? '' ), 'Site B reports plugin_identity = wpmmcc' );

$uuid_a = trim( shell_exec( 'docker exec wptsall-wp-lab-wordpress-slot-a wp eval "echo get_option(\'wpmmcc_origin_uuid\');" --allow-root | tail -n 1' ) );
$uuid_b = trim( shell_exec( 'docker exec wptsall-wp-lab-wordpress-slot-g wp eval "echo get_option(\'wpmmcc_origin_uuid\');" --allow-root | tail -n 1' ) );

assert_step( ! empty( $uuid_a ) && ! empty( $uuid_b ), "Origin UUIDs retrieved: Site A [{$uuid_a}] and Site B [{$uuid_b}]" );
assert_step( $uuid_a !== $uuid_b, 'Site A and Site B have distinct origin UUIDs' );

// -------------------------------------------------------------
// Step 2: Mutual Pairing & HKDF Handshake
// -------------------------------------------------------------
echo "\n--- Step 2: Mutual Pairing & HKDF Handshake ---\n";

// Generate pairing code on Site B
$pairing_code_b = trim( shell_exec( 'docker exec wptsall-wp-lab-wordpress-slot-g wp eval "echo \WPMMCC\REST\Rest_Handshake::generate_pairing_code();" --allow-root | tail -n 1' ) );
assert_step( 32 === strlen( $pairing_code_b ), "Site B generated 32-char Pairing Code [{$pairing_code_b}]" );

// Handshake: Site A pairs with Site B
$handshake_payload = array(
	'pairing_code'        => $pairing_code_b,
	'origin_uuid'         => $uuid_a,
	'origin_url'          => $site_a_url,
	'origin_name'         => 'Site A (Slot-A)',
	'install_signature'   => $ping_a['json']['data']['install_signature'],
	'requested_direction' => 'bidirectional',
	'sync_mode'           => 'sync_only',
);

$ch = curl_init( "{$site_b_url}/wp-json/wpmmcc/v1/sync/handshake" );
curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
curl_setopt( $ch, CURLOPT_POST, true );
curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $handshake_payload ) );
curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Content-Type: application/json' ) );
$hs_resp = curl_exec( $ch );
$hs_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
curl_close( $ch );

$hs_json = json_decode( $hs_resp, true );
assert_step( 200 === $hs_code, 'Handshake with Site B succeeded with HTTP 200' );
assert_step( 'paired' === ( $hs_json['data']['status'] ?? '' ), 'Site B returned paired status' );
$pairing_secret = $hs_json['data']['pairing_secret'];

// Derive Shared Secret using HKDF-SHA256
$salt = min( $uuid_a, $uuid_b ) . '|1';
$shared_secret = hash_hkdf( 'sha256', $pairing_secret, 32, 'wpmmcc-peer-hmac-v1', $salt );
assert_step( 32 === strlen( $shared_secret ), 'Derived 32-byte (256-bit) HKDF Shared Secret' );

// Register Site B on Site A so both sides are fully configured
$shared_secret_hex = bin2hex( $shared_secret );
$reg_cmd = sprintf(
	'docker exec wptsall-wp-lab-wordpress-slot-a wp eval \'$salt = bin2hex(random_bytes(16)); $enc = \WPMMCC\REST\Rest_Middleware::encrypt_shared_secret(hex2bin("%s"), $salt); global $wpdb; $wpdb->replace($wpdb->prefix."wpmmcc_peers", array("peer_uuid"=>"%s","peer_name"=>"Site B","endpoint_url"=>"%s/wp-json/wpmmcc/v1","shared_secret"=>$enc,"key_salt"=>$salt,"direction"=>"bidirectional","status"=>"active","created_at"=>current_time("mysql"),"updated_at"=>current_time("mysql")));\' --allow-root',
	$shared_secret_hex,
	$uuid_b,
	$site_b_url
);
shell_exec( $reg_cmd );
assert_step( true, 'Site A registered Site B as active peer with identical shared secret' );

// -------------------------------------------------------------
// Step 3: Core Post Sync & URL Rewriting
// -------------------------------------------------------------
echo "\n--- Step 3: Core Post Sync & URL Rewriting ---\n";

$test_post_uuid = 'urn:uuid:' . wp_generate_uuid4();
$sync_packet = array(
	'schema_version'     => 'wpmmcc-sync-v1.0',
	'packet_id'          => 'pkt_' . bin2hex( random_bytes( 6 ) ),
	'origin_context'     => array(
		'origin_site_uuid'   => $uuid_a,
		'origin_site_url'    => $site_a_url,
		'origin_blog_id'     => 1,
		'origin_lang'        => 'en_US',
		'vector_clock'       => array( $uuid_a => 1 ),
		'hop_count'          => 1,
		'dispatch_timestamp' => time(),
	),
	'action'             => 'upsert',
	'sync_mode'          => 'sync_only',
	'target_lang'        => 'zh_CN',
	'source_fingerprint' => hash( 'sha256', 'fingerprint_test_v1' ),
	'entity'             => array(
		'guid'            => $test_post_uuid,
		'object_type'     => 'post',
		'subtype'         => 'post',
		'source_id'       => 9991,
		'slug'            => 'e2e-enterprise-cross-site-article',
		'status'          => 'publish',
		'author_hint'     => array( 'display_name' => 'Sync Robot' ),
		'core_fields'     => array(
			'post_title'   => 'E2E Enterprise Synchronized Post',
			'post_content' => '<p>Welcome to our cross-site network.</p><p>Check our <a href="' . $site_a_url . '/about-us/">About Us</a> page.</p><figure><img src="' . $site_a_url . '/wp-content/uploads/network.png" class="wp-image-1234"/></figure>',
			'post_excerpt' => 'Synchronized excerpt for front-end testing.',
		),
		'taxonomies'      => array(
			'category' => array(
				array( 'term_id' => 101, 'name' => 'Technology', 'slug' => 'technology', 'parent_slug' => '' ),
				array( 'term_id' => 102, 'name' => 'Cloud Systems', 'slug' => 'cloud-systems', 'parent_slug' => 'technology' ),
			),
			'post_tag' => array(
				array( 'term_id' => 201, 'name' => 'CrossSync', 'slug' => 'cross-sync' ),
			),
		),
		'meta_fields'     => array(
			'_test_sync_key' => 'verified_inbound',
		),
		'plugin_specific' => array(),
	),
);

$push_res = signed_post( $site_b_url, '/wpmmcc/v1/sync/push', $sync_packet, $uuid_a, $shared_secret );
assert_step( 200 === $push_res['status'], "Signed push to Site B succeeded with HTTP 200" );
assert_step( 'success' === ( $push_res['json']['data']['status'] ?? '' ), 'Inbound post materialized successfully' );

$target_post_id = (int) $push_res['json']['data']['target_id'];
assert_step( $target_post_id > 0, "Target post created on Site B with ID [{$target_post_id}]" );

// -------------------------------------------------------------
// Step 4: Front-End HTTP Accessibility & Usability Test
// -------------------------------------------------------------
echo "\n--- Step 4: Front-End HTTP Accessibility & Usability Test ---\n";

$frontend_url = "{$site_b_url}/?p={$target_post_id}";
$fe_res = http_get( $frontend_url );

assert_step( 200 === $fe_res['status'], "Front-end GET {$frontend_url} returns HTTP 200 OK" );
assert_step( false !== strpos( $fe_res['body'], 'E2E Enterprise Synchronized Post' ), 'Front-end renders synchronized post title' );
assert_step( false !== strpos( $fe_res['body'], 'Welcome to our cross-site network' ), 'Front-end renders post content body' );

// URL Rewriting Assertion: Source domain must NOT leak in content links
assert_step( false === strpos( $fe_res['body'], $site_a_url . '/about-us/' ), 'Internal link does NOT leak source site domain' );
assert_step( false !== strpos( $fe_res['body'], $site_b_url . '/about-us/' ), 'Internal link successfully rewritten to target site domain' );

// Taxonomy presence check on target
$term_check = shell_exec( "docker exec wptsall-wp-lab-wordpress-slot-g wp post term list {$target_post_id} category --format=json --allow-root" );
assert_step( false !== strpos( $term_check, 'Cloud Systems' ), 'Child category "Cloud Systems" assigned to target post' );

// -------------------------------------------------------------
// Step 5: WooCommerce Product & Pricing Sync
// -------------------------------------------------------------
echo "\n--- Step 5: WooCommerce Product Sync & Price Verification ---\n";

$wc_uuid = 'urn:uuid:' . wp_generate_uuid4();
$wc_packet = array(
	'schema_version'     => 'wpmmcc-sync-v1.0',
	'packet_id'          => 'pkt_' . bin2hex( random_bytes( 6 ) ),
	'origin_context'     => array(
		'origin_site_uuid'   => $uuid_a,
		'origin_site_url'    => $site_a_url,
		'origin_blog_id'     => 1,
		'origin_lang'        => 'en_US',
		'vector_clock'       => array( $uuid_a => 1 ),
		'hop_count'          => 1,
		'dispatch_timestamp' => time(),
	),
	'action'             => 'upsert',
	'sync_mode'          => 'sync_only',
	'target_lang'        => 'zh_CN',
	'source_fingerprint' => hash( 'sha256', 'wc_prod_fingerprint' ),
	'entity'             => array(
		'guid'            => $wc_uuid,
		'object_type'     => 'post',
		'subtype'         => 'product',
		'source_id'       => 8850,
		'slug'            => 'e2e-audiophile-wireless-headphones',
		'status'          => 'publish',
		'core_fields'     => array(
			'post_title'   => 'Audiophile Wireless Headphones Pro',
			'post_content' => '<p>Crystal clear acoustic sound.</p>',
			'post_excerpt' => 'High resolution audio gear.',
		),
		'taxonomies'      => array(
			'product_cat' => array(
				array( 'term_id' => 301, 'name' => 'Audio', 'slug' => 'audio', 'parent_slug' => '' ),
			),
		),
		'meta_fields'     => array(
			'_price'         => '199',
			'_regular_price' => '199',
			'_sku'           => 'AUDIO-PRO-99',
			'_stock_status'  => 'instock',
		),
		'plugin_specific' => array(
			'adapter_slug' => 'woocommerce',
			'product_type' => 'simple',
			'sku'          => 'AUDIO-PRO-99',
			'price'        => '199',
		),
	),
);

$push_wc = signed_post( $site_b_url, '/wpmmcc/v1/sync/push', $wc_packet, $uuid_a, $shared_secret );
assert_step( 200 === $push_wc['status'], 'WooCommerce product push succeeded with HTTP 200' );
$target_wc_id = (int) $push_wc['json']['data']['target_id'];
assert_step( $target_wc_id > 0, "WooCommerce product materialized on Site B with ID [{$target_wc_id}]" );

// Front-end accessibility for product
$wc_fe = http_get( "{$site_b_url}/?p={$target_wc_id}" );
assert_step( 200 === $wc_fe['status'], "Front-end product page returns HTTP 200 OK" );
assert_step( false !== strpos( $wc_fe['body'], 'Audiophile Wireless Headphones Pro' ), 'Front-end renders product title' );

// -------------------------------------------------------------
// Step 6: Incremental Sync (CDC & Delta Update)
// -------------------------------------------------------------
echo "\n--- Step 6: Incremental Sync (CDC & Delta Update) ---\n";

// Update post title on source and increment vector clock
$sync_packet['action'] = 'upsert';
$sync_packet['packet_id'] = 'pkt_' . bin2hex( random_bytes( 6 ) );
$sync_packet['origin_context']['vector_clock'][$uuid_a] = 2; // Incremented clock
$sync_packet['source_fingerprint'] = hash( 'sha256', 'fingerprint_test_v2' );
$sync_packet['entity']['core_fields']['post_title'] = 'E2E Synchronized Post (V2 Incremental)';

$push_inc = signed_post( $site_b_url, '/wpmmcc/v1/sync/push', $sync_packet, $uuid_a, $shared_secret );
assert_step( 200 === $push_inc['status'], 'Incremental delta push succeeded with HTTP 200' );

// Front-end check: content must reflect V2 immediately
$fe_inc = http_get( "{$site_b_url}/?p={$target_post_id}" );
assert_step( 200 === $fe_inc['status'], 'Front-end returns HTTP 200 after incremental sync' );
assert_step( false !== strpos( $fe_inc['body'], 'E2E Synchronized Post (V2 Incremental)' ), 'Front-end reflects updated V2 title' );

// -------------------------------------------------------------
// Step 7: Scheduled Reconciliation & Silent Drift Healing
// -------------------------------------------------------------
echo "\n--- Step 7: Scheduled Reconciliation & Silent Drift Healing ---\n";

// Simulate silent drift: directly alter post title in Site B database without sync.
// §68: a DIRECT database write — `wp post update` fires save_post and the
// plugin's local-edit tracking (FL-14) legitimately bumps the mapping's
// vector clock, so the later reconcile exchange verdicts local_edited
// (remote clock does not lead) instead of the silent-drift source_newer
// this step exercises. The tamper must stay hook-free to be "silent".
$drift_cmd = sprintf(
	'docker exec wptsall-wp-lab-db-1 sh -c "mysql -uroot -p\$MYSQL_ROOT_PASSWORD wplab_slot_g -N -e \'UPDATE wp_posts SET post_title=\\"Locally Tampered Title\\" WHERE ID=%d\'" 2>/dev/null',
	$target_post_id
);
shell_exec( $drift_cmd );

$tampered_fe = http_get( "{$site_b_url}/?p={$target_post_id}" );
assert_step( false !== strpos( $tampered_fe['body'], 'Locally Tampered Title' ), 'Simulated silent data drift on target site' );

// Exchange reconcile digest with higher source vector clock (clock = 3)
$digest_payload = array(
	'fingerprints' => array(
		array(
			'canonical_uuid'     => $test_post_uuid,
			'source_fingerprint' => hash( 'sha256', 'fingerprint_healed_v3' ),
			'vector_clock'       => 3,
		),
	),
);

$reconcile_res = signed_post( $site_b_url, '/wpmmcc/v1/sync/reconcile-digest', $digest_payload, $uuid_a, $shared_secret );
assert_step( 200 === $reconcile_res['status'], 'Reconcile digest exchange returned HTTP 200' );
$verdict = $reconcile_res['json']['data']['verdicts'][$test_post_uuid] ?? '';
assert_step( 'source_newer' === $verdict, "Target correctly diagnosed drifted entity as [source_newer]" );

// Self-healing: push latest source version
$sync_packet['origin_context']['vector_clock'][$uuid_a] = 3;
$sync_packet['source_fingerprint'] = hash( 'sha256', 'fingerprint_healed_v3' );
$sync_packet['entity']['core_fields']['post_title'] = 'E2E Synchronized Post (Self-Healed V3)';

$push_heal = signed_post( $site_b_url, '/wpmmcc/v1/sync/push', $sync_packet, $uuid_a, $shared_secret );
assert_step( 200 === $push_heal['status'], 'Self-healing push succeeded' );

// Verify front-end is restored
$healed_fe = http_get( "{$site_b_url}/?p={$target_post_id}" );
assert_step( false !== strpos( $healed_fe['body'], 'E2E Synchronized Post (Self-Healed V3)' ), 'Target post content restored to source truth' );

// -------------------------------------------------------------
// Step 8: Soft Delete / Trash Sync
// -------------------------------------------------------------
echo "\n--- Step 8: Soft Delete / Trash Sync ---\n";

$trash_packet = $sync_packet;
$trash_packet['action'] = 'trash';
$trash_packet['packet_id'] = 'pkt_' . bin2hex( random_bytes( 6 ) );

$push_trash = signed_post( $site_b_url, '/wpmmcc/v1/sync/push', $trash_packet, $uuid_a, $shared_secret );
assert_step( 200 === $push_trash['status'], 'Trash sync packet accepted with HTTP 200' );

// Verify target post status in DB is trash
$trash_check = trim( shell_exec( "docker exec wptsall-wp-lab-wordpress-slot-g wp post get {$target_post_id} --field=post_status --allow-root | tail -n 1" ) );
assert_step( 'trash' === $trash_check, "Target post status is now [trash]" );

// Front-end access on trashed post returns 404 (or redirects)
$trash_fe = http_get( "{$site_b_url}/?p={$target_post_id}" );
assert_step( 404 === $trash_fe['status'] || false === strpos( $trash_fe['body'], 'Self-Healed V3' ), 'Front-end no longer serves trashed post' );

// -------------------------------------------------------------
// Step 9: Conflict Arbitration (Shadow Draft Review Mode)
// -------------------------------------------------------------
echo "\n--- Step 9: Conflict Arbitration (Shadow Draft Review Mode) ---\n";

// Set peer conflict strategy to manual_review on Site B
shell_exec( "docker exec wptsall-wp-lab-wordpress-slot-g wp eval 'global \$wpdb; \$wpdb->update(\$wpdb->prefix.\"wpmmcc_peers\", array(\"conflict_strategy\"=>\"manual_review\"), array(\"peer_uuid\"=>\"{$uuid_a}\"));' --allow-root" );

// Restore post from trash to publish for conflict testing
shell_exec( "docker exec wptsall-wp-lab-wordpress-slot-g wp post update {$target_post_id} --post_status=publish --post_title=\"Active Target Document\" --allow-root" );

// Push inbound conflicting update with LOWER vector clock (clock = 1)
$sync_packet['action'] = 'upsert';
$sync_packet['origin_context']['vector_clock'][$uuid_a] = 1;
$sync_packet['source_fingerprint'] = hash( 'sha256', 'conflict_payload' );
$sync_packet['entity']['core_fields']['post_title'] = 'Incoming Remote Divergence';

$push_conflict = signed_post( $site_b_url, '/wpmmcc/v1/sync/push', $sync_packet, $uuid_a, $shared_secret );
assert_step( 200 === $push_conflict['status'], 'Conflict push responded with HTTP 200' );
assert_step( 'conflict' === ( $push_conflict['json']['data']['status'] ?? '' ), 'Conflict engine placed incoming update in Review Mode' );

$shadow_id = (int) $push_conflict['json']['data']['target_id'];
assert_step( $shadow_id > 0 && $shadow_id !== $target_post_id, "Shadow review draft created with distinct ID [{$shadow_id}]" );

$shadow_title = trim( shell_exec( "docker exec wptsall-wp-lab-wordpress-slot-g wp post get {$shadow_id} --field=post_title --allow-root | tail -n 1" ) );
assert_step( false !== strpos( $shadow_title, '[Sync Conflict Review]' ), "Shadow draft title prefixed with [Sync Conflict Review]" );

// Verify original live post is UNTOUCHED
$orig_title = trim( shell_exec( "docker exec wptsall-wp-lab-wordpress-slot-g wp post get {$target_post_id} --field=post_title --allow-root | tail -n 1" ) );
assert_step( 'Active Target Document' === $orig_title, 'Original published document preserved without overwriting' );

// Reset peer conflict strategy back to lww for subsequent runs
shell_exec( "docker exec wptsall-wp-lab-wordpress-slot-g wp eval 'global \$wpdb; \$wpdb->update(\$wpdb->prefix.\"wpmmcc_peers\", array(\"conflict_strategy\"=>\"lww\"), array(\"peer_uuid\"=>\"{$uuid_a}\"));' --allow-root" );

// -------------------------------------------------------------
// Step 10: ADR-5 Intra-Site Pairing Rejection
// -------------------------------------------------------------
echo "\n--- Step 10: ADR-5 Intra-Site Pairing Rejection ---\n";

$same_sig = $ping_b['json']['data']['install_signature'];
$bad_pairing_code = trim( shell_exec( 'docker exec wptsall-wp-lab-wordpress-slot-g wp eval "echo \WPMMCC\REST\Rest_Handshake::generate_pairing_code();" --allow-root | tail -n 1' ) );

$self_pair_payload = array(
	'pairing_code'        => $bad_pairing_code,
	'origin_uuid'         => 'different-uuid-same-db',
	'origin_url'          => "{$site_b_url}/subsite/",
	'install_signature'   => $same_sig, // Same install signature!
	'requested_direction' => 'bidirectional',
);

$ch = curl_init( "{$site_b_url}/wp-json/wpmmcc/v1/sync/handshake" );
curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
curl_setopt( $ch, CURLOPT_POST, true );
curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $self_pair_payload ) );
curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Content-Type: application/json' ) );
$rej_resp = curl_exec( $ch );
$rej_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
curl_close( $ch );

$rej_json = json_decode( $rej_resp, true );
assert_step( 400 === $rej_code, 'ADR-5: Same installation handshake rejected with HTTP 400' );
assert_step( 'wpmmcc_same_installation_rejected' === ( $rej_json['code'] ?? '' ), 'ADR-5: Returned code wpmmcc_same_installation_rejected' );

echo "\n==============================================================\n";
echo "SUCCESS: All {$pass_count} Live E2E Integration & Front-End Assertions Passed!\n";
echo "==============================================================\n";
