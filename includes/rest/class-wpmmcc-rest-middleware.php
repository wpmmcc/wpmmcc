<?php
/**
 * WPMMCC REST Security & HMAC Middleware (WP-A-02).
 *
 * Enforces HMAC-SHA256 message signing, clock drift validation,
 * and anti-replay nonce protection according to doc 02 §2.2 and doc 14 §4.
 *
 * @package WPMMCC\REST
 */

namespace WPMMCC\REST;

use WPMMCC\Sync\Event_Recorder;
use WP_REST_Request;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Rest_Middleware
 */
class Rest_Middleware {

	/**
	 * Maximum allowed clock drift in seconds (5 minutes).
	 */
	const MAX_CLOCK_DRIFT_SECONDS = 300;

	/**
	 * Default per-identity rate limit (requests per minute, doc 14 §6).
	 * Filterable via `wpmmcc_rate_limit_per_minute`; 0 disables limiting.
	 */
	const DEFAULT_RATE_LIMIT_PER_MINUTE = 240;

	/**
	 * Fixed-window rate limit for a caller identity (WP-A-02, doc 14 §6).
	 *
	 * Identities: 'peer|<uuid>' for HMAC-authenticated peer traffic,
	 * 'preauth|ip|<addr>' for rejected unauthenticated attempts and
	 * 'client|<token-hash>' / 'client|ip|<addr>' for client-token traffic.
	 * Returns a 429 WP_Error (with Retry-After) once the caller exceeds the
	 * window budget. Uses transients so it works on object cache and plain
	 * installs alike; windows are 60 seconds, keyed by bucket + window id.
	 *
	 * @param string   $bucket_id Stable caller identity bucket.
	 * @param int|null $limit     Optional per-minute limit override.
	 * @return true|WP_Error True when within budget, WP_Error(429) otherwise.
	 */
	public static function enforce_rate_limit( string $bucket_id, ?int $limit = null ) {
		if ( '' === $bucket_id ) {
			return true;
		}

		$limit = $limit ?? (int) apply_filters( 'wpmmcc_rate_limit_per_minute', self::DEFAULT_RATE_LIMIT_PER_MINUTE, $bucket_id );
		if ( $limit <= 0 ) {
			return true;
		}

		$window   = (int) floor( time() / 60 );
		$cache_key = 'wpmmcc_rl_' . substr( md5( $bucket_id ), 0, 24 ) . '_' . $window;

		$count = (int) get_transient( $cache_key );
		if ( $count >= $limit ) {
			return new WP_Error(
				'wpmmcc_rate_limited',
				__( 'Rate limit exceeded for this identity. Slow down and retry later.', 'wpmmcc' ),
				array(
					'status'      => 429,
					'retry_after' => ( 60 - ( time() % 60 ) ),
				)
			);
		}

		set_transient( $cache_key, $count + 1, 61 );
		return true;
	}

