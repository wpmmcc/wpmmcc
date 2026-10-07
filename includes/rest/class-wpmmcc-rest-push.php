<?php
/**
 * WPMMCC REST Push Controller (POST /wp-json/wpmmcc/v1/sync/push).
 *
 * Ingests inbound Sync Packets, applies conflict arbitration (LWW / Review Mode),
 * materializes entities, replicates hierarchical taxonomies, dispatches to 20 content
 * plugin adapters, and logs to the CDC journal within an anti-loop scope.
 *
 * @package WPMMCC\REST
 */

namespace WPMMCC\REST;

use WPMMCC\Sync\Packet;
use WPMMCC\Sync\Event_Recorder;
use WPMMCC\Sync\Rewriter;
use WPMMCC\Sync\Taxonomy_Syncer;
use WPMMCC\Sync\Arbiter;
use WPMMCC\Sync\Shadow_Draft;
use WPMMCC\Adapters\Adapter_Registry;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Rest_Push
 */
class Rest_Push extends Rest_Base {

	/**
	 * Most relays a packet may have been through (filter: wpmmcc_max_hop_count).
	 * The standalone client advances hop_count by one per relay, so a packet
	 * past this is circling, not travelling.
	 */
	const DEFAULT_MAX_HOP_COUNT = 8;

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/sync/push',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_push' ),
				'permission_callback' => array( 'WPMMCC\REST\Rest_Middleware', 'verify_hmac' ),
			)
		);
		register_rest_route( $this->namespace, '/sync/receipt', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'handle_receipt' ),
			'permission_callback' => array( Rest_Middleware::class, 'verify_hmac' ),
		) );
	}

	public function handle_receipt( WP_REST_Request $request ) {
		$packet = Packet::parse( (string) $request->get_body() );
		if ( is_wp_error( $packet ) ) {
			return $packet;
		}
		$result = \WPMMCC\Sync\Delivery_Receipt::status( Rest_Middleware::requesting_peer_uuid( $request ), $packet );
		return is_wp_error( $result ) ? $result : $this->format_success( $result, 200 );
	}

	/**
	 * Handle inbound Sync Packet push.
	 *
	 * @param WP_REST_Request $request Inbound request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_push( WP_REST_Request $request ) {
		global $wpdb;

		$start_time = microtime( true );
		$body_raw   = (string) $request->get_body();

		// 1. Strict parse with Zero Wire Unserialize policy
		$packet = Packet::parse( $body_raw );
		if ( is_wp_error( $packet ) ) {
			return $packet;
		}

		$packet_id          = $packet['packet_id'];
		$origin_context     = $packet['origin_context'];
		$origin_uuid        = is_string( $origin_context['origin_site_uuid'] ?? null ) ? $origin_context['origin_site_uuid'] : '';
		$source_fingerprint = $packet['source_fingerprint'];
		$action             = $packet['action'];
		$entity             = $packet['entity'];
		$guid               = $entity['guid'];
		$object_type        = $entity['object_type'];
		$source_id          = (int) $entity['source_id'];

		// A packet carries two identities. The HMAC envelope names the peer
		// that DELIVERED it (the only one the gate verified); the body names the
		// site it ORIGINATED on. On a direct push they are the same site. The
		// standalone client is a peer of its own and relays packets pulled from
		// a source site with the origin context kept and hop_count advanced, so
		// for a relay they differ by design. No envelope means an internal call
		// (the pull leg); the route's permission gate guarantees one otherwise.
		$sender_uuid = Rest_Middleware::requesting_peer_uuid( $request );
		$hop_count   = (int) ( $origin_context['hop_count'] ?? 1 );

		// WMC-04: a first-hand packet speaks for the peer that sent it. Left
		// unchecked, an authenticated peer could file its writes under another
		// peer's uuid and borrow that peer's conflict strategy, vector clock
		// and mapping rows. A relay (hop_count >= 2) keeps its logical origin.
		if ( '' !== $sender_uuid && $hop_count <= 1 && ! hash_equals( $sender_uuid, $origin_uuid ) ) {
			return $this->reject_inbound(
				'origin_peer_mismatch',
				new WP_Error(
					'wpmmcc_origin_peer_mismatch',
					sprintf( 'Packet claims origin %s but was delivered by peer %s; a first-hand packet must come from the peer that sends it.', $origin_uuid, $sender_uuid ),
					array( 'status' => 403 )
				),
				$packet,
				$sender_uuid
			);
		}

		// WMC-10: hop_count is the relay counter it always looked like and
		// nothing read. Past the limit the packet is circling; refuse it before
		// anything changes, for every action.
		$max_hops = max( 1, (int) apply_filters( 'wpmmcc_max_hop_count', self::DEFAULT_MAX_HOP_COUNT ) );
		if ( $hop_count > $max_hops ) {
			return $this->reject_inbound(
				'hop_limit_exceeded',
				new WP_Error(
					'wpmmcc_hop_limit_exceeded',
					sprintf( 'Packet has been relayed %d times (limit %d); refusing a probable relay loop.', $hop_count, $max_hops ),
					array( 'status' => 400 )
				),
				$packet,
				$origin_uuid
			);
		}

		// The conflict strategy chosen at pairing sits on the row of the peer
		// that did the handshake, which is the one that delivers (WMC-14): for a
		// relay that is the client, not the source site the body names.
		$strategy_peer = '' !== $sender_uuid ? $sender_uuid : $origin_uuid;
		$cache_key     = 'peer_strat_' . md5( $strategy_peer );
		$peer_strategy = wp_cache_get( $cache_key, 'wpmmcc' );
		if ( false === $peer_strategy ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom peers table query.
			$peer_strategy = $wpdb->get_var(
				$wpdb->prepare( "SELECT conflict_strategy FROM {$wpdb->prefix}wpmmcc_peers WHERE peer_uuid = %s LIMIT 1", $strategy_peer )
			) ?: 'lww';
			wp_cache_set( $cache_key, $peer_strategy, 'wpmmcc', 300 );
		}

		$lease = \WPMMCC\Sync\SQL_Lease::acquire( 'inbound:' . $guid );
		if ( is_wp_error( $lease ) ) {
			return $lease;
		}
		$authority = \WPMMCC\Sync\Delivery_Receipt::begin( '' !== $sender_uuid ? $sender_uuid : $origin_uuid, $packet );
		if ( is_wp_error( $authority ) ) {
			return $authority;
		}
		if ( isset( $authority['replay'] ) ) {
			return $this->format_success( $authority['replay'], 200 );
		}
		// The intent survives rollback or death. Only native transactional
		// tables on the original connection can commit an applied receipt.
		$transaction = null;
		try {
		$transaction = \WPMMCC\Sync\SQL_Transaction::begin( $lease, array(
			$wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->termmeta,
			$wpdb->term_taxonomy, $wpdb->term_relationships,
			$wpdb->prefix . 'wpmmcc_cross_mappings', $wpdb->prefix . 'wpmmcc_journal',
			$wpdb->prefix . 'wpmmcc_delivery_receipts',
		) );
		// 2. Anti-loop ingestion scope execution
		$result = Event_Recorder::run_in_ingestion_scope(
			function () use ( $packet, $entity, $action, $guid, $origin_uuid, $peer_strategy ) {
				return $this->materialize_entity( $packet, $entity, $action, $guid, $origin_uuid, $peer_strategy );
			}
		);

		$duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );

		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Original intent remains outside this transaction.
			$transaction->rollback();
			Event_Recorder::record_journal_entry(
				array(
					'event_type'         => $action,
					'direction'          => 'inbound',
					'canonical_uuid'     => $guid,
					'object_type'        => $object_type,
					'peer_uuid'          => $origin_uuid,
					'remote_object_id'   => $source_id,
					'packet_id'          => $packet_id,
					'source_fingerprint' => $source_fingerprint,
					'outcome'            => 'error',
					'duration_ms'        => $duration_ms,
					'message'            => $result->get_error_message(),
					'consumed'           => 1,
				)
			);
			return $result;
		}

		$status_outcome = $result['outcome'] ?? 'success';
		$transaction->assert_active();
		$local_id       = (int) ( $result['local_id'] ?? 0 );
		$permalink      = (string) ( $result['permalink'] ?? '' );
		$vector_clk     = (int) ( $origin_context['vector_clock'][ $origin_uuid ] ?? 1 );

		// 3. Update Cross Mappings on overwrite or initial creation
		if ( 'skipped' !== $status_outcome && $local_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom mapping table replace.
			$mapping_saved = $wpdb->replace(
				$wpdb->prefix . 'wpmmcc_cross_mappings',
				array(
					'canonical_uuid'      => $guid,
					'local_object_type'   => $object_type,
					'local_object_id'     => $local_id,
					'peer_uuid'           => $origin_uuid,
					'remote_object_id'    => $source_id,
					'remote_permalink'    => (string) ( $origin_context['origin_permalink'] ?? '' ),
					'remote_lang'         => (string) ( $origin_context['origin_lang'] ?? '' ),
					'local_lang'          => (string) ( $packet['target_lang'] ?? '' ),
					'transport_peer_uuid' => '' !== $sender_uuid ? $sender_uuid : $origin_uuid,
					'content_checksum'    => $source_fingerprint,
					'vector_clock'        => $vector_clk,
					'last_sync_direction' => 'inbound',
					'last_synced_at'      => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
			);
			if ( false === $mapping_saved ) {
				throw new \RuntimeException( 'Target mapping was not saved.' );
			}

			wp_cache_delete( 'mapping_' . md5( $guid . '_' . $origin_uuid ), 'wpmmcc' );
			wp_cache_delete( 'canonical_mapping_' . md5( $guid ), 'wpmmcc' );
			wp_cache_delete( 'canonical_uuid_' . $object_type . '_' . $local_id, 'wpmmcc' );
			wp_cache_delete( 'vclock_' . $local_id, 'wpmmcc' );
			wp_cache_delete( 'cross_site_alt_' . $local_id, 'wpmmcc' );
		}

		// 4. Record inbound outcome in journal
		$journal_id = Event_Recorder::record_journal_entry(
			array(
				'event_type'         => $action,
				'direction'          => 'inbound',
				'canonical_uuid'     => $guid,
				'object_type'        => $object_type,
				'local_object_id'    => $local_id,
				'peer_uuid'          => $origin_uuid,
				'remote_object_id'   => $source_id,
				'packet_id'          => $packet_id,
				'source_fingerprint' => $source_fingerprint,
				'outcome'            => $status_outcome,
				'duration_ms'        => $duration_ms,
				'message'            => $result['reason'] ?? null,
				'consumed'           => 1,
			)
		);
		if ( $journal_id <= 0 ) {
			throw new \RuntimeException( 'Target journal was not saved.' );
		}
		$receipt = array(
				'committed'       => true,
				'status'          => $status_outcome,
				'packet_id'       => $packet_id,
				'canonical_uuid'  => $guid,
				'origin_site_uuid' => $origin_uuid,
				'target_site_uuid' => (string) get_option( 'wpmmcc_origin_uuid', '' ),
				'target_lang'      => (string) ( $packet['target_lang'] ?? '' ),
				'target_id'       => $local_id,
				'target_url'      => $permalink,
				'fingerprint_ack' => $source_fingerprint,
				'reason'          => $result['reason'] ?? '',
			);
		$transaction->assert_active();
		if ( ! \WPMMCC\Sync\Delivery_Receipt::finish( $authority, $receipt ) ) {
			throw new \RuntimeException( 'Target receipt was not saved.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Only now can the caller acknowledge this packet.
		$transaction->commit();
		return $this->format_success( $receipt, 200 );
		} catch ( \Throwable $error ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Keep the original applying intent for reconciliation.
			if ( $transaction ) {
				$transaction->rollback();
			}
			return new WP_Error( 'wpmmcc_delivery_not_confirmed', 'Target completion was not confirmed; original intent retained.', array( 'status' => 503 ) );
		}
	}

	/**
	 * Refuse a packet before it reaches the materializer and leave an audit row.
	 *
	 * @param string   $event_type Journal event type, named after the reason.
	 * @param WP_Error $error      Error answered to the caller.
	 * @param array    $packet     Parsed packet.
	 * @param string   $peer_uuid  Peer the audit row is filed under.
	 * @return WP_Error The same error.
	 */
	private function reject_inbound( string $event_type, WP_Error $error, array $packet, string $peer_uuid ): WP_Error {
		Event_Recorder::record_journal_entry(
			array(
				'event_type'         => $event_type,
				'direction'          => 'inbound',
				'canonical_uuid'     => (string) ( $packet['entity']['guid'] ?? '' ),
				'object_type'        => (string) ( $packet['entity']['object_type'] ?? 'post' ),
				'peer_uuid'          => $peer_uuid,
				'remote_object_id'   => (int) ( $packet['entity']['source_id'] ?? 0 ),
				'packet_id'          => (string) ( $packet['packet_id'] ?? '' ),
				'source_fingerprint' => (string) ( $packet['source_fingerprint'] ?? '' ),
				'outcome'            => 'error',
				'message'            => $error->get_error_message(),
				'consumed'           => 1,
			)
		);
		return $error;
	}

	/**
	 * Materialize inbound post or entity.
	 *
	 * @param array  $packet Full packet.
	 * @param array  $entity Entity data.
	 * @param string $action upsert | delete | trash.
	 * @param string $guid Canonical UUID.
	 * @param string $origin_uuid Origin peer UUID.
	 * @param string $peer_strategy Configured conflict strategy.
	 * @return array|WP_Error
	 */
	private function materialize_entity( array $packet, array $entity, string $action, string $guid, string $origin_uuid, string $peer_strategy ) {
		global $wpdb;

		$object_type = $entity['object_type'] ?? 'post';
		if ( 'post' !== $object_type ) {
			return new WP_Error( 'wpmmcc_unsupported_object_type', 'Object type not yet implemented in v1', array( 'status' => 501 ) );
		}

		// The unique key is (uuid, peer): the drift verdict needs the row of the
		// peer whose packet this is (clock and last sync are per peer). A peer
		// that has not synced the entity yet is judged against the most recent
		// sync point, so the verdict never depends on storage order. Read
		// straight from the table: a cached row would outlive the writes of the
		// other paths that touch mappings.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom mapping table query.
		$existing_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wpmmcc_cross_mappings WHERE canonical_uuid = %s AND local_object_type = 'post' AND peer_uuid = %s LIMIT 1",
				$guid,
				$origin_uuid
			)
		);
		if ( ! $existing_row ) {
			$existing_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}wpmmcc_cross_mappings WHERE canonical_uuid = %s AND local_object_type = 'post' ORDER BY last_synced_at DESC LIMIT 1",
					$guid
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

		$existing_local_id = $existing_row ? (int) $existing_row->local_object_id : 0;
		$existing_post     = $existing_local_id > 0 ? get_post( $existing_local_id ) : null;

		// A mapping that still points at a conflict-review draft means the
		// review is open: the draft is refreshed below, not replaced (WMC-11).
		$review_open = $existing_post && Shadow_Draft::is_shadow( $existing_local_id );

		if ( 'trash' === $action ) {
			if ( $existing_local_id > 0 ) {
				$trashed = wp_trash_post( $existing_local_id );
				$after = get_post( $existing_local_id );
				if ( ( false === $trashed || null === $trashed ) && $after && 'trash' !== $after->post_status ) {
					return new WP_Error( 'wpmmcc_trash_not_confirmed', 'Target trash was not confirmed.', array( 'status' => 503 ) );
				}
			}
			return array( 'local_id' => $existing_local_id, 'permalink' => '', 'outcome' => 'success' );
		}

		if ( 'delete' === $action ) {
			if ( $existing_local_id > 0 ) {
				wp_delete_post( $existing_local_id, true );
				if ( get_post( $existing_local_id ) ) {
					return new WP_Error( 'wpmmcc_delete_not_confirmed', 'Target deletion was not confirmed.', array( 'status' => 503 ) );
				}
			}
			return array( 'local_id' => $existing_local_id, 'permalink' => '', 'outcome' => 'success' );
		}

		// Conflict Arbitration
		$verdict = Arbiter::arbitrate( $packet, $existing_row, $existing_post, $peer_strategy );
		if ( 'skip' === $verdict['action'] ) {
			return array(
				'local_id'  => $existing_local_id,
				'permalink' => get_permalink( $existing_local_id ),
				'outcome'   => 'skipped',
				'reason'    => $verdict['reason'],
			);
		}

		$core = $entity['core_fields'] ?? array();

		// URL rewriting: map origin site URL to local site URL
		$origin_site_url = $packet['origin_context']['origin_site_url'] ?? '';
		$local_site_url  = function_exists( 'get_site_url' ) ? get_site_url() : site_url();
		$url_map         = array();
		if ( ! empty( $origin_site_url ) && ! empty( $local_site_url ) ) {
			$url_map[ $origin_site_url ] = $local_site_url;
		}

		$title   = sanitize_text_field( $core['post_title'] ?? 'Untitled' );
		$content = Rewriter::rewrite_html_content( (string) ( $core['post_content'] ?? '' ), $url_map );
		$excerpt = sanitize_textarea_field( $core['post_excerpt'] ?? '' );
		$status  = sanitize_key( $entity['status'] ?? 'publish' );
		$slug    = sanitize_title( $entity['slug'] ?? '' );
		$subtype = sanitize_key( $entity['subtype'] ?? 'post' );
		$write_context = array(
			'delta_merge'      => 'merge' === $verdict['action'] && ! empty( $entity['changed_fields'] ),
			'changed_fields'   => array_map( 'strval', (array) ( $entity['changed_fields'] ?? array() ) ),
			'protected_fields' => (array) apply_filters( 'wpmmcc_merge_protected_fields', array( 'post_status' ), $guid, $origin_uuid ),
		);

		// Shadow Draft Review Mode (conflict detected with manual_review strategy)
		if ( 'shadow_draft' === $verdict['action'] ) {
			$title  = Shadow_Draft::prefixed_title( $title );
			$status = 'draft';
		}

		// Field-ownership merge (doc 03 §2.2, T-3.4): when a merge verdict is
		// reached with local drift, incoming values land ONLY on fields the
		// source declared as changed (and that are remote-writable); every
		// other field keeps the local post's value so concurrent local edits
		// survive. Without a delta declaration the packet is treated as full
		// (all remote-writable fields apply, subject to the protection filter).
		if ( 'merge' === $verdict['action'] && $existing_post ) {
			$changed_fields = array_map( 'strval', (array) ( $entity['changed_fields'] ?? array() ) );
			$protected      = (array) apply_filters( 'wpmmcc_merge_protected_fields', array( 'post_status' ), $guid, $origin_uuid );
			$has_delta      = ! empty( $changed_fields );

			$take_incoming = static function ( string $field ) use ( $changed_fields, $protected, $has_delta ): bool {
				if ( in_array( $field, $protected, true ) ) {
					return false; // Field ownership: remote may never write protected fields.
				}
				if ( $has_delta ) {
					return in_array( $field, $changed_fields, true ); // Delta merge: only declared changes land.
				}
				return true; // Full packet, unprotected field: incoming applies.
			};

			if ( ! $take_incoming( 'post_title' ) ) {
				$title = (string) $existing_post->post_title;
			}
			if ( ! $take_incoming( 'post_content' ) ) {
				$content = (string) $existing_post->post_content;
			}
			if ( ! $take_incoming( 'post_excerpt' ) ) {
				$excerpt = (string) $existing_post->post_excerpt;
			}
			if ( ! $take_incoming( 'post_name' ) ) {
				$slug = (string) $existing_post->post_name;
			}
			// Local status is always preserved on merge (publish state is a
			// local editorial decision; a remote delete/trash uses its own action).
			$status = (string) $existing_post->post_status;
		}

		$post_data = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_excerpt' => $excerpt,
			'post_status'  => $status,
			'post_name'    => $slug,
			'post_type'    => $subtype,
		);

		// A fresh conflict stages a NEW draft next to the local post (which is
		// never touched); a newer revision of an open review rewrites that draft.
		if ( 'shadow_draft' === $verdict['action'] ) {
			$update_id = $review_open ? $existing_local_id : 0;
		} else {
			$update_id = $existing_local_id > 0 && $existing_post ? $existing_local_id : 0;
		}

		if ( $update_id > 0 ) {
			$post_data['ID'] = $update_id;
			$saved_id        = wp_update_post( $post_data, true );
		} else {
			$saved_id = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $saved_id ) ) {
			return $saved_id;
		}

		// Save canonical UUID in post meta
		update_post_meta( $saved_id, '_wpmmcc_canonical_uuid', $guid );

		if ( 'shadow_draft' === $verdict['action'] ) {
			update_post_meta( $saved_id, Shadow_Draft::META_FLAG, 1 );
			update_post_meta( $saved_id, Shadow_Draft::META_ORIGIN, $origin_uuid );
			// 3.8flash B3: record the displaced local counterpart so the
			// Conflict Review surface can render a real diff and Keep Local
			// can re-point the mapping onto it. Legacy shadows (created before
			// this line) simply have no counterpart recorded — the review
			// surface degrades honestly to Accept-only. A refresh keeps the
			// counterpart already recorded: the mapping now points at the draft
			// itself, so $existing_local_id is no longer the displaced post.
			if ( ! $review_open ) {
				update_post_meta( $saved_id, Shadow_Draft::META_LOCAL_ID, $existing_local_id );
			}
		}

		// Apply basic meta fields safely. Under a delta merge, only meta keys
		// the source declared as changed are written (field ownership). Keys
		// that hold this site's own identity / review state are never taken
		// from the wire.
		if ( ! empty( $entity['meta_fields'] ) && is_array( $entity['meta_fields'] ) ) {
			$meta_delta = 'merge' === $verdict['action'] && ! empty( $entity['changed_fields'] );
			foreach ( $entity['meta_fields'] as $meta_key => $meta_val ) {
				if ( ! \WPMMCC\Sync\Write_Policy::allows_meta( (string) $meta_key, $write_context ) ) {
					continue;
				}
				if ( $meta_delta && ! in_array( (string) $meta_key, array_map( 'strval', (array) $entity['changed_fields'] ), true ) ) {
					continue;
				}
				if ( is_array( $meta_val ) && isset( $meta_val['_wpmmcc_kind'] ) && 'php_serialized' === $meta_val['_wpmmcc_kind'] ) {
					update_post_meta( $saved_id, $meta_key, wp_slash( $meta_val['data'] ?? array() ) );
				} else {
					update_post_meta( $saved_id, $meta_key, wp_slash( $meta_val ) );
				}
			}
		}

		// Replicate Taxonomies (Categories, Tags, Custom Taxonomies)
		if ( ! empty( $entity['taxonomies'] ) && is_array( $entity['taxonomies'] ) ) {
			$taxonomies = array_filter(
				$entity['taxonomies'],
				static function ( $taxonomy ) use ( $write_context ): bool {
					return \WPMMCC\Sync\Write_Policy::allows_field( (string) $taxonomy, $write_context );
				},
				ARRAY_FILTER_USE_KEY
			);
			Taxonomy_Syncer::sync_post_taxonomies( $saved_id, $taxonomies, $origin_uuid );
		}

		// Dispatch to 20 Content Plugin Adapters
		$adapter = Adapter_Registry::get_for_post_type( $subtype );
		if ( $adapter && ! empty( $entity['plugin_specific'] ) ) {
			$imported = Adapter_Registry::import_payload(
				$adapter,
				$saved_id,
				$entity['plugin_specific'],
				array_merge( $write_context, array(
					'url_map'   => $url_map,
					'peer_uuid' => $origin_uuid,
					'guid'      => $guid,
				) )
			);
			if ( ! $imported ) {
				return new WP_Error( 'wpmmcc_adapter_import_failed', 'Content adapter import failed.', array( 'status' => 500 ) );
			}
		}

		$permalink = (string) get_permalink( $saved_id );
		return array(
			'local_id'  => $saved_id,
			'permalink' => $permalink,
			'outcome'   => 'shadow_draft' === $verdict['action'] ? 'conflict' : 'success',
			'reason'    => $verdict['reason'],
		);
	}
}
