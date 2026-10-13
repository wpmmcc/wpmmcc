<?php
/**
 * WPMMCC Core Plugin Bootstrap.
 *
 * @package WPMMCC
 */

namespace WPMMCC;

use WPMMCC\REST\Rest_Ping;
use WPMMCC\REST\Rest_Handshake;
use WPMMCC\REST\Rest_Preflight;
use WPMMCC\REST\Rest_Push;
use WPMMCC\REST\Rest_Pull;
use WPMMCC\REST\Rest_Media;
use WPMMCC\REST\Rest_Reconcile;
use WPMMCC\REST\Rest_Digest;
use WPMMCC\Sync\Event_Recorder;
use WPMMCC\Admin\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Plugin
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Registers autoloader and core lifecycle hooks.
	 */
	private function __construct() {
		$this->register_autoloader();
		$this->init_hooks();
	}

	/**
	 * Register PSR-4 style autoloader for WPMMCC namespace.
	 *
	 * @return void
	 */
	private function register_autoloader(): void {
		spl_autoload_register(
			function ( $class ) {
				$prefix = 'WPMMCC\\';
				$len    = strlen( $prefix );

				if ( 0 !== strncmp( $prefix, $class, $len ) ) {
					return;
				}

				$relative_class = substr( $class, $len );
				$parts          = explode( '\\', $relative_class );

				$class_name = array_pop( $parts );
				$subpath    = ! empty( $parts ) ? strtolower( implode( '/', $parts ) ) . '/' : '';

				$base       = strtolower( str_replace( '_', '-', $class_name ) );
				$candidates = array(
					WPMMCC_PLUGIN_DIR . 'includes/' . $subpath . 'class-wpmmcc-' . $base . '.php',
					WPMMCC_PLUGIN_DIR . 'includes/' . $subpath . 'interface-wpmmcc-' . $base . '.php',
					WPMMCC_PLUGIN_DIR . 'includes/' . $subpath . 'interface-wpmmcc-' . str_replace( '-interface', '', $base ) . '.php',
				);

				foreach ( $candidates as $file_path ) {
					if ( file_exists( $file_path ) ) {
						require_once $file_path;
						return;
					}
				}
			}
		);
	}

	/**
	 * Initialize WordPress action & filter hooks.
	 *
	 * @return void
	 */
	private function init_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'init', array( Event_Recorder::class, 'register_hooks' ) );

		// Identity Contract v1.1 §3: sign client-facing wpmmcc/v1 responses
		// (X-WPTSALL-Response-Signature) so the standalone client's Protocol
		// v2 transport can verify identity on plain http.
		add_filter( 'rest_post_dispatch', array( \WPMMCC\REST\Rest_Middleware::class, 'maybe_sign_client_response' ), 999, 3 );

		if ( is_admin() ) {
			Admin::register_hooks();
		}

		// Scheduled reconciliation cron job
		add_action( 'wpmmcc_cron_reconcile_check', array( \WPMMCC\Sync\Reconciler::class, 'run_scheduled_reconcile' ) );

		// ADR-7: Cross-site alternates filter for hreflang coordination
		add_filter( 'wptsall_cross_site_alternates', array( $this, 'filter_cross_site_alternates' ), 10, 2 );
	}

	/**
	 * Register REST API controllers under namespace `wpmmcc/v1`.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		$controllers = array(
			new Rest_Ping(),
			new Rest_Handshake(),
			new Rest_Preflight(),
			new Rest_Push(),
			new Rest_Pull(),
			new Rest_Media(),
			new Rest_Reconcile(),
			new Rest_Digest(),
			new \WPMMCC\REST\Rest_Confirm(),
		);

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}

	/**
	 * Filter cross-site alternates (ADR-7).
	 * Supplies hreflang pairs for synchronized remote posts without polluting ATS post_mappings.
	 *
	 * @param array $alternates Existing alternates.
	 * @param int   $post_id Local post ID.
	 * @return array
	 */
	public function filter_cross_site_alternates( array $alternates, int $post_id ): array {
		global $wpdb;

		$cache_key = 'cross_site_alt_' . $post_id;
		$cached    = wp_cache_get( $cache_key, 'wpmmcc' );
		if ( false !== $cached && is_array( $cached ) ) {
			return array_merge( $alternates, $cached );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table or connection command; WordPress has no replacement API
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.remote_permalink, m.remote_lang, m.local_lang, m.last_sync_direction, p.endpoint_url, p.target_lang, p.source_lang
				 FROM {$wpdb->prefix}wpmmcc_cross_mappings m
				 INNER JOIN {$wpdb->prefix}wpmmcc_peers p ON (CASE WHEN m.transport_peer_uuid <> '' THEN m.transport_peer_uuid ELSE m.peer_uuid END) = p.peer_uuid
				 WHERE m.local_object_type = 'post' AND m.local_object_id = %d AND p.status = 'active'",
				$post_id
			)
		);

		// Mapping rows written by earlier releases hold this site's own URL in
		// remote_permalink; a page is never its own alternate in another language.
		$self_url = untrailingslashit( (string) get_permalink( $post_id ) );

		$new_alternates = array();
		$ambiguous = array();
		if ( ! empty( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( '' !== $self_url && untrailingslashit( (string) $row->remote_permalink ) === $self_url ) {
					continue;
				}
				$lang = (string) ( $row->remote_lang ?? '' );
				if ( '' === $lang ) {
					$lang = 'inbound' === ( $row->last_sync_direction ?? '' ) ? (string) $row->source_lang : (string) $row->target_lang;
				}
				if ( ! empty( $row->remote_permalink ) && preg_match( '/^[A-Za-z]{2,8}(?:[-_][A-Za-z0-9]{2,8})*$/D', $lang ) ) {
					$url = wp_parse_url( $row->remote_permalink );
					if ( ! is_array( $url ) || ! in_array( $url['scheme'] ?? '', array( 'http', 'https' ), true )
						|| empty( $url['host'] ) || isset( $url['user'] ) || isset( $url['pass'] ) || isset( $url['fragment'] ) ) {
						continue;
					}
					$lang_code = strtolower( str_replace( '_', '-', $lang ) );
					if ( isset( $ambiguous[ $lang_code ] ) ) {
						continue;
					}
					if ( ! isset( $new_alternates[ $lang_code ] ) ) {
						$new_alternates[ $lang_code ] = $row->remote_permalink;
					} elseif ( $new_alternates[ $lang_code ] !== $row->remote_permalink ) {
						unset( $new_alternates[ $lang_code ] );
						$ambiguous[ $lang_code ] = true;
					}
				}
			}
		}

		wp_cache_set( $cache_key, $new_alternates, 'wpmmcc', HOUR_IN_SECONDS );

		return array_merge( $alternates, $new_alternates );
	}
}
