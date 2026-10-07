<?php
/**
 * WPMMCC Conflict Arbitration Engine (WP-A-10, doc 12/ADR-6, doc 03 §2).
 *
 * Implements the canonical five-value conflict strategy vocabulary
 * (X-1, tasks/5.3falsh2/12 批 B): lww / source_wins / target_wins /
 * manual_review / merge — Last-Write-Wins, Source Wins, Target Wins,
 * Shadow Draft Review and Field-Ownership Merge (doc 03 §2.2) for
 * concurrent cross-site edits.
 *
 * @package WPMMCC\Sync
 */

namespace WPMMCC\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Arbiter
 */
class Arbiter {

	/**
	 * Normalize a conflict strategy to the canonical five-value vocabulary
	 * (X-1, tasks/5.3falsh2/12 批 B): lww / source_wins / target_wins /
	 * manual_review / merge. Historical vocabularies map onto their
	 * canonical twin; unknown values fall back to 'lww' (the historical
	 * default), so a stale peers row can never disable arbitration.
	 *
	 * @param string $raw Raw strategy from any historical vocabulary.
	 * @return string Canonical strategy.
	 */
	public static function normalize_strategy( string $raw ): string {
		$raw = strtolower( trim( (string) $raw ) );
		switch ( $raw ) {
			case 'source_wins':
			case 'origin_wins':
			case 'source_dominant':
				return 'source_wins';

			case 'target_wins':
			case 'target_dominant':
				return 'target_wins';

			case 'manual_review':
			case 'manual':
				return 'manual_review';

			case 'merge':
				return 'merge';

			case 'lww':
			case 'newest_wins':
			default:
				return 'lww';
		}
	}

	/**
	 * Evaluate potential conflict between incoming packet and local entity state.
	 *
	 * @param array       $packet Inbound Sync Packet.
	 * @param object|null $existing_mapping Existing row from wpmmcc_cross_mappings.
	 * @param \WP_Post|null $local_post Existing local post object if mapped.
	 * @param string      $peer_strategy Canonical conflict strategy on peer ('lww', 'source_wins', 'target_wins', 'manual_review', 'merge'; legacy synonyms are normalized by normalize_strategy()).
	 * @return array Verdict array: ['action' => 'overwrite'|'skip'|'shadow_draft'|'merge', 'reason' => string, 'local_drift' => bool]. 'shadow_draft' is also the verdict while a review is open ($local_post is the review draft).
	 */
	public static function arbitrate( array $packet, $existing_mapping, $local_post, string $peer_strategy = 'lww' ): array {
		$peer_strategy = self::normalize_strategy( $peer_strategy );

		// If entity has never been synced before, no conflict exists
		if ( ! $existing_mapping || ! $local_post ) {
			return array(
				'action'      => 'overwrite',
				'reason'      => 'initial_creation',
				'local_drift' => false,
			);
		}

		// The mapped post is a conflict-review draft, so the review is still
		// open. Every newer revision from the peer refreshes that draft, whatever
		// the peer's strategy says today: a later "clean" packet must not publish
		// the draft over the displaced local post and skip the human decision
		// (WMC-11).
		if ( Shadow_Draft::is_shadow( (int) ( $local_post->ID ?? 0 ) ) ) {
			return array(
				'action'      => 'shadow_draft',
				'reason'      => 'conflict_review_open',
				'local_drift' => true,
			);
		}

		$origin_uuid  = $packet['origin_context']['origin_site_uuid'] ?? '';
		$inbound_clk  = (int) ( $packet['origin_context']['vector_clock'][ $origin_uuid ] ?? 1 );
		$stored_clk   = (int) $existing_mapping->vector_clock;
		$last_synced  = strtotime( $existing_mapping->last_synced_at );
		$local_edited = strtotime( $local_post->post_modified_gmt . ' UTC' );

		// Check if local post was modified AFTER last sync time
		$has_local_drift = ( $local_edited > ( $last_synced + 2 ) ); // 2-second clock jitter margin

		// If no local edits occurred since last sync, inbound update is safe
		if ( ! $has_local_drift ) {
			return array(
				'action'      => 'overwrite',
				'reason'      => 'clean_inbound_progression',
				'local_drift' => false,
			);
		}

		// Conflict detected: Concurrent local edit exists
		switch ( $peer_strategy ) {
			case 'source_wins':
			case 'source_dominant':
				return array(
					'action'      => 'overwrite',
					'reason'      => 'source_dominant_policy',
					'local_drift' => true,
				);

			case 'target_wins':
			case 'target_dominant':
				return array(
					'action'      => 'skip',
					'reason'      => 'target_edits_preserved',
					'local_drift' => true,
				);

			case 'manual_review':
				return array(
					'action'      => 'shadow_draft',
					'reason'      => 'conflict_divergence_created_shadow_draft',
					'local_drift' => true,
				);

			case 'merge':
				// Field-ownership merge (doc 03 §2.2): the materializer takes
				// incoming values only for remote-writable fields the source
				// actually changed; local edits to other fields win (T-3.4).
				return array(
					'action'      => 'merge',
					'reason'      => 'field_ownership_merge',
					'local_drift' => true,
				);

			case 'lww':
			default:
				$inbound_time = (int) ( $packet['origin_context']['dispatch_timestamp'] ?? time() );
				if ( $inbound_time >= $local_edited || $inbound_clk > $stored_clk ) {
					return array(
						'action'      => 'overwrite',
						'reason'      => 'lww_source_newer',
						'local_drift' => true,
					);
				}
				return array(
					'action'      => 'skip',
					'reason'      => 'lww_target_newer',
					'local_drift' => true,
				);
		}
	}
}
