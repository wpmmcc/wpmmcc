<?php
/**
 * WPMMCC Database Schema and Installer.
 *
 * @package WPMMCC\Database
 */

namespace WPMMCC\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Schema
 *
 * Handles database table installation, upgrading, and uninstallation
 * strictly using the `wpmmcc_` table prefix.
 */
class Schema {

	/**
	 * Database schema version.
	 */
	const DB_VERSION = '1.3.0';

	/**
	 * Database version option key.
	 */
	const DB_VERSION_OPTION = 'wpmmcc_db_version';

	/**
	 * Get table name with WordPress prefix.
	 *
	 * @param string $base_name Table name without prefix (e.g. 'peers').
	 * @return string
	 */
	public static function get_table_name( string $base_name ): string {
		global $wpdb;
		return $wpdb->prefix . 'wpmmcc_' . $base_name;
	}

	/**
	 * Get list of all tables managed by this plugin.
	 *
	 * @return string[]
	 */
	public static function get_all_tables(): array {
		global $wpdb;
		$prefix = $wpdb->prefix . 'wpmmcc_';
		return array(
			$prefix . 'peers',
			$prefix . 'cross_mappings',
			$prefix . 'journal',
			$prefix . 'asset_registry',
			$prefix . 'nonces',
			$prefix . 'delivery_receipts',
			$prefix . 'source_deliveries',
			$prefix . 'outbound_deliveries',
			$prefix . 'media_deliveries',
		);
	}

	/**
	 * Create or update database tables.
	 *
	 * @return void
	 */
	public static function install_or_upgrade(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix . 'wpmmcc_';

		if ( file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		// 1. Peer topology table (doc 07 §1.1 + doc 14 §4)
		$sql_peers = "CREATE TABLE {$prefix}peers (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			peer_uuid varchar(64) NOT NULL,
			peer_name varchar(128) NOT NULL DEFAULT '',
			peer_type varchar(20) NOT NULL DEFAULT 'standalone',
			endpoint_url varchar(255) NOT NULL,
			shared_secret text NOT NULL,
			key_salt char(32) NOT NULL DEFAULT '',
			direction varchar(20) NOT NULL DEFAULT 'bidirectional',
			sync_mode varchar(20) NOT NULL DEFAULT 'sync_only',
			source_lang varchar(16) NOT NULL DEFAULT 'en_US',
			target_lang varchar(16) NOT NULL DEFAULT 'zh_CN',
			conflict_strategy varchar(20) NOT NULL DEFAULT 'lww',
			status varchar(20) NOT NULL DEFAULT 'active',
			install_signature char(64) NOT NULL DEFAULT '',
			last_ping_at datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_peer_uuid (peer_uuid),
			KEY idx_status (status)
		) $charset_collate;";

		// 2. Cross mappings table (doc 07 §1.2 + ADR-4)
		$sql_cross_mappings = "CREATE TABLE {$prefix}cross_mappings (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			canonical_uuid varchar(64) NOT NULL,
			local_object_type varchar(32) NOT NULL DEFAULT 'post',
			local_object_id bigint(20) unsigned NOT NULL,
			peer_uuid varchar(64) NOT NULL,
			remote_object_id bigint(20) unsigned NOT NULL,
			remote_permalink text DEFAULT NULL,
			remote_lang varchar(16) NOT NULL DEFAULT '',
			local_lang varchar(16) NOT NULL DEFAULT '',
			transport_peer_uuid varchar(64) NOT NULL DEFAULT '',
			content_checksum char(64) NOT NULL,
			vector_clock int(11) unsigned NOT NULL DEFAULT 1,
			last_sync_direction varchar(10) NOT NULL DEFAULT 'outbound',
			last_synced_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_canonical_peer (canonical_uuid, peer_uuid),
			KEY idx_local_entity (local_object_type, local_object_id),
			KEY idx_peer_remote (peer_uuid, remote_object_id)
		) $charset_collate;";

