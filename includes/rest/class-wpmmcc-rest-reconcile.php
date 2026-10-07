<?php
/**
 * WPMMCC REST Reconcile Digest Controller (POST /wp-json/wpmmcc/v1/sync/reconcile-digest).
 *
 * Implements scheduled fingerprint drift reconciliation according to doc 09 §4 and doc 14 §6.
 *
 * @package WPMMCC\REST
 */

namespace WPMMCC\REST;

use WPMMCC\Sync\Reconciler;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Rest_Reconcile
 */
class Rest_Reconcile extends Rest_Base {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/sync/reconcile-digest',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_reconcile' ),
				'permission_callback' => array( 'WPMMCC\REST\Rest_Middleware', 'verify_hmac' ),
			)
		);
	}

	/**
	 * Handle fingerprint reconciliation digest.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_reconcile( WP_REST_Request $request ) {
		$peer_uuid = Rest_Middleware::requesting_peer_uuid( $request );
		$params    = $request->get_json_params() ?: $request->get_params();

		$fingerprints = $params['fingerprints'] ?? array();
		if ( ! is_array( $fingerprints ) ) {
			return $this->format_error( 'wpmmcc_invalid_fingerprints', __( 'fingerprints array is required.', 'wpmmcc' ), 400 );
		}

		$verdicts = Reconciler::reconcile_fingerprints( $fingerprints, $peer_uuid );

		return $this->format_success(
			array(
				'verdicts'    => $verdicts,
				'server_time' => time(),
			),
			200
		);
	}
}
