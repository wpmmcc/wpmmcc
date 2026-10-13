<?php
/**
 * Receiver-owned metadata and delta ownership, shared by every import channel.
 *
 * @package WPMMCC\Sync
 */

namespace WPMMCC\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Write_Policy {

	private static $receiver_uuid_writes = array();

	public static function is_local_meta( string $key ): bool {
		return '' === $key || (bool) preg_match( '/^_(?:wpmmcc_|wptsall_|edit_|oembed_|transient_)/i', $key )
			|| (bool) preg_match( '/[\x00-\x1f]/', $key );
	}

	/**
	 * The context is receiver-generated, never copied from plugin_specific.
	 */
	public static function allows_meta( string $key, array $context = array() ): bool {
		return ! self::is_local_meta( $key ) && self::allows_field( $key, $context );
	}

	public static function allows_field( string $key, array $context = array() ): bool {
		if ( in_array( $key, (array) ( $context['protected_fields'] ?? array() ), true ) ) {
			return false;
		}
		return empty( $context['delta_merge'] )
			|| in_array( $key, (array) ( $context['changed_fields'] ?? array() ), true );
	}

	public static function child_context( string $group, array $context ): ?array {
		if ( ! self::allows_field( $group, $context ) ) {
			return null;
		}
		$context['delta_merge'] = false;
		return $context;
	}