		// 3. Journal table (doc 14 §1 - dual-role CDC queue + audit log)
		$sql_journal = "CREATE TABLE {$prefix}journal (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			occurred_at datetime NOT NULL,
			event_type varchar(32) NOT NULL,
			direction varchar(12) NOT NULL,
			canonical_uuid varchar(64) NOT NULL DEFAULT '',
			object_type varchar(32) NOT NULL DEFAULT 'post',
			local_object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			peer_uuid varchar(64) NOT NULL DEFAULT '',
			remote_object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			packet_id varchar(64) NOT NULL DEFAULT '',
			source_fingerprint char(64) NOT NULL DEFAULT '',
			outcome varchar(16) NOT NULL,
			duration_ms int(10) unsigned NOT NULL DEFAULT 0,
			payload_digest char(64) NOT NULL DEFAULT '',
			diff_summary text NULL,
			message text NULL,
			consumed tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY idx_consumed_id (consumed, id),
			KEY idx_peer_outcome (peer_uuid, outcome),
			KEY idx_canonical (canonical_uuid(16)),
			KEY idx_occurred (occurred_at)
		) $charset_collate;";

		// 4. Asset registry CAS table (doc 14 §3)
		$sql_asset_registry = "CREATE TABLE {$prefix}asset_registry (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			sha256 char(64) NOT NULL,
			size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
			attachment_id bigint(20) unsigned NOT NULL,
			origin_peer_uuid varchar(64) NOT NULL DEFAULT '',
			first_seen_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_sha256 (sha256)
		) $charset_collate;";

		// 5. Nonces table for anti-replay (doc 14 §4.4)
		$sql_nonces = "CREATE TABLE {$prefix}nonces (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			nonce char(64) NOT NULL,
			peer_uuid varchar(64) NOT NULL DEFAULT '',
			expires_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idx_nonce (nonce),
			KEY idx_expires (expires_at)
		) $charset_collate;";

		$sql_delivery_receipts = "CREATE TABLE {$prefix}delivery_receipts (
			receipt_key char(64) NOT NULL,
			packet_digest char(64) NOT NULL,
			phase varchar(16) NOT NULL,
			result_json longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY (receipt_key)
		) $charset_collate;";
		$sql_source_deliveries = "CREATE TABLE {$prefix}source_deliveries (
			delivery_key char(64) NOT NULL,
			scope_digest char(64) NOT NULL,
			intent_json longtext NOT NULL,
			phase varchar(16) NOT NULL,
			result_json longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY (delivery_key)
		) $charset_collate;";
		$sql_outbound_deliveries = "CREATE TABLE {$prefix}outbound_deliveries (
			delivery_key char(64) NOT NULL,
			peer_uuid varchar(64) NOT NULL,
			canonical_uuid varchar(64) NOT NULL,
			event_id bigint(20) unsigned NOT NULL DEFAULT 0,
			intent_json longtext NOT NULL,
			phase varchar(16) NOT NULL,
			result_json longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY (delivery_key),
			KEY idx_peer_entity (peer_uuid, canonical_uuid),
			KEY idx_entity_event (canonical_uuid, event_id),
			KEY idx_event (event_id)
		) $charset_collate;";
		$sql_media_deliveries = "CREATE TABLE {$prefix}media_deliveries (
			delivery_key char(64) NOT NULL,
			state_json longtext NOT NULL,
			reserved_bytes bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY (delivery_key)
		) $charset_collate;";

		dbDelta( $sql_peers );
		dbDelta( $sql_cross_mappings );
		dbDelta( $sql_journal );
		dbDelta( $sql_asset_registry );
		dbDelta( $sql_nonces );
		dbDelta( $sql_delivery_receipts );
		dbDelta( $sql_source_deliveries );
		dbDelta( $sql_outbound_deliveries );
		dbDelta( $sql_media_deliveries );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Drop all tables on uninstallation.
	 * Strictly drops only wpmmcc_* tables. Never touches any other tables.
	 *
	 * @return void
	 */
	public static function drop_all_tables(): void {
		global $wpdb;

		$tables = self::get_all_tables();
		foreach ( $tables as $table ) {
			// Safety assertion: Table name MUST start with wpdb->prefix . 'wpmmcc_'
			$expected_prefix = $wpdb->prefix . 'wpmmcc_';
			if ( 0 !== strpos( $table, $expected_prefix ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery -- schema removal drops only wpmmcc_ tables
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}

		delete_option( self::DB_VERSION_OPTION );
	}
}
