<?php
/**
 * Frozen outbound packets and receipts shared by manual and scheduled sync.
 *
 * @package WPMMCC\Sync
 */
namespace WPMMCC\Sync;

use WPMMCC\REST\Rest_Confirm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Outbound_Delivery {
	public static function deliver( array $peer, array $event, callable $export ) {
		global $wpdb;
		$peer_uuid = (string) ( $peer['peer_uuid'] ?? '' );
		$guid = (string) ( $event['canonical_uuid'] ?? '' );
		if ( '' === $peer_uuid || '' === $guid ) {
			return new \WP_Error( 'wpmmcc_outbound_scope_invalid', 'Outbound authority is missing.' );
		}
		$lease = SQL_Lease::acquire( 'outbound:' . $peer_uuid . ':' . $guid );
		if ( is_wp_error( $lease ) ) {
			return $lease;
		}
		$queue_lease = SQL_Lease::acquire( 'queue:' . $guid );
		if ( is_wp_error( $queue_lease ) ) {
			return $queue_lease;
		}
		$table = $wpdb->prefix . 'wpmmcc_outbound_deliveries';
		$event_id = (int) ( $event['id'] ?? 0 );
		$key = hash( 'sha256', $peer_uuid . "\n" . $guid . "\n" . $event_id . "\n"
			. (string) ( $event['payload_digest'] ?? $event['source_fingerprint'] ?? '' ) );
		$secret = \WPMMCC\REST\Rest_Middleware::decrypt_shared_secret( (string) ( $peer['shared_secret'] ?? '' ), (string) ( $peer['key_salt'] ?? '' ) );
		$peer_scope = Packet::digest( array(
			'uuid' => $peer_uuid, 'url' => $peer['endpoint_url'] ?? '',
			'key' => hash( 'sha256', (string) $secret ),
			'direction' => $peer['direction'] ?? 'bidirectional',
			'source_lang' => $peer['source_lang'] ?? '', 'target_lang' => $peer['target_lang'] ?? '',
			'mode' => $peer['sync_mode'] ?? 'sync_only',
		) );
		// Finish an older unknown effect before a newer event may supersede it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Stable entity authority, independent of coalesced queue leaders.
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE peer_uuid = %s AND canonical_uuid = %s AND phase <> 'confirmed' ORDER BY created_at ASC LIMIT 1",
			$peer_uuid, $guid
		), ARRAY_A );
		if ( ! $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Confirmed deliveries remain for exact receipt replay.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE delivery_key = %s", $key ), ARRAY_A );
		}
		try {
			$lease->assert_owner();
			if ( ! $row ) {
				try {
					$packet = $export();
				} catch ( \Throwable $error ) {
					return new \WP_Error( 'wpmmcc_outbound_export_failed', 'Local entity export failed before delivery intent; no target write started.' );
				}
				if ( is_wp_error( $packet ) ) {
					return new \WP_Error( 'wpmmcc_outbound_export_failed', 'Local entity export failed before delivery intent; no target write started.' );
				}
				$packet = Packet::parse( (string) wp_json_encode( $packet ) );
				if ( is_wp_error( $packet ) ) {
					return new \WP_Error( 'wpmmcc_outbound_export_failed', 'Local entity export failed before delivery intent; no target write started.' );
				}
				$intent = array( 'packet' => $packet, 'event' => $event, 'peer_scope' => $peer_scope );
				$encoded = Private_Document::encode( $intent, 'outbound:' . $key );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Create-only intent before any target request.
				$changed = $wpdb->query( $wpdb->prepare(
					"INSERT IGNORE INTO {$table} (delivery_key, peer_uuid, canonical_uuid, event_id, intent_json, phase, result_json, created_at) VALUES (%s, %s, %s, %d, %s, 'prepared', '', %s)",
					$key, $peer_uuid, $guid, $event_id, $encoded, current_time( 'mysql' )
				) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact private intent readback.
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE delivery_key = %s", $key ), ARRAY_A );
				if ( 1 !== $changed || ! is_array( $row ) || $row['intent_json'] !== $encoded ) {
					throw new \RuntimeException( 'Outbound intent was not confirmed.' );
				}
			}
			$key = $row['delivery_key'];
			$intent = Private_Document::decode( $row['intent_json'], 'outbound:' . $key );
			if ( ( $intent['peer_scope'] ?? '' ) !== $peer_scope || ( $intent['packet']['entity']['guid'] ?? '' ) !== $guid
				|| ! is_array( $intent['event'] ?? null ) || '' === (string) $secret ) {
				throw new \RuntimeException( 'Frozen paired authority differs.' );
			}
			$packet = $intent['packet'];
			if ( 'confirmed' === $row['phase'] || 'target_acked' === $row['phase'] ) {
				$receipt = Private_Document::decode( $row['result_json'], 'outbound-result:' . $key );
			} else {
				if ( 'prepared' === $row['phase'] ) {
					if ( 'upsert' === $packet['action'] ) {
						$prepared = Rest_Confirm::prepare( $packet['origin_context']['origin_site_uuid'], $packet, $peer_uuid, $peer['endpoint_url'] );
						if ( is_wp_error( $prepared ) ) {
							return $prepared;
						}
					}
					$row = self::transition( $row, 'submitted', '' );
					$route = '/sync/push';
				} elseif ( 'submitted' === $row['phase'] ) {
					$route = '/sync/receipt';
				} else {
					throw new \RuntimeException( 'Unknown outbound phase.' );
				}
				$lease->assert_owner();
				$response = Manual_Sync::remote_call( $peer, $route, $packet );
				if ( is_wp_error( $response ) ) {
					return $response;
				}
				$zero = $response['json']['data'] ?? array();
				if ( '/sync/receipt' === $route && 200 === (int) $response['status']
					&& false === ( $zero['committed'] ?? null ) && 'absent' === ( $zero['phase'] ?? '' )
					&& ( $zero['packet_id'] ?? '' ) === $packet['packet_id']
					&& ( $zero['canonical_uuid'] ?? '' ) === $guid
					&& ( $zero['origin_site_uuid'] ?? '' ) === $packet['origin_context']['origin_site_uuid']
					&& ( $zero['fingerprint_ack'] ?? '' ) === $packet['source_fingerprint']
					&& ( $zero['target_lang'] ?? '' ) === $packet['target_lang']
					&& ( $zero['target_site_uuid'] ?? '' ) === $peer_uuid ) {
					// Proven zero effect permits only the SAME frozen packet.
					// The persisted submitted marker already precedes this send.
					$lease->assert_owner();
					$response = Manual_Sync::remote_call( $peer, '/sync/push', $packet );
					if ( is_wp_error( $response ) ) {
						return $response;
					}
				}
				if ( 'ok' !== Manual_Sync::classify_push_response( (int) $response['status'], $response['json'], $packet, $peer ) ) {
					$response['packet'] = $packet;
					return $response;
				}
				$receipt = $response['json']['data'];
				$row = self::transition( $row, 'target_acked', Private_Document::encode( $receipt, 'outbound-result:' . $key ) );
			}
			if ( 'confirmed' !== $row['phase'] ) {
				$lease->assert_owner();
				if ( 'upsert' === $packet['action'] ) {
					$confirmed = Rest_Confirm::apply( $packet['origin_context']['origin_site_uuid'], $packet, $receipt );
					if ( is_wp_error( $confirmed ) ) {
						return $confirmed;
					}
				}
				self::transition( $row, 'confirmed', $row['result_json'] );
			}
			$lease->assert_owner();
			return array( 'status' => 200, 'json' => array( 'success' => true, 'data' => $receipt ),
				'ack_event' => $intent['event'], 'packet' => $packet );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'wpmmcc_outbound_unconfirmed', 'Original outbound packet, paired authority or receipt could not be confirmed; no new packet was submitted.', array( 'status' => 409 ) );
		}
	}

	private static function transition( array $row, string $phase, string $result ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'wpmmcc_outbound_deliveries';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact phase/private-bytes CAS.
		$changed = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET phase = %s, result_json = %s WHERE delivery_key = %s AND phase = %s AND intent_json = %s AND result_json = %s",
			$phase, $result, $row['delivery_key'], $row['phase'], $row['intent_json'], $row['result_json']
		) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact readback before another side effect or closure.
		$live = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE delivery_key = %s", $row['delivery_key'] ), ARRAY_A );
		if ( 1 !== $changed || ! is_array( $live ) || $live['phase'] !== $phase || $live['result_json'] !== $result || $live['intent_json'] !== $row['intent_json'] ) {
			throw new \RuntimeException( 'Outbound transition was not confirmed.' );
		}
		return $live;
	}
}
