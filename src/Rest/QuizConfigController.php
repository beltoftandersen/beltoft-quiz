<?php

namespace Bgq\Rest;

use Bgq\Quiz\Config;
use Bgq\Quiz\PostType;

defined( 'ABSPATH' ) || exit;

/**
 * GET/POST /bgq/v1/quizzes/{id}/config — the builder's save/load endpoint.
 */
class QuizConfigController {

	public static function register() {
		register_rest_route(
			AttemptsController::NS,
			'/quizzes/(?P<id>\d+)/config',
			[
				[
					'methods'             => 'GET',
					'permission_callback' => [ __CLASS__, 'can_edit' ],
					'callback'            => [ __CLASS__, 'read' ],
				],
				[
					'methods'             => 'POST',
					'permission_callback' => [ __CLASS__, 'can_edit' ],
					'callback'            => [ __CLASS__, 'save' ],
				],
			]
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public static function can_edit( \WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$post = get_post( $id );
		if ( ! $post || PostType::TYPE !== $post->post_type ) {
			return new \WP_Error( 'bgq_not_found', __( 'Quiz not found.', 'beltoft-quiz' ), [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new \WP_Error( 'bgq_forbidden', __( 'You cannot edit this quiz.', 'beltoft-quiz' ), [ 'status' => 403 ] );
		}
		return true;
	}

	public static function read( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		return rest_ensure_response( self::payload( $id ) );
	}

	public static function save( \WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$body = $request->get_json_params();
		$raw  = is_array( $body ) ? $body : [];

		$config = Config::validate( $raw );
		if ( is_wp_error( $config ) ) {
			$config->add_data( [ 'status' => 400, 'errors' => $config->get_error_data()['errors'] ?? [] ] );
			return $config;
		}
		Config::save( $id, $config );

		$post_update = [ 'ID' => $id ];
		if ( isset( $raw['title'] ) ) {
			$post_update['post_title'] = sanitize_text_field( (string) $raw['title'] );
		}
		if ( isset( $raw['status'] ) && in_array( $raw['status'], [ 'publish', 'draft' ], true ) ) {
			// Publishing follows the post type's capability, like the classic editor.
			if ( 'draft' === $raw['status'] || current_user_can( 'publish_post', $id ) ) {
				$post_update['post_status'] = $raw['status'];
			}
		}
		if ( count( $post_update ) > 1 ) {
			wp_update_post( $post_update );
		}

		return rest_ensure_response( self::payload( $id ) );
	}

	/**
	 * Config plus title/status, as the builder consumes it.
	 */
	private static function payload( int $id ): array {
		$post              = get_post( $id );
		$payload           = Config::load( $id );
		$payload['title']  = $post ? $post->post_title : '';
		$payload['status'] = $post ? $post->post_status : 'draft';
		$payload['can_publish'] = current_user_can( 'publish_post', $id );
		return $payload;
	}
}
