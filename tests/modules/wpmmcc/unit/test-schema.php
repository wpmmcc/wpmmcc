<?php
/**
 * WPMMCC Schema Unit Test (WP-A-01).
 *
 * Verifies that all 9 tables strictly use `wpmmcc_` prefix,
 * and contain zero references or collisions with `wptsall_*`.
 *
 * @package WPMMCC\Tests\Unit
 */

require_once dirname( __DIR__ ) . '/bootstrap.php';

use WPMMCC\Database\Schema;

echo "=== Running Schema Unit Test (WP-A-01) ===\n";

global $wpdb;

// 1. Verify table names
$tables = Schema::get_all_tables();

$expected = array(
	'wp_wpmmcc_peers',
	'wp_wpmmcc_cross_mappings',
	'wp_wpmmcc_journal',
	'wp_wpmmcc_asset_registry',
	'wp_wpmmcc_nonces',
	'wp_wpmmcc_delivery_receipts',
	'wp_wpmmcc_source_deliveries',
	'wp_wpmmcc_outbound_deliveries',
	'wp_wpmmcc_media_deliveries',
);

if ( count( $tables ) !== count( $expected ) ) {
	echo "FAIL: Expected 9 tables, got " . count( $tables ) . "\n";
	exit( 1 );
}

foreach ( $expected as $exp_table ) {
	if ( ! in_array( $exp_table, $tables, true ) ) {
		echo "FAIL: Missing expected table: $exp_table\n";
		exit( 1 );
	}
}

// 2. Strict isolation: Ensure zero wptsall_ tables
foreach ( $tables as $table ) {
	if ( 0 === strpos( $table, 'wp_wptsall_' ) ) {
		echo "FAIL: Prohibited table prefix detected: $table\n";
		exit( 1 );
	}
	if ( 0 !== strpos( $table, 'wp_wpmmcc_' ) ) {
		echo "FAIL: Table does not have required prefix wp_wpmmcc_: $table\n";
		exit( 1 );
	}
}

// 3. Test installation routine
Schema::install_or_upgrade();
$installed_ver = get_option( Schema::DB_VERSION_OPTION );
if ( Schema::DB_VERSION !== $installed_ver ) {
	echo "FAIL: Expected DB version " . Schema::DB_VERSION . ", got $installed_ver\n";
	exit( 1 );
}

// 4. Test drop routine
Schema::drop_all_tables();
$after_drop_ver = get_option( Schema::DB_VERSION_OPTION );
if ( false !== $after_drop_ver ) {
	echo "FAIL: DB version option should be deleted after drop\n";
	exit( 1 );
}

echo "PASS: Schema unit tests passed successfully!\n";
exit( 0 );