	/**
	 * Wire-compatibility response signing for the standalone client
	 * (Identity Contract v1.1 §3 / client Protocol v2 transport).
	 *
	 * The client's transport layer rejects unsigned plaintext responses on
	 * plain http (its `requires_transport_encryption` policy), so without a
	 * signature the client can never complete identity verification against
	 * a wpmmcc site. When a request to a `wpmmcc/v1` route presents the
	 * valid client token, sign the response JSON with the same derivation
	 * the ATS plugin uses:
	 *   key = hash_hkdf( sha256, client_token, 32, 'wptsall-signing-v1', 'request-signing' )
	 *   sig = base64url( HMAC-SHA256( key, response_json ) )   (no padding)
	 * and declare `X-WPTSALL-Transport: plaintext`. Open-discovery requests
	 * (no token configured / no token presented) stay unsigned — there is
	 * no shared secret to sign with, and the client never verifies
	 * unpaired sites. Hooked at `rest_post_dispatch` priority 999.
	 *
	 * @param mixed            $response Response object.
	 * @param \WP_REST_Server  $server   Server instance.
	 * @param \WP_REST_Request $request  Request instance.
	 * @return mixed Unmodified response, or signed response.
	 */
	public static function maybe_sign_client_response( $response, $server, $request ) {
		unset( $server );

		if ( ! is_object( $response ) || ! method_exists( $response, 'get_data' ) || ! method_exists( $response, 'header' ) ) {
			return $response;
		}

		$route = (string) $request->get_route();
		if ( '' === $route || 0 !== strpos( $route, '/wpmmcc/v1' ) ) {
			return $response;
		}
		self::sign_sync_response( $response, $request );

		$stored_token = (string) get_option( 'wpmmcc_client_token', '' );
		if ( '' === $stored_token ) {
			return $response;
		}

		$presented = trim( (string) $request->get_header( 'x-wptsall-client-token' ) );
		if ( '' === $presented ) {
			$presented = trim( (string) $request->get_header( 'x-wpmmcc-client-token' ) );
		}
		if ( '' === $presented || ! hash_equals( $stored_token, $presented ) ) {
			return $response;
		}

		$plaintext = wp_json_encode( $response->get_data() );
		if ( ! is_string( $plaintext ) || '' === $plaintext ) {
			return $response;
		}

		$signing_key = hash_hkdf( 'sha256', $presented, 32, 'wptsall-signing-v1', 'request-signing' );
		if ( false === $signing_key ) {
			return $response;
		}
		$mac = hash_hmac( 'sha256', $plaintext, $signing_key, true );
		if ( false === $mac ) {
			return $response;
		}

		$signature = rtrim( strtr( base64_encode( $mac ), '+/', '-_' ), '=' );
		$response->header( 'X-WPTSALL-Response-Signature', $signature );
		$response->header( 'X-WPTSALL-Transport', 'plaintext' );
		return $response;
	}

	private static function sign_sync_response( $response, WP_REST_Request $request ): void {
		global $wpdb;
		$route = (string) $request->get_route();
		if ( 0 !== strpos( $route, '/wpmmcc/v1/sync/' ) ) {
			return;
		}
		$memo = self::$gate_memo[ spl_object_id( $request ) . ':hmac' ] ?? null;
		if ( ! is_array( $memo ) || $memo['request'] !== $request || true !== $memo['result']
			|| ! hash_equals( $memo['fingerprint'], self::hmac_fingerprint( $request ) ) ) {
			return;
		}
		$sender = self::requesting_peer_uuid( $request );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Use the currently authenticated peer key, never request-supplied key material.
		$peer = $wpdb->get_row( $wpdb->prepare(
			"SELECT shared_secret, key_salt FROM {$wpdb->prefix}wpmmcc_peers WHERE peer_uuid = %s AND status = 'active' LIMIT 1", $sender
		) );
		if ( ! $peer ) {
			return;
		}
		$secret = self::decrypt_shared_secret( (string) $peer->shared_secret, (string) $peer->key_salt );
		$body = wp_json_encode( $response->get_data() );
		if ( ! is_string( $secret ) || '' === $secret || ! is_string( $body ) ) {
			return;
		}
		$canonical = implode( "\n", array(
			'WPMMCC-RESPONSE-v1', (string) $request->get_header( 'x-wpmmcc-nonce' ),
			(string) $request->get_header( 'x-wpmmcc-timestamp' ), $sender,
			strtoupper( (string) $request->get_method() ), $route,
			(string) $response->get_status(), hash( 'sha256', $body ),
		) );
		$response->header( 'X-WPMMCC-Response-Signature', hash_hmac( 'sha256', $canonical, $secret ) );
	}

	/**
	 * Nonce TTL in seconds (10 minutes).
	 */
	const NONCE_TTL_SECONDS = 600;

