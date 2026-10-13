<?php
/**
 * Owned wpmmcc fixture only.
 *
 * The browser keeps the container DNS name, so stored permalinks stay there.
 * The client calls the published loopback port and only accepts a receipt URL
 * on that same origin. Rewrite the push receipt host to the host that
 * accepted the request. Do not change home_url: source confirmation compares
 * the stored permalink with the live one.
 *
 * @package WPTSALL
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'rest_post_dispatch',
	static function ( $result, $server, $request ) {
		unset( $server );
		if ( ! $result instanceof WP_REST_Response || '/wpmmcc/v1/sync/push' !== (string) $request->get_route() ) {
			return $result;
		}
		$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '';
		if ( ! preg_match( '/^127\\.0\\.0\\.1(?::\\d+)?$/', $host ) ) {
			return $result;
		}
		$data = $result->get_data();
		if ( ! is_array( $data ) ) {
			return $result;
		}
		$wrapped = isset( $data['data'] ) && is_array( $data['data'] );
		$receipt = $wrapped ? $data['data'] : $data;
		if ( ! is_string( $receipt['target_url'] ?? null ) || '' === $receipt['target_url'] ) {
			return $result;
		}
		$parts = wp_parse_url( $receipt['target_url'] );
		if ( ! is_array( $parts ) ) {
			return $result;
		}
		$path  = $parts['path'] ?? '';
		$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$receipt['target_url'] = 'http://' . $host . $path . $query;
		if ( $wrapped ) {
			$data['data'] = $receipt;
		} else {
			$data = $receipt;
		}
		$result->set_data( $data );
		return $result;
	},
	20,
	3
);
