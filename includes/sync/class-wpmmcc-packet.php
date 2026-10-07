<?php
/**
 * WPMMCC Sync Packet v1.0 Serializer / Deserializer (WP-A-05).
 *
 * Enforces Zero Wire Unserialize policy (doc 14 §2).
 * All network payloads are strictly JSON. PHP object injection vectors are blocked.
 *
 * @package WPMMCC\Sync
 */

namespace WPMMCC\Sync;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Packet
 */
class Packet {

	/**
	 * Packet schema version.
	 */
	const SCHEMA_VERSION = 'wpmmcc-sync-v1.0';

	/**
	 * Maximum recursion depth for JSON decoding and parsing (doc 14 §2.4).
	 */
	const MAX_RECURSION_DEPTH = 32;

	/**
	 * Maximum allowed packet payload size in bytes (8MB default, doc 14 §5.3).
	 */
	const MAX_PAYLOAD_BYTES = 8388608;

	/**
	 * Serialize a WordPress post or entity into a standard WPMMCC Sync Packet array.
	 *
	 * @param array $args Entity export parameters.
	 * @return array
	 */
	public static function build( array $args ): array {
		$origin_uuid = (string) get_option( 'wpmmcc_origin_uuid', '' );
		$site_url    = function_exists( 'get_site_url' ) ? get_site_url() : site_url();

		$packet_id = 'pkt_' . bin2hex( random_bytes( 8 ) );
		$clock     = (int) ( $args['vector_clock'] ?? 1 );

		$entity = array(
			'guid'            => $args['guid'] ?? ( 'urn:uuid:' . wp_generate_uuid4() ),
			'object_type'     => $args['object_type'] ?? 'post',
			'subtype'         => $args['subtype'] ?? 'post',
			'source_id'       => (int) ( $args['source_id'] ?? 0 ),
			'slug'            => $args['slug'] ?? '',
			'status'          => $args['status'] ?? 'publish',
			'author_hint'     => $args['author_hint'] ?? array( 'display_name' => 'Sync Engine' ),
			'core_fields'     => $args['core_fields'] ?? array(),
			'taxonomies'      => $args['taxonomies'] ?? array(),
			'meta_fields'     => self::sanitize_meta_for_export( $args['meta_fields'] ?? array() ),
			'plugin_specific' => $args['plugin_specific'] ?? array(),
		);

		// Optional delta declaration (doc 03 §2.2 merge strategy): the sender
		// lists the fields it actually changed since the last sync. The merge
		// arbiter takes ONLY these from the packet; other fields keep the
		// target's local values (T-3.4 field ownership). Absent = full packet.
		$changed_fields = $args['changed_fields'] ?? array();
		if ( is_array( $changed_fields ) && ! empty( $changed_fields ) ) {
			$entity['changed_fields'] = array_values( array_filter( array_map( 'strval', $changed_fields ) ) );
		}

		$manifest = $args['multimodal_manifest'] ?? array();

		// Calculate ADR-4 Content Fingerprint
		$content_hash       = hash( 'sha256', wp_json_encode( $entity['core_fields'] ) . wp_json_encode( $entity['meta_fields'] ) );
		$source_fingerprint = hash( 'sha256', $origin_uuid . '|' . $clock . '|' . $content_hash );

		return array(
			'schema_version'      => self::SCHEMA_VERSION,
			'packet_id'           => $packet_id,
			'origin_context'      => array(
				'origin_site_uuid'   => $origin_uuid,
				'origin_site_url'    => $site_url,
				'origin_permalink'   => (string) ( $args['origin_permalink'] ?? '' ),
				'origin_blog_id'     => function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1,
				'origin_lang'        => $args['origin_lang'] ?? 'en_US',
				'vector_clock'       => array( $origin_uuid => $clock ),
				'hop_count'          => (int) ( $args['hop_count'] ?? 1 ),
				'dispatch_timestamp' => time(),
			),
			'action'              => $args['action'] ?? 'upsert',
			'sync_mode'           => $args['sync_mode'] ?? 'sync_only',
			'target_lang'         => $args['target_lang'] ?? 'zh_CN',
			'source_fingerprint'  => $source_fingerprint,
			'entity'              => $entity,
			'multimodal_manifest' => $manifest,
		);
	}