	/**
	 * Record an authentication/pairing failure into the wpmmcc_journal
	 * as an inbound AUDIT row (G-06).
	 *
	 * The journal table is dual-role: rows with consumed=0 form the outbound
	 * CDC outbox that peers pull, while consumed=1 rows are inbound audit
	 * only (same pattern as rest/class-wpmmcc-rest-push.php:100,152). Every
	 * row written here MUST set consumed=1 so attack-noise can never leak
	 * into the CDC queue and be shipped to remote sites.
	 *
	 * Rate: auth failures can be attack-noise frequent. A per
	 * route+reason+peer 60-second transient throttle caps journal growth
	 * under brute-force floods while preserving the audit signal.
	 *
	 * Never log secrets (tokens, signatures, pairing codes) — only the
	 * route, a machine-readable reason key, and non-secret context.
	 *
	 * @param string $route     REST route the failure occurred on (e.g. '/wpmmcc/v1/sync/pull').
	 * @param string $reason    Machine-readable reason key (e.g. 'signature_mismatch').
	 * @param string $peer_uuid Optional claimed origin UUID (attacker-supplied, sanitized + capped).
	 * @param array   $detail   Optional non-secret context for the diff_summary column.
	 * @return int Inserted journal row ID, 0 when throttled.
	 */
	public static function record_auth_failure( string $route, string $reason, string $peer_uuid = '', array $detail = array() ): int {
		$route     = substr( sanitize_text_field( $route ), 0, 191 );
		$reason     = sanitize_key( $reason );
		$peer_uuid = substr( sanitize_text_field( $peer_uuid ), 0, 64 );

		$throttle_key = 'wpmmcc_authlog_' . md5( $route . '|' . $reason . '|' . $peer_uuid );
		if ( false !== get_transient( $throttle_key ) ) {
			return 0;
		}
		set_transient( $throttle_key, 1, 60 );

		return Event_Recorder::record_journal_entry(
			array(
				'event_type'   => 'auth_failure',
				'direction'    => 'inbound',
				'object_type'  => 'auth',
				'peer_uuid'    => $peer_uuid,
				'outcome'      => 'error',
				'message'      => $reason,
				'diff_summary' => array_merge( array( 'route' => $route ), $detail ),
				'consumed'     => 1, // Audit-only row: MUST never enter the CDC outbox.
			)
		);
	}

	/**
	 * Most request objects the once-per-request memory pins at once.
	 */
	const GATE_MEMO_LIMIT = 16;

	/**
	 * Verdicts of stateful permission gates, per request object.
	 *
	 * @var array<string,array{request:WP_REST_Request,fingerprint:string,result:mixed}>
	 */
	private static $gate_memo = array();

	/**
	 * Evaluate a stateful permission gate once per request object.
	 *
	 * WordPress core calls a route's permission_callback twice for one HTTP
	 * request, with the same request object: in respond_to_request(), and
	 * again in rest_send_allow_header() (hooked on rest_post_dispatch) to build
	 * the `Allow:` header. A gate that spends state (a rate-limit slot, a
	 * nonce) would be charged twice and would see its own nonce as a replay.
	 *
	 * The repeat is answered from memory only when it is the same object AND
	 * the security-relevant input is unchanged (`$fingerprint`); anything else
	 * is evaluated from scratch, so replay protection is untouched: another
	 * object with the same nonce never sees this memory. Entries hold the
	 * request itself, so `spl_object_id()` cannot be recycled for a different
	 * request while an entry lives; the memory is capped and drops the oldest.
	 *
	 * @param WP_REST_Request $request     Request being authorised.
	 * @param string          $gate        Gate name (one memory slot per gate).
	 * @param string          $fingerprint Digest of every input the verdict depends on.
	 * @param callable        $evaluate    Computes the verdict: true|WP_Error.
	 * @return mixed The verdict of `$evaluate`.
	 */
	public static function once_per_request( WP_REST_Request $request, string $gate, string $fingerprint, callable $evaluate ) {
		$key = spl_object_id( $request ) . ':' . $gate;

		if ( isset( self::$gate_memo[ $key ] ) ) {
			$entry = self::$gate_memo[ $key ];
			if ( $entry['request'] === $request && hash_equals( $entry['fingerprint'], $fingerprint ) ) {
				return $entry['result'];
			}
		}

		$result = $evaluate();

		unset( self::$gate_memo[ $key ] );
		self::$gate_memo[ $key ] = array(
			'request'     => $request,
			'fingerprint' => $fingerprint,
			'result'      => $result,
		);
		while ( count( self::$gate_memo ) > self::GATE_MEMO_LIMIT ) {
			array_shift( self::$gate_memo );
		}

		return $result;
	}

