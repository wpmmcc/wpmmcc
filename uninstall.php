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

// 1. Drop sync tables and the tables created by directory release 2.0.1.
//    Action Scheduler's shared actionscheduler_* tables are not included.
$wpmmcc_tables = array(
	$wpdb->prefix . 'wpmmcc_peers',
	$wpdb->prefix . 'wpmmcc_cross_mappings',
	$wpdb->prefix . 'wpmmcc_journal',
	$wpdb->prefix . 'wpmmcc_asset_registry',
	$wpdb->prefix . 'wpmmcc_nonces',
	$wpdb->prefix . 'wpmmcc_delivery_receipts',
	$wpdb->prefix . 'wpmmcc_source_deliveries',
	$wpdb->prefix . 'wpmmcc_outbound_deliveries',
	$wpdb->prefix . 'wpmmcc_media_deliveries',
	$wpdb->prefix . 'wpmmcc_task_logs',
	$wpdb->prefix . 'wpmmcc_task_items',
	$wpdb->prefix . 'wpmmcc_tasks',
	$wpdb->prefix . 'wpmmcc_translations',
	$wpdb->prefix . 'wpmmcc_theme_translations',
	$wpdb->prefix . 'wpmmcc_theme_original_entries',
	$wpdb->prefix . 'wpmmcc_theme_cache',
	$wpdb->prefix . 'wpmmcc_sites',
	$wpdb->prefix . 'wpmmcc_site_groups',
	$wpdb->prefix . 'wpmmcc_translation_rules',
	$wpdb->prefix . 'wpmmcc_llm_models',
	$wpdb->prefix . 'wpmmcc_settings',
	$wpdb->prefix . 'wpmmcc_actionscheduler_actions',
	$wpdb->prefix . 'wpmmcc_actionscheduler_claims',
	$wpdb->prefix . 'wpmmcc_actionscheduler_groups',
	$wpdb->prefix . 'wpmmcc_actionscheduler_logs',
);

if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
	wp_clear_scheduled_hook( 'wpmmcc_cron_reconcile_check' );
}

foreach ( $wpmmcc_tables as $wpmmcc_table ) {
	// Guard: Ensure table name strictly matches expected prefix
	if ( 0 === strpos( $wpmmcc_table, $wpdb->prefix . 'wpmmcc_' ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery -- uninstall drops only this plugin's wpmmcc_ tables
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpmmcc_table ) );
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
	'wpmmcc_ml_options',
	'wpmmcc_confirmed_action_scheduler',
	'wpmmcc_crypto_secret',
	'wpmmcc_ml_sitemap_rewrite_version',
	'wpmmcc_ml_sitemap_xml_fields',
	'wpmmcc_ml_deleted_target_behavior',
	'wpmmcc_keep_data_on_uninstall',
	'wpmmcc_logging_enabled',
	'wpmmcc_plugin_locale',
);

foreach ( $wpmmcc_options as $wpmmcc_option ) {
	delete_option( $wpmmcc_option );
	delete_site_option( $wpmmcc_option );
}

// 2b. Per-peer sweep cursors (WMC-08): the option names embed a peer hash, so
// they cannot be listed statically.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- uninstall must delete the cursor options that exist now
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
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- uninstall must delete the transients that exist now
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_wpmmcc_%',
		'_transient_timeout_wpmmcc_%'
	)
);
if ( is_multisite() ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- uninstall must delete the site transients that exist now
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
			'_site_transient_wpmmcc_%',
			'_site_transient_timeout_wpmmcc_%'
		)
	);
}
