<?php
/**
 * WPMMCC Plugin Deactivator.
 *
 * @package WPMMCC
 */

namespace WPMMCC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Deactivator
 *
 * Fired during plugin deactivation.
 */
class Deactivator {

	/**
	 * Run deactivation cleanup:
	 * - Clear scheduled cron events
	 * - Clear plugin transients
	 * Does NOT drop tables or persistent settings.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Clear cron jobs if registered
		$timestamp = wp_next_scheduled( 'wpmmcc_cron_reconcile_check' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wpmmcc_cron_reconcile_check' );
		}

		delete_transient( 'wpmmcc_flush_rewrite_rules' );
	}
}
