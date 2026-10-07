<?php
/**
 * WPMMCC Adapter Registry (WP-A-13, doc 05 §3).
 *
 * Discovers, registers, and routes synchronization logic to specialized
 * content adapters across all 20 supported WordPress plugins.
 *
 * @package WPMMCC\Adapters
 */

namespace WPMMCC\Adapters;

use WPMMCC\Sync\Event_Recorder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Adapter_Registry
 */
class Adapter_Registry {

	/**
	 * Registered adapter instances indexed by slug.
	 *
	 * @var Adapter_Interface[]
	 */
	private static $adapters = array();

	/**
	 * Initialized flag.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Initialize default adapters.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}

		self::register_adapter( new Adapter_Woocommerce() );
		self::register_adapter( new Adapter_LMS() );
		self::register_adapter( new Adapter_Bbpress() );
		self::register_adapter( new Adapter_Elementor() );
		self::register_adapter( new Adapter_ACF() );
		self::register_adapter( new Adapter_SEO() );
		self::register_adapter( new Adapter_Media_Gallery() );
		self::register_adapter( new Adapter_Generic() );

		// Extensibility hook for third-party custom adapters
		$custom_adapters = apply_filters( 'wpmmcc_registered_adapters', array() );
		if ( is_array( $custom_adapters ) ) {
			foreach ( $custom_adapters as $adapter ) {
				if ( $adapter instanceof Adapter_Interface ) {
					self::register_adapter( $adapter );
				}
			}
		}

		self::$initialized = true;
	}

	/**
	 * Register an adapter instance.
	 *
	 * @param Adapter_Interface $adapter Adapter.
	 * @return void
	 */
	public static function register_adapter( Adapter_Interface $adapter ): void {
		self::$adapters[ $adapter->get_slug() ] = $adapter;
	}

	/**
	 * Get all registered adapters.
	 *
	 * @return Adapter_Interface[]
	 */
	public static function get_all(): array {
		self::init();
		return self::$adapters;
	}

	/**
	 * Get adapter responsible for a specific post type.
	 *
	 * @param string $post_type Post type.
	 * @return Adapter_Interface|null
	 */
	public static function get_for_post_type( string $post_type ): ?Adapter_Interface {
		self::init();

		// Check specific adapters first
		foreach ( self::$adapters as $slug => $adapter ) {
			if ( 'generic' === $slug || 'acf' === $slug || ! $adapter->is_plugin_active() ) {
				continue;
			}
			if ( in_array( $post_type, $adapter->get_supported_post_types(), true ) ) {
				return $adapter;
			}
		}

		// Fallback to generic adapter
		return self::$adapters['generic'] ?? null;
	}

	/**
	 * The only packet-to-adapter entry, including third-party adapters.
	 */
	public static function import_payload( Adapter_Interface $adapter, int $post_id, array $payload, array $context = array() ): bool {
		return \WPMMCC\Sync\Write_Policy::import( $adapter, $post_id, $payload, $context );
	}

	/**
	 * Record an adapter parse/extract failure into the wpmmcc_journal
	 * as an audit row (G-20).
	 *
	 * Shared by all adapters for the decode paths where source content is
	 * decoded into fields (Elementor widget JSON trees, gallery/podcast
	 * serialized trees, product attribute matrices). The journal is the
	 * dual-role CDC outbox AND inbound audit log; rows MUST carry
	 * consumed=1 so parse-error audits are never shipped to peers via
	 * the CDC pull queue (same pattern as rest/class-wpmmcc-rest-push.php:100,152).
	 *
	 * @param string $adapter_slug Adapter that failed to parse (e.g. 'elementor').
	 * @param int    $post_id      Post the payload belongs to.
	 * @param string $post_type    Post type (or best-known object type).
	 * @param string $message      Human-readable parse error (sanitized, capped).
	 * @param string $direction    'inbound' when materializing a packet, 'outbound' when exporting.
	 * @return int Inserted journal row ID or 0 on failure.
	 */
	public static function record_parse_error( string $adapter_slug, int $post_id, string $post_type, string $message, string $direction = 'inbound' ): int {
		if ( ! in_array( $direction, array( 'inbound', 'outbound' ), true ) ) {
			$direction = 'inbound';
		}

		return Event_Recorder::record_journal_entry(
			array(
				'event_type'      => 'adapter_parse_error',
				'direction'       => $direction,
				'object_type'     => 'post',
				'local_object_id' => $post_id,
				'outcome'         => 'error',
				'message'         => substr( sanitize_text_field( $message ), 0, 255 ),
				'diff_summary'    => array(
					'adapter'   => sanitize_key( $adapter_slug ),
					'post_type' => sanitize_key( $post_type ),
				),
				'consumed'        => 1, // Audit-only row: MUST never enter the CDC outbox.
			)
		);
	}
}
