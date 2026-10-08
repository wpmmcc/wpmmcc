<?php
/**
 * WPMMCC Sync Packet v1.0 & Zero-Unserialize Unit Test (WP-A-05).
 *
 * Verifies standard packet serialization, JSON parsing, and the strict
 * enforcement of Zero Wire Unserialize policy (doc 14 §2).
 *
 * @package WPMMCC\Tests\Unit
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

use WPMMCC\Sync\Packet;

echo "=== Running Sync Packet & Zero-Unserialize Unit Test (WP-A-05) ===\n";

update_option( 'wpmmcc_origin_uuid', wp_generate_uuid4() );

// 1. Test Packet Construction (Serializer)
$entity_args = array(
	'guid'         => 'urn:uuid:' . wp_generate_uuid4(),
	'object_type'  => 'post',
	'subtype'      => 'post',
	'source_id'    => 42,
	'slug'         => 'hello-cross-site',
	'status'       => 'publish',
	'vector_clock' => 1,
	'core_fields'  => array(
		'post_title'   => 'Cross-Site Sync Article',
		'post_content' => '<p>Synchronized via WPMMCC</p>',
	),
	'meta_fields'  => array(
		'_custom_key'  => 'custom_value',
		'_nested_meta' => array( 'key1' => 'val1', 'key2' => 'val2' ),
	),
);

$packet = Packet::build( $entity_args );

if ( Packet::SCHEMA_VERSION !== $packet['schema_version'] ) {
	echo "FAIL: Expected schema_version " . Packet::SCHEMA_VERSION . ", got {$packet['schema_version']}\n";
	exit( 1 );
}

if ( empty( $packet['packet_id'] ) || empty( $packet['source_fingerprint'] ) ) {
	echo "FAIL: Packet must contain packet_id and source_fingerprint\n";
	exit( 1 );
}

if ( ! preg_match( '/^[a-f0-9]{64}$/', $packet['source_fingerprint'] ) ) {
	echo "FAIL: source_fingerprint must be a 64-char sha256 hex\n";
	exit( 1 );
}

// 2. Test Packet Parsing of Valid JSON
$valid_json = wp_json_encode( $packet );
$parsed     = Packet::parse( $valid_json );

if ( is_wp_error( $parsed ) ) {
	echo "FAIL: Valid packet should parse successfully: " . $parsed->get_error_message() . "\n";
	exit( 1 );
}

if ( $parsed['packet_id'] !== $packet['packet_id'] ) {
	echo "FAIL: Parsed packet_id does not match original\n";
	exit( 1 );
}

// Receipt URLs use the WordPress installation, not its REST namespace.
$subsite = Packet::peer_site_url( 'https://target.example/subsite/wp-json/wpmmcc/v1/' );
if ( 'https://target.example/subsite' !== $subsite
	|| ! Packet::url_belongs_to( 'https://target.example/subsite/?p=1', $subsite )
	|| Packet::url_belongs_to( 'https://target.example/subsite-other/?p=1', $subsite )
	|| Packet::url_belongs_to( 'https://other.example/subsite/?p=1', $subsite )
	|| Packet::url_belongs_to( 'https://target.example:8443/subsite/?p=1', $subsite ) ) {
	echo "FAIL: Peer receipt URLs must stay inside the exact site scheme/host/port/installation path\n";
	exit( 1 );
}

// 3. Test Security Defense: Rejection of PHP Serialized Gadgets (doc 14 §2)
// 3a: Object injection attack vector
$malicious_payload_1 = json_encode(
	array(
		'schema_version' => Packet::SCHEMA_VERSION,
		'packet_id'      => 'pkt_bad01',
		'exploit'        => 'O:8:"Exploit":1:{s:4:"cmd";s:6:"whoami";}',
	)
);
$res1 = Packet::parse( $malicious_payload_1 );
if ( ! is_wp_error( $res1 ) || 'wpmmcc_prohibited_serialized_payload' !== $res1->get_error_code() ) {
	echo "FAIL: PHP object gadget must be blocked with wpmmcc_prohibited_serialized_payload\n";
	exit( 1 );
}

// 3b: Serialized array injection attack vector
$malicious_payload_2 = '{"schema_version":"wpmmcc-sync-v1.0","payload":"a:2:{i:0;s:4:\"test\";}"}';
$res2 = Packet::parse( $malicious_payload_2 );
if ( ! is_wp_error( $res2 ) || 'wpmmcc_prohibited_serialized_payload' !== $res2->get_error_code() ) {
	echo "FAIL: PHP serialized array must be blocked with wpmmcc_prohibited_serialized_payload\n";
	exit( 1 );
}

// 4. Test Incompatible Schema Version
$bad_schema_json = json_encode(
	array(
		'schema_version' => 'unsupported-version-9.9',
		'packet_id'      => 'pkt_bad_schema',
	)
);
$res_schema = Packet::parse( $bad_schema_json );
if ( ! is_wp_error( $res_schema ) || 'wpmmcc_incompatible_schema' !== $res_schema->get_error_code() ) {
	echo "FAIL: Incompatible schema must return wpmmcc_incompatible_schema\n";
	exit( 1 );
}

// 5. §68 (nested serialized meta): storage may double-wrap a serialized
// value (serialize of an already-serialized string, e.g. the E2E fixture
// `_e2e_manual_serialized`). The export must unwrap ALL layers into the
// structured php_serialized envelope — leaking the inner serialized text
// would trip the receiving side's Zero-Unserialize packet guard
// (wpmmcc_prohibited_serialized_payload) and reject the WHOLE packet for
// that one meta (observed live: 23-post page poisoned by one fixture).
$logical          = array(
	'title'  => 'E2E serialized fixture',
	'items'  => array( 'one', 'two', 'three' ),
	'marker' => '',
);
$inner_serialized  = serialize( $logical );            // a:3:{...}
$double_serialized = serialize( $inner_serialized );   // s:NN:"a:3:{...}"

$sanitized_meta = Packet::sanitize_meta_for_export(
	array( '_e2e_manual_serialized' => array( $double_serialized ) )
);
$envelope = $sanitized_meta['_e2e_manual_serialized'] ?? null;
if ( ! is_array( $envelope ) || 'php_serialized' !== ( $envelope['_wpmmcc_kind'] ?? '' ) ) {
	echo "FAIL: double-serialized meta must export as the php_serialized envelope\n";
	exit( 1 );
}
if ( ! is_array( $envelope['data'] ?? null ) ) {
	echo "FAIL: envelope data must be the fully-unwrapped structure, not raw serialized text\n";
	exit( 1 );
}
if ( $envelope['data'] !== $logical ) {
	echo "FAIL: envelope data must equal the logical structure\n";
	exit( 1 );
}

// The wrapped packet must clear the Zero-Unserialize wire guard.
$nested_packet = Packet::build(
	array(
		'guid'         => 'urn:uuid:' . wp_generate_uuid4(),
		'object_type'  => 'post',
		'subtype'      => 'post',
		'source_id'    => 1,
		'slug'         => 'nested-meta-fixture',
		'status'       => 'publish',
		'vector_clock' => 1,
		'core_fields'  => array(
			'post_title'   => 'Nested meta post',
			'post_content' => '<p>serialized meta fixture</p>',
		),
		'meta_fields'  => array( '_e2e_manual_serialized' => array( $double_serialized ) ),
	)
);
$parsed_nested = Packet::parse( wp_json_encode( $nested_packet ) );
if ( is_wp_error( $parsed_nested ) ) {
	echo 'FAIL: nested-serialized meta packet must clear the wire guard: ' . $parsed_nested->get_error_message() . "\n";
	exit( 1 );
}

// 6. WMC-03 bidirectional fixtures: the wire guard must reject whole-value
// serialized gadgets AND pass legitimate content that merely CONTAINS the
// marker bytes. (Cases 6a–6c fail on the pre-fix raw-text regex; 6d/6e
// must fail on both old and new code.)
$build_with_content = function ( string $content ) {
	return Packet::build(
		array(
			'guid'         => 'urn:uuid:' . wp_generate_uuid4(),
			'object_type'  => 'post',
			'subtype'      => 'post',
			'source_id'    => 7,
			'slug'         => 'wmc03-fixture',
			'status'       => 'publish',
			'vector_clock' => 1,
			'core_fields'  => array(
				'post_title'   => 'WMC-03 fixture',
				'post_content' => $content,
			),
			'meta_fields'  => array(),
		)
	);
};

// 6a. A technical post QUOTING a serialized array inside a longer text.
$legit_a = $build_with_content( '<p>PHP serialization sample: a:1:{s:1:"x";} — note the braces and quotes.</p>' );
$res6a   = Packet::parse( wp_json_encode( $legit_a ) );
if ( is_wp_error( $res6a ) ) {
	echo "FAIL: 6a legit prose quoting a:1:{…} must pass the guard: " . $res6a->get_error_message() . "\n";
	exit( 1 );
}

// 6b. Literal "Area:51:" followed by a newline — in raw JSON the newline is
// a backslash-n escape, which the old raw-text regex matched (a:\d+:\).
$legit_b = $build_with_content( "Area:51:\nNext line of the field report." );
$res6b   = Packet::parse( wp_json_encode( $legit_b ) );
if ( is_wp_error( $res6b ) ) {
	echo "FAIL: 6b legit 'Area:51:' + newline must pass the guard: " . $res6b->get_error_message() . "\n";
	exit( 1 );
}

// 6c. Literal data:1:"x" inside prose.
$legit_c = $build_with_content( '<p>The config line data:1:"x" is just notation here.</p>' );
$res6c   = Packet::parse( wp_json_encode( $legit_c ) );
if ( is_wp_error( $res6c ) ) {
	echo "FAIL: 6c legit prose containing data:1:\"x\" must pass the guard: " . $res6c->get_error_message() . "\n";
	exit( 1 );
}

// 6d. A value that IS wholly a serialized blob is indistinguishable from a
// gadget — the gate must still reject it (documents the anchoring boundary).
$blob_whole = $build_with_content( 'a:1:{s:1:"x";}' );
$res6d      = Packet::parse( wp_json_encode( $blob_whole ) );
if ( ! is_wp_error( $res6d ) || 'wpmmcc_prohibited_serialized_payload' !== $res6d->get_error_code() ) {
	echo "FAIL: 6d whole-value serialized blob must stay rejected\n";
	exit( 1 );
}

// 6e. Attack nested deep in meta_fields: whole-value object gadget.
$nested_attack                     = $legit_a;
$nested_attack['entity']['meta_fields']['_evil'] = 'O:8:"Exploit":1:{s:4:"boom";s:6:"whoami";}';
$res6e = Packet::parse( wp_json_encode( $nested_attack ) );
if ( ! is_wp_error( $res6e ) || 'wpmmcc_prohibited_serialized_payload' !== $res6e->get_error_code() ) {
	echo "FAIL: 6e nested meta object gadget must stay rejected\n";
	exit( 1 );
}

echo "PASS: Sync Packet & Zero-Unserialize unit tests passed!\n";
exit( 0 );
