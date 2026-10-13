<?php
/**
 * WPMMCC 6-Step Preflight Verification Unit Test (doc 08 §3, doc 13 §4).
 *
 * Verifies diagnostic probe checks including shadow probe, storage capacity,
 * ADR-5 loop prevention, and schema compatibility.
 *
 * @package WPMMCC\Tests\Unit
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

use WPMMCC\Sync\Preflight;
use WPMMCC\REST\Rest_Base;

echo "=== Running Preflight Verification Unit Test ===\n";

if ( ! function_exists( 'wp_is_writable' ) ) {
	function wp_is_writable( $path ) {
		return true;
	}
}

if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( $post_type ) {
		return in_array( $post_type, array( 'post', 'page', 'product', 'courses' ), true );
	}
}

// 1. Test Clean Pass
$args_pass = array(
	'peer_uuid'        => '',
	'target_post_type' => 'product',
	'shadow_write'     => true,
	'origin_signature' => 'remote-different-signature-12345',
);

$res_pass = Preflight::run_verification( $args_pass );
if ( 'PASS' !== $res_pass['status'] ) {
	echo "FAIL: Expected overall PASS, got {$res_pass['status']}\n";
	exit( 1 );
}

// 2. Test ADR-5 Loop Prevention Rejection (signature collision)
$local_sig  = Rest_Base::compute_install_signature();
$args_loop  = array(
	'origin_signature' => $local_sig, // Same install!
);

$res_loop = Preflight::run_verification( $args_loop );
if ( 'FAIL' !== $res_loop['status'] || 'FAIL' !== $res_loop['steps']['2_loop_prevention']['status'] ) {
	echo "FAIL: Loop prevention must trigger FAIL on identical signature\n";
	exit( 1 );
}

// 3. Test Unsupported Post Type Warning
$args_unknown = array(
	'target_post_type' => 'completely_unknown_post_type',
	'origin_signature' => 'remote-diff-sig',
);

$res_unknown = Preflight::run_verification( $args_unknown );
if ( 'WARN' !== $res_unknown['steps']['5_post_type_support']['status'] ) {
	echo "FAIL: Unknown post type should return WARN\n";
	exit( 1 );
}

echo "PASS: Preflight verification unit tests passed!\n";
exit( 0 );
