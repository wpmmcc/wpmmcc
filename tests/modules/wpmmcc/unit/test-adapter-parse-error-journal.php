<?php
/**
 * WPMMCC Adapter Parse-Failure Journal Unit Test (G-20).
 *
 * Verifies that the adapter decode/extract paths record an audit journal
 * row (event_type adapter_parse_error, consumed=1) when source content
 * fails to decode (Elementor JSON trees, gallery/EDD serialized trees,
 * WooCommerce attribute matrices), and that healthy payloads write nothing.
 *
 * @package WPMMCC\Tests\Unit
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

use WPMMCC\Adapters\Adapter_Elementor;
use WPMMCC\Adapters\Adapter_Media_Gallery;
use WPMMCC\Adapters\Adapter_Woocommerce;
use WPMMCC\Adapters\Adapter_Registry;

echo "=== Running Adapter Parse-Failure Journal Unit Test (G-20) ===\n";

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
 * Count adapter_parse_error journal inserts captured by the Mock_wpdb.
 *
 * @return int
 */
function wpmmcc_parse_error_count(): int {
	global $wpdb;
	$n = 0;
	foreach ( $wpdb->queries as $q ) {
		if ( 0 === strpos( $q, 'INSERT INTO wp_wpmmcc_journal' ) && false !== strpos( $q, 'adapter_parse_error' ) ) {
			$n++;
		}
	}
	return $n;
}

/**
 * Decode the most recent adapter_parse_error journal row.
 *
 * @return array|null
 */
function wpmmcc_last_parse_error_row(): ?array {
	global $wpdb;
	foreach ( array_reverse( $wpdb->queries ) as $q ) {
		if ( 0 === strpos( $q, 'INSERT INTO wp_wpmmcc_journal' ) && false !== strpos( $q, 'adapter_parse_error' ) ) {
			return json_decode( substr( $q, strpos( $q, '{' ) ), true );
		}
	}
	return null;
}

/**
 * Assert the common audit-row shape for a parse error.
 *
 * @param array  $row     Journal row.
 * @param string $adapter Expected adapter slug.
 * @param string $post_type Expected post type in diff_summary.
 */
function wpmmcc_assert_parse_row( $row, string $adapter, string $post_type ) {
	assert_test( is_array( $row ), "Journal row recorded for adapter [{$adapter}]" );
	assert_test( 'adapter_parse_error' === $row['event_type'], "event_type is adapter_parse_error [{$adapter}]" );
	assert_test( 'error' === $row['outcome'], "outcome is error [{$adapter}]" );
	assert_test( 1 === (int) $row['consumed'], "consumed=1 (audit-only, never CDC) [{$adapter}]" );
	$diff = is_string( $row['diff_summary'] ) ? json_decode( $row['diff_summary'], true ) : $row['diff_summary'];
	assert_test( is_array( $diff ) && $adapter === $diff['adapter'], "diff_summary carries the adapter slug [{$adapter}]" );
	assert_test( is_array( $diff ) && $post_type === $diff['post_type'], "diff_summary carries the post type [{$adapter}]" );
}

// -------------------------------------------------------------
// 1. Elementor: malformed _elementor_data JSON on export
// -------------------------------------------------------------
echo "\n--- Elementor malformed JSON export ---\n";
$elementor = new Adapter_Elementor();
$post_id   = 601;
$post      = new WP_Post( array( 'ID' => $post_id, 'post_type' => 'post', 'post_title' => 'Elementor Post' ) );
update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
update_post_meta( $post_id, '_elementor_data', '{"widgets": [BROKEN_JSON{' );

$before = wpmmcc_parse_error_count();
$payload = $elementor->export_custom_payload( $post_id, $post );
assert_test( true === $payload['is_elementor'], 'Elementor export still identifies the post as elementor' );
assert_test( array() === $payload['elementor_data'], 'Malformed widget tree degrades to an empty array' );
assert_test( wpmmcc_parse_error_count() === $before + 1, 'Malformed widget tree writes one journal row' );
$row = wpmmcc_last_parse_error_row();
wpmmcc_assert_parse_row( $row, 'elementor', 'post' );
assert_test( $post_id === (int) $row['local_object_id'], 'Row carries the source post ID' );
assert_test( 'outbound' === $row['direction'], 'Export-side parse failure is tagged outbound' );

// Healthy JSON must NOT record an error.
update_post_meta( $post_id, '_elementor_data', '[{"id":"sec1","elType":"section"}]' );
$before = wpmmcc_parse_error_count();
$payload = $elementor->export_custom_payload( $post_id, $post );
assert_test( 1 === count( $payload['elementor_data'] ), 'Healthy widget tree exports normally' );
assert_test( wpmmcc_parse_error_count() === $before, 'Healthy widget tree writes no parse-error row' );

