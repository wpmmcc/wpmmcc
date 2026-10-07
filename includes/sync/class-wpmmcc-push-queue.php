<?php
/**
 * WPMMCC outbound push queue (WMC-01 / WMC-06 / WMC-09).
 *
 * The outbound CDC queue lives in the dual-role wpmmcc_journal table
 * (direction 'outbound', consumed 0). Historically one row was written per
 * change, `consumed` was global, a failed packet stayed at the head forever
 * and nothing ever aged out. This class owns the queue rules so the writer
 * (Event_Recorder), the shipper (Manual_Sync) and the scheduler (Reconciler)
 * cannot drift apart:
 *
 *  - Fan-out: one row per push-capable active peer (peer_uuid = target), so
 *    each peer has independent progress. Rows with peer_uuid '' are legacy
 *    (pre-fan-out) rows and stay visible to every peer; the first peer that
 *    delivers one consumes it, exactly as before.
 *  - No target, no row: a site with nobody to push to records nothing (the
 *    fingerprint digest reconcile heals a peer added later), so the journal
 *    no longer grows without bound on peer-less sites.
 *  - Row lifecycle in `consumed`: PENDING (0) -> DONE (1, acked; audit rows
 *    are always DONE) or DEAD (2, gave up). DEAD rows stay visible and can be
 *    requeued. All rows are retained by default; only confirmed DONE rows
 *    participate in an explicitly configured retention policy.
 *  - Retry state without a schema change: `outcome` 'failed' marks a row that
 *    has been attempted and failed (fresh rows keep 'success'); attempts and
 *    the last error ride in the `diff_summary` JSON. Fresh rows are served
 *    before retries (with a guaranteed retry share) so a failing row can
 *    delay but never starve the rows behind it.
 *  - Entity grouping: several pending rows of one entity are one delivery;
 *    the newest row is the "leader" and an ack / failure applies to every
 *    row of the entity up to the leader.
 *
 * @package WPMMCC\Sync
 */

namespace WPMMCC\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Push_Queue
 */
class Push_Queue {

	/**
	 * journal.consumed: still to be delivered.
	 */
	const PENDING = 0;

	/**
	 * journal.consumed: delivered (or an audit-only row).
	 */
	const DONE = 1;

	/**
	 * journal.consumed: given up on (kept for inspection / requeue).
	 */
	const DEAD = 2;

	/**
	 * Failed deliveries after which a row is dead-lettered.
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Save-debounce window (doc 09 §3.1).
	 */
	const DEBOUNCE_SECONDS = 5;

	/**
	 * Optional unreferenced peer-audit retention (days). Default keeps all.
	 */
	const DONE_TTL_DAYS = 0;

	/**
	 * Pending rows never expire by age. Kept as the zero-retention contract.
	 */
	const PENDING_TTL_DAYS = 0;

	/**
	 * Rows deleted / expired per statement, and statements per prune run.
	 */
	const PRUNE_BATCH       = 1000;
	const PRUNE_MAX_BATCHES = 10;

	/**
	 * Unshippable rows dead-lettered per statement.
	 */
	const SWEEP_BATCH = 500;

	/**
	 * One in RETRY_SHARE delivery slots is reserved for retry entities.
	 */
	const RETRY_SHARE = 4;

	/**
	 * Rows fetched per wanted delivery, so that several rows of one entity
	 * (each save can queue one) do not shrink a run below its packet budget.
	 */
	const ROW_WINDOW = 4;

	/**
	 * Current time in the timezone occurred_at is stored in.
	 *
	 * @return string MySQL datetime.
	 */
	public static function now(): string {
		return (string) current_time( 'mysql' );
	}

	/**
	 * A datetime N seconds before now(), in the same wall-clock basis.
	 * (occurred_at is written with current_time('mysql'); comparing it with a
	 * gmdate() cutoff mixes timezones on any site not set to UTC.)
	 *
	 * @param int $seconds_ago Seconds before now.
	 * @return string MySQL datetime.
	 */
	public static function cutoff( int $seconds_ago ): string {
		return gmdate( 'Y-m-d H:i:s', (int) strtotime( self::now() . ' UTC' ) - $seconds_ago );
	}

