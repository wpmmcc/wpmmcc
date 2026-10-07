<?php
/**
 * WPMMCC Admin Interface & Settings Manager.
 *
 * @package WPMMCC\Admin
 */

namespace WPMMCC\Admin;

use WPMMCC\REST\Peer_Http;
use WPMMCC\REST\Rest_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin
 */
class Admin {

	/**
	 * Sync modes a direct pairing can be created with.
	 *
	 * "Sync & Translate" is not offered: between two sites this plugin only
	 * moves content, nothing in it translates. Translation runs in the pair of
	 * the WPMMCC ATS client, which sends that mode in its own handshake
	 * (Rest_Handshake still accepts it).
	 *
	 * @return array<string,string> Mode => label.
	 */
	public static function selectable_sync_modes(): array {
		return array( 'sync_only' => __( 'Sync Only', 'wpmmcc' ) );
	}

	/**
	 * Validate the sync mode the direct pairing form posted (WMC-02).
	 *
	 * @param string $posted Posted value.
	 * @return string|\WP_Error The mode to store; an error for the translate mode, which needs the client.
	 */
	public static function validate_sync_mode( string $posted ) {
		if ( 'sync_and_translate' === $posted ) {
			return new \WP_Error(
				'wpmmcc_sync_mode_needs_client',
				__( 'Sync & Translate needs the WPMMCC ATS client: pair the two sites through the client. A direct pairing only syncs content.', 'wpmmcc' )
			);
		}
		return array_key_exists( $posted, self::selectable_sync_modes() ) ? $posted : 'sync_only';
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_wpmmcc_generate_pairing_code', array( __CLASS__, 'handle_generate_pairing_code' ) );
		add_action( 'admin_post_wpmmcc_generate_client_token', array( __CLASS__, 'handle_generate_client_token' ) );
		add_action( 'admin_post_wpmmcc_connect_peer_site', array( __CLASS__, 'handle_connect_peer_site' ) );
		add_action( 'admin_post_wpmmcc_disconnect_peer', array( __CLASS__, 'handle_disconnect_peer' ) );
		add_action( 'admin_post_wpmmcc_peer_test_connection', array( __CLASS__, 'handle_peer_test_connection' ) );
		add_action( 'admin_post_wpmmcc_peer_push_now', array( __CLASS__, 'handle_peer_push_now' ) );
		add_action( 'admin_post_wpmmcc_peer_pull_now', array( __CLASS__, 'handle_peer_pull_now' ) );
		add_action( 'admin_post_wpmmcc_peer_requeue_dead', array( __CLASS__, 'handle_peer_requeue_dead' ) );
		add_action( 'admin_post_wpmmcc_accept_remote', array( __CLASS__, 'handle_accept_remote' ) );
		add_action( 'admin_post_wpmmcc_keep_local', array( __CLASS__, 'handle_keep_local' ) );
		add_action( 'admin_post_wpmmcc_clear_journal', array( __CLASS__, 'handle_clear_journal' ) );
	}

	/**
	 * Register WordPress admin menu.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_menu_page(
			__( 'WPMMCC Sync', 'wpmmcc' ),
			__( 'WPMMCC Sync', 'wpmmcc' ),
			'manage_options',
			'wpmmcc-sync',
			array( __CLASS__, 'render_admin_page' ),
			'dashicons-update',
			81
		);
	}

	/**
	 * Handle generation of a 10-minute pairing code.
	 *
	 * @return void
	 */
	public static function handle_generate_pairing_code(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wpmmcc' ) );
		}

		check_admin_referer( 'wpmmcc_generate_pairing' );

		$code = bin2hex( random_bytes( 16 ) );
		set_transient( 'wpmmcc_active_pairing_code', $code, 600 ); // 10 minutes

