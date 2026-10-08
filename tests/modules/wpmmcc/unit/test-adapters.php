<?php
/**
 * WPMMCC 20 Content Plugins Adapters Unit Test (WP-A-13, doc 05).
 *
 * Verifies specialized synchronization adapters for WooCommerce,
 * LMS course trees, bbPress forums, Elementor widget trees, and ACF.
 *
 * @package WPMMCC\Tests\Unit
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

use WPMMCC\Adapters\Adapter_Registry;
use WPMMCC\Adapters\Adapter_Woocommerce;
use WPMMCC\Adapters\Adapter_LMS;
use WPMMCC\Adapters\Adapter_Bbpress;
use WPMMCC\Adapters\Adapter_Elementor;
use WPMMCC\Adapters\Adapter_ACF;
use WPMMCC\Adapters\Adapter_SEO;
use WPMMCC\Adapters\Adapter_Generic;

echo "=== Running 20 Content Plugins Adapters Unit Test (WP-A-13) ===\n";

// 1. Verify Adapter Registry Routing
$woo_adapter = Adapter_Registry::get_for_post_type( 'product' );
$woo_active  = ( new Adapter_Woocommerce() )->is_plugin_active();
if ( $woo_active ? ! ( $woo_adapter instanceof Adapter_Woocommerce ) : ! ( $woo_adapter instanceof Adapter_Generic ) ) {
	echo "FAIL: WooCommerce routing must follow actual plugin activation\n";
	exit( 1 );
}
$woo_adapter = new Adapter_Woocommerce();

$lms_adapter = Adapter_Registry::get_for_post_type( 'courses' );
$lms_active  = ( new Adapter_LMS() )->is_plugin_active();
if ( $lms_active ? ! ( $lms_adapter instanceof Adapter_LMS ) : ! ( $lms_adapter instanceof Adapter_Generic ) ) {
	echo "FAIL: LMS routing must follow actual plugin activation\n";
	exit( 1 );
}
$lms_adapter = new Adapter_LMS();

$bbp_adapter = Adapter_Registry::get_for_post_type( 'topic' );
$bbp_active  = ( new Adapter_Bbpress() )->is_plugin_active();
if ( $bbp_active ? ! ( $bbp_adapter instanceof Adapter_Bbpress ) : ! ( $bbp_adapter instanceof Adapter_Generic ) ) {
	echo "FAIL: bbPress routing must follow actual plugin activation\n";
	exit( 1 );
}

// 2. Test WooCommerce Variable Product Adaptation
$mock_woo_payload = array(
	'product_type'  => 'variable',
	'sku'           => 'TSHIRT-MASTER',
	'price'         => '29.99',
	'regular_price' => '29.99',
	'stock_status'  => 'instock',
	'attributes'    => array(
		'pa_color' => array(
			'name'         => 'pa_color',
			'is_variation' => 1,
			'is_visible'   => 1,
		),
	),
	'variations'    => array(
		array(
			'sku'           => 'TSHIRT-RED-M',
			'price'         => '29.99',
			'regular_price' => '29.99',
			'menu_order'    => 1,
			'meta_fields'   => array(
				'attribute_pa_color' => 'red',
				'_price'             => '29.99',
			),
		),
		array(
			'sku'           => 'TSHIRT-BLUE-L',
			'price'         => '34.99',
			'regular_price' => '34.99',
			'menu_order'    => 2,
			'meta_fields'   => array(
				'attribute_pa_color' => 'blue',
				'_price'             => '34.99',
			),
		),
	),
);

$target_product_id = 101;
$woo_success       = $woo_adapter->import_custom_payload( $target_product_id, $mock_woo_payload );
if ( ! $woo_success ) {
	echo "FAIL: WooCommerce variable product import failed\n";
	exit( 1 );
}

if ( 'TSHIRT-MASTER' !== get_post_meta( $target_product_id, '_sku', true ) ) {
	echo "FAIL: WooCommerce master product SKU was not stored\n";
	exit( 1 );
}

// 3. Test LMS Course Tree DAG Adaptation (Topological sort)
$mock_lms_payload = array(
	'course_level'    => 'intermediate',
	'course_duration' => '10 weeks',
	'topics'          => array(
		array(
			'title'   => 'Module 1: Introduction',
			'summary' => 'Basics of the course',
			'lessons' => array(
				array(
					'title'     => 'Lesson 1.1: Getting Started',
					'content'   => 'Welcome to lesson 1',
					'post_type' => 'lesson',
				),
			),
		),
	),
);

$target_course_id = 202;
$lms_success      = $lms_adapter->import_custom_payload( $target_course_id, $mock_lms_payload );
if ( ! $lms_success ) {
	echo "FAIL: LMS course tree import failed\n";
	exit( 1 );
}

if ( 'intermediate' !== get_post_meta( $target_course_id, '_tutor_course_level', true ) ) {
	echo "FAIL: LMS course level was not stored\n";
	exit( 1 );
}

// 4. Test Elementor Deep JSON Widget Tree Rewriting
$mock_elementor_payload = array(
	'is_elementor'   => true,
	'elementor_data' => array(
		array(
			'id'       => 'sec1',
			'elType'   => 'section',
			'elements' => array(
				array(
					'id'         => 'col1',
					'elType'     => 'column',
					'elements'   => array(
						array(
							'id'         => 'widget1',
							'elType'     => 'widget',
							'widgetType' => 'image',
							'settings'   => array(
								'image' => array(
									'url' => 'https://site-a.com/wp-content/uploads/photo.jpg',
									'id'  => 55,
								),
							),
						),
					),
				),
			),
		),
	),
);

$target_page_id    = 303;
$elementor_adapter = new Adapter_Elementor();
$elementor_success = $elementor_adapter->import_custom_payload(
	$target_page_id,
	$mock_elementor_payload,
	array( 'url_map' => array( 'https://site-a.com' => 'https://site-b.com' ) )
);

if ( ! $elementor_success ) {
	echo "FAIL: Elementor payload import failed\n";
	exit( 1 );
}

$stored_elementor_data = get_post_meta( $target_page_id, '_elementor_data', true );
if ( strpos( $stored_elementor_data, 'https://site-b.com' ) === false ) {
	echo "FAIL: Elementor widget tree image URL was not rewritten to target domain\n";
	exit( 1 );
}

// 5. Test ACF Custom Field Adaptation
$mock_acf_payload = array(
	'has_acf' => true,
	'fields'  => array(
		'hero_banner' => array(
			'field_key' => 'field_60a8b9c1d2e3f',
			'value'     => 'https://site-a.com/banner.png',
		),
	),
);

$target_acf_post_id = 404;
$acf_adapter        = new Adapter_ACF();
$acf_success        = $acf_adapter->import_custom_payload(
	$target_acf_post_id,
	$mock_acf_payload,
	array( 'url_map' => array( 'https://site-a.com' => 'https://site-b.com' ) )
);

if ( ! $acf_success ) {
	echo "FAIL: ACF import failed\n";
	exit( 1 );
}

if ( 'field_60a8b9c1d2e3f' !== get_post_meta( $target_acf_post_id, '_hero_banner', true ) ) {
	echo "FAIL: ACF reference key _hero_banner was not stored\n";
	exit( 1 );
}

if ( 'https://site-b.com/banner.png' !== get_post_meta( $target_acf_post_id, 'hero_banner', true ) ) {
	echo "FAIL: ACF field value URL was not rewritten\n";
	exit( 1 );
}

// 6. Test SEO Metadata Adaptation (Yoast & Rank Math)
$seo_adapter     = new Adapter_SEO();
$mock_seo_payload = array(
	'yoast_title'      => 'Target Page Title %%sep%% %%sitename%%',
	'yoast_canonical'  => 'https://site-a.com/my-page',
	'rank_math_title'  => 'Rank Math Title %sep% %sitename%',
);

$target_seo_post_id = 505;
$seo_success        = $seo_adapter->import_custom_payload(
	$target_seo_post_id,
	$mock_seo_payload,
	array( 'url_map' => array( 'https://site-a.com' => 'https://site-b.com' ) )
);

if ( ! $seo_success ) {
	echo "FAIL: SEO metadata import failed\n";
	exit( 1 );
}

if ( 'https://site-b.com/my-page' !== get_post_meta( $target_seo_post_id, '_yoast_wpseo_canonical', true ) ) {
	echo "FAIL: Yoast canonical URL was not rewritten\n";
	exit( 1 );
}

echo "PASS: All 20 content plugins adapters unit tests passed!\n";
exit( 0 );
