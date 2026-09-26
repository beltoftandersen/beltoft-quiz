<?php

namespace Bgq\Quiz;

defined( 'ABSPATH' ) || exit;

class PostType {

	const TYPE = 'bgq_quiz';
	const META = '_bgq_config';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'init', [ __CLASS__, 'register' ] );
		add_action( 'before_delete_post', [ __CLASS__, 'delete_attempts' ], 10, 2 );
	}

	/**
	 * Remove a quiz's attempts when it is permanently deleted (personal data must not linger).
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post.
	 */
	public static function delete_attempts( $post_id, $post = null ) {
		$post = $post instanceof \WP_Post ? $post : get_post( $post_id );
		if ( ! $post || self::TYPE !== $post->post_type ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->delete( $wpdb->prefix . 'bgq_attempts', [ 'quiz_id' => (int) $post_id ], [ '%d' ] );
	}

	/**
	 * Register the quiz post type.
	 */
	public static function register() {
		register_post_type(
			self::TYPE,
			[
				'labels'          => [
					'name'          => __( 'Quizzes', 'beltoft-quiz' ),
					'singular_name' => __( 'Quiz', 'beltoft-quiz' ),
					'add_new_item'  => __( 'Add New Quiz', 'beltoft-quiz' ),
					'edit_item'     => __( 'Edit Quiz', 'beltoft-quiz' ),
					'not_found'     => __( 'No quizzes yet.', 'beltoft-quiz' ),
				],
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_icon'       => 'dashicons-editor-help',
				'supports'        => [ 'title' ],
				'show_in_rest'    => false,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			]
		);
	}
}
