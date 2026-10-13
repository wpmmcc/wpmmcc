<?php
/**
 * Checked InnoDB transaction on the original lease-owned connection.
 *
 * @package WPMMCC\Sync
 */
namespace WPMMCC\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SQL_Transaction {
	private $lease;
	private $connection;
	private $fence;
	private $open = false;

	private function __construct( SQL_Lease $lease, $connection ) {
		$this->lease = $lease;
		$this->connection = $connection;
		$this->fence = 'wmc_' . bin2hex( random_bytes( 12 ) );
	}

	public static function begin( SQL_Lease $lease, array $tables ): self {
		global $wpdb;
		$lease->assert_owner();
		$connection = $wpdb->dbh;
		// mysqli does not expose the C API server_status flag. Use the native
		// SQL admission check below, which refuses an already-active transaction.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- transaction and savepoint commands must run on this connection
		$autocommit = $wpdb->get_var( 'SELECT @@session.autocommit' );
		if ( ( ! is_object( $connection ) && ! is_resource( $connection ) )
			|| '1' !== (string) $autocommit || '' !== (string) $wpdb->last_error || empty( $tables ) ) {
			throw new \RuntimeException( 'Transaction ownership is unavailable; evidence retained.' );
		}
		$tables = array_values( array_unique( $tables ) );
		foreach ( $tables as $table ) {
			if ( ! is_string( $table ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) {
				throw new \RuntimeException( 'Transaction table scope is invalid.' );
			}
		}
		$engines = array();
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- transaction and savepoint commands must run on this connection
			$engine = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
					$table
				),
				ARRAY_A
			);
			if ( ! is_array( $engine ) ) {
				$engines = null;
				break;
			}
			$engines[] = $engine;
		}
		if ( ! is_array( $engines ) || '' !== (string) $wpdb->last_error || count( $engines ) !== count( $tables ) ) {
			throw new \RuntimeException( 'Transaction table evidence is unavailable.' );
		}
		$seen = array();
		foreach ( $engines as $engine ) {
			$name = $engine['TABLE_NAME'] ?? '';
			if ( ! in_array( $name, $tables, true ) || isset( $seen[ $name ] )
				|| 'innodb' !== strtolower( (string) ( $engine['ENGINE'] ?? '' ) ) ) {
				throw new \RuntimeException( 'Nontransactional or changed tables cannot authorize completion.' );
			}
			$seen[ $name ] = true;
		}
		$transaction = new self( $lease, $connection );
		$lease->assert_owner();
		// SET TRANSACTION (without SESSION/GLOBAL) errors inside an active
		// transaction and never commits it. It admits only our NEXT transaction.
		if ( $wpdb->dbh !== $connection
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- transaction and savepoint commands must run on this connection
			|| false === $wpdb->query( 'SET TRANSACTION READ WRITE' )
			|| false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw new \RuntimeException( 'Transaction did not start on the original connection.' );
		}
		$transaction->open = true;
		// The fence detects hooks that commit, reconnect or start a replacement
		// transaction, even when the connection is still marked IN_TRANS.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- transaction and savepoint commands must run on this connection
		if ( false === $wpdb->query( $wpdb->prepare( 'SAVEPOINT %i', $transaction->fence ) ) ) {
			$transaction->rollback();
			throw new \RuntimeException( 'Transaction fence was not saved.' );
		}
		$transaction->assert_active();
		return $transaction;
	}

	public function assert_active(): void {
		global $wpdb;
		$this->lease->assert_owner();
		if ( ! $this->open || $wpdb->dbh !== $this->connection
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- transaction and savepoint commands must run on this connection
			|| false === $wpdb->query( $wpdb->prepare( 'RELEASE SAVEPOINT %i', $this->fence ) ) ) {
			throw new \RuntimeException( 'Original transaction changed; completion is unconfirmed.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- transaction and savepoint commands must run on this connection
		if ( false === $wpdb->query( $wpdb->prepare( 'SAVEPOINT %i', $this->fence ) ) ) {
			throw new \RuntimeException( 'Original transaction fence changed.' );
		}
	}

	public function commit(): void {
		global $wpdb;
		$this->assert_active();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- transaction and savepoint commands must run on this connection
		if ( false === $wpdb->query( 'COMMIT' ) || $wpdb->dbh !== $this->connection ) {
			throw new \RuntimeException( 'Original transaction commit is unconfirmed.' );
		}
		$this->open = false;
		$this->lease->assert_owner();
	}

	public function rollback(): void {
		global $wpdb;
		if ( $this->open && $wpdb->dbh === $this->connection && $this->lease->owns()
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- transaction and savepoint commands must run on this connection
			&& false !== $wpdb->query( $wpdb->prepare( 'RELEASE SAVEPOINT %i', $this->fence ) ) ) {
			$wpdb->query( 'ROLLBACK' );
		}
		$this->open = false;
	}

	public function __destruct() {
		try {
			$this->rollback();
		} catch ( \Throwable $error ) {
			// Never roll back another connection's or a hook's transaction.
		}
	}
}
