<?php
/**
 * WPMMCC REST Digest Controller (POST /wp-json/wpmmcc/v1/sync/digest).
 *
 * Exposes the incremental entity digest (Reconciler::generate_digest) to
 * HMAC-authenticated peers so a pulling peer can discover new/changed
 * canonical UUIDs with a keyset cursor (last_seen_id), then fetch the
 * changed entities via /sync/pull. This is the same digest the plugin's
 * own scheduled reconciliation loop uses (doc 09 §4.1) — no new logic,
 * only an authenticated transport for it.
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
 * Class Rest_Digest
 */
class Rest_Digest extends Rest_Base {

	/**
	 * Maximum digest page size per request (bounds work per call).
	 */
	const MAX_LIMIT = 200;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/sync/digest',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_digest' ),
				'permission_callback' => array( 'WPMMCC\REST\Rest_Middleware', 'verify_hmac' ),
			)
		);
	}

	/**
	 * Handle digest request.
	 *
	 * Request body:
	 *   - limit        (int, optional, default 100, max 200)
	 *   - last_seen_id (int, optional, default 0 — keyset cursor by local post ID)
	 *   - post_types   (string[], optional, default Reconciler::DEFAULT_POST_TYPES: post/page/product)
	 *
	 * Response data.items[] each carry:
	 *   canonical_uuid, source_fingerprint, vector_clock, local_id,
	 *   post_status, post_type, last_modified.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_digest( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_json_params() ?: $request->get_params();

		$limit = (int) ( $params['limit'] ?? 100 );
		if ( $limit < 1 ) {
			$limit = 100;
		}
		if ( $limit > self::MAX_LIMIT ) {
			$limit = self::MAX_LIMIT;
		}

		$last_seen_id = max( 0, (int) ( $params['last_seen_id'] ?? 0 ) );

		// An empty list falls back to Reconciler::DEFAULT_POST_TYPES there.
		$post_types = $params['post_types'] ?? array();
		if ( ! is_array( $post_types ) ) {
			$post_types = array();
		}

		$items = Reconciler::generate_digest( $limit, $last_seen_id, $post_types );

		return $this->format_success(
			array(
				'items'        => $items,
				'last_seen_id' => $last_seen_id,
				'limit'        => $limit,
				'server_time'  => time(),
			),
			200
		);
	}
}
