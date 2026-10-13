<?php
/**
 * WPMMCC REST Handshake Controller (WP-A-03).
 *
 * Implements one-time pairing, HKDF key derivation, ADR-5 same-installation
 * rejection, and direction negotiation according to doc 02 §2.1 and doc 14 §4.
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
 * Class Rest_Handshake
 */
class Rest_Handshake extends Rest_Base {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/sync/handshake',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_handshake' ),
				'permission_callback' => array( $this, 'permission_handshake' ),
			)
		);
	}

	/**
	 * Generate and store a fresh 10-minute pairing code.
	 *
	 * @return string 32-character hexadecimal pairing token.
	 */
	public static function generate_pairing_code(): string {
		$code = bin2hex( random_bytes( 16 ) );
		set_transient( 'wpmmcc_active_pairing_code', $code, 600 );
		return $code;
	}

	/**
	 * Handle pairing handshake.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function permission_handshake( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_params();
		}
		$pairing_code = sanitize_text_field( $params['pairing_code'] ?? '' );
		$stored_code  = get_transient( 'wpmmcc_active_pairing_code' );
		if ( empty( $stored_code ) || ! hash_equals( (string) $stored_code, $pairing_code ) ) {
			$origin_uuid = sanitize_text_field( $params['origin_uuid'] ?? '' );
			$origin_url  = esc_url_raw( $params['origin_url'] ?? '' );
			Rest_Middleware::record_auth_failure(
				'/wpmmcc/v1/sync/handshake',
				'invalid_pairing_code',
				$origin_uuid,
				array( 'origin_url' => substr( $origin_url, 0, 200 ) )
			);
			return $this->format_error(
				'wpmmcc_invalid_pairing_code',
				__( 'Invalid or expired pairing code. Please generate a new one.', 'wpmmcc' ),
				400
			);
		}
		return true;
	}

	public function handle_handshake( WP_REST_Request $request ) {
		global $wpdb;

		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_params();
		}

		$pairing_code        = sanitize_text_field( $params['pairing_code'] ?? '' );
		$stored_code         = get_transient( 'wpmmcc_active_pairing_code' );
		if ( empty( $stored_code ) || ! hash_equals( (string) $stored_code, $pairing_code ) ) {
			$origin_uuid = sanitize_text_field( $params['origin_uuid'] ?? '' );
			$origin_url  = esc_url_raw( $params['origin_url'] ?? '' );
			Rest_Middleware::record_auth_failure(
				'/wpmmcc/v1/sync/handshake',
				'invalid_pairing_code',
				$origin_uuid,
				array( 'origin_url' => substr( $origin_url, 0, 200 ) )
			);
			return $this->format_error(
				'wpmmcc_invalid_pairing_code',
				__( 'Invalid or expired pairing code. Please generate a new one.', 'wpmmcc' ),
				400
			);
		}

		$origin_uuid         = sanitize_text_field( $params['origin_uuid'] ?? '' );
		$origin_url          = esc_url_raw( $params['origin_url'] ?? '' );
		$origin_name         = sanitize_text_field( $params['origin_name'] ?? 'Peer Site' );
		$origin_signature    = sanitize_text_field( $params['install_signature'] ?? '' );
		$requested_direction = sanitize_text_field( $params['requested_direction'] ?? 'bidirectional' );
		$source_lang         = sanitize_text_field( $params['source_lang'] ?? 'en_US' );
		$target_lang         = sanitize_text_field( $params['target_lang'] ?? 'zh_CN' );
		$sync_mode           = sanitize_text_field( $params['sync_mode'] ?? 'sync_only' );

		// permission_handshake() already checked this and did not consume it.
		// Verify again here so a direct call cannot skip the gate, then consume once.
		delete_transient( 'wpmmcc_active_pairing_code' );

		// 2. Validate Origin UUID
		if ( empty( $origin_uuid ) || empty( $origin_url ) ) {
			return $this->format_error(
				'wpmmcc_missing_peer_identity',
				__( 'Origin UUID and URL must be provided.', 'wpmmcc' ),
				400
			);
		}

		// 3. ADR-5 Same Installation Rejection
		$local_signature = self::compute_install_signature();
		if ( ! empty( $origin_signature ) && hash_equals( $local_signature, $origin_signature ) ) {
			Rest_Middleware::record_auth_failure(
				'/wpmmcc/v1/sync/handshake',
				'same_installation_rejected',
				$origin_uuid
			);
			return $this->format_error(
				'wpmmcc_same_installation_rejected',
				__( 'Pairing rejected: origin and target share the same WordPress installation. Use WPMMCC ATS for intra-site multilingual operations.', 'wpmmcc' ),
				400
			);
		}

		$local_uuid = (string) get_option( 'wpmmcc_origin_uuid', '' );
		if ( '' === $local_uuid ) {
			$local_uuid = wp_generate_uuid4();
			update_option( 'wpmmcc_origin_uuid', $local_uuid );
		}

		// Prevent pairing with self UUID
		if ( hash_equals( $local_uuid, $origin_uuid ) ) {
			Rest_Middleware::record_auth_failure(
				'/wpmmcc/v1/sync/handshake',
				'self_pairing_prohibited',
				$origin_uuid
			);
			return $this->format_error(
				'wpmmcc_self_pairing_prohibited',
				__( 'Cannot pair a site with itself.', 'wpmmcc' ),
				400
			);
		}

		// 4. Direction Negotiation (doc 14 §4.3)
		$negotiated_direction = 'bidirectional';
		if ( 'push_only' === $requested_direction ) {
			// Initiator pushes only -> receiver will pull / accept pushes only
			$negotiated_direction = 'pull_only';
		} elseif ( 'pull_only' === $requested_direction ) {
			// Initiator pulls only -> receiver will push only
			$negotiated_direction = 'push_only';
		}

		// 5. Generate 32B random pairing secret
		$pairing_secret = bin2hex( random_bytes( 16 ) ); // 32 hex chars

		// 6. HKDF-SHA256 Key Derivation
		// salt = min(a_uuid, b_uuid) || '|' || protocol_version
		$salt = min( $origin_uuid, $local_uuid ) . '|1';
		$derived_shared_secret = hash_hkdf( 'sha256', $pairing_secret, 32, 'wpmmcc-peer-hmac-v1', $salt );

		// 7. Store Peer Record in DB
		$key_salt   = bin2hex( random_bytes( 16 ) );
		$enc_secret = Rest_Middleware::encrypt_shared_secret( $derived_shared_secret, $key_salt );

		$peer_id_key = 'peer_id_' . md5( $origin_uuid );
		$existing_id = wp_cache_get( $peer_id_key, 'wpmmcc' );
		if ( false === $existing_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table or connection command; WordPress has no replacement API
			$existing_id = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wpmmcc_peers WHERE peer_uuid = %s", $origin_uuid )
			);
			wp_cache_set( $peer_id_key, $existing_id, 'wpmmcc', 60 );
		}

		// X-1 (tasks/5.3falsh2/12 批 B): the origin declares its pairing-chosen
		// conflict strategy on the handshake; it lands in the peers row that
		// rest-push feeds to the arbiter. Canonical five-value vocabulary —
		// legacy synonyms normalize, and an absent field (older clients)
		// keeps the historical 'lww' default.
		$conflict_strategy = \WPMMCC\Sync\Arbiter::normalize_strategy( sanitize_text_field( $params['conflict_strategy'] ?? 'lww' ) );

		$peer_data = array(
			'peer_uuid'         => $origin_uuid,
			'peer_name'         => $origin_name,
			'peer_type'         => 'standalone',
			'endpoint_url'      => rtrim( $origin_url, '/' ) . '/wp-json/wpmmcc/v1',
			'shared_secret'     => $enc_secret,
			'key_salt'          => $key_salt,
			'direction'         => $negotiated_direction,
			'sync_mode'         => $sync_mode,
			'source_lang'       => $source_lang,
			'target_lang'       => $target_lang,
			'conflict_strategy' => $conflict_strategy,
			'status'            => 'active',
			'install_signature' => $origin_signature,
			'updated_at'        => current_time( 'mysql' ),
		);

		if ( $existing_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table or connection command; WordPress has no replacement API
			$wpdb->update( $wpdb->prefix . 'wpmmcc_peers', $peer_data, array( 'id' => $existing_id ) );
		} else {
			$peer_data['created_at'] = current_time( 'mysql' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table or connection command; WordPress has no replacement API
			$wpdb->insert( $wpdb->prefix . 'wpmmcc_peers', $peer_data );
		}

		wp_cache_delete( 'peer_' . md5( $origin_uuid ), 'wpmmcc' );
		wp_cache_delete( $peer_id_key, 'wpmmcc' );
		wp_cache_delete( 'active_peers_list', 'wpmmcc' );
		wp_cache_delete( 'admin_peers_recent', 'wpmmcc' );
		wp_cache_delete( 'admin_peer_name_' . md5( $origin_uuid ), 'wpmmcc' );
		if ( $existing_id ) {
			wp_cache_delete( 'admin_peer_' . (int) $existing_id, 'wpmmcc' );
			wp_cache_delete( 'admin_peer_row_' . (int) $existing_id, 'wpmmcc' );
		}

		return $this->format_success(
			array(
				'peer_uuid'            => $local_uuid,
				'peer_name'            => get_bloginfo( 'name' ),
				'status'               => 'paired',
				'pairing_secret'       => $pairing_secret,
				'key_scheme'           => 'hmac_v1',
				'negotiated_direction' => $negotiated_direction,
				'conflict_strategy'    => $conflict_strategy,
				'install_signature'    => $local_signature,
			),
			200
		);
	}
}