	/**
	 * Verify HMAC signature and anti-replay protection for incoming request.
	 *
	 * Evaluated once per request object (see once_per_request()).
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return true|WP_Error
	 */
	public static function verify_hmac( WP_REST_Request $request ) {
		$body_hash   = hash( 'sha256', (string) $request->get_body() );
		$fingerprint = self::hmac_fingerprint( $request );

		return self::once_per_request(
			$request,
			'hmac',
			$fingerprint,
			static function () use ( $request, $body_hash ) {
				$result = self::evaluate_hmac( $request, $body_hash );
				$data   = is_wp_error( $result ) ? $result->get_error_data() : array();
				if ( is_array( $data ) && in_array( (int) ( $data['status'] ?? 0 ), array( 401, 403 ), true ) ) {
					$ip   = sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
					$rate = self::enforce_rate_limit( 'preauth|ip|' . $ip );
					if ( is_wp_error( $rate ) ) {
						return $rate;
					}
				}
				return $result;
			}
		);
	}

	private static function hmac_fingerprint( WP_REST_Request $request ): string {
		return hash(
			'sha256',
			implode(
				"\n",
				array(
					strtoupper( (string) $request->get_method() ),
					(string) $request->get_route(),
					(string) $request->get_header( 'x-wpmmcc-site-uuid' ),
					(string) $request->get_header( 'x-wpmmcc-origin-uuid' ),
					(string) $request->get_header( 'x-wpmmcc-timestamp' ),
					(string) $request->get_header( 'x-wpmmcc-nonce' ),
					(string) $request->get_header( 'x-wpmmcc-signature' ),
					hash( 'sha256', (string) $request->get_body() ),
				)
			)
		);

	}

	/**
	 * The peer a request claims to come from: the uuid the HMAC envelope is
	 * signed under (X-WPMMCC-Site-UUID, legacy alias X-WPMMCC-Origin-UUID).
	 *
	 * After verify_hmac() has passed this IS the authenticated peer. It is ''
	 * for a request that never went through the gate (the pull leg feeds its
	 * own synthetic requests to the push handler).
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return string
	 */
	public static function requesting_peer_uuid( WP_REST_Request $request ): string {
		$peer_uuid = (string) $request->get_header( 'x-wpmmcc-site-uuid' );
		if ( '' === $peer_uuid ) {
			$peer_uuid = (string) $request->get_header( 'x-wpmmcc-origin-uuid' );
		}
		return $peer_uuid;
	}