	/**
	 * Peer uuids that must receive this site's outbound changes.
	 *
	 * @return string[]
	 */
	public static function push_target_uuids(): array {
		$uuids = array();
		foreach ( Reconciler::get_active_peers() as $peer ) {
			if ( 'pull_only' === (string) ( $peer['direction'] ?? 'bidirectional' ) ) {
				continue;
			}
			$uuid = (string) ( $peer['peer_uuid'] ?? '' );
			if ( '' !== $uuid ) {
				$uuids[] = $uuid;
			}
		}
		return array_values( array_unique( $uuids ) );
	}

	/**
	 * Queue one change for every push target.
	 *
	 * @param array $entry Journal entry (event_type, canonical_uuid, ...).
	 * @return int First inserted journal id, 0 when there is no target.
	 */
	public static function enqueue( array $entry ): int {
		$first = 0;
		foreach ( self::push_target_uuids() as $peer_uuid ) {
			$id = Event_Recorder::record_journal_entry(
				array_merge(
					$entry,
					array(
						'direction' => 'outbound',
						'peer_uuid' => $peer_uuid,
						'consumed'  => self::PENDING,
					)
				)
			);
			if ( 0 === $first ) {
				$first = $id;
			}
		}
		return $first;
	}

	/**
	 * Fold a rapid re-save into the entity's still-pending rows (debounce).
	 *
	 * @param string $canonical_uuid Entity uuid.
	 * @param string $event_type     'insert' | 'update'.
	 * @param string $payload_digest Digest of the latest state.
	 * @return bool True when at least one pending row absorbed the save.
	 */
	public static function coalesce( string $canonical_uuid, string $event_type, string $payload_digest ): bool {
		global $wpdb;

		$table  = $wpdb->prefix . 'wpmmcc_journal';
		$cutoff = self::cutoff( self::DEBOUNCE_SECONDS );
		// The freezer shares this entity fence. A busy or partially covered
		// group must enqueue a new event for ALL current targets instead.
		$lease = SQL_Lease::acquire( 'queue:' . $canonical_uuid );
		if ( is_wp_error( $lease ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom journal table query.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE direction = 'outbound' AND consumed = 0 AND canonical_uuid = %s AND occurred_at >= %s ORDER BY id ASC LIMIT 1001",
				$canonical_uuid,
				$cutoff
			), ARRAY_A
		);
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error || empty( $rows ) || count( $rows ) > 1000 ) {
			return false;
		}
		$targets = self::push_target_uuids();
		$covered = array();
		$ids = array();
		foreach ( $rows as $row ) {
			if ( '' === $row['peer_uuid'] ) {
				$covered = $targets;
			} elseif ( in_array( $row['peer_uuid'], $targets, true ) ) {
				$covered[] = $row['peer_uuid'];
			} else {
				continue;
			}
			$ids[] = (int) $row['id'];
		}
		if ( empty( $ids ) || array_diff( $targets, $covered )
			|| self::delivery_covers_row( $canonical_uuid, min( $ids ) ) ) {
			return false;
		}

