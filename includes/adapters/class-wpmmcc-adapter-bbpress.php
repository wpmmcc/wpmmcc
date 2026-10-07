<?php
/**
 * WPMMCC bbPress Adapter (WP-A-13, doc 05 §1).
 *
 * Implements bidirectional synchronization for forums, topics, and replies,
 * maintaining the Forum -> Topic -> Reply 3-level tree hierarchy and recount counters.
 *
 * @package WPMMCC\Adapters
 */

namespace WPMMCC\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Adapter_Bbpress
 */
class Adapter_Bbpress implements Adapter_Interface {

	/**
	 * Adapter slug.
	 */
	public function get_slug(): string {
		return 'bbpress';
	}

	/**
	 * Supported post types.
	 */
	public function get_supported_post_types(): array {
		return array( 'forum', 'topic', 'reply' );
	}

	/**
	 * Check if bbPress is active.
	 */
	public function is_plugin_active(): bool {
		return class_exists( 'bbPress' );
	}

	/**
	 * Export bbPress entity payload.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @return array
	 */
	public function export_custom_payload( int $post_id, $post ): array {
		$payload = array(
			'bbp_type'         => $post->post_type,
			'forum_id'         => (int) get_post_meta( $post_id, '_bbp_forum_id', true ),
			'topic_id'         => (int) get_post_meta( $post_id, '_bbp_topic_id', true ),
			'reply_count'      => (int) get_post_meta( $post_id, '_bbp_reply_count', true ),
			'topic_count'      => (int) get_post_meta( $post_id, '_bbp_topic_count', true ),
			'last_active_time' => (string) get_post_meta( $post_id, '_bbp_last_active_time', true ),
			'author_ip'        => (string) get_post_meta( $post_id, '_bbp_author_ip', true ),
		);

		// If exporting a topic, export its replies
		if ( 'topic' === $post->post_type ) {
			$replies = get_posts(
				array(
					'post_type'   => 'reply',
					'post_parent' => $post_id,
					'numberposts' => -1,
					'orderby'     => 'date',
					'order'       => 'ASC',
				)
			);

			$payload['replies'] = array();
			foreach ( $replies as $reply ) {
				$payload['replies'][] = array(
					'title'       => $reply->post_title,
					'content'     => $reply->post_content,
					'author_name' => get_the_author_meta( 'display_name', $reply->post_author ),
					'date'        => $reply->post_date,
					'meta_fields' => get_post_meta( $reply->ID ),
				);
			}
		}

		return $payload;
	}

	/**
	 * Import and materialize bbPress entity.
	 *
	 * @param int   $target_post_id Target post ID.
	 * @param array $payload Exported payload.
	 * @param array $context Synchronization context.
	 * @return bool
	 */
	public function import_custom_payload( int $target_post_id, array $payload, array $context = array() ): bool {
		$type = $payload['bbp_type'] ?? 'topic';

		if ( 'topic' === $type && ! empty( $payload['replies'] ) ) {
			foreach ( $payload['replies'] as $reply_data ) {
				$reply_post = array(
					'post_title'   => sanitize_text_field( $reply_data['title'] ?? 'Reply' ),
					'post_content' => (string) ( $reply_data['content'] ?? '' ),
					'post_status'  => 'publish',
					'post_type'    => 'reply',
					'post_parent'  => $target_post_id,
					'post_date'    => $reply_data['date'] ?? current_time( 'mysql' ),
				);

				$reply_id = wp_insert_post( $reply_post );
				if ( ! is_wp_error( $reply_id ) && $reply_id > 0 ) {
					update_post_meta( $reply_id, '_bbp_topic_id', $target_post_id );
				}
			}

			// Recompute topic reply count
			$count = count( $payload['replies'] );
			update_post_meta( $target_post_id, '_bbp_reply_count', $count );
			update_post_meta( $target_post_id, '_bbp_total_reply_count', $count );

			if ( function_exists( 'bbp_update_topic_reply_count' ) ) {
				bbp_update_topic_reply_count( $target_post_id );
			}
		}

		return true;
	}

	/**
	 * Translatable fields for bbPress.
	 */
	public function get_translatable_fields( string $post_type ): array {
		return array(
			'post_title',
			'post_content',
		);
	}
}
