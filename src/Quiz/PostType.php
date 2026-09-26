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
