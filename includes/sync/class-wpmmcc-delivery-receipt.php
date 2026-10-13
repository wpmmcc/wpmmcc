<?php
/**
 * Durable delivery authority. Unknown target effects are never age-retried.
 *
 * @package WPMMCC\Sync
 */

namespace WPMMCC\Sync;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Delivery_Receipt {
	public static function find( string $sender, array $packet ) {
		global $wpdb;
		$key = hash( 'sha256', $sender . "\n" . $packet['packet_id'] );
		$digest = Packet::digest( $packet );
		$table = $wpdb->prefix . 'wpmmcc_delivery_receipts';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- receipt is loaded to continue this delivery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE receipt_key = %s", $table, $key ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'wpmmcc_receipt_read_failed', 'Original target authority is unavailable.', array( 'status' => 503 ) );
		}
		if ( ! is_array( $row ) || ! hash_equals( $digest, (string) $row['packet_digest'] ) ) {
			return new WP_Error( 'wpmmcc_receipt_missing_or_changed', 'No exact original receipt; no target write was started by this query.', array( 'status' => 409 ) );
		}
		if ( 'applied' !== $row['phase'] ) {
			return new WP_Error( 'wpmmcc_delivery_unknown', 'Original target effect requires reconciliation; no reapply.', array( 'status' => 409 ) );
		}
		try {
			$result = Private_Document::decode( (string) $row['result_json'], 'receipt:' . $key );
		} catch ( \Throwable $error ) {
			return new WP_Error( 'wpmmcc_receipt_invalid', 'Original receipt is damaged; retained.', array( 'status' => 409 ) );
		}
		if ( true !== ( $result['committed'] ?? false ) || ( $result['packet_id'] ?? '' ) !== $packet['packet_id']
			|| ( $result['fingerprint_ack'] ?? '' ) !== $packet['source_fingerprint']
			|| ( $result['canonical_uuid'] ?? '' ) !== $packet['entity']['guid']
			|| ( $result['origin_site_uuid'] ?? '' ) !== $packet['origin_context']['origin_site_uuid'] ) {
			return new WP_Error( 'wpmmcc_receipt_invalid', 'Original receipt scope differs; retained.', array( 'status' => 409 ) );
		}
		return $result;
	}

	/** Absence is proven under the same execution lease that creates intent. */
	public static function status( string $sender, array $packet ) {
		global $wpdb;
		if ( '' === $sender ) {
			return new WP_Error( 'wpmmcc_receipt_scope_missing', 'Original sender is required.', array( 'status' => 403 ) );
		}
		$lease = SQL_Lease::acquire( 'inbound:' . $packet['entity']['guid'] );
		if ( is_wp_error( $lease ) ) {
			return $lease;
		}
		$key = hash( 'sha256', $sender . "\n" . $packet['packet_id'] );
		$table = $wpdb->prefix . 'wpmmcc_delivery_receipts';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- receipt is loaded to continue this delivery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE receipt_key = %s", $table, $key ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return new WP_Error( 'wpmmcc_receipt_read_failed', 'Original target authority is unavailable.', array( 'status' => 503 ) );
		}
		$lease->assert_owner();
		if ( null === $row ) {
			return array( 'committed' => false, 'phase' => 'absent',
				'packet_id' => $packet['packet_id'], 'canonical_uuid' => $packet['entity']['guid'],
				'origin_site_uuid' => $packet['origin_context']['origin_site_uuid'],
				'target_site_uuid' => (string) get_option( 'wpmmcc_origin_uuid', '' ),
				'target_lang' => $packet['target_lang'], 'fingerprint_ack' => $packet['source_fingerprint'],
			);
		}
		return self::find( $sender, $packet );
	}

	public static function begin( string $sender, array $packet ) {
		global $wpdb;
		$key    = hash( 'sha256', $sender . "\n" . $packet['packet_id'] );
		$digest = Packet::digest( $packet );
		$table  = $wpdb->prefix . 'wpmmcc_delivery_receipts';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- receipt insert must hit the table
		$inserted = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO %i (receipt_key, packet_digest, phase, result_json, created_at) VALUES (%s, %s, 'applying', '', %s)",
			$table, $key, $digest, current_time( 'mysql' )
		) );
		if ( false === $inserted ) {
			return new WP_Error( 'wpmmcc_receipt_not_saved', 'Delivery intent was not saved; no target write started.', array( 'status' => 503 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- read-back proves the receipt just written
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE receipt_key = %s", $table, $key ), ARRAY_A );
		if ( ! is_array( $row ) || ! hash_equals( $digest, (string) $row['packet_digest'] ) ) {
			return new WP_Error( 'wpmmcc_receipt_scope_changed', 'Original packet identity is retained.', array( 'status' => 409 ) );
		}
		if ( 1 !== $inserted ) {
			if ( 'applied' === $row['phase'] ) {
				$result = self::find( $sender, $packet );
				return is_wp_error( $result ) ? $result : array( 'replay' => $result );
			}
			return new WP_Error( 'wpmmcc_delivery_unknown', 'Original target effect requires reconciliation; packet retained.', array( 'status' => 409 ) );
		}
		return array( 'key' => $key, 'digest' => $digest );
	}

	public static function finish( array $authority, array $result ): bool {
		global $wpdb;
		$table   = $wpdb->prefix . 'wpmmcc_delivery_receipts';
		$encoded = Private_Document::encode( $result, 'receipt:' . $authority['key'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- receipt phase write must hit the table
		$changed = $wpdb->query( $wpdb->prepare(
			"UPDATE %i SET phase = 'applied', result_json = %s WHERE receipt_key = %s AND packet_digest = %s AND phase = 'applying'",
			$table, $encoded, $authority['key'], $authority['digest']
		) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- read-back proves the receipt phase just written
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT phase, packet_digest, result_json FROM %i WHERE receipt_key = %s", $table, $authority['key'] ), ARRAY_A );
		return 1 === $changed && is_array( $row ) && 'applied' === $row['phase']
			&& $row['packet_digest'] === $authority['digest'] && $row['result_json'] === $encoded;
	}
}
