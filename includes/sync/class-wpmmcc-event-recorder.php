<?php
/**
 * WPMMCC Event Recorder & Anti-Loop Depth Counter (WP-A-06, doc 12/ADR-6.3, doc 14 §1).
 *
 * Captures entity changes into the dual-role CDC & audit journal table.
 * Enforces request-scoped depth counter to eliminate infinite sync loops.
 *
 * @package WPMMCC\Sync
 */

namespace WPMMCC\Sync;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Event_Recorder
 */
class Event_Recorder {

	/**
	 * Request-scoped ingestion depth counter.
	 * Replaces unsafe static define() constants (ADR-6.3).
	 *
	 * @var int
	 */
	public static $depth = 0;

	/**
	 * Maximum allowed loop recursion depth.
	 */
	const MAX_RECURSION_DEPTH = 3;

	/**
	 * Post types that are never synced (internal / derived content).
	 */
	const IGNORED_POST_TYPES = array( 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'action_monitor' );

	/**
	 * Register WordPress action hooks for event capture.
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 99, 3 );
		add_action( 'delete_post', array( __CLASS__, 'on_delete_post' ), 99, 2 );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_trash_post' ), 99, 1 );
	}

	/**
	 * Execute a callable within an active ingestion scope.
	 * Increments depth counter, executes callback, and guarantees decrement via finally.
	 *
	 * @param callable $callback Ingestion callable.
	 * @return mixed Result of callback.
	 */
	public static function run_in_ingestion_scope( callable $callback ) {
		self::$depth++;
		try {
			return $callback();
		} finally {
			self::$depth--;
			if ( self::$depth < 0 ) {
				self::$depth = 0;
			}
		}
	}

	/**
	 * Handle post save / update.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post object.
	 * @param bool    $update True if updating existing post.
	 * @return void
	 */
	public static function on_save_post( int $post_id, $post, bool $update ): void {
		// 1. Anti-Loop Gate: Skip if currently executing inside ingestion scope
		if ( self::$depth > 0 ) {
			return;
		}

		// 2. Ignore autosaves, revisions, and internal post types
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		if ( in_array( $post->post_type, self::IGNORED_POST_TYPES, true ) ) {
			return;
		}

		// Only track published or draft posts
		if ( ! in_array( $post->post_status, array( 'publish', 'draft', 'pending', 'future' ), true ) ) {
			return;
		}

		// A conflict-review draft is a local staging copy of the PEER's version:
		// saving it is not an edit of the entity (WMC-11).
		if ( Shadow_Draft::is_shadow( $post_id ) ) {
			return;
		}

		// 3. Resolve or generate canonical UUID
		$canonical_uuid = self::resolve_or_create_canonical_uuid( 'post', $post_id );
		if ( '' === $canonical_uuid ) {
			return;
		}

		// 3b. FL-14 (doc 24 §四.5): advance the source-side vector clock on every
		// tracked save. Without this the digest clock never moves past the peer's
		// stored clock, checksum drift always verdicts local_edited, and the client
		// drops the edit. Placed before the debounce arm so coalesced saves still bump.
		self::upsert_source_clock( 'post', $post_id, $canonical_uuid );

		// 4. Compute change payload digest
		$digest_string  = $post->post_title . '|' . $post->post_content . '|' . $post->post_status;
		$payload_digest = hash( 'sha256', $digest_string );

		// 5. 5-Second Debounce Window (doc 09 §3.1, test T-5.2): a rapid re-save
		// folds into the entity's still-pending queue rows instead of adding more.
		$event_type = $update ? 'update' : 'insert';
		if ( Push_Queue::coalesce( $canonical_uuid, $event_type, $payload_digest ) ) {
			return;
		}

		// 6. Queue the change for every push-capable peer (none -> no row; the
		// fingerprint digest reconcile picks the entity up when a peer is added).
		Push_Queue::enqueue(
			array(
				'event_type'      => $event_type,
				'canonical_uuid'  => $canonical_uuid,
				'object_type'     => 'post',
				'local_object_id' => $post_id,
				'payload_digest'  => $payload_digest,
				'outcome'         => 'success',
			)
		);
	}