// -------------------------------------------------------------
// 2. Elementor: un-encodable widget tree on import
// -------------------------------------------------------------
echo "\n--- Elementor un-encodable import ---\n";
$target_id = 602;
$bad_utf8  = "invalid-utf8-\xB1\x31";
$before    = wpmmcc_parse_error_count();
$ok = $elementor->import_custom_payload(
	$target_id,
	array(
		'is_elementor'   => true,
		'elementor_data' => array( 'corrupt' => $bad_utf8 ),
	),
	array()
);
assert_test( true === $ok, 'Import still returns true (degraded, logged)' );
assert_test( '' === (string) get_post_meta( $target_id, '_elementor_data', true ), 'Un-encodable tree stores empty JSON instead of garbage' );
assert_test( wpmmcc_parse_error_count() === $before + 1, 'Un-encodable import writes one journal row' );
wpmmcc_assert_parse_row( wpmmcc_last_parse_error_row(), 'elementor', 'post' );

// -------------------------------------------------------------
// 3. Media Gallery: corrupt gallery / EDD trees
// -------------------------------------------------------------
echo "\n--- Media Gallery corrupt trees ---\n";
$gallery = new Adapter_Media_Gallery();
$env_id  = 603;
$env_post = new WP_Post( array( 'ID' => $env_id, 'post_type' => 'envira', 'post_title' => 'Gallery' ) );
update_post_meta( $env_id, '_eg_gallery_data', 'corrupt-gallery-not-an-array' );

$before = wpmmcc_parse_error_count();
$payload = $gallery->export_custom_payload( $env_id, $env_post );
assert_test( array() === $payload['gallery_data'], 'Corrupt gallery tree degrades to an empty array' );
assert_test( wpmmcc_parse_error_count() === $before + 1, 'Corrupt gallery export writes one journal row' );
wpmmcc_assert_parse_row( wpmmcc_last_parse_error_row(), 'media_gallery', 'envira' );

$dl_id   = 604;
$dl_post = new WP_Post( array( 'ID' => $dl_id, 'post_type' => 'download', 'post_title' => 'Download' ) );
update_post_meta( $dl_id, 'edd_download_files', 'corrupt-file-list-not-an-array' );
$before = wpmmcc_parse_error_count();
$payload = $gallery->export_custom_payload( $dl_id, $dl_post );
assert_test( array() === $payload['edd_files'], 'Corrupt EDD file list degrades to an empty array' );
assert_test( wpmmcc_parse_error_count() === $before + 1, 'Corrupt EDD export writes one journal row' );
wpmmcc_assert_parse_row( wpmmcc_last_parse_error_row(), 'media_gallery', 'download' );

// Inbound non-array gallery payload
$before = wpmmcc_parse_error_count();
$ok = $gallery->import_custom_payload( 605, array( 'type' => 'envira', 'gallery_data' => 'not-an-array' ), array() );
assert_test( true === $ok, 'Import returns true (degraded, logged)' );
assert_test( wpmmcc_parse_error_count() === $before + 1, 'Non-array inbound gallery payload writes one journal row' );
wpmmcc_assert_parse_row( wpmmcc_last_parse_error_row(), 'media_gallery', 'envira' );

// -------------------------------------------------------------
// 4. WooCommerce: corrupt attribute matrix
// -------------------------------------------------------------
echo "\n--- WooCommerce corrupt attributes ---\n";
$woo = new Adapter_Woocommerce();
$woo_post = new WP_Post( array( 'ID' => 606, 'post_type' => 'product', 'post_title' => 'Product' ) );
update_post_meta( 606, '_product_attributes', 'corrupt-attributes-not-an-array' );

$before = wpmmcc_parse_error_count();
$payload = $woo->export_custom_payload( 606, $woo_post );
assert_test( array() === $payload['attributes'], 'Corrupt attribute matrix degrades to an empty array' );
assert_test( wpmmcc_parse_error_count() === $before + 1, 'Corrupt attribute export writes one journal row' );
wpmmcc_assert_parse_row( wpmmcc_last_parse_error_row(), 'woocommerce', 'product' );

$before = wpmmcc_parse_error_count();
$ok = $woo->import_custom_payload( 607, array( 'product_type' => 'simple', 'attributes' => 'corrupt-inbound-attributes' ), array() );
assert_test( true === $ok, 'Import returns true (degraded, logged)' );
assert_test( wpmmcc_parse_error_count() === $before + 1, 'Non-array inbound attributes write one journal row' );
wpmmcc_assert_parse_row( wpmmcc_last_parse_error_row(), 'woocommerce', 'product' );

// Healthy attributes must NOT record an error
$before = wpmmcc_parse_error_count();
$ok = $woo->import_custom_payload( 608, array( 'product_type' => 'simple', 'attributes' => array( 'pa_color' => array( 'name' => 'pa_color' ) ) ), array() );
assert_test( true === $ok && wpmmcc_parse_error_count() === $before, 'Healthy attribute import writes no parse-error row' );

// -------------------------------------------------------------
// 5. Registry helper never marks rows unconsumed (CDC safety)
// -------------------------------------------------------------
echo "\n--- CDC safety ---\n";
$id = Adapter_Registry::record_parse_error( 'unit-test', 1, 'post', 'sanity message' );
assert_test( $id > 0, 'Adapter_Registry::record_parse_error returns the inserted journal ID' );
$row = wpmmcc_last_parse_error_row();
assert_test( 1 === (int) $row['consumed'], 'record_parse_error always writes consumed=1 (never enters the CDC outbox)' );

echo "\n==================================================\n";
echo "PASS: All {$pass_count} Adapter parse-failure journal tests passed!\n";
echo "==================================================\n";
exit( 0 );