	/**
	 * The HMAC / replay check itself.
	 *
	 * @param WP_REST_Request $request   Incoming REST request.
	 * @param string          $body_hash sha256 of the raw request body.
	 * @return true|WP_Error
	 */
	private static function evaluate_hmac( WP_REST_Request $request, string $body_hash ) {
		global $wpdb;

		$route = '/' . ltrim( (string) $request->get_route(), '/' );

		// 1. Extract security headers (case-insensitive in WP REST)
		$peer_uuid = self::requesting_peer_uuid( $request );
		$timestamp = (int) $request->get_header( 'x-wpmmcc-timestamp' );
		$nonce     = sanitize_text_field( (string) $request->get_header( 'x-wpmmcc-nonce' ) );
		$signature = sanitize_text_field( (string) $request->get_header( 'x-wpmmcc-signature' ) );

		if ( '' === $peer_uuid || 0 === $timestamp || '' === $nonce || '' === $signature ) {
			self::record_auth_failure( $route, 'missing_auth_headers', $peer_uuid );
			return new WP_Error(
				'wpmmcc_missing_auth_headers',
				__( 'Missing required HMAC authentication headers (UUID, Timestamp, Nonce, Signature)', 'wpmmcc' ),
				array( 'status' => 401 )
			);
		}

		// 2. Clock drift validation
		$server_time = time();
		if ( abs( $server_time - $timestamp ) > self::MAX_CLOCK_DRIFT_SECONDS ) {
			self::record_auth_failure( $route, 'clock_drift', $peer_uuid, array( 'server_time' => $server_time ) );
			return new WP_Error(
				'wpmmcc_clock_drift',
				__( 'Clock drift exceeds 300 seconds limit', 'wpmmcc' ),
				array(
					'status'      => 401,
					'server_time' => $server_time,
				)
			);
		}

		// 3. Replay protection (Transient + DB nonce check)
		$transient_key = 'wpmmcc_n_' . substr( md5( $nonce ), 0, 20 );
		if ( false !== get_transient( $transient_key ) ) {
			self::record_auth_failure( $route, 'nonce_replayed', $peer_uuid );
			return new WP_Error(
				'wpmmcc_nonce_replayed',
				__( 'Nonce has already been used (replay detected)', 'wpmmcc' ),
				array( 'status' => 403 )
			);
		}

		// Database nonce table fallback check
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom nonce table query.
		$existing = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wpmmcc_nonces WHERE nonce = %s LIMIT 1", $nonce )
		);
		if ( $existing ) {
			self::record_auth_failure( $route, 'nonce_replayed', $peer_uuid );
			return new WP_Error(
				'wpmmcc_nonce_replayed',
				__( 'Nonce has already been used (replay detected)', 'wpmmcc' ),
				array( 'status' => 403 )
			);
		}