	/**
	 * Parse and validate an incoming JSON packet strictly enforcing Zero-Unserialize.
	 *
	 * @param string $raw_json Raw JSON payload string.
	 * @return array|WP_Error
	 */
	public static function parse( string $raw_json ) {
		// 1. Size guard
		if ( strlen( $raw_json ) > self::MAX_PAYLOAD_BYTES ) {
			return new WP_Error(
				'wpmmcc_payload_too_large',
				__( 'Payload size exceeds 8MB limit.', 'wpmmcc' ),
				array( 'status' => 413 )
			);
		}

		// 2. Strict JSON decoding with recursion depth limit
		$data = json_decode( $raw_json, true, self::MAX_RECURSION_DEPTH, JSON_INVALID_UTF8_SUBSTITUTE );
		if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error(
				'wpmmcc_malformed_json',
				/* translators: %s: JSON error message */
				sprintf( __( 'Invalid JSON payload: %s', 'wpmmcc' ), json_last_error_msg() ),
				array( 'status' => 400 )
			);
		}

		// 3. Zero-unserialize safety scan (AFTER decoding): reject the packet
		// when any string VALUE is, in its entirety, a PHP serialized gadget
		// (O:8:"…":…, C:16:"…":…, a:2:{…}). The previous scan ran a regex over
		// the RAW JSON text and false-positived on legitimate content — a
		// technical post quoting `a:1:{…}`, the literal text `Area:51:`
		// followed by a JSON-escaped newline ("\n" in raw bytes), or
		// `data:1:"x"` — rejecting the WHOLE packet for one harmless post
		// (WMC-03). Whole-value anchoring keeps the gadget ban while letting
		// prose that merely CONTAINS those byte sequences through.
		if ( self::contains_serialized_gadget_value( $data ) ) {
			return new WP_Error(
				'wpmmcc_prohibited_serialized_payload',
				__( 'Security violation: raw PHP serialized gadgets are strictly prohibited.', 'wpmmcc' ),
				array( 'status' => 400 )
			);
		}

		// 4. Schema version compatibility check
		$schema = $data['schema_version'] ?? '';
		if ( self::SCHEMA_VERSION !== $schema ) {
			return new WP_Error(
				'wpmmcc_incompatible_schema',
				/* translators: %s: schema version */
				sprintf( __( 'Unsupported schema version: %s', 'wpmmcc' ), esc_html( $schema ) ),
				array( 'status' => 400 )
			);
		}

		// 5. Structure validation
		if ( empty( $data['packet_id'] ) || empty( $data['origin_context'] ) || empty( $data['entity'] ) ) {
			return new WP_Error(
				'wpmmcc_missing_packet_fields',
				__( 'Missing mandatory packet fields (packet_id, origin_context, entity).', 'wpmmcc' ),
				array( 'status' => 400 )
			);
		}
		$origin = $data['origin_context'];
		$entity = $data['entity'];
		if ( ! is_string( $data['packet_id'] ) || strlen( $data['packet_id'] ) > 64
			|| ! is_array( $origin ) || ! is_array( $entity )
			|| ! is_string( $origin['origin_site_uuid'] ?? null ) || '' === trim( $origin['origin_site_uuid'] )
			|| ! is_string( $entity['guid'] ?? null ) || '' === trim( $entity['guid'] )
			|| ! is_string( $data['source_fingerprint'] ?? null ) || '' === $data['source_fingerprint']
			|| ! in_array( $data['action'] ?? '', array( 'upsert', 'trash', 'delete' ), true )
			|| ! is_int( $entity['source_id'] ?? null ) || $entity['source_id'] < 0
			|| ! is_string( $entity['object_type'] ?? null )
			|| ! is_int( $origin['hop_count'] ?? null ) || $origin['hop_count'] < 1
			|| ! is_array( $origin['vector_clock'] ?? null )
			|| ! is_int( $origin['vector_clock'][ $origin['origin_site_uuid'] ] ?? null )
			|| $origin['vector_clock'][ $origin['origin_site_uuid'] ] < 1 ) {
			return new WP_Error( 'wpmmcc_invalid_packet_scope', 'Packet identity or clock is invalid.', array( 'status' => 400 ) );
		}
		if ( ! empty( $origin['origin_permalink'] ) && ( ! is_string( $origin['origin_permalink'] )
			|| ! self::url_belongs_to( $origin['origin_permalink'], (string) ( $origin['origin_site_url'] ?? '' ) ) ) ) {
			return new WP_Error( 'wpmmcc_invalid_origin_permalink', 'Origin permalink is outside the packet origin.', array( 'status' => 400 ) );
		}

