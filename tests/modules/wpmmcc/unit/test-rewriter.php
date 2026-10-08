<?php
/**
 * WPMMCC Content & URL Rewriter Unit Test (WP-A-08).
 *
 * Verifies that deep rewriting operates recursively across arrays, JSON trees,
 * Gutenberg block comments, and HTML content with ZERO PHP unserialize.
 *
 * @package WPMMCC\Tests\Unit
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

use WPMMCC\Sync\Rewriter;

echo "=== Running Rewriter Unit Test (WP-A-08) ===\n";

// 1. Test Plain Array and String Rewriting
$source_data = array(
	'site_url' => 'https://site-a.com',
	'media'    => array(
		'featured_image' => 'https://site-a.com/wp-content/uploads/2026/09/banner.jpg',
		'gallery'        => array(
			'https://site-a.com/wp-content/uploads/2026/09/photo1.jpg',
			'https://site-a.com/wp-content/uploads/2026/09/photo2.jpg',
		),
	),
);

$replacements = array(
	'https://site-a.com' => 'https://site-b.com',
);

$rewritten = Rewriter::deep_rewrite_array_tree( $source_data, $replacements );

if ( 'https://site-b.com' !== $rewritten['site_url'] ) {
	echo "FAIL: Expected rewritten site_url https://site-b.com, got {$rewritten['site_url']}\n";
	exit( 1 );
}

if ( 'https://site-b.com/wp-content/uploads/2026/09/photo1.jpg' !== $rewritten['media']['gallery'][0] ) {
	echo "FAIL: Nested array URL was not rewritten\n";
	exit( 1 );
}

// 2. Test JSON-in-string rewriting (e.g. Elementor JSON or serialized JSON trees)
$json_meta = json_encode(
	array(
		'link' => array(
			'url' => 'https://site-a.com/about-us',
		),
	)
);

$rewritten_json = Rewriter::deep_rewrite_array_tree( $json_meta, $replacements );
$decoded_result = json_decode( $rewritten_json, true );

if ( ! isset( $decoded_result['link']['url'] ) || 'https://site-b.com/about-us' !== $decoded_result['link']['url'] ) {
	echo "FAIL: JSON string tree was not properly rewritten\n";
	exit( 1 );
}

// 3. Test Gutenberg Block HTML Rewriting
$html_content = '<!-- wp:paragraph -->
<p>Visit us at <a href="https://site-a.com/page">our site</a></p>
<!-- /wp:paragraph -->
<!-- wp:image {"id":12,"url":"https://site-a.com/wp-content/uploads/pic.jpg"} -->
<figure class="wp-block-image"><img src="https://site-a.com/wp-content/uploads/pic.jpg" alt=""/></figure>
<!-- /wp:image -->';

$rewritten_html = Rewriter::rewrite_html_content( $html_content, $replacements );

if ( strpos( $rewritten_html, 'https://site-a.com' ) !== false ) {
	echo "FAIL: Rewritten HTML still contains source domain\n";
	exit( 1 );
}

if ( strpos( $rewritten_html, 'https://site-b.com/wp-content/uploads/pic.jpg' ) === false ) {
	echo "FAIL: Gutenberg image block was not rewritten to target domain\n";
	exit( 1 );
}

if ( strpos( $rewritten_html, '"url":"https://site-b.com/wp-content/uploads/pic.jpg"' ) === false ) {
	echo "FAIL: Gutenberg JSON comment attribute was not rewritten\n";
	exit( 1 );
}

echo "PASS: Rewriter unit tests passed!\n";
exit( 0 );
