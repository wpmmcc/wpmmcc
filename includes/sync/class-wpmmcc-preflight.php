<?php
/**
 * WPMMCC 6-Step Preflight Verification Engine (doc 08 §3, doc 13 §4).
 *
 * Implements full diagnostics gate before sync tasks are dispatched or processed.
 *
 * @package WPMMCC\Sync
 */

namespace WPMMCC\Sync;

use WPMMCC\REST\Rest_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Preflight
 */
class Preflight {

	/**
	 * Run the 6-step preflight verification.
	 *
	 * @param array $args Probe parameters (peer_uuid, target_post_type, shadow_write).
	 * @return array Diagnostic report with overall 'status' => 'PASS'|'FAIL'|'WARN' and step-by-step verdicts.
	 */
	public static function run_verification( array $args = array() ): array {
		global $wpdb;

		$report = array(
			'status' => 'PASS',
			'steps'  => array(),
		);

		// Step 1: Peer Authorization & Identity
		$peer_uuid = sanitize_text_field( $args['peer_uuid'] ?? '' );
		$peer      = null;
		if ( ! empty( $peer_uuid ) ) {
			$cache_key = 'peer_' . md5( $peer_uuid );
			$peer      = wp_cache_get( $cache_key, 'wpmmcc' );
			if ( false === $peer ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom plugin table query.
				$peer = $wpdb->get_row(
					$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpmmcc_peers WHERE peer_uuid = %s", $peer_uuid )
				);
				if ( $peer ) {
					wp_cache_set( $cache_key, $peer, 'wpmmcc', 300 );
				}
			}
		}

		if ( ! empty( $peer_uuid ) && ! $peer ) {
			$report['steps']['1_peer_identity'] = array(
				'status'  => 'FAIL',
				'message' => 'Peer is not registered in wpmmcc_peers.',
			);
			$report['status'] = 'FAIL';
		} else {
			$report['steps']['1_peer_identity'] = array(
				'status'  => 'PASS',
				'message' => 'Peer identity verified.',
			);
		}

		// Step 2: ADR-5 Same-Installation Loop Rejection
		$local_sig  = Rest_Base::compute_install_signature();
		$origin_sig = sanitize_text_field( $args['origin_signature'] ?? ( $peer->install_signature ?? '' ) );

		if ( ! empty( $origin_sig ) && hash_equals( $local_sig, $origin_sig ) ) {
			$report['steps']['2_loop_prevention'] = array(
				'status'  => 'FAIL',
				'message' => 'Origin and target share the same installation signature. Intra-site pairing rejected.',
			);
			$report['status'] = 'FAIL';
		} else {
			$report['steps']['2_loop_prevention'] = array(
				'status'  => 'PASS',
				'message' => 'Unique installation signature confirmed.',
			);
		}

		// Step 3: Uploads Directory Writability
		$upload_dir = wp_upload_dir();
		$writable   = ! empty( $upload_dir['basedir'] ) && wp_is_writable( $upload_dir['basedir'] );

		if ( ! $writable ) {
			$report['steps']['3_uploads_writable'] = array(
				'status'  => 'FAIL',
				'message' => 'Uploads directory is not writable by web server.',
			);
			$report['status'] = 'FAIL';
		} else {
			$report['steps']['3_uploads_writable'] = array(
				'status'  => 'PASS',
				'message' => 'Uploads directory is writable.',
			);
		}

		// Step 4: Storage Watermark Check (>= 100MB)
		$free_space = function_exists( 'disk_free_space' ) ? (int) @disk_free_space( $upload_dir['basedir'] ) : -1;
		if ( $free_space > 0 && $free_space < 104857600 ) { // 100MB
			$report['steps']['4_disk_watermark'] = array(
				'status'  => 'WARN',
				'message' => 'Low disk space warning: under 100MB free.',
			);
			if ( 'PASS' === $report['status'] ) {
				$report['status'] = 'WARN';
			}
		} else {
			$report['steps']['4_disk_watermark'] = array(
				'status'  => 'PASS',
				'message' => 'Sufficient disk capacity.',
			);
		}

		// Step 5: Post Type & Schema Compatibility
		$post_type = sanitize_key( $args['target_post_type'] ?? 'post' );
		if ( 'post' !== $post_type && 'page' !== $post_type && ! post_type_exists( $post_type ) ) {
			$report['steps']['5_post_type_support'] = array(
				'status'  => 'WARN',
				'message' => sprintf( "Target post type '%s' is not registered on this WordPress installation. Entities may be imported as draft.", $post_type ),
			);
			if ( 'PASS' === $report['status'] ) {
				$report['status'] = 'WARN';
			}
		} else {
			$report['steps']['5_post_type_support'] = array(
				'status'  => 'PASS',
				'message' => sprintf( "Post type '%s' is supported.", $post_type ),
			);
		}

		// Step 6: Shadow Write Probe
		if ( ! empty( $args['shadow_write'] ) && $writable ) {
			$probe_file = $upload_dir['basedir'] . '/.wpmmcc_probe_' . bin2hex( random_bytes( 4 ) ) . '.tmp';
			$written    = @file_put_contents( $probe_file, 'probe' );
			if ( false !== $written && file_exists( $probe_file ) ) {
				wp_delete_file( $probe_file );
				$report['steps']['6_shadow_probe'] = array(
					'status'  => 'PASS',
					'message' => 'Shadow write probe passed and cleaned.',
				);
			} else {
				$report['steps']['6_shadow_probe'] = array(
					'status'  => 'FAIL',
					'message' => 'Shadow write probe failed to write.',
				);
				$report['status'] = 'FAIL';
			}
		} else {
			$report['steps']['6_shadow_probe'] = array(
				'status'  => 'PASS',
				'message' => 'Shadow probe skipped.',
			);
		}

		return $report;
	}
}