		// Revoked/rotated authority cannot remain usable for a cache TTL.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Security authority must be current.
		$peer = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpmmcc_peers WHERE peer_uuid = %s AND status = 'active' LIMIT 1", $peer_uuid )
		);

		if ( ! $peer ) {
			self::record_auth_failure( $route, 'peer_unauthorized', $peer_uuid );
			return new WP_Error(
				'wpmmcc_peer_unauthorized',
				__( 'Origin peer is not registered or is inactive', 'wpmmcc' ),
				array( 'status' => 403 )
			);
		}

		// 5. Decrypt stored shared secret
		$shared_secret = self::decrypt_shared_secret( $peer->shared_secret, $peer->key_salt );
		if ( empty( $shared_secret ) ) {
			self::record_auth_failure( $route, 'key_decryption_failed', $peer_uuid );
			return new WP_Error(
				'wpmmcc_key_decryption_failed',
				__( 'Failed to decrypt peer shared secret', 'wpmmcc' ),
				array( 'status' => 500 )
			);
		}

		// 6. Verify HMAC signature (constant-time)
		$http_method   = strtoupper( $request->get_method() );
		$canonical_uri = '/' . ltrim( $request->get_route(), '/' );

		$string_to_sign = implode(
			"\n",
			array(
				$http_method,
				$canonical_uri,
				(string) $timestamp,
				$nonce,
				$peer_uuid,
				$body_hash,
			)
		);

		$expected_signature = hash_hmac( 'sha256', $string_to_sign, $shared_secret );

		if ( ! hash_equals( $expected_signature, $signature ) ) {
			self::record_auth_failure( $route, 'signature_mismatch', $peer_uuid );
			return new WP_Error(
				'wpmmcc_signature_mismatch',
				__( 'HMAC signature mismatch', 'wpmmcc' ),
				array( 'status' => 401 )
			);
		}

		$direction = (string) ( $peer->direction ?? 'bidirectional' );
		$inbound_write = in_array( $route, array( '/wpmmcc/v1/sync/push', '/wpmmcc/v1/sync/media-chunk' ), true );
		$outbound_read = in_array( $route, array( '/wpmmcc/v1/sync/digest', '/wpmmcc/v1/sync/pull',
			'/wpmmcc/v1/sync/reconcile-digest', '/wpmmcc/v1/sync/prepare', '/wpmmcc/v1/sync/confirm' ), true );
		if ( ! in_array( $direction, array( 'bidirectional', 'push_only', 'pull_only' ), true )
			|| ( $inbound_write && 'push_only' === $direction ) || ( $outbound_read && 'pull_only' === $direction ) ) {
			return new WP_Error( 'wpmmcc_peer_direction_refused', 'This route is outside the paired local direction.', array( 'status' => 403 ) );
		}

		// Only a valid proof may spend the registered peer's budget.
		$rate = self::enforce_rate_limit( 'peer|' . $peer_uuid );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		// 7. Store nonce to prevent future replay
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom nonce table insert.
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'wpmmcc_nonces',
			array(
				'nonce'      => $nonce,
				'peer_uuid'  => $peer_uuid,
				'expires_at' => gmdate( 'Y-m-d H:i:s', $server_time + self::NONCE_TTL_SECONDS ),
			),
			array( '%s', '%s', '%s' )
		);
		if ( 1 !== $inserted ) {
			return new WP_Error( 'wpmmcc_nonce_not_saved', 'Replay authority could not be saved; request refused.', array( 'status' => 409 ) );
		}
		set_transient( $transient_key, 1, self::NONCE_TTL_SECONDS );

		return true;
	}

	/**
	 * Compute HMAC signature for an outbound request.
	 *
	 * @param string $method HTTP method (GET, POST).
	 * @param string $uri Route URI path.
	 * @param int    $timestamp Unix timestamp.
	 * @param string $nonce Random nonce string.
	 * @param string $site_uuid Local site UUID.
	 * @param string $body Raw request body.
	 * @param string $shared_secret Secret key.
	 * @return string 64-char lowercase hex HMAC-SHA256.
	 */
	public static function sign_request( string $method, string $uri, int $timestamp, string $nonce, string $site_uuid, string $body, string $shared_secret ): string {
		$canonical_uri = '/' . ltrim( $uri, '/' );
		$body_hash     = hash( 'sha256', $body );

		$string_to_sign = implode(
			"\n",
			array(
				strtoupper( $method ),
				$canonical_uri,
				(string) $timestamp,
				$nonce,
				$site_uuid,
				$body_hash,
			)
		);

		return hash_hmac( 'sha256', $string_to_sign, $shared_secret );
	}

	/**
	 * Encrypt shared secret for storage at rest (doc 14 §4.2).
	 * AES-256-GCM using wp_salt('auth_key') + per-peer salt.
	 *
	 * @param string $raw_secret Raw secret key.
	 * @param string $key_salt Salt for key derivation.
	 * @return string Base64 encoded ciphertext with iv and tag.
	 */
	public static function encrypt_shared_secret( string $raw_secret, string $key_salt ): string {
		$auth_salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'wpmmcc-default-salt-key';
		$derived_key = hash_hkdf( 'sha256', $auth_salt, 32, 'wpmmcc-atrest', $key_salt );

		$iv  = random_bytes( 12 );
		$tag = '';
		$ciphertext = openssl_encrypt( $raw_secret, 'aes-256-gcm', $derived_key, OPENSSL_RAW_DATA, $iv, $tag );

		return base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt stored shared secret.
	 *
	 * @param string $encrypted_secret Stored ciphertext.
	 * @param string $key_salt Salt used during encryption.
	 * @return string|null Decrypted secret or null on failure.
	 */
	public static function decrypt_shared_secret( string $encrypted_secret, string $key_salt ): ?string {
		$raw = base64_decode( $encrypted_secret, true );
		if ( false === $raw || strlen( $raw ) < 28 ) { // 12 iv + 16 tag = 28 min
			return null;
		}

		$auth_salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'wpmmcc-default-salt-key';
		$derived_key = hash_hkdf( 'sha256', $auth_salt, 32, 'wpmmcc-atrest', $key_salt );

		$iv         = substr( $raw, 0, 12 );
		$tag        = substr( $raw, 12, 16 );
		$ciphertext = substr( $raw, 28 );

		$decrypted = openssl_decrypt( $ciphertext, 'aes-256-gcm', $derived_key, OPENSSL_RAW_DATA, $iv, $tag );
		return false !== $decrypted ? $decrypted : null;
	}
}
