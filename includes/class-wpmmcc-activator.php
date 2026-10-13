<?php
/**
 * WPMMCC Plugin Activator.
 *
 * @package WPMMCC
 */

namespace WPMMCC;

use WPMMCC\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Activator
 *
 * Fired during plugin activation.
 */
class Activator {

	/**
	 * Run activation logic:
	 * - Install/upgrade database tables
	 * - Initialize local site UUID if missing
	 * - Record activation state
	 *
	 * @return void
	 */
	public static function activate(): void {
		require_once WPMMCC_PLUGIN_DIR . 'includes/database/class-wpmmcc-schema.php';

		// Ensure DB tables exist
		Schema::install_or_upgrade();

		// Ensure origin UUID is set
		if ( ! get_option( 'wpmmcc_origin_uuid' ) ) {
			$uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : self::generate_fallback_uuid();
			add_option( 'wpmmcc_origin_uuid', $uuid, '', 'no' );
		}

		// Ensure client route secret is initialized
		if ( ! get_option( 'wpmmcc_route_secret' ) ) {
			$secret = bin2hex( random_bytes( 16 ) );
			add_option( 'wpmmcc_route_secret', $secret, '', 'no' );
		}

		// Flush rewrite rules on next load if needed
		set_transient( 'wpmmcc_flush_rewrite_rules', 1, 60 );

		// Schedule periodic reconciliation cron if not already scheduled
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( 'wpmmcc_cron_reconcile_check' ) ) {
			if ( function_exists( 'wp_schedule_event' ) ) {
				wp_schedule_event( time(), 'hourly', 'wpmmcc_cron_reconcile_check' );
			}
		}

		if ( defined( 'WPMMCC_VERSION' ) ) {
			update_option( 'wpmmcc_version', WPMMCC_VERSION, false );
		}
	}

	/**
	 * Create or update tables after a file replacement.
	 *
	 * Directory release 2.0.1 is the previous multilingual plugin and stamps
	 * wpmmcc_db_version as 2.0.0. This release's schema stamp is 1.3.0, so a
	 * numeric "is the stored version older" check would skip the new tables.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}

		require_once WPMMCC_PLUGIN_DIR . 'includes/database/class-wpmmcc-schema.php';

		$code      = defined( 'WPMMCC_VERSION' ) ? WPMMCC_VERSION : '';
		$installed = (string) get_option( 'wpmmcc_version', '' );
		$db        = (string) get_option( Schema::DB_VERSION_OPTION, '' );
		if ( '' !== $code && $installed === $code && $db === Schema::DB_VERSION ) {
			return;
		}

		self::activate();
	}

	/**
	 * Generate fallback UUID v4 if wp_generate_uuid4 is not available.
	 *
	 * @return string
	 */
	private static function generate_fallback_uuid(): string {
		$data = random_bytes( 16 );
		$data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 ); // Set version to 0100
		$data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 ); // Set bits 6-7 to 10
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}