	/**
	 * Upsert the SOURCE-side vector clock row for a local entity (FL-14, doc 24 §四.5).
	 *
	 * Honest vector-clock semantics require the source's own clock to advance
	 * on every tracked mutation; otherwise reconcile_fingerprints() compares a
	 * stale digest clock against the peer's stored clock, every edit ties or
	 * loses, and drift always verdicts local_edited (silently dropped by the
	 * desktop client) instead of source_newer (re-pulled and shipped).
	 *
	 * Two modes:
	 * - bump (default): clock = max(1, current) + 1 — wired into save/trash/delete hooks.
	 * - set ($clock int): persist exactly the clock a packet SHIPPED (GREATEST-guarded
	 *   so a racing edit bump is never clobbered) — called from Packet::export_post().
	 *
	 * The row is the entity's local "self row" (peer_uuid = '', remote_object_id = 0);
	 * existing rows (e.g. bidirectional inbound mappings) are updated in place because
	 * the vector clock is shared per-entity state.
	 *
	 * @param string   $object_type Entity object type ('post').
	 * @param int      $local_id Local object ID.
	 * @param string   $canonical_uuid Resolved canonical UUID (used when creating the self row).
	 * @param int|null $clock null = bump mode; int = persist this shipped clock.
	 * @return int The clock value written (or preserved by the GREATEST guard input).
	 */
	public static function upsert_source_clock( string $object_type, int $local_id, string $canonical_uuid = '', ?int $clock = null ): int {
		global $wpdb;

		$table    = $wpdb->prefix . 'wpmmcc_cross_mappings';
		if ( '' === $canonical_uuid ) {
			$canonical_uuid = self::lookup_canonical_uuid( $object_type, $local_id );
		}
		$lease = SQL_Lease::acquire( 'source:' . $canonical_uuid );
		if ( '' === $canonical_uuid || is_wp_error( $lease ) ) {
			throw new \RuntimeException( 'Source clock authority is unavailable.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- clock increment starts from the value stored now
		$raw_curr = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT vector_clock FROM %i WHERE local_object_type = %s AND local_object_id = %d ORDER BY vector_clock DESC LIMIT 1',
				$table,
				$object_type,
				$local_id
			)
		);
		if ( '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException( 'Source clock could not be read.' );
		}

		if ( null === $clock ) {
			// Bump mode: advance past the last known state.
			$next = max( 1, (int) $raw_curr ) + 1;
		} else {
			// Set mode: persist the shipped clock (floor at 1).
			$next = max( 1, (int) $clock );
		}

		$lease->assert_owner();
		if ( null !== $raw_curr && false !== $raw_curr && (int) $raw_curr > 0 ) {
			if ( null === $clock ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table or connection command; WordPress has no replacement API
				$changed = $wpdb->update(
					$table,
					array( 'vector_clock' => $next ),
					array(
						'local_object_type' => $object_type,
						'local_object_id'   => $local_id,
					),
					array( '%d' ),
					array( '%s', '%d' )
				);
			} else {
				// GREATEST guard: a save-hook bump racing between export_post()'s
				// read and this write must never be lowered.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table or connection command; WordPress has no replacement API
				$changed = $wpdb->query(
					$wpdb->prepare(
						'UPDATE %i SET vector_clock = GREATEST(vector_clock, %d) WHERE local_object_type = %s AND local_object_id = %d',
						$table,
						$next,
						$object_type,
						$local_id
					)
				);
			}
		} else {
			// No local row yet: create the self row.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table or connection command; WordPress has no replacement API
			$changed = $wpdb->insert(
				$table,
				array(
					'canonical_uuid'      => $canonical_uuid,
					'local_object_type'   => $object_type,
					'local_object_id'     => $local_id,
					'peer_uuid'           => '',
					'remote_object_id'    => 0,
					'content_checksum'    => '',
					'vector_clock'        => $next,
					'last_sync_direction' => 'outbound',
					'last_synced_at'      => current_time( 'mysql' ),
				)
			);
		}
		if ( false === $changed ) {
			throw new \RuntimeException( 'Source clock was not saved.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- read-back proves the clock just written
		$live = $wpdb->get_var( $wpdb->prepare(
			'SELECT vector_clock FROM %i WHERE local_object_type = %s AND local_object_id = %d ORDER BY vector_clock DESC LIMIT 1',
			$table,
			$object_type,
			$local_id
		) );
		if ( '' !== (string) $wpdb->last_error || ! is_numeric( $live ) || (int) $live < $next ) {
			throw new \RuntimeException( 'Source clock readback differs.' );
		}
		$lease->assert_owner();

		// generate_digest()/export_post() cache the clock for 300s — invalidate.
		wp_cache_delete( 'vclock_' . $local_id, 'wpmmcc' );
		return (int) $live;
	}

	/**
	 * Handle post deletion.
	 *
	 * Only entities this site already tracks are queued. WordPress fires
	 * delete_post for every revision it prunes and for posts that never had a
	 * save tracked; the old handler minted a canonical uuid, a mapping row and
	 * a never-shippable queue row for each of those.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post object.
	 * @return void
	 */
	public static function on_delete_post( int $post_id, $post = null ): void {
		if ( self::$depth > 0 ) {
			return;
		}

		$canonical_uuid = self::tracked_uuid_for_removal( $post_id, $post );
		if ( '' === $canonical_uuid ) {
			return;
		}

		// FL-14: advance the clock so trashing changes the fingerprint (clock is
		// hashed in) and reconcile verdicts source_newer → the client re-pulls the
		// entity and the trashed status propagates.
		self::upsert_source_clock( 'post', $post_id, $canonical_uuid );

		Push_Queue::enqueue(
			array(
				'event_type'      => 'delete',
				'canonical_uuid'  => $canonical_uuid,
				'object_type'     => 'post',
				'local_object_id' => $post_id,
				'payload_digest'  => '',
				'outcome'         => 'success',
			)
		);
	}

	/**
	 * Handle post trashed.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function on_trash_post( int $post_id ): void {
		if ( self::$depth > 0 ) {
			return;
		}

		$canonical_uuid = self::tracked_uuid_for_removal( $post_id );
		if ( '' === $canonical_uuid ) {
			return;
		}

		// FL-14: same clock advance on trash (status change must alter the
		// fingerprint or the digest reports the entity as unchanged).
		self::upsert_source_clock( 'post', $post_id, $canonical_uuid );

		Push_Queue::enqueue(
			array(
				'event_type'      => 'trash',
				'canonical_uuid'  => $canonical_uuid,
				'object_type'     => 'post',
				'local_object_id' => $post_id,
				'payload_digest'  => '',
				'outcome'         => 'success',
			)
		);
	}

	/**
	 * The canonical uuid of a post being trashed / deleted, or '' when that
	 * event must not be synced: an ignored post type (revisions, menu items ...),
	 * a post this site never tracked, or a conflict-review draft (its uuid is
	 * the entity's, so a tombstone for it would delete the PEER's real post —
	 * WMC-11). Never creates a uuid.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object when the hook supplied it.
	 * @return string
	 */
	private static function tracked_uuid_for_removal( int $post_id, $post = null ): string {
		if ( ! ( $post instanceof WP_Post ) ) {
			$post = get_post( $post_id );
		}
		if ( $post instanceof WP_Post && in_array( $post->post_type, self::IGNORED_POST_TYPES, true ) ) {
			return '';
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return '';
		}
		if ( Shadow_Draft::is_shadow( $post_id ) ) {
			return '';
		}
		return self::lookup_canonical_uuid( 'post', $post_id );
	}

	/**
	 * Write an entry directly to the wpmmcc_journal table.
	 *
	 * @param array $entry Journal record details.
	 * @return int Inserted ID or 0 on failure.
	 */
	public static function record_journal_entry( array $entry ): int {
		global $wpdb;

		$table = esc_sql( $wpdb->prefix . 'wpmmcc_journal' );

		$data = array(
			'occurred_at'        => current_time( 'mysql' ),
			'event_type'         => $entry['event_type'] ?? 'update',
			'direction'          => $entry['direction'] ?? 'outbound',
			'canonical_uuid'     => $entry['canonical_uuid'] ?? '',
			'object_type'        => $entry['object_type'] ?? 'post',
			'local_object_id'    => (int) ( $entry['local_object_id'] ?? 0 ),
			'peer_uuid'          => $entry['peer_uuid'] ?? '',
			'remote_object_id'   => (int) ( $entry['remote_object_id'] ?? 0 ),
			'packet_id'          => $entry['packet_id'] ?? '',
			'source_fingerprint' => $entry['source_fingerprint'] ?? '',
			'outcome'            => $entry['outcome'] ?? 'success',
			'duration_ms'        => (int) ( $entry['duration_ms'] ?? 0 ),
			'payload_digest'     => $entry['payload_digest'] ?? '',
			'diff_summary'       => isset( $entry['diff_summary'] ) ? wp_json_encode( $entry['diff_summary'] ) : null,
			'message'            => $entry['message'] ?? null,
			'consumed'           => (int) ( $entry['consumed'] ?? 0 ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table or connection command; WordPress has no replacement API
		$changed = $wpdb->insert( $wpdb->prefix . 'wpmmcc_journal', $data );
		if ( 1 !== $changed || (int) $wpdb->insert_id <= 0 ) {
			throw new \RuntimeException( 'Journal evidence was not saved.' );
		}
		$journal_generation = wp_cache_get( 'wpmmcc_journal_generation', 'wpmmcc' );
		wp_cache_set( 'wpmmcc_journal_generation', ( is_int( $journal_generation ) ? $journal_generation : 0 ) + 1, 'wpmmcc' );
		wp_cache_delete( 'admin_journal_recent', 'wpmmcc' );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Find the canonical UUID of a tracked entity without creating one.
	 *
	 * @param string $object_type Entity object type.
	 * @param int    $object_id Local object ID.
	 * @return string Canonical UUID, or '' when the entity is not tracked.
	 */
	public static function lookup_canonical_uuid( string $object_type, int $object_id ): string {
		global $wpdb;

		$cache_key = 'canonical_uuid_' . $object_type . '_' . $object_id;
		$uuid      = wp_cache_get( $cache_key, 'wpmmcc' );
		if ( false === $uuid || null === $uuid ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table or connection command; WordPress has no replacement API
			$uuid = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT canonical_uuid FROM {$wpdb->prefix}wpmmcc_cross_mappings WHERE local_object_type = %s AND local_object_id = %d LIMIT 1",
					$object_type,
					$object_id
				)
			);
			if ( $uuid ) {
				wp_cache_set( $cache_key, $uuid, 'wpmmcc', 3600 );
			}
		}

		if ( ! empty( $uuid ) ) {
			return (string) $uuid;
		}

		// Also check post meta if previously stored
		$meta_uuid = get_post_meta( $object_id, '_wpmmcc_canonical_uuid', true );
		if ( ! empty( $meta_uuid ) ) {
			wp_cache_set( $cache_key, (string) $meta_uuid, 'wpmmcc', 3600 );
			return (string) $meta_uuid;
		}

		return '';
	}

	/**
	 * Resolve canonical UUID from wpmmcc_cross_mappings or create new UUID.
	 *
	 * @param string $object_type Entity object type.
	 * @param int    $object_id Local object ID.
	 * @return string Canonical UUID.
	 */
	public static function resolve_or_create_canonical_uuid( string $object_type, int $object_id ): string {
		$uuid = self::lookup_canonical_uuid( $object_type, $object_id );
		if ( '' !== $uuid ) {
			return $uuid;
		}

		// Otherwise generate a new canonical UUID v4 and store in postmeta
		$new_uuid = wp_generate_uuid4();
		if ( ! Write_Policy::store_receiver_uuid( $object_id, $new_uuid ) ) {
			return '';
		}
		wp_cache_set( 'canonical_uuid_' . $object_type . '_' . $object_id, $new_uuid, 'wpmmcc', 3600 );
		return $new_uuid;
	}
}