	/**
	 * Receiver code alone generates identity, including inside a child import.
	 * This narrow authority is not an escape hatch for incoming meta values.
	 */
	public static function store_receiver_uuid( int $id, string $uuid ): bool {
		if ( ! preg_match( '/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $uuid ) ) {
			return false;
		}
		$existing = (string) get_post_meta( $id, '_wpmmcc_canonical_uuid', true );
		if ( '' !== $existing ) {
			return $existing === $uuid;
		}
		$previous                        = self::$receiver_uuid_writes[ $id ] ?? null;
		self::$receiver_uuid_writes[ $id ] = $uuid;
		try {
			$stored = (bool) update_post_meta( $id, '_wpmmcc_canonical_uuid', $uuid );
			if ( $stored ) {
				wp_cache_delete( 'postmeta_uuid_' . md5( $uuid ), 'wpmmcc' );
			}
			return $stored;
		} finally {
			if ( null === $previous ) {
				unset( self::$receiver_uuid_writes[ $id ] );
			} else {
				self::$receiver_uuid_writes[ $id ] = $previous;
			}
		}
	}

	/**
	 * Metadata writes belong to the imported post or its native descendants.
	 * Child-field ownership remains a separate adapter-specific contract.
	 */
	private static function owns_post( int $root_id, int $object_id ): bool {
		$seen = array();
		for ( $depth = 0; $object_id > 0 && $depth < 64; ++$depth ) {
			if ( $root_id > 0 && $root_id === $object_id ) {
				return true;
			}
			if ( isset( $seen[ $object_id ] ) || ! function_exists( 'get_post' ) ) {
				return false;
			}
			$seen[ $object_id ] = true;
			$post             = get_post( $object_id );
			$object_id        = $post ? (int) $post->post_parent : 0;
		}
		return false;
	}

	private static function object_context( int $root_id, int $object_id, array $context ): ?array {
		if ( ! self::owns_post( $root_id, $object_id ) ) {
			return null;
		}
		if ( $root_id === $object_id ) {
			return $context;
		}
		$post  = get_post( $object_id );
		$group = $post ? ( $context['_receiver_child_groups'][ $post->post_type ] ?? null ) : null;
		return is_string( $group ) ? self::child_context( $group, $context ) : null;
	}

	/**
	 * Third-party adapters using the WP metadata APIs obey the same policy.
	 * This is not a sandbox for hostile local PHP or direct SQL.
	 *
	 * @return bool
	 */
	public static function import( \WPMMCC\Adapters\Adapter_Interface $adapter, int $id, array $payload, array $context ): bool {
		$identity_failed = false;
		$child_groups = array(
			'woocommerce' => array( 'product_variation' => 'variations' ),
			'lms'         => array_fill_keys( array( 'topics', 'lesson', 'tutor_quiz', 'tutor_announcements', 'lp_lesson', 'lp_quiz', 'llms_quiz' ), 'topics' ),
			'bbpress'     => array( 'reply' => 'replies' ),
		);
		// Extensions declare their native child group in local receiver code,
		// never in plugin_specific or a packet-supplied scope.
		$context['_receiver_child_groups'] = (array) apply_filters(
			'wpmmcc_adapter_child_groups',
			$child_groups[ $adapter->get_slug() ] ?? array(),
			$adapter
		);
		$guard = static function ( $check, $object_id, $key, $value = null ) use ( $context, $id ) {
			$object_context = self::object_context( $id, (int) $object_id, $context );
			if ( null === $object_context ) {
				return false;
			}
			if ( '_wpmmcc_canonical_uuid' === $key
				&& isset( self::$receiver_uuid_writes[ (int) $object_id ] )
				&& self::$receiver_uuid_writes[ (int) $object_id ] === $value ) {
				return $check;
			}
			return self::allows_meta( (string) $key, $object_context ) ? $check : false;
		};
		$delete_guard = static function ( $check, $object_id, $key ) use ( $guard ) {
			return self::is_local_meta( (string) $key ) ? false : $guard( $check, $object_id, $key );
		};
		$empty_guard = static function ( $empty, $postarr ) use ( $context, $id ) {
			$target = (int) ( $postarr['ID'] ?? 0 );
			if ( $target > 0 ) {
				return null === self::object_context( $id, $target, $context ) ? true : $empty;
			}
			$group = $context['_receiver_child_groups'][ $postarr['post_type'] ?? 'post' ] ?? null;
			return ! is_string( $group )
				|| ! self::owns_post( $id, (int) ( $postarr['post_parent'] ?? 0 ) )
				|| null === self::child_context( $group, $context ) ? true : $empty;
		};
		$core_guard = static function ( $data, $postarr ) use ( $context, $id ) {
			$target = (int) ( $postarr['ID'] ?? 0 );
			if ( $target > 0 ) {
				$object_context = self::object_context( $id, $target, $context );
				$post           = get_post( $target );
				if ( $post && null !== $object_context ) {
					foreach ( $data as $field => $value ) {
						if ( in_array( $field, array( 'ID', 'post_type', 'post_parent', 'post_author', 'post_status', 'guid' ), true )
							|| ! self::allows_field( (string) $field, $object_context ) ) {
							if ( property_exists( $post, $field ) ) {
								$data[ $field ] = wp_slash( $post->$field );
							}
						}
					}
				}
			}
			return $data;
		};
		$term_guard = static function ( $object_id, $terms, $taxonomy ) use ( $context, $id ) {
			$object_context = self::object_context( $id, (int) $object_id, $context );
			if ( null === $object_context || ! self::allows_field( (string) $taxonomy, $object_context ) ) {
				throw new Adapter_Write_Denied( 'Adapter taxonomy write is outside receiver ownership.' );
			}
		};
		$identity_guard = static function ( $post_id ) use ( $context, $id, &$identity_failed ) {
			if ( null !== self::object_context( $id, (int) $post_id, $context )
				&& '' === Event_Recorder::resolve_or_create_canonical_uuid( 'post', (int) $post_id ) ) {
				$identity_failed = true;
			}
		};
		$hooks = array(
			array( 'add_post_metadata', $guard, 4 ),
			array( 'update_post_metadata', $guard, 4 ),
			array( 'delete_post_metadata', $delete_guard, 3 ),
			array( 'wp_insert_post_empty_content', $empty_guard, 2 ),
			array( 'wp_insert_post_data', $core_guard, 2 ),
			array( 'wp_insert_attachment_data', $core_guard, 2 ),
			array( 'add_term_relationship', $term_guard, 3 ),
			array( 'delete_term_relationships', $term_guard, 3 ),
			array( 'wp_insert_post', $identity_guard, 1 ),
		);
		foreach ( $hooks as $hook ) {
			add_filter( $hook[0], $hook[1], PHP_INT_MAX, $hook[2] );
		}
		try {
			$imported = $adapter->import_custom_payload( $id, $payload, $context );
			return $imported && ! $identity_failed;
		} catch ( Adapter_Write_Denied $error ) {
			return false;
		} finally {
			foreach ( $hooks as $hook ) {
				remove_filter( $hook[0], $hook[1], PHP_INT_MAX );
			}
		}
	}
}

class Adapter_Write_Denied extends \RuntimeException {}