		return $data;
	}

	/** Stable across object-member order, including independent client restarts. */
	public static function digest( array $packet ): string {
		$sort = static function ( $value ) use ( &$sort ) {
			if ( ! is_array( $value ) ) {
				return $value;
			}
			if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
				ksort( $value, SORT_STRING );
			}
			foreach ( $value as $key => $entry ) {
				$value[ $key ] = $sort( $entry );
			}
			return $value;
		};
		$encoded = wp_json_encode( $sort( $packet ) );
		if ( ! is_string( $encoded ) ) {
			throw new \RuntimeException( 'Packet digest could not be encoded.' );
		}
		return hash( 'sha256', $encoded );
	}

	/** A peer REST root scopes public permalinks to its WordPress installation. */
	public static function peer_site_url( string $endpoint ): string {
		return (string) preg_replace( '#/wp-json/wpmmcc/v1$#', '', rtrim( $endpoint, '/' ) );
	}

	public static function url_belongs_to( string $url, string $base ): bool {
		$actual = wp_parse_url( $url );
		$origin = wp_parse_url( $base );
		if ( ! is_array( $actual ) || ! is_array( $origin ) || isset( $actual['user'] )
			|| isset( $actual['pass'] ) || isset( $actual['fragment'] ) || isset( $origin['user'] ) || isset( $origin['pass'] ) ) {
			return false;
		}
		$scheme = strtolower( (string) ( $actual['scheme'] ?? '' ) );
		$base_scheme = strtolower( (string) ( $origin['scheme'] ?? '' ) );
		$port = (int) ( $actual['port'] ?? ( 'https' === $scheme ? 443 : 80 ) );
		$base_port = (int) ( $origin['port'] ?? ( 'https' === $base_scheme ? 443 : 80 ) );
		$base_path = rtrim( (string) ( $origin['path'] ?? '' ), '/' );
		$path = (string) ( $actual['path'] ?? '' );
		return in_array( $scheme, array( 'http', 'https' ), true ) && $scheme === $base_scheme
			&& ! empty( $actual['host'] ) && strtolower( $actual['host'] ) === strtolower( (string) ( $origin['host'] ?? '' ) )
			&& $port === $base_port && ( '' === $base_path || $path === $base_path || 0 === strpos( $path, $base_path . '/' ) );
	}

	/**
	 * Recursively detect a string VALUE whose entire content is a PHP
	 * serialized gadget (WMC-03). Array KEYS are intentionally not scanned:
	 * the maybe_unserialize() risk this gate guards against applies to
	 * values, and scanning keys/prose would reintroduce false positives.
	 *
	 * @param mixed $node Decoded JSON node.
	 * @return bool
	 */
	private static function contains_serialized_gadget_value( $node ): bool {
		if ( is_string( $node ) ) {
			return self::is_serialized_gadget_blob( $node );
		}
		if ( is_array( $node ) ) {
			foreach ( $node as $value ) {
				if ( self::contains_serialized_gadget_value( $value ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * True when the WHOLE (trimmed) string is a single serialized datum:
	 * `O:<len>:"<class>":<props>:{…}`, `C:<len>:"<class>":<len>:{…}`, or
	 * `a:<count>:{…}`. The same bytes embedded in longer prose are content,
	 * not a gadget (WMC-03).
	 *
	 * @param string $value String value.
	 * @return bool
	 */
	private static function is_serialized_gadget_blob( string $value ): bool {
		return (bool) preg_match(
			'/\A\s*(?:[OC]:\d+:"[^"]*":\d+:\{.*\}|a:\d+:\{.*\})\s*\z/s',
			$value
		);
	}

	/**
	 * Prepare meta fields for export on the source site.
	 * Decodes any locally serialized data into structured arrays with `_wpmmcc_kind: php_serialized`.
	 *
	 * @param array $raw_meta Meta entries from post.
	 * @return array
	 */
	public static function sanitize_meta_for_export( array $raw_meta ): array {
		$sanitized = array();

		foreach ( $raw_meta as $key => $value ) {
			// Skip internal WP transients and session keys
			if ( 0 === strpos( $key, '_transient_' ) || 0 === strpos( $key, '_edit_lock' ) ) {
				continue;
			}

			// In WP, get_post_meta($id, '', true) returns array of values
			$val = is_array( $value ) && 1 === count( $value ) && isset( $value[0] ) ? $value[0] : $value;

			if ( is_string( $val ) && is_serialized( $val ) ) {
				// Safely unserialize ONLY locally on the source site for export conversion.
				// §68 (nested serialized meta): storage may double-wrap a serialized
				// value (e.g. maybe_serialize of an already-serialized string); a
				// single unwrap then leaves the inner serialized text in `data`, the
				// receiving side's Zero-Unserialize packet guard
				// (wpmmcc_prohibited_serialized_payload) rejects the WHOLE packet for
				// that one meta, and the post never converges. Unwrap layer by layer
				// until a plain structure emerges; a value that cannot be fully
				// unwrapped is dropped with an explicit skip marker instead of
				// leaking raw serialized text onto the wire.
				$unserialized = maybe_unserialize( $val );
				$unwrap_depth = 0;
				while ( is_string( $unserialized ) && is_serialized( $unserialized ) && $unwrap_depth < self::MAX_RECURSION_DEPTH ) {
					$unserialized = maybe_unserialize( $unserialized );
					$unwrap_depth++;
				}
				if ( is_string( $unserialized ) && is_serialized( $unserialized ) ) {
					// Unresolvable nested serialization — never wire the raw text.
					$sanitized[ $key ] = array(
						'_wpmmcc_kind' => 'php_serialized',
						'data'         => array(),
						'skipped'      => 'nested_serialized_unresolvable',
					);
				} else {
					$sanitized[ $key ] = array(
						'_wpmmcc_kind' => 'php_serialized',
						'data'         => self::object_to_array( $unserialized ),
					);
				}
			} else {
				$sanitized[ $key ] = self::object_to_array( $val );
			}
		}

		return $sanitized;
	}

	/**
	 * Convert PHP objects to arrays recursively (safety conversion).
	 *
	 * @param mixed $item Item to convert.
	 * @param int   $depth Current depth.
	 * @return mixed
	 */
	private static function object_to_array( $item, int $depth = 0 ) {
		if ( $depth >= self::MAX_RECURSION_DEPTH ) {
			return null;
		}

		if ( is_object( $item ) ) {
			$item = (array) $item;
		}

		if ( is_array( $item ) ) {
			$result = array();
			foreach ( $item as $k => $v ) {
				$result[ $k ] = self::object_to_array( $v, $depth + 1 );
			}
			return $result;
		}

		return $item;
	}

	/**
	 * Export a WordPress post or custom post type into a complete Sync Packet.
	 *
	 * Gathers core fields, hierarchical taxonomies, sanitized post meta,
	 * content plugin payload (via Adapter_Registry), and multimodal assets.
	 *
	 * @param int   $post_id Local post ID.
	 * @param array $options Optional override options (action, target_lang, sync_mode).
	 * @return array|\WP_Error Complete Sync Packet array or WP_Error.
	 */
	public static function export_post( int $post_id, array $options = array() ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'wpmmcc_post_not_found', 'Post not found', array( 'status' => 404 ) );
		}

		// A conflict-review draft holds the PEER's version under the entity's
		// uuid. Shipping it would hand the peer its own content back as an edit
		// (review meta included) and overwrite its real post. Refused before
		// anything below persists a clock for it (WMC-11).
		if ( Shadow_Draft::is_shadow( $post_id ) ) {
			return new \WP_Error( 'wpmmcc_shadow_not_exportable', 'A conflict review draft is a local staging copy and is never exported', array( 'status' => 409 ) );
		}

		// 1. Resolve canonical UUID
		$canonical_uuid = Event_Recorder::resolve_or_create_canonical_uuid( 'post', $post_id );

		// 2. Extract core fields
		$core_fields = array(
			'post_title'     => $post->post_title,
			'post_content'   => $post->post_content,
			'post_excerpt'   => $post->post_excerpt,
			'post_date_gmt'  => $post->post_date_gmt ?? '',
			'comment_status' => $post->comment_status ?? 'open',
			'ping_status'    => $post->ping_status ?? 'open',
			'menu_order'     => (int) ( $post->menu_order ?? 0 ),
		);

		// 3. Extract hierarchical taxonomies
		$taxonomies_manifest = array();
		if ( function_exists( 'get_object_taxonomies' ) ) {
			$taxonomies = get_object_taxonomies( $post->post_type );
			if ( ! empty( $taxonomies ) && is_array( $taxonomies ) ) {
				foreach ( $taxonomies as $taxonomy ) {
					$terms = wp_get_object_terms( $post_id, $taxonomy );
					if ( ! empty( $terms ) && ! is_wp_error( $terms ) && is_array( $terms ) ) {
						$taxonomies_manifest[ $taxonomy ] = array();
						foreach ( $terms as $term ) {
							$parent_slug = '';
							if ( is_object( $term ) && ! empty( $term->parent ) && $term->parent > 0 ) {
								$parent_term = get_term( $term->parent, $taxonomy );
								if ( $parent_term && ! is_wp_error( $parent_term ) && is_object( $parent_term ) ) {
									$parent_slug = $parent_term->slug;
								}
							}
							$taxonomies_manifest[ $taxonomy ][] = array(
								'term_id'     => is_object( $term ) ? (int) $term->term_id : (int) ( $term['term_id'] ?? 0 ),
								'name'        => is_object( $term ) ? $term->name : ( $term['name'] ?? '' ),
								'slug'        => is_object( $term ) ? $term->slug : ( $term['slug'] ?? '' ),
								'parent_slug' => $parent_slug ?: ( is_array( $term ) ? ( $term['parent_slug'] ?? '' ) : '' ),
								'description' => is_object( $term ) ? ( $term->description ?? '' ) : ( $term['description'] ?? '' ),
							);
						}
					}
				}
			}
		}

		// 4. Extract post meta
		$raw_meta    = get_post_meta( $post_id );
		$meta_fields = self::sanitize_meta_for_export( is_array( $raw_meta ) ? $raw_meta : array() );

		// 5. Query content plugin adapter
		$plugin_specific = array();
		$adapter = \WPMMCC\Adapters\Adapter_Registry::get_for_post_type( $post->post_type );
		if ( $adapter ) {
			// Adapter_Interface::export_custom_payload() requires the post
			// object as its second argument (int $post_id, $post). Calling it
			// with only the ID is an ArgumentCountError fatal on PHP 8+.
			$plugin_specific = $adapter->export_custom_payload( $post_id, $post );
		}

		// 6. Multimodal manifest (featured image & media)
		$manifest = array();
		if ( function_exists( 'get_post_thumbnail_id' ) ) {
			$thumb_id = (int) get_post_thumbnail_id( $post_id );
			if ( $thumb_id > 0 ) {
				$thumb_url  = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $thumb_id ) : '';
				$thumb_file = function_exists( 'get_attached_file' ) ? get_attached_file( $thumb_id ) : '';
				$sha256     = '';
				$size       = 0;
				if ( $thumb_file && file_exists( $thumb_file ) ) {
					$sha256 = hash_file( 'sha256', $thumb_file );
					$size   = filesize( $thumb_file );
				}
				$manifest[] = array(
					'asset_ref'         => 'asset_thumb_' . $thumb_id,
					'type'              => 'image',
					'mime_type'         => function_exists( 'get_post_mime_type' ) ? ( get_post_mime_type( $thumb_id ) ?: 'image/jpeg' ) : 'image/jpeg',
					'original_filename' => basename( $thumb_url ?: 'image.jpg' ),
					'remote_url'        => $thumb_url ?: '',
					'sha256'            => $sha256,
					'size_bytes'        => $size,
					'metadata'          => array(
						'alt'   => get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) ?: '',
						'title' => function_exists( 'get_the_title' ) ? get_the_title( $thumb_id ) : '',
					),
				);
			}
		}

		// Reading a packet cannot invent a new source revision. The tracked
		// save/trash/delete hooks advance the clock; repeated pulls of the
		// same source must agree with its digest and frozen paid intent.
		$vector_clock = max( 1, self::stored_clock( $post_id ) );
		if ( 0 === self::stored_clock( $post_id ) ) {
			Event_Recorder::upsert_source_clock( 'post', $post_id, $canonical_uuid, $vector_clock );
		}

		return self::build(
			array(
				'guid'                => $canonical_uuid,
				'object_type'         => 'post',
				'subtype'             => $post->post_type,
				'source_id'           => $post->ID,
				'slug'                => $post->post_name,
				'status'              => $post->post_status,
				'vector_clock'        => $vector_clock,
				'core_fields'         => $core_fields,
				'taxonomies'          => $taxonomies_manifest,
				'meta_fields'         => $meta_fields,
				'plugin_specific'     => $plugin_specific,
				'multimodal_manifest' => $manifest,
				'action'              => $options['action'] ?? 'upsert',
				'origin_permalink'    => (string) get_permalink( $post_id ),
				'origin_lang'         => (string) ( $options['source_lang'] ?? get_locale() ),
				'sync_mode'           => $options['sync_mode'] ?? 'sync_only',
				'target_lang'         => $options['target_lang'] ?? 'zh_CN',
			)
		);
	}

	/**
	 * Build a content-free trash / delete packet (WMC-01).
	 *
	 * export_post() needs the post row, so an entity that was deleted after its
	 * CDC event was queued could never be exported: the delete event failed
	 * forever and sat at the head of the push queue. A tombstone needs only
	 * the canonical uuid (the receiver looks the entity up through its
	 * mapping), so it can be built for a post that no longer exists. It is
	 * stamped like every other packet (origin uuid, schema, packet id) and
	 * passes Packet::parse() on the receiving side.
	 *
	 * The vector clock is the stored one, not bumped: nothing is persisted,
	 * and the trash / delete arms of the receiver do not arbitrate on it.
	 *
	 * @param string        $canonical_uuid Entity uuid.
	 * @param int           $post_id        Local post id (may no longer exist).
	 * @param string        $action         'trash' | 'delete'.
	 * @param \WP_Post|null $post           The post when it still exists.
	 * @return array Sync packet.
	 */
	public static function export_tombstone( string $canonical_uuid, int $post_id, string $action, $post = null ): array {
		return self::build(
			array(
				'guid'         => $canonical_uuid,
				'object_type'  => 'post',
				'subtype'      => is_object( $post ) && ! empty( $post->post_type ) ? (string) $post->post_type : 'post',
				'source_id'    => $post_id,
				'slug'         => is_object( $post ) ? (string) ( $post->post_name ?? '' ) : '',
				'status'       => 'trash',
				'vector_clock' => max( 1, self::stored_clock( $post_id ) ),
				'core_fields'  => array(),
				'action'       => 'delete' === $action ? 'delete' : 'trash',
			)
		);
	}

	/**
	 * The vector clock persisted for a local post (0 when untracked).
	 *
	 * @param int $post_id Local post id.
	 * @return int
	 */
	public static function stored_clock( int $post_id ): int {
		global $wpdb;

		$clock_key = 'vclock_' . $post_id;
		$clock     = wp_cache_get( $clock_key, 'wpmmcc' );
		if ( false === $clock ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom mapping table query.
			$clock = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT MAX(vector_clock) FROM {$wpdb->prefix}wpmmcc_cross_mappings WHERE local_object_type = 'post' AND local_object_id = %d", $post_id )
			);
			wp_cache_set( $clock_key, $clock, 'wpmmcc', 300 );
		}
		return (int) $clock;
	}
}