		wp_safe_redirect( admin_url( 'admin.php?page=wpmmcc-sync&pairing_generated=1' ) );
		exit;
	}

	/**
	 * Generate (or regenerate) the shared client token used by the shared
	 * translation client to authenticate against wpmmcc/v1 endpoints
	 * (Identity Contract v1 §2 client-token auth). Regenerating invalidates
	 * the previously issued token immediately (fail-closed on the client).
	 *
	 * @return void
	 */
	public static function handle_generate_client_token(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wpmmcc' ) );
		}

		check_admin_referer( 'wpmmcc_generate_client_token' );

		$token = bin2hex( random_bytes( 32 ) ); // 64-char hex, same shape as ATS client tokens.
		update_option( 'wpmmcc_client_token', $token, false );

		wp_safe_redirect( admin_url( 'admin.php?page=wpmmcc-sync&client_token_generated=1' ) );
		exit;
	}

	/**
	 * Create site connection pack for standalone client import (Identity Contract v1).
	 *
	 * @return array
	 */
	public static function create_site_connection_pack(): array {
		$site_url     = home_url();
		$route_secret = (string) get_option( 'wpmmcc_route_secret', '' );
		$client_token = (string) get_option( 'wpmmcc_client_token', '' );
		$origin_uuid  = (string) get_option( 'wpmmcc_origin_uuid', '' );
		if ( '' === $origin_uuid ) {
			$origin_uuid = wp_generate_uuid4();
			update_option( 'wpmmcc_origin_uuid', $origin_uuid );
		}
		$client_base = '' !== $route_secret
			? rest_url( 'wpmmcc/v1/' . $route_secret . '/sync' )
			: rest_url( 'wpmmcc/v1/sync' );

		return array(
			'schema'            => 'wptsall-site-connection.v1',
			'plugin_identity'   => defined( 'WPMMCC_IDENTITY' ) ? WPMMCC_IDENTITY : 'wpmmcc',
			'site_id'           => md5( untrailingslashit( $site_url ) ),
			'site_url'          => $site_url,
			'wp_client_base'    => $client_base,
			'route_secret'      => $route_secret,
			'wp_client_token'   => $client_token,
			'origin_uuid'       => $origin_uuid,
			'protocol_version'  => defined( 'WPMMCC_PROTOCOL_CURRENT' ) ? (int) WPMMCC_PROTOCOL_CURRENT : 1,
			'wp_plugin_version' => defined( 'WPMMCC_VERSION' ) ? WPMMCC_VERSION : '1.0.0',
			'wordpress_version' => get_bloginfo( 'version' ),
			'capabilities'      => array( 'sync_ping', 'sync_handshake', 'sync_journal', 'sync_pull', 'sync_push' ),
		);
	}

	/**
	 * Handle connecting to a remote peer site via handshake.
	 *
	 * @return void
	 */
	public static function handle_connect_peer_site(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wpmmcc' ) );
		}

		check_admin_referer( 'wpmmcc_connect_peer' );

		$remote_url        = esc_url_raw( wp_unslash( $_POST['remote_url'] ?? '' ) );
		$pairing_code      = sanitize_text_field( wp_unslash( $_POST['pairing_code'] ?? '' ) );
		$peer_name         = sanitize_text_field( wp_unslash( $_POST['peer_name'] ?? '' ) );
		$direction         = sanitize_text_field( wp_unslash( $_POST['direction'] ?? 'bidirectional' ) );
		$sync_mode         = self::validate_sync_mode( sanitize_text_field( wp_unslash( $_POST['sync_mode'] ?? 'sync_only' ) ) );
		$conflict_strategy = \WPMMCC\Sync\Arbiter::normalize_strategy( sanitize_text_field( wp_unslash( $_POST['conflict_strategy'] ?? 'lww' ) ) );
		$source_lang       = sanitize_text_field( wp_unslash( $_POST['source_lang'] ?? 'en_US' ) );
		$target_lang       = sanitize_text_field( wp_unslash( $_POST['target_lang'] ?? 'zh_CN' ) );

		if ( empty( $remote_url ) || empty( $pairing_code ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wpmmcc-sync&connect_error=' . rawurlencode( __( 'Remote URL and Pairing Code are required.', 'wpmmcc' ) ) ) );
			exit;
		}

		if ( is_wp_error( $sync_mode ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wpmmcc-sync&connect_error=' . rawurlencode( $sync_mode->get_error_message() ) ) );
			exit;
		}

		$local_uuid = (string) get_option( 'wpmmcc_origin_uuid', '' );
		if ( '' === $local_uuid ) {
			$local_uuid = wp_generate_uuid4();
			update_option( 'wpmmcc_origin_uuid', $local_uuid );
		}
		$install_sig = Rest_Base::compute_install_signature();

		$handshake_endpoint = rtrim( $remote_url, '/' ) . '/wp-json/wpmmcc/v1/sync/handshake';
		$payload            = array(
			'pairing_code'        => $pairing_code,
			'origin_uuid'         => $local_uuid,
			'origin_url'          => home_url(),
			'origin_name'         => get_bloginfo( 'name' ),
			'install_signature'   => $install_sig,
			'requested_direction' => $direction,
			'sync_mode'           => $sync_mode,
			'source_lang'         => $source_lang,
			'target_lang'         => $target_lang,
			'conflict_strategy'   => $conflict_strategy,
		);

		$response = Peer_Http::post(
			$handshake_endpoint,
			array(
				'timeout'     => 15,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'        => wp_json_encode( $payload ),
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wpmmcc-sync&connect_error=' . rawurlencode( $response->get_error_message() ) ) );
			exit;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body_str    = wp_remote_retrieve_body( $response );
		$body_json   = json_decode( $body_str, true );

		if ( 200 !== $status_code || empty( $body_json['success'] ) ) {
			$error_msg = $body_json['message'] ?? ( $body_json['data']['message'] ?? ( 'Handshake failed with HTTP ' . $status_code ) );
			wp_safe_redirect( admin_url( 'admin.php?page=wpmmcc-sync&connect_error=' . rawurlencode( $error_msg ) ) );
			exit;
		}

		$remote_data    = $body_json['data'] ?? array();
		$remote_uuid    = sanitize_text_field( $remote_data['peer_uuid'] ?? '' );
		$pairing_secret = (string) ( $remote_data['pairing_secret'] ?? '' );

		if ( empty( $remote_uuid ) || empty( $pairing_secret ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wpmmcc-sync&connect_error=' . rawurlencode( __( 'Remote response missing peer_uuid or pairing_secret', 'wpmmcc' ) ) ) );
			exit;
		}

		// HKDF key derivation matching handshake contract
		$salt                  = min( $local_uuid, $remote_uuid ) . '|1';
		$derived_shared_secret = hash_hkdf( 'sha256', $pairing_secret, 32, 'wpmmcc-peer-hmac-v1', $salt );
		$key_salt              = bin2hex( random_bytes( 16 ) );
		$enc_secret            = \WPMMCC\REST\Rest_Middleware::encrypt_shared_secret( $derived_shared_secret, $key_salt );

		global $wpdb;
		$table       = $wpdb->prefix . 'wpmmcc_peers';
		$peer_id_key = 'peer_id_' . md5( $remote_uuid );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$existing_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE peer_uuid = %s", $remote_uuid ) );

		if ( empty( $peer_name ) ) {
			$peer_name = sanitize_text_field( $remote_data['peer_name'] ?? (string) wp_parse_url( $remote_url, PHP_URL_HOST ) );
		}

		$peer_record = array(
			'peer_uuid'         => $remote_uuid,
			'peer_name'         => $peer_name,
			'peer_type'         => 'standalone',
			'endpoint_url'      => rtrim( $remote_url, '/' ) . '/wp-json/wpmmcc/v1',
			'shared_secret'     => $enc_secret,
			'key_salt'          => $key_salt,
			'direction'         => $direction,
			'sync_mode'         => $sync_mode,
			'source_lang'       => $source_lang,
			'target_lang'       => $target_lang,
			'conflict_strategy' => $conflict_strategy,
			'status'            => 'active',
			'install_signature' => sanitize_text_field( $remote_data['install_signature'] ?? '' ),
			'updated_at'        => current_time( 'mysql' ),
		);

		if ( $existing_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update( $table, $peer_record, array( 'id' => $existing_id ) );
		} else {
			$peer_record['created_at'] = current_time( 'mysql' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $table, $peer_record );
		}

		wp_cache_delete( 'peer_' . md5( $remote_uuid ), 'wpmmcc' );
		wp_cache_delete( $peer_id_key, 'wpmmcc' );
		wp_cache_delete( 'active_peers_list', 'wpmmcc' );
		wp_cache_delete( 'admin_peers_recent', 'wpmmcc' );

		wp_safe_redirect( admin_url( 'admin.php?page=wpmmcc-sync&peer_connected=1' ) );
		exit;
	}

	/**
	 * Deactivate a paired peer without discarding original delivery evidence.
	 *
	 * @return void
	 */
	public static function handle_disconnect_peer(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wpmmcc' ) );
		}

		$peer_id = isset( $_POST['peer_id'] ) ? absint( wp_unslash( $_POST['peer_id'] ) ) : 0;
		check_admin_referer( 'wpmmcc_disconnect_peer_' . $peer_id );

		if ( $peer_id <= 0 ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wpmmcc-sync&peer_delete_error=' . rawurlencode( __( 'Invalid peer id.', 'wpmmcc' ) ) ) );
			exit;
		}

		global $wpdb;
		$peers_table = $wpdb->prefix . 'wpmmcc_peers';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom peers table query.
		$peer = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, peer_uuid, peer_name FROM {$peers_table} WHERE id = %d", $peer_id )
		);

		if ( ! $peer ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wpmmcc-sync&peer_delete_error=' . rawurlencode( __( 'Peer not found (already removed?).', 'wpmmcc' ) ) ) );
			exit;
		}

		$peer_uuid = (string) $peer->peer_uuid;

		// Current authentication checks status on every request. Preserve the
		// original peer, mappings, encrypted intents and dedup receipts.
		$changed = $wpdb->update( $peers_table,
			array( 'status' => 'disconnected', 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $peer_id, 'peer_uuid' => $peer_uuid ) );
		$live = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$peers_table} WHERE id = %d AND peer_uuid = %s", $peer_id, $peer_uuid ) );
		if ( false === $changed || ! $live || 'disconnected' !== $live->status ) {
			wp_die( esc_html__( 'Peer deactivation was not confirmed. Original evidence is retained.', 'wpmmcc' ) );
		}

		// Park pending rows; re-pairing does not silently submit unknown effects.
		$queue_dropped = \WPMMCC\Sync\Push_Queue::forget_peer( $peer_uuid );
		\WPMMCC\Sync\Reconciler::forget_peer_cursors( $peer_uuid );

		// Invalidate every cache layer the peer feeds (same key set the
		// connect handler refreshes).
		wp_cache_delete( 'peer_' . md5( $peer_uuid ), 'wpmmcc' );
		wp_cache_delete( 'peer_id_' . md5( $peer_uuid ), 'wpmmcc' );
		wp_cache_delete( 'active_peers_list', 'wpmmcc' );
		wp_cache_delete( 'admin_peers_recent', 'wpmmcc' );

		// Audit trail: a manual lifecycle change must be visible in the
		// journal. consumed=1 keeps it out of the CDC queue (nothing ships).
		\WPMMCC\Sync\Event_Recorder::record_journal_entry(
			array(
				'event_type'      => 'peer_disconnect',
				'direction'       => 'outbound',
				'object_type'     => 'peer',
				'local_object_id' => (int) $peer_id,
				'peer_uuid'       => $peer_uuid,
				'outcome'         => 'success',
				'message'         => sprintf( 'Manual disconnect of peer "%s" (%s); original mappings and receipts retained, parked %d queue row(s).', $peer->peer_name, $peer_uuid, (int) $queue_dropped ),
				'consumed'        => 1,
			)
		);

		wp_safe_redirect( admin_url( 'admin.php?page=wpmmcc-sync&peer_deleted=1' ) );
		exit;
	}

	/**
	 * Handle a manual connection test against a peer (3.8flash B1).
	 *
	 * @return void
	 */
	public static function handle_peer_test_connection(): void {
		self::run_manual_sync_operation( 'test_connection' );
	}

	/**
	 * Handle a manual push of pending outbound CDC to a peer (3.8flash B1).
	 *
	 * @return void
	 */
	public static function handle_peer_push_now(): void {
		self::run_manual_sync_operation( 'push_now' );
	}

	/**
	 * Handle a manual pull of missing content from a peer (3.8flash B1).
	 *
	 * @return void
	 */
	public static function handle_peer_pull_now(): void {
		self::run_manual_sync_operation( 'pull_now' );
	}

	/**
	 * Put a peer's dead-lettered push events back in the queue (WMC-01).
	 *
	 * @return void
	 */
	public static function handle_peer_requeue_dead(): void {
		self::run_manual_sync_operation( 'requeue_dead' );
	}

	/**
	 * Shared runner for the manual sync operations (3.8flash B1).
	 *
	 * The wpmmcc lifecycle previously ended at pairing: no UI path ever
	 * verified a peer, shipped pending CDC, or pulled missing content — the
	 * site was passive (cron reconcile summary only). The three manual legs
	 * reuse the exact REST wire primitives (HMAC envelope, Packet::export_post,
	 * /sync/digest, /sync/pull, Rest_Push ingest) so the manual console
	 * cannot drift from the protocol the shared client speaks.
	 *
	 * @param string $op One of: test_connection | push_now | pull_now | requeue_dead.
	 * @return void
	 */
	private static function run_manual_sync_operation( string $op ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wpmmcc' ) );
		}

		$peer_id = isset( $_POST['peer_id'] ) ? absint( wp_unslash( $_POST['peer_id'] ) ) : 0;
		check_admin_referer( 'wpmmcc_manual_sync_' . $peer_id );

		$redirect = static function ( array $args ): void {
			$qs = '';
			foreach ( $args as $key => $value ) {
				$qs .= ( '' === $qs ? '' : '&' ) . $key . '=' . rawurlencode( (string) $value );
			}
			wp_safe_redirect( admin_url( 'admin.php?page=wpmmcc-sync' ) . '&' . $qs );
			exit;
		};

		if ( $peer_id <= 0 ) {
			$redirect( array( 'sync_error' => __( 'Invalid peer id.', 'wpmmcc' ) ) );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom peers table query.
		$peer = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpmmcc_peers WHERE id = %d", $peer_id ),
			\ARRAY_A
		);

		if ( ! $peer ) {
			$redirect( array( 'sync_error' => __( 'Peer not found (already removed?).', 'wpmmcc' ) ) );
		}

		if ( 'test_connection' === $op ) {
			$result = \WPMMCC\Sync\Manual_Sync::test_connection( $peer );
			$redirect(
				$result['ok']
					? array(
						'test_ok'      => 1,
						'test_latency' => (int) $result['latency_ms'],
						'test_detail'  => (string) $result['message'],
					)
					: array( 'test_error' => (string) $result['message'] )
			);
		} elseif ( 'push_now' === $op ) {
			$result = \WPMMCC\Sync\Manual_Sync::push_now( $peer );
			$redirect(
				array(
					'push_done' => 1,
					'pushed'    => (int) $result['pushed'],
					'failed'    => (int) $result['failed'],
					'dead'      => (int) ( $result['dead'] ?? 0 ),
				)
			);
		} elseif ( 'requeue_dead' === $op ) {
			$redirect(
				array(
					'requeue_done' => 1,
					'requeued'     => \WPMMCC\Sync\Push_Queue::requeue_dead( (string) ( $peer['peer_uuid'] ?? '' ) ),
				)
			);
		} elseif ( 'pull_now' === $op ) {
			$result = \WPMMCC\Sync\Manual_Sync::pull_now( $peer );
			$redirect(
				array(
					'pull_done' => 1,
					'pulled'    => (int) $result['pulled'],
					'failed'    => (int) $result['failed'],
				)
			);
		} else {
			$redirect( array( 'sync_error' => __( 'Unknown sync operation.', 'wpmmcc' ) ) );
		}
	}

	/**
	 * Handle accepting the remote (shadow draft) version of a conflict (3.8flash B3).
	 *
	 * The resolution itself lives in Shadow_Draft::accept_remote() so the admin
	 * screen and every other caller treat a review draft the same way: the
	 * draft is promoted in place, the displaced local post is detached from the
	 * entity (never deleted), and exactly one post answers to the canonical uuid
	 * afterwards.
	 *
	 * @return void
	 */
	public static function handle_accept_remote(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wpmmcc' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		check_admin_referer( 'wpmmcc_conflict_' . $post_id );

		$result = \WPMMCC\Sync\Shadow_Draft::accept_remote( $post_id );
		if ( is_wp_error( $result ) ) {
			self::redirect_to_sync_page( array( 'conflict_error' => $result->get_error_message() ) );
		}

		self::redirect_to_sync_page( array( 'conflict_accepted' => 1 ) );
	}

	/**
	 * Handle keeping the local version of a conflict (3.8flash B3).
	 *
	 * Shadow_Draft::keep_local() re-points the canonical mapping onto the
	 * displaced original post, keeps the mapping's content_checksum /
	 * vector_clock (so the peer's next digest of the SAME revision verdicts
	 * in_sync instead of re-offering it), stamps last_synced_at, and discards
	 * the staging draft without echoing a tombstone to the peer.
	 *
	 * @return void
	 */
	public static function handle_keep_local(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wpmmcc' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		check_admin_referer( 'wpmmcc_conflict_' . $post_id );

		$result = \WPMMCC\Sync\Shadow_Draft::keep_local( $post_id );
		if ( is_wp_error( $result ) ) {
			self::redirect_to_sync_page( array( 'conflict_error' => $result->get_error_message() ) );
		}

		self::redirect_to_sync_page( array( 'conflict_kept' => 1 ) );
	}

	/**
	 * Redirect back to the sync dashboard with notice arguments, then stop.
	 *
	 * @param array $args Query arguments.
	 * @return void
	 */
	private static function redirect_to_sync_page( array $args ): void {
		$qs = '';
		foreach ( $args as $key => $value ) {
			$qs .= ( '' === $qs ? '' : '&' ) . $key . '=' . rawurlencode( (string) $value );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=wpmmcc-sync' ) . '&' . $qs );
		exit;
	}

	/**
	 * Handle clearing processed journal entries.
	 *
	 * WPMMCC-P2-04 (3.8flash B2): the journal is dual-role (CDC queue +
	 * audit log), so "Clear" is scoped to AUDIT rows only (consumed = 1).
	 * A full wipe would silently drop unconsumed outbound CDC entries
	 * (consumed = 0) — the sync engine's pending queue — and pending
	 * content changes would never ship. This op never records a journal
	 * row for itself: the user's intent is an empty audit view, the op is
	 * guarded by nonce + confirm, and completion is surfaced via the
	 * journal_cleared notice.
	 *
	 * @return void
	 */
	public static function handle_clear_journal(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'wpmmcc' ) );
		}
		check_admin_referer( 'wpmmcc_clear_journal' );

		try {
			\WPMMCC\Sync\Journal_Cleanup::apply(
				isset( $_POST['cleanup_revision'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['cleanup_revision'] ) ) : '',
				isset( $_POST['confirm_cleanup'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST['confirm_cleanup'] ) )
			);
		} catch ( \Throwable $error ) {
			wp_die( esc_html__( 'Cleanup was not confirmed. Refresh the preview and confirm again; delivery evidence stays retained.', 'wpmmcc' ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wpmmcc-sync&journal_cleared=1' ) );
		exit;
	}

	/**
	 * Render admin dashboard.
	 *
	 * @return void
	 */
	public static function render_admin_page(): void {
		global $wpdb;

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$origin_uuid  = (string) get_option( 'wpmmcc_origin_uuid', 'Not initialized' );
		$install_sig  = Rest_Base::compute_install_signature();
		$route_secret = (string) get_option( 'wpmmcc_route_secret', '' );
		$client_token = (string) get_option( 'wpmmcc_client_token', '' );
		$pairing_code = get_transient( 'wpmmcc_active_pairing_code' );

		$peers = wp_cache_get( 'admin_peers_recent', 'wpmmcc' );
		if ( false === $peers ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom peers table query.
			$peers = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpmmcc_peers ORDER BY id DESC LIMIT %d", 20 )
			);
			wp_cache_set( 'admin_peers_recent', $peers, 'wpmmcc', 30 );
		}

		$journal = wp_cache_get( 'admin_journal_recent', 'wpmmcc' );
		if ( false === $journal ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom journal table query.
			$journal = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpmmcc_journal ORDER BY id DESC LIMIT %d", 20 )
			);
			wp_cache_set( 'admin_journal_recent', $journal, 'wpmmcc', 30 );
		}

		// WPMMCC-P1-05 (3.8flash B3): the shadow-draft review queue — the
		// meta IS the queue membership (posts carrying _wpmmcc_shadow_conflict=1).
		$conflict_queue = wp_cache_get( 'conflict_queue', 'wpmmcc' );
		if ( false === $conflict_queue ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Shadow queue postmeta join query.
			$conflict_queue = $wpdb->get_results(
				"SELECT p.ID, p.post_title, p.post_date, p.post_type
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm_shadow
					ON (pm_shadow.post_id = p.ID AND pm_shadow.meta_key = '" . \WPMMCC\Sync\Shadow_Draft::META_FLAG . "' AND pm_shadow.meta_value = '1')
				 WHERE p.post_status NOT IN ('trash')
				 ORDER BY p.ID DESC
				 LIMIT 20"
			);
			wp_cache_set( 'conflict_queue', $conflict_queue, 'wpmmcc', 30 );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WPMMCC - Cross-Site Synchronization Engine', 'wpmmcc' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Standalone cross-site bidirectional content synchronization engine. Zero coupling with intra-site ATS.', 'wpmmcc' ); ?>
			</p>

			<?php if ( isset( $_GET['peer_connected'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible" style="max-width: 900px;">
					<p><?php esc_html_e( 'Peer site connected successfully! Mutual synchronization credentials have been established.', 'wpmmcc' ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['peer_deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible" style="max-width: 900px;">
					<p><?php esc_html_e( 'Peer site deactivated. Original mappings, receipts and parked deliveries are retained.', 'wpmmcc' ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['peer_delete_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error is-dismissible" style="max-width: 900px;">
					<p><strong><?php esc_html_e( 'Peer disconnect failed:', 'wpmmcc' ); ?></strong> <?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['peer_delete_error'] ) ) ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['journal_cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible" style="max-width: 900px;">
					<p><?php esc_html_e( 'Processed journal entries cleared. Pending sync events (CDC queue) were preserved.', 'wpmmcc' ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['connect_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error is-dismissible" style="max-width: 900px;">
					<p><strong><?php esc_html_e( 'Peer connection failed:', 'wpmmcc' ); ?></strong> <?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['connect_error'] ) ) ); ?></p>
				</div>
			<?php endif; ?>
			<?php // WPMMCC-P1-03 (3.8flash B1): manual sync console result notices. ?>
			<?php if ( isset( $_GET['test_ok'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible" style="max-width: 900px;">
					<p>
						<strong><?php esc_html_e( 'Connection test succeeded:', 'wpmmcc' ); ?></strong>
						<?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['test_detail'] ?? '' ) ) ); ?>
						<em>(<?php echo esc_html( sprintf( /* translators: %d: round-trip latency in milliseconds. */ __( '%d ms round-trip', 'wpmmcc' ), absint( $_GET['test_latency'] ?? 0 ) ) ); ?>)</em>
					</p>
				</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['test_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error is-dismissible" style="max-width: 900px;">
					<p><strong><?php esc_html_e( 'Connection test failed:', 'wpmmcc' ); ?></strong> <?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['test_error'] ) ) ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['push_done'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice <?php echo absint( $_GET['failed'] ?? 0 ) > 0 ? 'notice-warning' : 'notice-success'; ?> is-dismissible" style="max-width: 900px;">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: pushed packet count, 2: failed count. */
								__( 'Manual push finished: %1$d packet(s) delivered to the peer, %2$d failed.', 'wpmmcc' ),
								absint( $_GET['pushed'] ?? 0 ),
								absint( $_GET['failed'] ?? 0 )
							)
						);
						if ( absint( $_GET['dead'] ?? 0 ) > 0 ) {
							echo ' ' . esc_html(
								sprintf(
									/* translators: %d: number of events given up on. */
									__( '%d event(s) were given up on after repeated rejections; use "Requeue dead" to retry them.', 'wpmmcc' ),
									absint( $_GET['dead'] )
								)
							);
						}
						?>
					</p>
				</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['requeue_done'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible" style="max-width: 900px;">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: number of events put back in the queue. */
								__( '%d dead-lettered event(s) were put back in the push queue.', 'wpmmcc' ),
								absint( $_GET['requeued'] ?? 0 )
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['pull_done'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice <?php echo absint( $_GET['failed'] ?? 0 ) > 0 ? 'notice-warning' : 'notice-success'; ?> is-dismissible" style="max-width: 900px;">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: pulled packet count, 2: failed count. */
								__( 'Manual pull finished: %1$d packet(s) ingested from the peer, %2$d failed.', 'wpmmcc' ),
								absint( $_GET['pulled'] ?? 0 ),
								absint( $_GET['failed'] ?? 0 )
							)
						);
						?>
					</p>
				</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['sync_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error is-dismissible" style="max-width: 900px;">
					<p><strong><?php esc_html_e( 'Manual sync failed:', 'wpmmcc' ); ?></strong> <?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['sync_error'] ) ) ); ?></p>
				</div>
			<?php endif; ?>
			<?php // WPMMCC-P1-05 (3.8flash B3): conflict review result notices. ?>
			<?php if ( isset( $_GET['conflict_accepted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible" style="max-width: 900px;">
					<p><?php esc_html_e( 'Conflict resolved: the remote (shadow draft) version was accepted and promoted to the mapped local representative.', 'wpmmcc' ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['conflict_kept'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible" style="max-width: 900px;">
					<p><?php esc_html_e( 'Conflict resolved: the local version was kept. The mapping now points at the original post and the shadow staging draft was removed.', 'wpmmcc' ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['conflict_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error is-dismissible" style="max-width: 900px;">
					<p><strong><?php esc_html_e( 'Conflict review failed:', 'wpmmcc' ); ?></strong> <?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['conflict_error'] ) ) ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( '' === $client_token ) : ?>
				<div class="notice notice-warning inline" style="max-width: 900px;">
					<p>
						<strong><?php esc_html_e( 'Open Discovery Mode is active.', 'wpmmcc' ); ?></strong>
						<?php esc_html_e( 'No client token is configured, so the identity endpoint (sync/ping) answers unauthenticated requests. This is intended only for initial discovery before the first client binding. Generate a Client Token below to close it (unauthenticated pings will then get 401).', 'wpmmcc' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<div class="card" style="max-width: 900px; margin-top: 20px;">
				<h2><?php esc_html_e( 'Site Identity & Credentials', 'wpmmcc' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Plugin Identity', 'wpmmcc' ); ?></th>
						<td><code><strong><?php echo esc_html( defined( 'WPMMCC_IDENTITY' ) ? WPMMCC_IDENTITY : 'wpmmcc' ); ?></strong></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Origin Site UUID', 'wpmmcc' ); ?></th>
						<td><code><?php echo esc_html( $origin_uuid ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Install Signature (ADR-5)', 'wpmmcc' ); ?></th>
						<td><code><?php echo esc_html( $install_sig ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'REST Base Endpoint', 'wpmmcc' ); ?></th>
						<td><code><?php echo esc_html( rest_url( 'wpmmcc/v1/sync/ping' ) ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Route Secret (for shared client binding)', 'wpmmcc' ); ?></th>
						<td>
							<input type="text" readonly value="<?php echo esc_attr( $route_secret ); ?>" style="width: 350px; font-family: monospace;" />
							<p class="description"><?php esc_html_e( 'Paste this together with the Client Token into the shared client Sites form (URL + token + route secret).', 'wpmmcc' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Client Token (Identity Contract v1 §2)', 'wpmmcc' ); ?></th>
						<td>
							<?php if ( '' !== $client_token ) : ?>
								<input type="text" readonly value="<?php echo esc_attr( $client_token ); ?>" style="width: 350px; font-family: monospace;" />
								<?php if ( isset( $_GET['client_token_generated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only success flag. ?>
									<span class="description" style="color: green;"><?php esc_html_e( 'Generated. Previous token (if any) is now invalid.', 'wpmmcc' ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<em><?php esc_html_e( 'No client token configured (open discovery mode).', 'wpmmcc' ); ?></em>
							<?php endif; ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin-left: 10px;">
								<input type="hidden" name="action" value="wpmmcc_generate_client_token" />
								<?php wp_nonce_field( 'wpmmcc_generate_client_token' ); ?>
								<button type="submit" class="button button-secondary"><?php echo '' !== $client_token ? esc_html__( 'Regenerate Client Token', 'wpmmcc' ) : esc_html__( 'Generate Client Token', 'wpmmcc' ); ?></button>
							</form>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Active Pairing Code', 'wpmmcc' ); ?></th>
						<td>
							<?php if ( $pairing_code ) : ?>
								<input type="text" readonly value="<?php echo esc_attr( $pairing_code ); ?>" style="width: 350px; font-family: monospace;" />
								<span class="description" style="color: green;"><?php esc_html_e( 'Valid for 10 minutes (single-use).', 'wpmmcc' ); ?></span>
							<?php else : ?>
								<em><?php esc_html_e( 'No active pairing code.', 'wpmmcc' ); ?></em>
							<?php endif; ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block; margin-left: 10px;">
								<input type="hidden" name="action" value="wpmmcc_generate_pairing_code" />
								<?php wp_nonce_field( 'wpmmcc_generate_pairing' ); ?>
								<button type="submit" class="button button-secondary"><?php esc_html_e( 'Generate New Pairing Code', 'wpmmcc' ); ?></button>
							</form>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Client Connection Pack', 'wpmmcc' ); ?></th>
						<td>
							<?php
							$pack      = self::create_site_connection_pack();
							$pack_json = wp_json_encode( $pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
							?>
							<textarea id="wpmmcc-conn-pack-textarea" readonly rows="5" style="width: 100%; max-width: 550px; font-family: monospace; font-size: 11px;"><?php echo esc_textarea( $pack_json ); ?></textarea>
							<p style="margin-top: 5px;">
								<button type="button" class="button button-secondary" onclick="navigator.clipboard.writeText(document.getElementById('wpmmcc-conn-pack-textarea').value); alert('Connection Pack copied to clipboard!');">
									<?php esc_html_e( 'Copy Connection Pack JSON', 'wpmmcc' ); ?>
								</button>
								<span class="description" style="margin-left: 10px;"><?php esc_html_e( 'Paste into the standalone client Sites page to bind automatically.', 'wpmmcc' ); ?></span>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<div class="card" style="max-width: 900px; margin-top: 20px;">
				<h2><?php esc_html_e( 'Connect to Peer Site', 'wpmmcc' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Enter the remote WordPress site URL and the active pairing code generated on that remote site to establish mutual synchronization.', 'wpmmcc' ); ?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 15px;">
					<input type="hidden" name="action" value="wpmmcc_connect_peer_site" />
					<?php wp_nonce_field( 'wpmmcc_connect_peer' ); ?>
					<table class="form-table" style="margin-top: 0;">
						<tr>
							<th scope="row"><label for="wpmmcc_remote_url"><?php esc_html_e( 'Remote Site URL', 'wpmmcc' ); ?> *</label></th>
							<td>
								<input type="url" id="wpmmcc_remote_url" name="remote_url" placeholder="https://peer.example.com" class="regular-text" required />
								<p class="description"><?php esc_html_e( 'Base WordPress URL of the remote site where WPMMCC is installed.', 'wpmmcc' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wpmmcc_pairing_code"><?php esc_html_e( 'Remote Pairing Code', 'wpmmcc' ); ?> *</label></th>
							<td>
								<input type="text" id="wpmmcc_pairing_code" name="pairing_code" placeholder="32-character pairing code" class="regular-text" style="font-family: monospace;" required />
								<p class="description"><?php esc_html_e( 'Active pairing code generated on the remote site dashboard (valid for 10 minutes).', 'wpmmcc' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wpmmcc_peer_name"><?php esc_html_e( 'Peer Friendly Name', 'wpmmcc' ); ?></label></th>
							<td>
								<input type="text" id="wpmmcc_peer_name" name="peer_name" placeholder="e.g. US Store or Peer B" class="regular-text" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wpmmcc_direction"><?php esc_html_e( 'Sync Direction', 'wpmmcc' ); ?></label></th>
							<td>
								<select id="wpmmcc_direction" name="direction">
									<option value="bidirectional" selected><?php esc_html_e( 'Bidirectional (Two-way sync)', 'wpmmcc' ); ?></option>
									<option value="push_only"><?php esc_html_e( 'Push Only (Local -> Remote)', 'wpmmcc' ); ?></option>
									<option value="pull_only"><?php esc_html_e( 'Pull Only (Remote -> Local)', 'wpmmcc' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wpmmcc_sync_mode"><?php esc_html_e( 'Sync Mode', 'wpmmcc' ); ?></label></th>
							<td>
								<select id="wpmmcc_sync_mode" name="sync_mode">
									<?php foreach ( self::selectable_sync_modes() as $mode => $mode_label ) : ?>
										<option value="<?php echo esc_attr( $mode ); ?>"<?php echo 'sync_only' === $mode ? ' selected' : ''; ?>><?php echo esc_html( $mode_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Sites paired directly only sync content. To translate while syncing, pair the sites through the WPMMCC ATS client.', 'wpmmcc' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wpmmcc_conflict_strategy"><?php esc_html_e( 'Conflict Strategy', 'wpmmcc' ); ?></label></th>
							<td>
								<select id="wpmmcc_conflict_strategy" name="conflict_strategy">
									<option value="lww" selected><?php esc_html_e( 'Last-Write-Wins (LWW)', 'wpmmcc' ); ?></option>
									<option value="source_wins"><?php esc_html_e( 'Source Wins', 'wpmmcc' ); ?></option>
									<option value="target_wins"><?php esc_html_e( 'Target Wins', 'wpmmcc' ); ?></option>
									<option value="manual_review"><?php esc_html_e( 'Manual Review (shadow draft)', 'wpmmcc' ); ?></option>
									<option value="merge"><?php esc_html_e( 'Field-Ownership Merge', 'wpmmcc' ); ?></option>
								</select>
							</td>
						</tr>
						<?php
						// UI-28-05: handle_connect_peer_site() reads source_lang /
						// target_lang, but these two controls never rendered —
						// every peer was silently hardwired en_US→zh_CN in the
						// peers table AND the handshake payload. Defaults below
						// preserve the old behaviour for existing users.
						?>
						<tr>
							<th scope="row"><label for="wpmmcc_source_lang"><?php esc_html_e( 'Source Language', 'wpmmcc' ); ?></label></th>
							<td>
								<input type="text" id="wpmmcc_source_lang" name="source_lang" value="en_US" class="regular-text" placeholder="en_US" />
								<p class="description"><?php esc_html_e( 'Locale of the content on THIS site. Recorded with the pairing; this plugin does not translate.', 'wpmmcc' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wpmmcc_target_lang"><?php esc_html_e( 'Target Language', 'wpmmcc' ); ?></label></th>
							<td>
								<input type="text" id="wpmmcc_target_lang" name="target_lang" value="zh_CN" class="regular-text" placeholder="zh_CN" />
								<p class="description"><?php esc_html_e( 'Locale of the content on the peer site. Recorded with the pairing; this plugin does not translate.', 'wpmmcc' ); ?></p>
							</td>
						</tr>
					</table>
					<p class="submit">
						<button type="submit" class="button button-primary">
							<span class="dashicons dashicons-admin-links" style="vertical-align: middle; margin-right: 4px;"></span>
							<?php esc_html_e( 'Connect Peer Site', 'wpmmcc' ); ?>
						</button>
					</p>
				</form>
			</div>

			<div class="card" style="max-width: 900px; margin-top: 20px;">
				<h2><?php esc_html_e( 'Connected Peer Sites', 'wpmmcc' ); ?></h2>
				<?php if ( empty( $peers ) ) : ?>
					<p><?php esc_html_e( 'No peers paired yet. Use a Pairing Code to establish a peer relationship.', 'wpmmcc' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Peer Name', 'wpmmcc' ); ?></th>
								<th><?php esc_html_e( 'UUID', 'wpmmcc' ); ?></th>
								<th><?php esc_html_e( 'Direction', 'wpmmcc' ); ?></th>
								<th><?php esc_html_e( 'Sync Mode', 'wpmmcc' ); ?></th>
								<th><?php esc_html_e( 'Languages', 'wpmmcc' ); ?></th>
								<th><?php esc_html_e( 'Conflict Strategy', 'wpmmcc' ); ?></th>
								<th><?php esc_html_e( 'Status', 'wpmmcc' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'wpmmcc' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $peers as $peer ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $peer->peer_name ); ?></strong></td>
									<td><code title="<?php echo esc_attr( $peer->peer_uuid ); ?>"><?php echo esc_html( substr( $peer->peer_uuid, 0, 16 ) ); ?>…</code></td>
									<td><?php echo esc_html( $peer->direction ); ?></td>
									<td><?php echo esc_html( $peer->sync_mode ); ?></td>
									<?php
									// UI-28-05: surface the language pair so the
									// previously-invisible hardwired values are
									// visible (and now editable at connect time).
									?>
									<td><code><?php echo esc_html( (string) $peer->source_lang . ' → ' . (string) $peer->target_lang ); ?></code></td>
									<td><code><?php echo esc_html( $peer->conflict_strategy ); ?></code></td>
									<td><span class="badge"><?php echo esc_html( $peer->status ); ?></span></td>
									<td>
										<?php $queue = \WPMMCC\Sync\Push_Queue::counts( (string) $peer->peer_uuid ); ?>
										<p class="description" style="margin:0 0 4px;" data-wpmmcc-queue="<?php echo esc_attr( (string) $peer->peer_uuid ); ?>">
											<?php
											echo esc_html(
												sprintf(
													/* translators: 1: not yet attempted, 2: attempted and failed but still queued, 3: given up on. */
													__( 'Push queue: %1$d pending, %2$d retrying, %3$d dead', 'wpmmcc' ),
													$queue['pending'],
													$queue['retrying'],
													$queue['dead']
												)
											);
											?>
										</p>
										<?php // WPMMCC-P1-03 (3.8flash B1): manual sync console — the three legs
										// previously missing from the pairing lifecycle. Same wire primitives
										// (HMAC envelope / export_post / digest / pull / ingest) as the REST surface. ?>
										<div style="display:flex; flex-wrap:wrap; gap:4px; margin-bottom:4px;">
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
												<?php wp_nonce_field( 'wpmmcc_manual_sync_' . (int) $peer->id ); ?>
												<input type="hidden" name="action" value="wpmmcc_peer_test_connection" />
												<input type="hidden" name="peer_id" value="<?php echo esc_attr( (int) $peer->id ); ?>" />
												<button type="submit" class="button button-secondary button-small"><?php esc_html_e( 'Test Connection', 'wpmmcc' ); ?></button>
											</form>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
												<?php wp_nonce_field( 'wpmmcc_manual_sync_' . (int) $peer->id ); ?>
												<input type="hidden" name="action" value="wpmmcc_peer_push_now" />
												<input type="hidden" name="peer_id" value="<?php echo esc_attr( (int) $peer->id ); ?>" />
												<button type="submit" class="button button-secondary button-small"><?php esc_html_e( 'Push Now', 'wpmmcc' ); ?></button>
											</form>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
												<?php wp_nonce_field( 'wpmmcc_manual_sync_' . (int) $peer->id ); ?>
												<input type="hidden" name="action" value="wpmmcc_peer_pull_now" />
												<input type="hidden" name="peer_id" value="<?php echo esc_attr( (int) $peer->id ); ?>" />
												<button type="submit" class="button button-secondary button-small"><?php esc_html_e( 'Pull Now', 'wpmmcc' ); ?></button>
											</form>
											<?php if ( $queue['dead'] > 0 ) : ?>
												<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
													<?php wp_nonce_field( 'wpmmcc_manual_sync_' . (int) $peer->id ); ?>
													<input type="hidden" name="action" value="wpmmcc_peer_requeue_dead" />
													<input type="hidden" name="peer_id" value="<?php echo esc_attr( (int) $peer->id ); ?>" />
													<button type="submit" class="button button-secondary button-small"><?php echo esc_html( sprintf( /* translators: %d: dead-lettered event count. */ __( 'Requeue %d dead', 'wpmmcc' ), $queue['dead'] ) ); ?></button>
												</form>
											<?php endif; ?>
										</div>
										<?php // WPMMCC-P0-01 (3.8flash): the missing inverse action. ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;"
											onsubmit="return confirm('<?php echo esc_js( __( 'Deactivate this peer? Original mappings, receipts and unfinished deliveries will be retained.', 'wpmmcc' ) ); ?>');">
											<?php wp_nonce_field( 'wpmmcc_disconnect_peer_' . (int) $peer->id ); ?>
											<input type="hidden" name="action" value="wpmmcc_disconnect_peer" />
											<input type="hidden" name="peer_id" value="<?php echo esc_attr( (int) $peer->id ); ?>" />
											<button type="submit" class="button button-small button-link-delete" style="color:#b32d2e;">
												<?php esc_html_e( 'Disconnect', 'wpmmcc' ); ?>
											</button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<?php // WPMMCC-P1-05 (3.8flash B3): Conflict Review Queue — shadow drafts
			// previously landed as invisible drafts (findable only by direct
			// postmeta query); there was no surface to review, diff, or resolve
			// a manual_review conflict. The queue IS the meta: posts carrying
			// _wpmmcc_shadow_conflict=1. ?>
			<div class="card" style="max-width: 900px; margin-top: 20px;">
				<h2><?php esc_html_e( 'Conflict Review Queue (Shadow Drafts)', 'wpmmcc' ); ?></h2>
				<?php if ( empty( $conflict_queue ) ) : ?>
					<p><?php esc_html_e( 'No conflict review drafts pending. Shadow drafts appear here when a manual-review peer pushes a version that conflicts with local edits.', 'wpmmcc' ); ?></p>
				<?php else : ?>
					<?php
					$peer_names = array();
					foreach ( (array) $conflict_queue as $cq ) {
						$origin_uuid = (string) get_post_meta( (int) $cq->ID, \WPMMCC\Sync\Shadow_Draft::META_ORIGIN, true );
						if ( '' === $origin_uuid || isset( $peer_names[ $origin_uuid ] ) ) {
							continue;
						}
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom peers table query.
						$peer_names[ $origin_uuid ] = (string) $wpdb->get_var(
							$wpdb->prepare( "SELECT peer_name FROM {$wpdb->prefix}wpmmcc_peers WHERE peer_uuid = %s LIMIT 1", $origin_uuid )
						);
					}
					?>
					<?php foreach ( (array) $conflict_queue as $cq ) : ?>
						<?php
						$shadow_id    = (int) $cq->ID;
						$canonical    = (string) get_post_meta( $shadow_id, '_wpmmcc_canonical_uuid', true );
						$origin_uuid   = (string) get_post_meta( $shadow_id, \WPMMCC\Sync\Shadow_Draft::META_ORIGIN, true );
						$local_id      = (int) get_post_meta( $shadow_id, \WPMMCC\Sync\Shadow_Draft::META_LOCAL_ID, true );
						$shadow_post   = get_post( $shadow_id );
						$local_post    = $local_id > 0 ? get_post( $local_id ) : null;
						$peer_name     = $peer_names[ $origin_uuid ] ?? '';
						$counterpart   = $local_post ? get_edit_post_link( $local_id, '' ) : '';
						?>
						<details class="wpmmcc-conflict-review" style="margin-bottom: 8px;">
							<summary style="cursor: pointer;">
								<strong><?php echo esc_html( get_the_title( $shadow_id ) ); ?></strong>
								&mdash;
								<?php echo esc_html( '' !== $peer_name ? $peer_name : $origin_uuid ); ?>
								&mdash;
								<code title="<?php echo esc_attr( $canonical ); ?>"><?php echo esc_html( substr( $canonical, 0, 16 ) ); ?>…</code>
								<em>(<?php echo esc_html( mysql2date( 'Y-m-d H:i', $cq->post_date ) ); ?>)</em>
							</summary>
							<?php if ( $local_post && $shadow_post ) : ?>
								<?php // Native diff renderer: left = kept local version, right = pushed remote version. ?>
								<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_text_diff() renders a core-escaped diff table; titles come from __() within it. ?>
							<?php echo wp_text_diff( (string) $local_post->post_content, (string) $shadow_post->post_content, array( 'title_left' => __( 'Local version (kept)', 'wpmmcc' ), 'title_right' => __( 'Remote version (pushed)', 'wpmmcc' ) ) ); ?>
								<p class="description">
									<?php esc_html_e( 'Local counterpart:', 'wpmmcc' ); ?>
									<?php if ( '' !== $counterpart ) : ?>
										<a href="<?php echo esc_url( $counterpart ); ?>"><?php echo esc_html( get_the_title( $local_id ) ); ?></a>
									<?php else : ?>
										<?php echo esc_html( get_the_title( $local_id ) ); ?>
									<?php endif; ?>
								</p>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'No recorded local counterpart (legacy shadow) — only Accept Remote is available for this row.', 'wpmmcc' ); ?></p>
							<?php endif; ?>
							<div style="display:flex; flex-wrap:wrap; gap:4px;">
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<?php wp_nonce_field( 'wpmmcc_conflict_' . $shadow_id ); ?>
									<input type="hidden" name="action" value="wpmmcc_accept_remote" />
									<input type="hidden" name="post_id" value="<?php echo esc_attr( $shadow_id ); ?>" />
									<button type="submit" class="button button-secondary button-small"><?php esc_html_e( 'Accept Remote', 'wpmmcc' ); ?></button>
								</form>
								<?php if ( $local_post ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;"
										onsubmit="return confirm('<?php echo esc_js( __( 'Keep the local version and discard this shadow draft? The staging draft is deleted; the original post stays untouched.', 'wpmmcc' ) ); ?>');">
										<?php wp_nonce_field( 'wpmmcc_conflict_' . $shadow_id ); ?>
										<input type="hidden" name="action" value="wpmmcc_keep_local" />
										<input type="hidden" name="post_id" value="<?php echo esc_attr( $shadow_id ); ?>" />
										<button type="submit" class="button button-small button-link-delete" style="color:#b32d2e;"><?php esc_html_e( 'Keep Local', 'wpmmcc' ); ?></button>
									</form>
								<?php endif; ?>
							</div>
						</details>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<div class="card" style="max-width: 900px; margin-top: 20px;">
				<h2><?php esc_html_e( 'Recent Sync Journal (CDC & Audit)', 'wpmmcc' ); ?></h2>
				<?php
				// WPMMCC-P2-04 (3.8flash B2): the journal table was a 16-char
				// UUID stub + a bare outcome cell. The schema has always had
				// object_type / local_object_id / remote_object_id /
				// duration_ms / message — zero DB change, pure render upgrade.
				?>
				<?php if ( empty( $journal ) ) : ?>
					<p><?php esc_html_e( 'No journal entries yet.', 'wpmmcc' ); ?></p>
				<?php else : ?>
					<script>
						<?php // WPMMCC-P2-04 (B2): copy must work on BOTH secure and insecure
						// origins — navigator.clipboard is undefined over plain http,
						// so the async API falls back to the execCommand path. ?>
						function wpmmccCopyUuid(btn) {
							var text = btn.dataset.wpmmccCopy;
							var done = function () {
								btn.textContent = <?php echo wp_json_encode( __( 'Copied', 'wpmmcc' ) ); ?>;
								setTimeout(function () {
									btn.textContent = <?php echo wp_json_encode( __( 'Copy', 'wpmmcc' ) ); ?>;
								}, 1200);
							};
							if (navigator.clipboard && navigator.clipboard.writeText) {
								navigator.clipboard.writeText(text).then(done).catch(function () {
									wpmmccCopyLegacy(text, done);
								});
							} else {
								wpmmccCopyLegacy(text, done);
							}
						}
						function wpmmccCopyLegacy(text, done) {
							var ta = document.createElement('textarea');
							ta.value = text;
							ta.style.position = 'fixed';
							ta.style.opacity = '0';
							document.body.appendChild(ta);
							ta.select();
							try { document.execCommand('copy'); done(); } catch (e) {}
							document.body.removeChild(ta);
						}
					</script>
					<?php
					try {
						$cleanup_preview = \WPMMCC\Sync\Journal_Cleanup::preview();
					} catch ( \Throwable $error ) {
						$cleanup_preview = null;
					}
					?>
					<?php if ( is_array( $cleanup_preview ) ) : ?>
					<p><?php echo esc_html( sprintf( __( 'Eligible peer-lifecycle audit rows: %d. Content, packets, queues, mappings, media and receipts are not deleted.', 'wpmmcc' ), $cleanup_preview['count'] ) ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom: 8px;">
						<?php wp_nonce_field( 'wpmmcc_clear_journal' ); ?>
						<input type="hidden" name="action" value="wpmmcc_clear_journal" />
						<input type="hidden" name="cleanup_revision" value="<?php echo esc_attr( $cleanup_preview['revision'] ); ?>" />
						<label><input type="checkbox" name="confirm_cleanup" value="1" required /> <?php esc_html_e( 'I confirm this audit-only cleanup preview.', 'wpmmcc' ); ?></label>
						<button type="submit" class="button button-small button-link-delete" style="color:#b32d2e;">
							<?php esc_html_e( 'Clear Eligible Audit Entries', 'wpmmcc' ); ?>
						</button>
					</form>
					<?php endif; ?>
					<p class="description" style="margin-top: 0;"><?php esc_html_e( 'Clear removes processed audit entries only; pending sync events (CDC queue) are preserved.', 'wpmmcc' ); ?></p>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Time', 'wpmmcc' ); ?></th>
								<th><?php esc_html_e( 'Direction', 'wpmmcc' ); ?></th>
								<th><?php esc_html_e( 'Event', 'wpmmcc' ); ?></th>
								<th><?php esc_html_e( 'Entity UUID', 'wpmmcc' ); ?></th>
								<th><?php esc_html_e( 'Object', 'wpmmcc' ); ?></th>
								<th><?php esc_html_e( 'Outcome', 'wpmmcc' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $journal as $entry ) : ?>
								<tr>
									<td><?php echo esc_html( $entry->occurred_at ); ?></td>
									<td><?php echo esc_html( $entry->direction ); ?></td>
									<td><code><?php echo esc_html( $entry->event_type ); ?></code></td>
									<td>
										<?php // Full UUID via title + one-click copy affordance. ?>
										<?php if ( ! empty( $entry->canonical_uuid ) ) : ?>
											<code title="<?php echo esc_attr( $entry->canonical_uuid ); ?>"><?php echo esc_html( substr( (string) $entry->canonical_uuid, 0, 16 ) ); ?>…</code>
											<button type="button" class="button button-small" data-wpmmcc-copy="<?php echo esc_attr( $entry->canonical_uuid ); ?>"
												onclick="wpmmccCopyUuid(this)">
												<?php esc_html_e( 'Copy', 'wpmmcc' ); ?>
											</button>
										<?php else : ?>
											—
										<?php endif; ?>
									</td>
									<td>
										<?php
										// Object column: type + local id (linked to the
										// edit screen for posts), remote id when present.
										$obj_type  = (string) $entry->object_type;
										$local_id  = (int) $entry->local_object_id;
										$remote_id = (int) $entry->remote_object_id;
										if ( 'post' === $obj_type && $local_id > 0 ) {
											$edit_link = get_edit_post_link( $local_id );
											if ( $edit_link ) {
												echo '<a href="' . esc_url( $edit_link ) . '">' . esc_html( sprintf( 'post #%d', $local_id ) ) . '</a>';
											} else {
												echo esc_html( sprintf( 'post #%d (deleted)', $local_id ) );
											}
										} elseif ( 'peer' === $obj_type && $local_id > 0 ) {
											echo esc_html( sprintf( 'peer #%d', $local_id ) );
										} elseif ( $local_id > 0 ) {
											echo esc_html( sprintf( '%s #%d', $obj_type, $local_id ) );
										} else {
											echo '—';
										}
										if ( $remote_id > 0 ) {
											echo ' ' . esc_html( sprintf( '↔ remote #%d', $remote_id ) );
										}
										?>
									</td>
									<td>
										<?php if ( 'failed' === (string) $entry->outcome ) : ?>
											<strong style="color: #b32d2e;"><?php esc_html_e( 'failed', 'wpmmcc' ); ?></strong>
											<?php if ( ! empty( $entry->message ) || (int) $entry->duration_ms > 0 ) : ?>
												<details style="margin-top: 4px;">
													<summary><?php esc_html_e( 'Error detail', 'wpmmcc' ); ?></summary>
													<?php if ( ! empty( $entry->message ) ) : ?>
														<pre style="white-space: pre-wrap; max-width: 480px;"><?php echo esc_html( (string) $entry->message ); ?></pre>
													<?php endif; ?>
													<?php if ( (int) $entry->duration_ms > 0 ) : ?>
														<p class="description"><?php echo esc_html( sprintf( /* translators: %d: operation duration in milliseconds. */ __( 'Duration: %d ms', 'wpmmcc' ), (int) $entry->duration_ms ) ); ?></p>
													<?php endif; ?>
												</details>
											<?php endif; ?>
										<?php else : ?>
											<strong><?php echo esc_html( $entry->outcome ); ?></strong>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
