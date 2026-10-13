<?php
/**
 * Freeze source authority before delivery, then project its committed receipt.
 *
 * @package WPMMCC\REST
 */
namespace WPMMCC\REST;

use WPMMCC\Sync\Packet;
use WPMMCC\Sync\Reconciler;
use WPMMCC\Sync\Private_Document;
use WPMMCC\Sync\SQL_Lease;
use WPMMCC\Sync\SQL_Transaction;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rest_Confirm extends Rest_Base {
	public function register_routes(): void {
		foreach ( array( 'prepare', 'confirm' ) as $action ) {
			register_rest_route( $this->namespace, '/sync/' . $action, array(
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => array( $this, 'handle_' . $action ),
				'permission_callback' => array( Rest_Middleware::class, 'verify_hmac' ),
			) );
		}
	}

	public function handle_prepare( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) || ! is_array( $params['packet'] ?? null )
			|| ! is_string( $params['target_site_uuid'] ?? null ) || ! is_string( $params['target_site_url'] ?? null ) ) {
			return self::failure( 'scope_invalid', 400 );
		}
		$result = self::prepare( Rest_Middleware::requesting_peer_uuid( $request ),
			$params['packet'], $params['target_site_uuid'], $params['target_site_url'] );
		return is_wp_error( $result ) ? $result : $this->format_success( $result, 200 );
	}

	public function handle_confirm( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) || ! is_array( $params['packet'] ?? null ) || ! is_array( $params['receipt'] ?? null ) ) {
			return self::failure( 'scope_invalid', 400 );
		}
		$result = self::apply( Rest_Middleware::requesting_peer_uuid( $request ), $params['packet'], $params['receipt'] );
		return is_wp_error( $result ) ? $result : $this->format_success( $result, 200 );
	}

	private static function failure( string $code, int $status = 409 ) {
		return new WP_Error( 'wpmmcc_confirmation_' . $code, 'Original delivery authority or committed receipt could not be confirmed; evidence retained.', array( 'status' => $status ) );
	}

	private static function source_matches( array $packet ): bool {
		$origin = $packet['origin_context'];
		$id = $packet['entity']['source_id'];
		$post = get_post( $id );
		$clock = $origin['vector_clock'][ $origin['origin_site_uuid'] ];
		$url = function_exists( 'get_site_url' ) ? get_site_url() : site_url();
		return 'upsert' === $packet['action'] && 'post' === $packet['entity']['object_type'] && $post
			&& (string) get_option( 'wpmmcc_origin_uuid', '' ) === $origin['origin_site_uuid']
			&& rtrim( (string) $origin['origin_site_url'], '/' ) === rtrim( (string) $url, '/' )
			&& (int) $origin['origin_blog_id'] === ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1 )
			&& get_post_meta( $id, '_wpmmcc_canonical_uuid', true ) === $packet['entity']['guid']
			&& max( 1, Packet::stored_clock( $id ) ) === $clock
			&& (string) get_permalink( $id ) === (string) ( $origin['origin_permalink'] ?? '' )
			&& Reconciler::current_fingerprint_for_post( $post, $id, $clock ) === $packet['source_fingerprint'];
	}

	public static function prepare( string $sender, array $packet, string $target_uuid, string $target_url ) {
		global $wpdb;
		$packet = Packet::parse( (string) wp_json_encode( $packet ) );
		if ( is_wp_error( $packet ) || '' === $sender || '' === $target_uuid || strlen( $target_uuid ) > 64
			|| ! Packet::url_belongs_to( $target_url, $target_url )
			|| '' !== (string) ( wp_parse_url( $target_url, PHP_URL_QUERY ) ?? '' ) ) {
			return self::failure( 'scope_invalid', 400 );
		}
		$lease = SQL_Lease::acquire( 'source:' . $packet['entity']['guid'] );
		if ( is_wp_error( $lease ) ) {
			return $lease;
		}
		$key = hash( 'sha256', $sender . "\n" . $packet['packet_id'] );
		$scope = array( 'sender' => $sender, 'packet' => $packet, 'target_uuid' => $target_uuid, 'target_url' => $target_url );
		$digest = Packet::digest( $scope );
		$table = $wpdb->prefix . 'wpmmcc_source_deliveries';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- delivery row is claimed inside this request
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE delivery_key = %s', $table, $key ), ARRAY_A );
		try {
			$lease->assert_owner();
			if ( ! $row ) {
				if ( ! self::source_matches( $packet ) ) {
					return self::failure( 'source_changed' );
				}
				$encoded = Private_Document::encode( $scope, 'source:' . $key );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- delivery phase write must hit the table
				$changed = $wpdb->query( $wpdb->prepare(
					'INSERT IGNORE INTO %i (delivery_key, scope_digest, intent_json, phase, result_json, created_at) VALUES (%s, %s, %s, \'prepared\', \'\', %s)',
					$table, $key, $digest, $encoded, current_time( 'mysql' )
				) );
				if ( 1 !== $changed ) {
					return self::failure( 'not_saved', 503 );
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- read-back proves the delivery row just written
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE delivery_key = %s', $table, $key ), ARRAY_A );
			}
			if ( ! is_array( $row ) || $row['scope_digest'] !== $digest
				|| Packet::digest( Private_Document::decode( $row['intent_json'], 'source:' . $key ) ) !== $digest
				|| ! in_array( $row['phase'], array( 'prepared', 'confirmed' ), true ) ) {
				return self::failure( 'scope_changed' );
			}
			$lease->assert_owner();
			return array( 'prepared' => true, 'packet_id' => $packet['packet_id'], 'canonical_uuid' => $packet['entity']['guid'], 'target_site_uuid' => $target_uuid );
		} catch ( \Throwable $error ) {
			return self::failure( 'not_saved', 503 );
		}
	}

	public static function apply( string $sender, array $packet, array $receipt ) {
		global $wpdb;
		$packet = Packet::parse( (string) wp_json_encode( $packet ) );
		if ( is_wp_error( $packet ) || '' === $sender ) {
			return self::failure( 'scope_invalid', 400 );
		}
		$lease = SQL_Lease::acquire( 'source:' . $packet['entity']['guid'] );
		if ( is_wp_error( $lease ) ) {
			return $lease;
		}
		$key = hash( 'sha256', $sender . "\n" . $packet['packet_id'] );
		$table = $wpdb->prefix . 'wpmmcc_source_deliveries';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- delivery row is locked for this confirmation
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE delivery_key = %s', $table, $key ), ARRAY_A );
		$transaction = null;
		try {
			$lease->assert_owner();
			if ( ! is_array( $row ) ) {
				return self::failure( 'intent_missing' );
			}
			$scope = Private_Document::decode( $row['intent_json'], 'source:' . $key );
			if ( Packet::digest( $scope ) !== $row['scope_digest'] || $scope['sender'] !== $sender
				|| Packet::digest( $scope['packet'] ) !== Packet::digest( $packet )
				|| true !== ( $receipt['committed'] ?? false )
				|| ! in_array( $receipt['status'] ?? '', array( 'success', 'skipped' ), true )
				|| ( $receipt['packet_id'] ?? '' ) !== $packet['packet_id']
				|| ( $receipt['canonical_uuid'] ?? '' ) !== $packet['entity']['guid']
				|| ( $receipt['origin_site_uuid'] ?? '' ) !== $packet['origin_context']['origin_site_uuid']
				|| ( $receipt['fingerprint_ack'] ?? '' ) !== $packet['source_fingerprint']
				|| ( $receipt['target_lang'] ?? '' ) !== $packet['target_lang']
				|| ( $receipt['target_site_uuid'] ?? '' ) !== $scope['target_uuid']
				|| ! is_int( $receipt['target_id'] ?? null ) || $receipt['target_id'] <= 0
				|| ! Packet::url_belongs_to( (string) ( $receipt['target_url'] ?? '' ), Packet::peer_site_url( $scope['target_url'] ) ) ) {
				return self::failure( 'scope_changed' );
			}
			if ( 'confirmed' === $row['phase'] ) {
				$result = Private_Document::decode( $row['result_json'], 'source-result:' . $key );
				return Packet::digest( $result['receipt'] ) === Packet::digest( $receipt ) ? $result['confirmation'] : self::failure( 'receipt_changed' );
			}
			if ( 'prepared' !== $row['phase'] ) {
				return self::failure( 'not_saved', 503 );
			}
			$transaction = SQL_Transaction::begin( $lease, array(
				$table, $wpdb->prefix . 'wpmmcc_cross_mappings',
			) );
			$guid = $packet['entity']['guid'];
			$id = $packet['entity']['source_id'];
			$origin = $packet['origin_context'];
			$clock = $origin['vector_clock'][ $origin['origin_site_uuid'] ];
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- mapping row is locked before the conflict decision
			$prior = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wpmmcc_cross_mappings WHERE canonical_uuid = %s AND peer_uuid = %s FOR UPDATE", $guid, $scope['target_uuid']
			), ARRAY_A );
			$superseded = ! self::source_matches( $packet ) || ( is_array( $prior ) && (int) $prior['vector_clock'] > $clock );
			if ( ! $superseded ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table or connection command; WordPress has no replacement API
				$saved = $wpdb->replace( $wpdb->prefix . 'wpmmcc_cross_mappings', array(
					'canonical_uuid' => $guid, 'local_object_type' => 'post', 'local_object_id' => $id,
					'peer_uuid' => $scope['target_uuid'], 'remote_object_id' => $receipt['target_id'],
					'remote_permalink' => $receipt['target_url'], 'remote_lang' => $packet['target_lang'],
					'local_lang' => $origin['origin_lang'], 'transport_peer_uuid' => $sender,
					'content_checksum' => $packet['source_fingerprint'], 'vector_clock' => $clock,
					'last_sync_direction' => 'outbound', 'last_synced_at' => current_time( 'mysql' ),
				) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- read-back proves the mapping row just written
				$live = $wpdb->get_row( $wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}wpmmcc_cross_mappings WHERE canonical_uuid = %s AND peer_uuid = %s", $guid, $scope['target_uuid']
				), ARRAY_A );
				if ( false === $saved || ! is_array( $live ) || $live['remote_permalink'] !== $receipt['target_url']
					|| (int) $live['remote_object_id'] !== $receipt['target_id'] || $live['content_checksum'] !== $packet['source_fingerprint'] ) {
					throw new \RuntimeException( 'Reciprocal projection was not confirmed.' );
				}
			}
			$confirmation = array( 'confirmed' => true, 'superseded' => $superseded,
				'packet_id' => $packet['packet_id'], 'canonical_uuid' => $guid, 'target_site_uuid' => $scope['target_uuid'] );
			$encoded = Private_Document::encode( array( 'receipt' => $receipt, 'confirmation' => $confirmation ), 'source-result:' . $key );
			$transaction->assert_active();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table or connection command; WordPress has no replacement API
			$changed = $wpdb->query( $wpdb->prepare(
				'UPDATE %i SET phase = \'confirmed\', result_json = %s WHERE delivery_key = %s AND scope_digest = %s AND phase = \'prepared\' AND intent_json = %s',
				$table, $encoded, $key, $row['scope_digest'], $row['intent_json']
			) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- read-back proves the confirmation just saved
			$live_result = $wpdb->get_var( $wpdb->prepare( "SELECT result_json FROM %i WHERE delivery_key = %s AND phase = 'confirmed'", $table, $key ) );
			if ( 1 !== $changed || $encoded !== $live_result ) {
				throw new \RuntimeException( 'Source completion was not confirmed.' );
			}
			$transaction->commit();
			wp_cache_delete( 'cross_site_alt_' . $id, 'wpmmcc' );
			wp_cache_delete( 'vclock_' . $id, 'wpmmcc' );
			wp_cache_delete( 'mapping_' . md5( $guid . '_' . $scope['target_uuid'] ), 'wpmmcc' );
			wp_cache_delete( 'local_post_uuid_' . md5( $guid ), 'wpmmcc' );
			return $confirmation;
		} catch ( \Throwable $error ) {
			if ( $transaction ) {
				$transaction->rollback();
			}
			return self::failure( 'not_saved', 503 );
		}
	}
}
