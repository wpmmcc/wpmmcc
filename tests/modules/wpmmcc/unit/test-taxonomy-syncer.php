<?php
/**
 * WPMMCC Hierarchical Taxonomy Syncer Unit Test (WP-A-05, Broadcast paradigm).
 *
 * Verifies that categories, tags, and custom hierarchical taxonomies
 * are replicated safely from parents to children with term mappings.
 *
 * @package WPMMCC\Tests\Unit
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

use WPMMCC\Sync\Taxonomy_Syncer;

echo "=== Running Taxonomy Syncer Unit Test ===\n";

global $wpdb;

// Mock terms storage
global $wp_mock_terms;
$wp_mock_terms = array();

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( $taxonomy ) {
		return in_array( $taxonomy, array( 'category', 'post_tag', 'product_cat' ), true );
	}
}

if ( ! function_exists( 'term_exists' ) ) {
	function term_exists( $term, $taxonomy = '', $parent = null ) {
		global $wp_mock_terms;
		foreach ( $wp_mock_terms as $t ) {
			if ( $t['slug'] === $term && $t['taxonomy'] === $taxonomy ) {
				return array( 'term_id' => $t['term_id'], 'term_taxonomy_id' => $t['term_id'] );
			}
		}
		return 0;
	}
}

if ( ! function_exists( 'wp_insert_term' ) ) {
	function wp_insert_term( $term, $taxonomy, $args = array() ) {
		global $wp_mock_terms;
		static $id_counter = 50;
		$id_counter++;
		$new_term = array(
			'term_id'  => $id_counter,
			'name'     => $term,
			'slug'     => $args['slug'] ?? $term,
			'taxonomy' => $taxonomy,
			'parent'   => $args['parent'] ?? 0,
		);
		$wp_mock_terms[] = $new_term;
		return array( 'term_id' => $id_counter, 'term_taxonomy_id' => $id_counter );
	}
}

if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( $field, $value, $taxonomy = '' ) {
		global $wp_mock_terms;
		foreach ( $wp_mock_terms as $t ) {
			if ( $t[ $field ] === $value && ( '' === $taxonomy || $t['taxonomy'] === $taxonomy ) ) {
				return (object) $t;
			}
		}
		return false;
	}
}

$mock_taxonomies = array(
	'product_cat' => array(
		array(
			'term_id'     => 10,
			'name'        => 'Electronics',
			'slug'        => 'electronics',
			'parent_slug' => '',
		),
		array(
			'term_id'     => 11,
			'name'        => 'Headphones',
			'slug'        => 'headphones',
			'parent_slug' => 'electronics',
		),
	),
);

$target_post_id = 901;
$peer_uuid      = wp_generate_uuid4();

Taxonomy_Syncer::sync_post_taxonomies( $target_post_id, $mock_taxonomies, $peer_uuid );

// Assert Terms were created in memory
if ( count( $wp_mock_terms ) !== 2 ) {
	echo "FAIL: Expected 2 terms created, got " . count( $wp_mock_terms ) . "\n";
	exit( 1 );
}

$parent_term = get_term_by( 'slug', 'electronics', 'product_cat' );
$child_term  = get_term_by( 'slug', 'headphones', 'product_cat' );

if ( ! $parent_term || ! $child_term ) {
	echo "FAIL: Terms not found after sync\n";
	exit( 1 );
}

if ( $child_term->parent !== $parent_term->term_id ) {
	echo "FAIL: Child term parent ID ({$child_term->parent}) does not match parent term ID ({$parent_term->term_id})\n";
	exit( 1 );
}

echo "PASS: Hierarchical taxonomy syncer unit tests passed!\n";
exit( 0 );
