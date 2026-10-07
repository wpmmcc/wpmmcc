<?php
/**
 * Revision-confirmed cleanup of unreferenced peer-lifecycle audit rows only.
 *
 * @package WPMMCC\Sync
 */
namespace WPMMCC\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Journal_Cleanup {
	const BATCH = 1000;

	/**
	 * The eligibility allowlist for the ONLY rows cleanup may ever touch.
	 *
	 * A row qualifies when it is a finished peer-lifecycle audit entry
	 * (peer_connect / peer_disconnect) that references no entity, packet,
	 * source, receipt, media or delivery evidence. Anything else - content
	 * events, failures, dead letters, unknown future kinds - stays retained,
	 * even when it is DONE and ancient.
	 *
	 * Unknown reference payloads are retained. NULL event ids are excluded
	 * from the subselect so they cannot change NOT IN semantics.
	 */
	public static function predicate(): string {
		global $wpdb;
		return "consumed = 1 AND outcome IN ('success', 'skipped')
			AND object_type = 'peer' AND event_type IN ('peer_connect', 'peer_disconnect')
			AND canonical_uuid = '' AND packet_id = '' AND payload_digest = ''
			AND source_fingerprint = '' AND local_object_id = 0 AND remote_object_id = 0
			AND diff_summary IS NULL
			AND id NOT IN (SELECT event_id FROM {$wpdb->prefix}wpmmcc_outbound_deliveries WHERE event_id IS NOT NULL)";
	}

	/**
	 * The exact rows a cleanup run would remove, plus the revision an
	 * operator confirms. The same digest re-derived inside the cleanup
	 * transaction must match before anything is deleted.
	 *
	 * @param string $cutoff Optional occurred_at upper bound ('Y-m-d H:i:s').
	 * @return array{rows: array[], count: int, revision: string}
	 */
	public static function preview( string $cutoff = '', bool $locked = false ): array {
		global $wpdb;
		$sql = "SELECT * FROM {$wpdb->prefix}wpmmcc_journal WHERE " . self::predicate();
		if ( '' !== $cutoff ) {
			$sql .= $wpdb->prepare( ' AND occurred_at < %s', $cutoff );
		}
		$sql .= ' ORDER BY id ASC LIMIT ' . self::BATCH . ( $locked ? ' FOR UPDATE' : '' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Literal allowlist predicate, prepared cutoff.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException( 'Cleanup preview is unavailable; all evidence retained.' );
		}
		return array( 'rows' => $rows, 'count' => count( $rows ), 'revision' => Packet::digest( $rows ) );
	}

	/**
	 * Delete exactly the rows of a confirmed preview, or nothing.
	 *
	 * The InnoDB transaction locks and re-derives the preview, refuses a
	 * changed revision, and checks its native connection/fence. The
	 * DELETE re-applies the full allowlist predicate to the frozen id list,
	 * and an affected-row count different from the preview aborts the whole
	 * transaction. A row that gained a reference after the preview therefore
	 * stops the cleanup instead of being deleted.
	 *
	 * @param string $revision  Revision hash of the reviewed preview.
	 * @param bool   $confirmed Explicit operator confirmation.
	 * @param string $cutoff    Same cutoff the preview used.
	 * @return int Deleted row count.
	 */
	public static function apply( string $revision, bool $confirmed, string $cutoff = '' ): int {
		global $wpdb;
		if ( ! $confirmed || ! preg_match( '/^[a-f0-9]{64}$/D', $revision ) ) {
			throw new \RuntimeException( 'A reviewed cleanup revision and explicit confirmation are required.' );
		}
		$lease = SQL_Lease::acquire( 'journal-cleanup' );
		if ( is_wp_error( $lease ) ) {
			throw new \RuntimeException( 'Cleanup authority is unavailable; all evidence retained.' );
		}
		$transaction = SQL_Transaction::begin( $lease, array(
			$wpdb->prefix . 'wpmmcc_journal', $wpdb->prefix . 'wpmmcc_outbound_deliveries',
		) );
		try {
			$live = self::preview( $cutoff, true );
			if ( ! hash_equals( $revision, $live['revision'] ) ) {
				throw new \RuntimeException( 'Cleanup preview changed; refresh and confirm again.' );
			}
			$transaction->assert_active();
			if ( 0 === $live['count'] ) {
				$transaction->commit();
				return 0;
			}
			$ids = array_map(
				static function ( array $row ): int {
					return (int) $row['id'];
				},
				$live['rows']
			);
			$sql = "DELETE FROM {$wpdb->prefix}wpmmcc_journal WHERE " . self::predicate()
				. ' AND id IN (' . implode( ',', $ids ) . ')';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Integer ids frozen inside the transaction with the full allowlist rechecked.
			$deleted = $wpdb->query( $sql );
			if ( count( $ids ) !== $deleted ) {
				throw new \RuntimeException( 'Cleanup scope differs; no partial cleanup is accepted.' );
			}
			$transaction->commit();
			wp_cache_delete( 'admin_journal_recent', 'wpmmcc' );
			return $deleted;
		} catch ( \Throwable $error ) {
			$transaction->rollback();
			throw $error;
		}
	}
}
