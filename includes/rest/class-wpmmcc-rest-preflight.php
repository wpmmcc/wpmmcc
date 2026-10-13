<?php
/**
 * WPMMCC REST Preflight Controller (POST /wp-json/wpmmcc/v1/sync/preflight).
 *
 * Implements preflight validation step 2/5: shadow write probe,
 * disk space watermark inspection, and upload dir writability check.
 *
 * @package WPMMCC\REST
 */

namespace WPMMCC\REST;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Rest_Preflight
 */
class Rest_Preflight extends Rest_Base {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/sync/preflight',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_preflight' ),
				'permission_callback' => array( 'WPMMCC\REST\Rest_Middleware', 'verify_hmac' ),
			)
		);
	}

	/**
	 * Handle preflight probe.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle_preflight( WP_REST_Request $request ): WP_REST_Response {
		$params       = $request->get_json_params() ?: $request->get_params();
		$probe_id     = sanitize_text_field( $params['probe_id'] ?? ( 'probe_' . bin2hex( random_bytes( 4 ) ) ) );
		$shadow_write = ! empty( $params['shadow_write'] );

		// 1. Check upload directory writability
		$upload_dir = wp_upload_dir();
		$writable   = ! empty( $upload_dir['basedir'] ) && wp_is_writable( $upload_dir['basedir'] );

		// 2. Check disk free space
		$disk_free = function_exists( 'disk_free_space' ) ? (int) @disk_free_space( $upload_dir['basedir'] ) : -1;

		// 3. Shadow write test
		$shadow_cleaned = false;
		if ( $shadow_write && $writable ) {
			$probe_file = $upload_dir['basedir'] . '/.wpmmcc_probe_' . sanitize_file_name( $probe_id ) . '.tmp';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$written = false;
			if ( WP_Filesystem() ) {
				global $wp_filesystem;
				$chmod   = defined( 'FS_CHMOD_FILE' ) ? \FS_CHMOD_FILE : 0644;
				$written = is_object( $wp_filesystem ) && $wp_filesystem->put_contents( $probe_file, 'wpmmcc_preflight_probe', $chmod );
			}
			if ( $written ) {
				wp_delete_file( $probe_file );
				$shadow_cleaned = true;
			}
		}

		return $this->format_success(
			array(
				'probe_id'         => $probe_id,
				'uploads_writable' => $writable,
				'disk_free_bytes'  => $disk_free,
				'shadow_cleaned'   => $shadow_cleaned,
				'server_time'      => time(),
			),
			200
		);
	}
}
