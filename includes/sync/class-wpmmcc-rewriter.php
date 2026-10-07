<?php
/**
 * WPMMCC Deep Array Tree & Content Rewriter (WP-A-08, doc 14 §2.4).
 *
 * Replaces old deep_rewrite_serialized_data.
 * Strictly operates on JSON trees, arrays, and scalars with ZERO PHP unserialize.
 * Recursion depth capped at 32 to prevent DoS.
 *
 * @package WPMMCC\Sync
 */

namespace WPMMCC\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Rewriter
 */
class Rewriter {

	/**
	 * Maximum recursion depth (doc 14 §2.4).
	 */
	const MAX_RECURSION_DEPTH = 32;

	/**
	 * Deeply rewrite URLs and references across nested arrays and JSON structures.
	 *
	 * @param mixed $data Arbitrary data structure (array, string, int, etc.).
	 * @param array $replacements Map of search_string => replace_string.
	 * @param int   $depth Current recursion depth.
	 * @return mixed
	 */
	public static function deep_rewrite_array_tree( $data, array $replacements, int $depth = 0 ) {
		if ( $depth >= self::MAX_RECURSION_DEPTH || empty( $replacements ) ) {
			return $data;
		}

		if ( is_string( $data ) ) {
			// Check if string is a JSON structure (e.g. Gutenberg block attributes or Elementor JSON)
			if ( ( 0 === strpos( $data, '{' ) && '}' === substr( $data, -1 ) ) ||
				( 0 === strpos( $data, '[' ) && ']' === substr( $data, -1 ) ) ) {
				$decoded = json_decode( $data, true, self::MAX_RECURSION_DEPTH, JSON_INVALID_UTF8_SUBSTITUTE );
				if ( is_array( $decoded ) && JSON_ERROR_NONE === json_last_error() ) {
					$rewritten_tree = self::deep_rewrite_array_tree( $decoded, $replacements, $depth + 1 );
					return wp_json_encode( $rewritten_tree, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				}
			}

			// Plain string replacement
			$search  = array_keys( $replacements );
			$replace = array_values( $replacements );
			return str_replace( $search, $replace, $data );
		}

		if ( is_array( $data ) ) {
			$rewritten = array();
			foreach ( $data as $key => $value ) {
				$new_key = is_string( $key ) ? str_replace( array_keys( $replacements ), array_values( $replacements ), $key ) : $key;
				$rewritten[ $new_key ] = self::deep_rewrite_array_tree( $value, $replacements, $depth + 1 );
			}
			return $rewritten;
		}

		return $data;
	}

	/**
	 * Rewrite HTML content, replacing media asset references and internal URLs.
	 *
	 * @param string $content Raw HTML / Gutenberg post content.
	 * @param array  $url_map Map of [source_url => target_url].
	 * @param array  $asset_map Map of [source_asset_ref => target_asset_url].
	 * @return string Rewritten HTML.
	 */
	public static function rewrite_html_content( string $content, array $url_map = array(), array $asset_map = array() ): string {
		if ( empty( $content ) ) {
			return '';
		}

		$combined_replacements = array_merge( $url_map, $asset_map );
		if ( empty( $combined_replacements ) ) {
			return $content;
		}

		// 1. Gutenberg comment block JSON attribute rewriting
		$content = preg_replace_callback(
			'/<!--\s+wp:([a-z0-9\/-]+)\s+(\{.*?\})\s+-->/s',
			function ( $matches ) use ( $combined_replacements ) {
				$block_name = $matches[1];
				$json_raw   = $matches[2];

				$decoded = json_decode( $json_raw, true, self::MAX_RECURSION_DEPTH, JSON_INVALID_UTF8_SUBSTITUTE );
				if ( is_array( $decoded ) ) {
					$rewritten = self::deep_rewrite_array_tree( $decoded, $combined_replacements, 0 );
					return sprintf( '<!-- wp:%s %s -->', $block_name, wp_json_encode( $rewritten, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
				}

				return $matches[0];
			},
			$content
		);

		// 2. Direct string URL replacements
		$search  = array_keys( $combined_replacements );
		$replace = array_values( $combined_replacements );

		return str_replace( $search, $replace, $content );
	}
}
