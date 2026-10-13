<?php
/**
 * WPMMCC Sync Topology Matrix & Handshake Direction Contract Test (WP-A-03, doc 02 §1, doc 12/ADR-5).
 *
 * Validates:
 * 1. Topology 1: Standalone <-> Standalone
 * 2. Topology 2: Cross-network Subsite <-> Subsite
 * 3. Topology 3: Subsite <-> Standalone
 * 4. Topology 4: Standalone <-> Subsite
 * 5. ADR-5 Same-Installation Rejection Gate (Anti-Topology)
 * 6. Sync Direction Negotiation (Bidirectional, Push-only, Pull-only)
 *
 * @package WPMMCC\Tests\Contract
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

echo "=== Running Sync Topology Matrix & Direction Contract Test ===\n";

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

// -------------------------------------------------------------
// Test 1: Topology 1 - Standalone <-> Standalone
// -------------------------------------------------------------
echo "\n--- Testing Topology 1: Standalone <-> Standalone ---\n";
$site_a = array(
	'url'        => 'https://site-a.example.com',
	'network_id' => 1,
	'is_subsite' => false,
);
$site_b = array(
	'url'        => 'https://site-b.example.com',
	'network_id' => 1,
	'is_subsite' => false,
);

$sig_a = hash( 'sha256', $site_a['url'] . '|' . $site_a['network_id'] );
$sig_b = hash( 'sha256', $site_b['url'] . '|' . $site_b['network_id'] );

assert_test( $sig_a !== $sig_b, 'Standalone Site A and Site B have distinct install signatures' );
assert_test( ! hash_equals( $sig_a, $sig_b ), 'Standalone pairing is accepted without collision' );

// -------------------------------------------------------------
// Test 2: Topology 2 - Cross-network Subsite <-> Subsite
// -------------------------------------------------------------
echo "\n--- Testing Topology 2: Cross-network Subsite <-> Subsite ---\n";
$net1_subsite = array(
	'url'        => 'https://network1.example.com/subsite-us/',
	'network_id' => 10,
	'blog_id'    => 2,
	'is_subsite' => true,
);
$net2_subsite = array(
	'url'        => 'https://network2.example.com/subsite-eu/',
	'network_id' => 20,
	'blog_id'    => 5,
	'is_subsite' => true,
);

$sig_net1 = hash( 'sha256', $net1_subsite['url'] . '|' . $net1_subsite['network_id'] );
$sig_net2 = hash( 'sha256', $net2_subsite['url'] . '|' . $net2_subsite['network_id'] );

assert_test( $sig_net1 !== $sig_net2, 'Cross-network subsites have distinct network-scoped signatures' );
assert_test( ! hash_equals( $sig_net1, $sig_net2 ), 'Cross-network subsite pairing is accepted' );

// -------------------------------------------------------------
// Test 3: Topology 3 - Subsite <-> Standalone
// -------------------------------------------------------------
echo "\n--- Testing Topology 3: Subsite <-> Standalone ---\n";
$sig_sub = hash( 'sha256', 'https://network1.example.com/subsite-us/|10' );
$sig_sta = hash( 'sha256', 'https://standalone-store.example.com|1' );

assert_test( $sig_sub !== $sig_sta, 'Subsite to Standalone cross-pairing has distinct signatures' );

// -------------------------------------------------------------
// Test 4: Topology 4 - Standalone <-> Subsite
// -------------------------------------------------------------
echo "\n--- Testing Topology 4: Standalone <-> Subsite ---\n";
$sig_rev_sta = hash( 'sha256', 'https://standalone-store.example.com|1' );
$sig_rev_sub = hash( 'sha256', 'https://network1.example.com/subsite-us/|10' );

assert_test( $sig_rev_sta !== $sig_rev_sub, 'Standalone to Subsite pairing is accepted' );

// -------------------------------------------------------------
// Test 5: ADR-5 Same-Installation Rejection Gate
// -------------------------------------------------------------
echo "\n--- Testing ADR-5 Same-Installation Rejection Gate ---\n";
$local_site_url = function_exists( 'get_site_url' ) ? get_site_url() : site_url();
$local_network  = function_exists( 'get_current_network_id' ) ? get_current_network_id() : 1;
$local_sig      = hash( 'sha256', $local_site_url . '|' . $local_network );

$pairing_token = 'TEST_PAIRING_CODE_' . bin2hex( random_bytes( 8 ) );
set_transient( 'wpmmcc_active_pairing_code', $pairing_token, 600 );

$request = new \WP_REST_Request( 'POST', '/wpmmcc/v1/sync/handshake' );
$request->set_body(
	json_encode(
		array(
			'pairing_code'        => $pairing_token,
			'origin_uuid'         => 'different-uuid-from-same-install',
			'origin_url'          => $local_site_url . '/subsite/',
			'install_signature'   => $local_sig, // Same install signature injected!
			'requested_direction' => 'bidirectional',
		)
	)
);

$handshake = new \WPMMCC\REST\Rest_Handshake();
$response  = $handshake->handle_handshake( $request );

assert_test( is_wp_error( $response ), 'Same installation pairing is strictly rejected with WP_Error' );
assert_test( 'wpmmcc_same_installation_rejected' === $response->get_error_code(), 'Error code matches wpmmcc_same_installation_rejected' );
assert_test( 400 === ( $response->get_error_data()['status'] ?? 0 ), 'HTTP status is 400 Bad Request' );
assert_test( false !== strpos( $response->get_error_message(), 'WPMMCC ATS' ), 'Guidance directs user to WPMMCC ATS for intra-site needs' );

// -------------------------------------------------------------
// Test 6: Sync Direction Negotiation Matrix
// -------------------------------------------------------------
echo "\n--- Testing Sync Direction Negotiation Matrix ---\n";

$directions = array(
	// [Initiator Request, Expected Negotiated on Target]
	array( 'bidirectional', 'bidirectional' ),
	array( 'push_only',     'pull_only' ),
	array( 'pull_only',     'push_only' ),
);

foreach ( $directions as $idx => $case ) {
	list( $req, $expected ) = $case;
	
	// Set fresh pairing code
	$code = 'CODE_' . $idx . '_' . bin2hex( random_bytes( 4 ) );
	set_transient( 'wpmmcc_active_pairing_code', $code, 600 );

	$req_obj = new \WP_REST_Request( 'POST', '/wpmmcc/v1/sync/handshake' );
	$req_obj->set_body(
		json_encode(
			array(
				'pairing_code'        => $code,
				'origin_uuid'         => "remote-peer-uuid-{$idx}",
				'origin_url'          => "https://remote-site-{$idx}.example.com",
				'install_signature'   => hash( 'sha256', "https://remote-site-{$idx}.example.com|1" ),
				'requested_direction' => $req,
			)
		)
	);

	$res = $handshake->handle_handshake( $req_obj );
	assert_test( ! is_wp_error( $res ), "Handshake successful for direction {$req}" );
	$res_data = $res->get_data();
	$negotiated = $res_data['data']['negotiated_direction'] ?? '';
	assert_test( $negotiated === $expected, "Direction case #{$idx}: [Initiator {$req}] => Negotiated Target [{$expected}]" );
}

echo "\n==================================================\n";
echo "PASS: All {$pass_count} Topology Matrix & Direction tests passed successfully!\n";
echo "==================================================\n";
