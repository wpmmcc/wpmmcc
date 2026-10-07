<?php
/**
 * WPMMCC Plugin Uninstall Routine.
 *
 * Fired when the plugin is uninstalled via WordPress admin.
 * Strictly removes only wpmmcc_* tables and options.
 * Zero pollution or interaction with wpmmcc-ats / wptsall_* data.
 *
 * @package WPMMCC
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// 1. Drop all wpmmcc_* database tables (exactly the five tables Schema creates)
$wpmmcc_tables = array(
	esc_sql( $wpdb->prefix . 'wpmmcc_peers' ),
	esc_sql( $wpdb->prefix . 'wpmmcc_cross_mappings' ),
	esc_sql( $wpdb->prefix . 'wpmmcc_journal' ),
	esc_sql( $wpdb->prefix . 'wpmmcc_asset_registry' ),
	esc_sql( $wpdb->prefix . 'wpmmcc_nonces' ),
);

foreach ( $wpmmcc_tables as $wpmmcc_table ) {
	// Guard: Ensure table name strictly matches expected prefix
	if ( 0 === strpos( $wpmmcc_table, $wpdb->prefix . 'wpmmcc_' ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpmmcc_table}`;" );
	}
}

// 2. Remove all wpmmcc_* options
$wpmmcc_options = array(
	'wpmmcc_version',
	'wpmmcc_db_version',
	'wpmmcc_origin_uuid',
	'wpmmcc_route_secret',
	'wpmmcc_client_token',
	'wpmmcc_settings',
	'wpmmcc_pairing_code',
);

foreach ( $wpmmcc_options as $wpmmcc_option ) {
	delete_option( $wpmmcc_option );
	delete_site_option( $wpmmcc_option );
}

// 2b. Per-peer sweep cursors (WMC-08): the option names embed a peer hash, so
// they cannot be listed statically.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic option lookup on uninstall.
$wpmmcc_cursor_options = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'wpmmcc_pull_cursor_' ) . '%',
		$wpdb->esc_like( 'wpmmcc_reconcile_cursor_' ) . '%'
	)
);
foreach ( (array) $wpmmcc_cursor_options as $wpmmcc_option ) {
	delete_option( $wpmmcc_option );
}

// 3. Clear transient locks and nonces
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transient cleanup on uninstall.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_wpmmcc_%',
		'_transient_timeout_wpmmcc_%'
	)
);
if ( is_multisite() ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transient cleanup on uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
			'_site_transient_wpmmcc_%',
			'_site_transient_timeout_wpmmcc_%'
		)
	);
}
