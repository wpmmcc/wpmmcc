<?php
/**
 * WPMMCC REST Ping Controller (WP-A-04 Identity Endpoint).
 *
 * Implements Identity Contract v1.0:
 * GET /wp-json/wpmmcc/v1/sync/ping
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
 * Class Rest_Ping
 *
 * Exposes the identity and protocol capabilities of this WPMMCC installation.
 */
class Rest_Ping extends Rest_Base {

	/**
	 * Register routes.
	 *
	 * Supports both standard `/sync/ping` and secret-prefixed `/{route_secret}/sync/ping`.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$route_secret = (string) get_option( 'wpmmcc_route_secret', '' );
		$routes       = array( '/sync/ping' );

		if ( '' !== $route_secret ) {
			$routes[] = '/' . $route_secret . '/sync/ping';
		}

		foreach ( $routes as $route ) {
			register_rest_route(
				$this->namespace,
				$route,
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_ping' ),
					'permission_callback' => array( $this, 'check_permission' ),
				)
			);
		}
	}

	/**
	 * Check client permissions.
	 *
	 * Validates client token against stored `wpmmcc_client_token`.
	 * Fail-closed policy: returns 401 on authentication mismatch.
	 *
	 * Evaluated once per request object: the gate spends a rate-limit slot,
	 * and WordPress core calls it a second time for the `Allow:` header
	 * (see Rest_Middleware::once_per_request()).
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return bool|WP_Error
	 */
	public function check_permission( WP_REST_Request $request ) {
		$fingerprint = hash(
			'sha256',
			implode(
				"\n",
				array(
					strtoupper( (string) $request->get_method() ),
					(string) $request->get_route(),
					(string) $request->get_header( 'x-wpmmcc-client-token' ),
					(string) $request->get_header( 'x-wptsall-client-token' ),
					(string) $request->get_header( 'authorization' ),
					is_scalar( $request->get_param( 'token' ) ) ? (string) $request->get_param( 'token' ) : '',
				)
			)
		);

		return Rest_Middleware::once_per_request(
			$request,
			'ping',
			$fingerprint,
			function () use ( $request ) {
				return $this->evaluate_permission( $request );
			}
		);
	}

	/**
	 * The client-token check and its rate limit.
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return bool|WP_Error
	 */
	private function evaluate_permission( WP_REST_Request $request ) {
		$stored_token = (string) get_option( 'wpmmcc_client_token', '' );

		// If no token is configured on this site yet (fresh install / pairing phase),
		// allow initial ping discovery so client can inspect plugin identity and install signature.
		if ( '' === $stored_token ) {
			// The bucket is the connection's address, never a request parameter:
			// a caller-named "address" would mint a fresh bucket per request.
			$ip = '';
			if ( isset( $_SERVER['REMOTE_ADDR'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Rate limit bucketing only.
				$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			}
			$rate = Rest_Middleware::enforce_rate_limit( 'client|ip|' . $ip );
			if ( is_wp_error( $rate ) ) {
				return $rate;
			}
			return true;
		}

		// Extract token from request headers, Bearer authorization, or query params
		$provided_token = '';
		if ( $request->get_header( 'x-wpmmcc-client-token' ) ) {
			$provided_token = (string) $request->get_header( 'x-wpmmcc-client-token' );
		} elseif ( $request->get_header( 'x-wptsall-client-token' ) ) {
			$provided_token = (string) $request->get_header( 'x-wptsall-client-token' );
		} else {
			$auth = (string) $request->get_header( 'authorization' );
			if ( 0 === stripos( $auth, 'bearer ' ) ) {
				$provided_token = trim( substr( $auth, 7 ) );
			} elseif ( $request->get_param( 'token' ) ) {
				$provided_token = (string) $request->get_param( 'token' );
			}
		}

		if ( '' === $provided_token || ! hash_equals( $stored_token, $provided_token ) ) {
			// G-06: audit the failure into the journal (never the token itself).
			Rest_Middleware::record_auth_failure(
				'/wpmmcc/v1/sync/ping',
				'' === $provided_token ? 'client_token_missing' : 'client_token_mismatch'
			);
			return $this->format_error(
				'wpmmcc_unauthorized',
				__( 'Client authentication failed', 'wpmmcc' ),
				401
			);
		}

		// Authenticated callers get their own rate bucket keyed by the token
		// (stable, not attacker-controllable across requests).
		$rate = Rest_Middleware::enforce_rate_limit( 'client|' . substr( md5( $stored_token ), 0, 16 ) );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		return true;
	}

	/**
	 * Handle GET /wp-json/wpmmcc/v1/sync/ping
	 *
	 * Output strictly conforms to Identity Contract v1.1:
	 * - Authenticated (client token configured): full payload including
	 *   install_signature.
	 * - Unauthenticated discovery (fresh install, no token stored yet,
	 *   gated only by the IP rate limiter in check_permission): MINIMAL
	 *   payload — pairing needs identity + protocol versions, nothing
	 *   else. install_signature enables offline same-install probing
	 *   (sha256 of site_url|network_id) and must not be disclosed before
	 *   the caller is paired (X-3, 10 号; contract §2's "401 leaks
	 *   nothing" posture extended to the discovery branch).
	 *
	 * @param WP_REST_Request $request Request instance.
	 * @return WP_REST_Response
	 */
	public function get_ping( WP_REST_Request $request ): WP_REST_Response {
		$data = array(
			'plugin_identity'  => defined( 'WPMMCC_IDENTITY' ) ? WPMMCC_IDENTITY : 'wpmmcc',
			'plugin_version'   => defined( 'WPMMCC_VERSION' ) ? WPMMCC_VERSION : '1.0.0',
			'protocol_min'     => defined( 'WPMMCC_PROTOCOL_MIN' ) ? (int) WPMMCC_PROTOCOL_MIN : 1,
			'protocol_current' => defined( 'WPMMCC_PROTOCOL_CURRENT' ) ? (int) WPMMCC_PROTOCOL_CURRENT : 1,
		);

		// check_permission guarantees: token configured ⇒ this request was
		// authenticated against it; no token ⇒ unauthenticated discovery.
		$stored_token = (string) get_option( 'wpmmcc_client_token', '' );
		if ( '' !== $stored_token ) {
			$data['install_signature'] = self::compute_install_signature();
		}

		return $this->format_success( $data, 200 );
	}
}
