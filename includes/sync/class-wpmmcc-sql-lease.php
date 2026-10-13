<?php
/**
 * Connection-owned execution fence. Age never authorizes takeover.
 *
 * @package WPMMCC\Sync
 */
namespace WPMMCC\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SQL_Lease {
	private $name;
	private $connection;
	private $database;

	private function __construct( string $name, int $connection, string $database ) {
		$this->name = $name;
		$this->connection = $connection;
		$this->database = $database;
	}

	public static function acquire( string $scope ) {
		global $wpdb;
		$name = 'wmc:' . substr( hash( 'sha256', $wpdb->prefix . "\n" . $scope ), 0, 60 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- lock ownership is a property of this database connection
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
		if ( '1' !== (string) $locked ) {
			return new \WP_Error( 'wpmmcc_delivery_busy', 'Original delivery is active or its execution fence is unavailable.', array( 'status' => 409 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- lock ownership is a property of this database connection
		$connection = (int) $wpdb->get_var( 'SELECT CONNECTION_ID()' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- lock ownership is a property of this database connection
		$database = $wpdb->get_var( 'SELECT DATABASE()' );
		$lease = new self( $name, $connection, (string) $database );
		if ( $connection <= 0 || ! is_string( $database ) || '' === $database || ! $lease->owns() ) {
			return new \WP_Error( 'wpmmcc_delivery_lease_changed', 'Original execution authority changed.', array( 'status' => 409 ) );
		}
		return $lease;
	}

	public function owns(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- lock ownership is a property of this database connection
		$owner = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $this->name ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- lock ownership is a property of this database connection
		$current = $wpdb->get_var( 'SELECT CONNECTION_ID()' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- lock ownership is a property of this database connection
		$database = $wpdb->get_var( 'SELECT DATABASE()' );
		return (string) $owner === (string) $this->connection && (string) $current === (string) $this->connection
			&& $database === $this->database && '' === (string) $wpdb->last_error;
	}

	public function assert_owner(): void {
		if ( ! $this->owns() ) {
			throw new \RuntimeException( 'Original execution authority changed; evidence retained.' );
		}
	}

	public function __destruct() {
		global $wpdb;
		try {
			if ( $this->owns() ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- lock ownership is a property of this database connection
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name ) );
			}
		} catch ( \Throwable $error ) {
			// A lost connection already revoked this invocation's authority.
		}
	}
}
