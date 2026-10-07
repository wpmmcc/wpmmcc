<?php
/**
 * WPMMCC LMS Adapter (WP-A-13, doc 05 §2.2).
 *
 * Implements bidirectional synchronization for online learning management systems:
 * Tutor LMS, LearnPress, and LifterLMS.
 * Handles 4-tier hierarchical course outline trees (Course -> Topic -> Lesson/Quiz).
 *
 * @package WPMMCC\Adapters
 */

namespace WPMMCC\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Adapter_LMS
 */
class Adapter_LMS implements Adapter_Interface {

	/**
	 * Adapter slug.
	 */
	public function get_slug(): string {
		return 'lms';
	}

	/**
	 * Supported post types across Tutor, LearnPress, and LifterLMS.
	 */
	public function get_supported_post_types(): array {
		return array(
			// Tutor LMS
			'courses',
			'lesson',
			'tutor_quiz',
			'tutor_announcements',
			// LearnPress
			'lp_course',
			'lp_lesson',
			'lp_quiz',
			// LifterLMS
			'course',
			'llms_quiz',
		);
	}

	/**
	 * Check if any LMS plugin is active.
	 */
	public function is_plugin_active(): bool {
		return function_exists( 'tutor' ) || class_exists( 'LearnPress' ) || class_exists( 'LifterLMS' );
	}

	/**
	 * Export course outline hierarchy and curriculum trees.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @return array
	 */
	public function export_custom_payload( int $post_id, $post ): array {
		$payload = array(
			'course_level'        => (string) get_post_meta( $post_id, '_tutor_course_level', true ),
			'course_duration'     => get_post_meta( $post_id, '_course_duration', true ),
			'video_source'        => get_post_meta( $post_id, '_video', true ),
			'course_benefits'     => get_post_meta( $post_id, '_tutor_course_benefits', true ),
			'course_requirements' => get_post_meta( $post_id, '_tutor_course_requirements', true ),
			'topics'              => array(),
		);

		// If this is a root course, export its child topics and lessons
		if ( in_array( $post->post_type, array( 'courses', 'lp_course', 'course' ), true ) ) {
			$topics = get_posts(
				array(
					'post_type'   => 'topics',
					'post_parent' => $post_id,
					'numberposts' => -1,
					'orderby'     => 'menu_order',
					'order'       => 'ASC',
				)
			);

			foreach ( $topics as $topic ) {
				$topic_data = array(
					'title'      => $topic->post_title,
					'summary'    => $topic->post_content,
					'menu_order' => $topic->menu_order,
					'lessons'    => array(),
				);

				$lessons = get_posts(
					array(
						'post_type'   => array( 'lesson', 'tutor_quiz' ),
						'post_parent' => $topic->ID,
						'numberposts' => -1,
						'orderby'     => 'menu_order',
						'order'       => 'ASC',
					)
				);

				foreach ( $lessons as $lesson ) {
					$topic_data['lessons'][] = array(
						'title'       => $lesson->post_title,
						'content'     => $lesson->post_content,
						'post_type'   => $lesson->post_type,
						'menu_order'  => $lesson->menu_order,
						'video_meta'  => get_post_meta( $lesson->ID, '_video', true ),
						'meta_fields' => get_post_meta( $lesson->ID ),
					);
				}

				$payload['topics'][] = $topic_data;
			}
		}

		return $payload;
	}

	/**
	 * Import and reconstruct LMS course curriculum tree using topological ordering.
	 *
	 * @param int   $target_post_id Target course ID.
	 * @param array $payload Exported course payload.
	 * @param array $context Context.
	 * @return bool
	 */
	public function import_custom_payload( int $target_post_id, array $payload, array $context = array() ): bool {
		// 1. Update course metadata
		if ( isset( $payload['course_level'] ) ) {
			update_post_meta( $target_post_id, '_tutor_course_level', sanitize_text_field( $payload['course_level'] ) );
		}
		if ( isset( $payload['course_duration'] ) ) {
			update_post_meta( $target_post_id, '_course_duration', $payload['course_duration'] );
		}
		if ( isset( $payload['video_source'] ) ) {
			update_post_meta( $target_post_id, '_video', $payload['video_source'] );
		}

		// 2. Topological reconstruction of Topics and Lessons
		if ( ! empty( $payload['topics'] ) && is_array( $payload['topics'] ) ) {
			foreach ( $payload['topics'] as $topic_index => $topic_data ) {
				$topic_title = sanitize_text_field( $topic_data['title'] ?? 'Topic ' . ( $topic_index + 1 ) );

				// Check existing topic by parent and title
				$existing_topics = get_posts(
					array(
						'post_type'   => 'topics',
						'post_parent' => $target_post_id,
						'title'       => $topic_title,
						'numberposts' => 1,
					)
				);

				$topic_post_data = array(
					'post_title'   => $topic_title,
					'post_content' => sanitize_textarea_field( $topic_data['summary'] ?? '' ),
					'post_status'  => 'publish',
					'post_type'    => 'topics',
					'post_parent'  => $target_post_id,
					'menu_order'   => (int) ( $topic_data['menu_order'] ?? $topic_index ),
				);

				if ( ! empty( $existing_topics ) ) {
					$topic_post_data['ID'] = $existing_topics[0]->ID;
					$topic_id = wp_update_post( $topic_post_data );
				} else {
					$topic_id = wp_insert_post( $topic_post_data );
				}

				if ( is_wp_error( $topic_id ) || $topic_id <= 0 ) {
					continue;
				}

				update_post_meta( $topic_id, '_tutor_course_id_for_topic', $target_post_id );

				// 3. Reconstruct Lessons within this Topic
				if ( ! empty( $topic_data['lessons'] ) && is_array( $topic_data['lessons'] ) ) {
					foreach ( $topic_data['lessons'] as $lesson_index => $lesson_data ) {
						$lesson_title = sanitize_text_field( $lesson_data['title'] ?? 'Lesson ' . ( $lesson_index + 1 ) );
						$lesson_type  = sanitize_key( $lesson_data['post_type'] ?? 'lesson' );

						$existing_lessons = get_posts(
							array(
								'post_type'   => $lesson_type,
								'post_parent' => $topic_id,
								'title'       => $lesson_title,
								'numberposts' => 1,
							)
						);

						$lesson_post_data = array(
							'post_title'   => $lesson_title,
							'post_content' => (string) ( $lesson_data['content'] ?? '' ),
							'post_status'  => 'publish',
							'post_type'    => $lesson_type,
							'post_parent'  => $topic_id,
							'menu_order'   => (int) ( $lesson_data['menu_order'] ?? $lesson_index ),
						);

						if ( ! empty( $existing_lessons ) ) {
							$lesson_post_data['ID'] = $existing_lessons[0]->ID;
							$lesson_id = wp_update_post( $lesson_post_data );
						} else {
							$lesson_id = wp_insert_post( $lesson_post_data );
						}

						if ( ! is_wp_error( $lesson_id ) && $lesson_id > 0 ) {
							update_post_meta( $lesson_id, '_tutor_course_id_for_lesson', $target_post_id );
							if ( ! empty( $lesson_data['video_meta'] ) ) {
								update_post_meta( $lesson_id, '_video', $lesson_data['video_meta'] );
							}
						}
					}
				}
			}
		}

		return true;
	}

	/**
	 * Translatable fields for LMS courses and lessons.
	 */
	public function get_translatable_fields( string $post_type ): array {
		return array(
			'post_title',
			'post_content',
			'_tutor_course_benefits',
			'_tutor_course_requirements',
			'_tutor_course_target_audience',
			'_tutor_course_material_includes',
		);
	}
}