		$lease->assert_owner();
		$now = self::now();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom journal table update.
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET occurred_at = %s, payload_digest = %s, event_type = %s WHERE id IN (" . implode( ',', $ids ) . ") AND direction = 'outbound' AND consumed = 0 AND canonical_uuid = %s AND occurred_at >= %s",
				$now,
				$payload_digest,
				$event_type,
				$canonical_uuid,
				$cutoff
			)
		);
		if ( false === $changed ) {
			throw new \RuntimeException( 'Queue coalescing was not saved.' );
		}
		$live = $wpdb->get_results( "SELECT * FROM {$table} WHERE id IN (" . implode( ',', $ids ) . ')', ARRAY_A );
		if ( ! is_array( $live ) || '' !== (string) $wpdb->last_error || count( $live ) !== count( $ids ) ) {
			throw new \RuntimeException( 'Queue coalescing readback is unavailable.' );
		}
		foreach ( $live as $row ) {
			if ( $row['payload_digest'] !== $payload_digest || $row['event_type'] !== $event_type || $row['occurred_at'] !== $now
				|| 0 !== (int) $row['consumed'] ) {
				throw new \RuntimeException( 'Queue coalescing scope changed.' );
			}
		}
		$lease->assert_owner();
		wp_cache_delete( 'admin_journal_recent', 'wpmmcc' );
		return true;
	}

	/**
	 * A frozen outbound delivery booked at or after the queue row covers it:
	 * an immutable packet is already booked, so folding
	 * a newer save into the covered row would rewrite committed evidence.
	 *
	 * @param string $canonical_uuid Entity uuid.
	 * @param int    $row_id         Queue row id.
	 * @return bool True when an outbound delivery already covers the row.
	 */
	private static function delivery_covers_row( string $canonical_uuid, int $row_id ): bool {
		global $wpdb;

		$deliveries = $wpdb->prefix . 'wpmmcc_outbound_deliveries';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom delivery table query.
		$event = $wpdb->get_var(
			$wpdb->prepare( "SELECT event_id FROM {$deliveries} WHERE canonical_uuid = %s AND event_id >= %d LIMIT 1", $canonical_uuid, $row_id )
		);
		if ( '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException( 'Frozen delivery evidence is unavailable.' );
		}
		return null !== $event;
	}

	/**
	 * The next deliveries for a peer, one per entity.
	 *
	 * Fresh rows come first; rows that already failed are guaranteed a share
	 * of the slots (RETRY_SHARE) so they keep moving toward success or death
	 * without ever starving the rows behind them. Each group's leader is the
	 * entity's NEWEST pending row at selection time, so an ack consumes every
	 * pending row of the entity, including ones beyond the row window.
	 *
	 * @param int    $limit     Deliveries (entities) wanted.
	 * @param string $peer_uuid Peer scope ('' = every peer).
	 * @return array[] Each: array('leader' => row, 'rows' => rows).
	 */
	public static function pending_groups( int $limit, string $peer_uuid = '' ): array {
		$limit  = max( 1, $limit );
		$quota  = intdiv( $limit, self::RETRY_SHARE );
		$window = $limit * self::ROW_WINDOW;

		$fresh = self::group_by_entity( self::select_pending( $peer_uuid, false, $window ) );
		$retry = self::group_by_entity( self::select_pending( $peer_uuid, true, $window ) );

		$take_retry = min( count( $retry ), max( $quota, $limit - count( $fresh ) ) );
		$take_fresh = min( count( $fresh ), $limit - $take_retry );
		$chosen     = array_merge( array_slice( $fresh, 0, $take_fresh ), array_slice( $retry, 0, $take_retry ) );

		$groups = array();
		$seen   = array();
		foreach ( $chosen as $group ) {
			$uuid = (string) $group['leader']['canonical_uuid'];
			if ( isset( $seen[ $uuid ] ) ) {
				// An entity with both a failed and a newer fresh row appears in
				// both lanes; it is one delivery.
				continue;
			}
			$seen[ $uuid ] = true;

			$newest = self::newest_pending_row( $uuid, $peer_uuid );
			if ( null !== $newest && (int) $newest['id'] > (int) $group['leader']['id'] ) {
				$group['leader'] = $newest;
			}
			$groups[] = $group;
		}
		return $groups;
	}

	/**
	 * The newest still-pending row of an entity in a peer's scope.
	 *
	 * @param string $canonical_uuid Entity uuid.
	 * @param string $peer_uuid      Peer scope.
	 * @return array|null Journal row.
	 */
	private static function newest_pending_row( string $canonical_uuid, string $peer_uuid ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'wpmmcc_journal';
		$sql   = "SELECT * FROM {$table} WHERE direction = 'outbound' AND consumed = 0 AND canonical_uuid = %s";
		$args  = array( $canonical_uuid );
		if ( '' !== $peer_uuid ) {
			$sql   .= " AND peer_uuid IN ('', %s)";
			$args[] = $peer_uuid;
		}
		$sql .= ' ORDER BY id DESC LIMIT 1';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Custom journal table query; placeholders assembled above.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $args ), \ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Fetch pending rows of one class.
	 *
	 * @param string $peer_uuid Peer scope.
	 * @param bool   $retry     True = rows that already failed once.
	 * @param int    $limit     Row cap.
	 * @return array[]
	 */
	private static function select_pending( string $peer_uuid, bool $retry, int $limit ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wpmmcc_journal';
		$sql   = "SELECT * FROM {$table} WHERE direction = 'outbound' AND consumed = 0 AND outcome " . ( $retry ? '=' : '<>' ) . " 'failed'";
		$args  = array();
		if ( '' !== $peer_uuid ) {
			$sql   .= " AND peer_uuid IN ('', %s)";
			$args[] = $peer_uuid;
		}
		$sql   .= ' ORDER BY id ASC LIMIT %d';
		$args[] = $limit;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Custom journal table query; placeholders assembled above.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), \ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Group rows by entity. The newest row of an entity is its leader: the
	 * current post state is what ships, so older rows are redundant.
	 *
	 * @param array[] $rows Journal rows.
	 * @return array[] Each: array('leader' => row, 'rows' => rows).
	 */
	public static function group_by_entity( array $rows ): array {
		$groups = array();
		foreach ( $rows as $row ) {
			$uuid = (string) ( $row['canonical_uuid'] ?? '' );
			if ( ! isset( $groups[ $uuid ] ) ) {
				$groups[ $uuid ] = array(
					'leader' => $row,
					'rows'   => array(),
				);
			}
			$groups[ $uuid ]['rows'][] = $row;
			if ( (int) $row['id'] > (int) $groups[ $uuid ]['leader']['id'] ) {
				$groups[ $uuid ]['leader'] = $row;
			}
		}
		return array_values( $groups );
	}

	/**
	 * Failed-attempt count carried by a row.
	 *
	 * @param array $event Journal row.
	 * @return int
	 */
	public static function attempts_of( array $event ): int {
		$state = json_decode( (string) ( $event['diff_summary'] ?? '' ), true );
		return is_array( $state ) ? max( 0, (int) ( $state['attempts'] ?? 0 ) ) : 0;
	}

	/**
	 * Delivered: consume the leader and every older pending row of the same
	 * entity in this peer's scope.
	 *
	 * @param array  $leader    Leader row.
	 * @param string $peer_uuid Peer that acked.
	 * @return int Rows consumed.
	 */
	public static function ack( array $leader, string $peer_uuid ): int {
		return self::update_group(
			$leader,
			$peer_uuid,
			"consumed = %d, outcome = 'success', message = NULL",
			array( self::DONE )
		);
	}

	/**
	 * A delivery attempt failed.
	 *
	 * Either way the entity group moves to the retry lane, so the rows behind
	 * it get their turn. Only a failure attributable to the entity ($charge)
	 * spends one of its MAX_ATTEMPTS; at the limit the group is dead-lettered.
	 * An outage says nothing about the event: charging it would dead-letter
	 * the whole queue during a peer restart.
	 *
	 * @param array  $leader      Leader row.
	 * @param string $peer_uuid   Peer that was attempted.
	 * @param string $error       Human-readable cause.
	 * @param int    $http_status Peer HTTP status (0 = local / transport failure).
	 * @param bool   $charge      Spend an attempt.
	 * @return string 'retry' | 'dead'
	 */
	public static function record_failure( array $leader, string $peer_uuid, string $error, int $http_status = 0, bool $charge = true ): string {
		$attempts = self::attempts_of( $leader ) + ( $charge ? 1 : 0 );
		$dead     = $charge && $attempts >= self::MAX_ATTEMPTS;
		$error    = substr( $error, 0, 240 );
		$state    = wp_json_encode(
			array(
				'attempts'        => $attempts,
				'last_http'       => $http_status,
				'last_error'      => $error,
				'last_attempt_at' => self::now(),
			)
		);
		if ( $dead ) {
			$message = sprintf( 'Dead-lettered after %d failed attempts: %s', $attempts, $error );
		} elseif ( $charge ) {
			$message = sprintf( 'Attempt %d of %d failed: %s', $attempts, self::MAX_ATTEMPTS, $error );
		} else {
			$message = sprintf( 'Delivery deferred, peer unavailable (attempt not counted): %s', $error );
		}

		self::update_group(
			$leader,
			$peer_uuid,
			"consumed = %d, outcome = 'failed', diff_summary = %s, message = %s" . ( $dead ? ', occurred_at = %s' : '' ),
			array_merge(
				array( $dead ? self::DEAD : self::PENDING, $state, $message ),
				$dead ? array( self::now() ) : array()
			)
		);
		return $dead ? 'dead' : 'retry';
	}

	/**
	 * Apply one SET clause to a leader's entity group.
	 *
	 * @param array  $leader    Leader row.
	 * @param string $peer_uuid Peer scope ('' = unscoped).
	 * @param string $set       SET body with %d / %s placeholders.
	 * @param array  $set_args  Placeholder values.
	 * @return int Rows changed.
	 */
	private static function update_group( array $leader, string $peer_uuid, string $set, array $set_args ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'wpmmcc_journal';
		$sql   = "UPDATE {$table} SET {$set} WHERE direction = 'outbound' AND consumed = 0 AND canonical_uuid = %s AND id <= %d";
		$args  = array_merge( $set_args, array( (string) ( $leader['canonical_uuid'] ?? '' ), (int) ( $leader['id'] ?? 0 ) ) );
		if ( '' !== $peer_uuid ) {
			$sql   .= " AND peer_uuid IN ('', %s)";
			$args[] = $peer_uuid;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Custom journal table update; placeholders assembled above.
		$changed = $wpdb->query( $wpdb->prepare( $sql, $args ) );
		if ( false === $changed ) {
			throw new \RuntimeException( 'Queue state was not saved; original delivery retained.' );
		}
		wp_cache_delete( 'admin_journal_recent', 'wpmmcc' );
		return (int) $changed;
	}

	/**
	 * Dead-letter rows that can never be shipped (not a post event, or the
	 * uuid / object id is missing). They used to sit at the queue head
	 * forever; here they leave the queue immediately, visibly.
	 *
	 * @param string $peer_uuid Peer scope ('' = every peer).
	 * @return int Rows dead-lettered.
	 */
	public static function sweep_unshippable( string $peer_uuid = '' ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'wpmmcc_journal';
		$rules = array(
			"object_type <> 'post'"  => 'unsupported object type',
			"canonical_uuid = ''"    => 'missing canonical uuid',
			'local_object_id = 0'    => 'missing local object id',
		);

		$total = 0;
		foreach ( $rules as $cond => $why ) {
			$sql  = "UPDATE {$table} SET consumed = 2, outcome = 'failed', occurred_at = %s, message = %s WHERE direction = 'outbound' AND consumed = 0 AND {$cond}";
			$args = array( self::now(), 'Dead-lettered: cannot ship this event (' . $why . ')' );
			if ( '' !== $peer_uuid ) {
				$sql   .= " AND peer_uuid IN ('', %s)";
				$args[] = $peer_uuid;
			}
			$sql   .= ' LIMIT %d';
			$args[] = self::SWEEP_BATCH;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Custom journal table update; placeholders assembled above.
			$changed = $wpdb->query( $wpdb->prepare( $sql, $args ) );
			if ( false === $changed ) {
				throw new \RuntimeException( 'Unshippable queue state was not saved.' );
			}
			$total += (int) $changed;
		}
		if ( $total > 0 ) {
			wp_cache_delete( 'admin_journal_recent', 'wpmmcc' );
		}
		return $total;
	}

	/**
	 * Put dead-lettered rows back in the queue with a fresh attempt budget.
	 *
	 * @param string $peer_uuid Peer scope ('' = every peer).
	 * @return int Rows requeued.
	 */
	public static function requeue_dead( string $peer_uuid = '' ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'wpmmcc_journal';
		$sql   = "UPDATE {$table} SET consumed = 0, outcome = 'success', diff_summary = NULL, message = NULL WHERE direction = 'outbound' AND consumed = 2";
		$args  = array();
		if ( '' !== $peer_uuid ) {
			$sql   .= " AND peer_uuid IN ('', %s)";
			$args[] = $peer_uuid;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Custom journal table update; placeholders assembled above.
		$changed = $wpdb->query( empty( $args ) ? $sql : $wpdb->prepare( $sql, $args ) );
		if ( false === $changed ) {
			throw new \RuntimeException( 'Queue requeue was not saved.' );
		}
		wp_cache_delete( 'admin_journal_recent', 'wpmmcc' );
		return is_int( $changed ) ? $changed : (int) (bool) $changed;
	}

	/**
	 * Queue depth for a peer (admin display).
	 *
	 * @param string $peer_uuid Peer scope ('' = every peer).
	 * @return array{pending:int,retrying:int,dead:int} pending = never attempted,
	 *               retrying = attempted and failed but still queued.
	 */
	public static function counts( string $peer_uuid = '' ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'wpmmcc_journal';
		$count = static function ( string $cond ) use ( $wpdb, $table, $peer_uuid ): int {
			$sql  = "SELECT COUNT(*) FROM {$table} WHERE direction = 'outbound' AND {$cond}";
			$args = array();
			if ( '' !== $peer_uuid ) {
				$sql   .= " AND peer_uuid IN ('', %s)";
				$args[] = $peer_uuid;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared -- Custom journal table count; placeholders assembled above.
			return (int) $wpdb->get_var( empty( $args ) ? $sql : $wpdb->prepare( $sql, $args ) );
		};

		return array(
			'pending'  => $count( "consumed = 0 AND outcome <> 'failed'" ),
			'retrying' => $count( "consumed = 0 AND outcome = 'failed'" ),
			'dead'     => $count( 'consumed = 2' ),
		);
	}

	/**
	 * Park a disconnected peer's queue rows without deleting its evidence.
	 *
	 * @param string $peer_uuid Peer uuid.
	 * @return int Rows parked.
	 */
	public static function forget_peer( string $peer_uuid ): int {
		global $wpdb;

		if ( '' === $peer_uuid ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Retain unconfirmed rows when disconnecting a peer.
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}wpmmcc_journal SET consumed = 2, outcome = 'failed', message = 'Peer disconnected; original delivery retained' WHERE direction = 'outbound' AND consumed = 0 AND peer_uuid = %s",
				$peer_uuid
			)
		);
		if ( false === $changed ) {
			throw new \RuntimeException( 'Disconnected queue was not parked.' );
		}
		wp_cache_delete( 'admin_journal_recent', 'wpmmcc' );
		return is_int( $changed ) ? $changed : (int) (bool) $changed;
	}

	/**
	 * Optional retention for confirmed DONE journal rows only. Default zero
	 * keeps completed evidence too. DEAD/PENDING and durable dedup receipts
	 * never expire here, regardless of age or retry budget.
	 *
	 * @return array{deleted:int,expired:int}
	 */
	public static function prune(): array {
		global $wpdb;

		$done_days = apply_filters( 'wpmmcc_journal_retention_days', self::DONE_TTL_DAYS );
		if ( ! is_int( $done_days ) || $done_days < 1 || $done_days > 36500
			|| true !== get_option( 'wpmmcc_audit_retention_confirmed', false ) ) {
			return array( 'deleted' => 0, 'expired' => 0 );
		}

		$deleted = 0;
		for ( $i = 0; $i < self::PRUNE_MAX_BATCHES; $i++ ) {
			$cutoff = self::cutoff( $done_days * 86400 );
			$preview = Journal_Cleanup::preview( $cutoff );
			$n = Journal_Cleanup::apply( $preview['revision'], true, $cutoff );
			$deleted += $n;
			if ( $n < self::PRUNE_BATCH ) {
				break;
			}
		}

		if ( $deleted > 0 ) {
			wp_cache_delete( 'admin_journal_recent', 'wpmmcc' );
		}
		return array(
			'deleted' => $deleted,
			'expired' => 0,
		);
	}
}
