<?php

namespace Bgq\Rest;

use Bgq\Quiz\Config;
use Bgq\Quiz\Grader;
use Bgq\Quiz\PostType;
use Bgq\Frontend\Results;

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
		register_rest_route(
			AttemptsController::NS,
			'/quizzes/(?P<id>\d+)/catalog-targets',
			[
				'methods'             => 'GET',
				'permission_callback' => [ __CLASS__, 'can_edit' ],
				'callback'            => [ __CLASS__, 'catalog_targets' ],
			]
		);
	}

	/**
	 * Search WooCommerce categories, tags and global attribute terms for rules.
	 */
	public static function catalog_targets( \WP_REST_Request $request ) {
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		if ( strlen( $search ) < 2 ) {
			return rest_ensure_response( [] );
		}
		$out        = [];
		$taxonomies = [];
		foreach ( get_object_taxonomies( 'product', 'objects' ) as $taxonomy => $object ) {
			if ( ! in_array( $taxonomy, [ 'product_cat', 'product_tag' ], true ) && 0 !== strpos( $taxonomy, 'pa_' ) ) {
				continue;
			}
			$taxonomies[ $taxonomy ] = $object;
		}
		$terms = get_terms( [ 'taxonomy' => array_keys( $taxonomies ), 'hide_empty' => false, 'name__like' => $search, 'number' => 30 ] );
		if ( is_wp_error( $terms ) ) {
			return rest_ensure_response( [] );
		}
		foreach ( $terms as $term ) {
			$object = $taxonomies[ $term->taxonomy ] ?? null;
			if ( ! $object ) {
				continue;
			}
			$out[] = [
				'id'       => $term->taxonomy . ':' . $term->term_id,
				'taxonomy' => $term->taxonomy,
				'term_id'  => (int) $term->term_id,
				'label'    => sprintf( '%1$s: %2$s', $object->labels->singular_name, $term->name ),
			];
		}
		usort( $out, static fn( $a, $b ) => strcasecmp( $a['label'], $b['label'] ) );
		return rest_ensure_response( array_slice( $out, 0, 30 ) );
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
		if ( ! empty( $raw['validate_only'] ) ) {
			return rest_ensure_response( $config );
		}
		if ( isset( $raw['preview_answers'] ) && is_array( $raw['preview_answers'] ) ) {
			$grade    = Grader::grade( $config, $raw['preview_answers'] );
			$products = [];
			foreach ( $grade['product_ids'] as $product_id ) {
				$product = Results::product( (int) $product_id );
				if ( $product ) {
					$product['reason'] = implode( ', ', (array) ( $grade['product_reasons'][ $product['id'] ] ?? [] ) );
					$products[] = $product;
				}
			}
			$result_title = '';
			foreach ( $config['results'] as $result ) {
				if ( $result['id'] === $grade['result_id'] ) {
					$result_title = $result['title'];
					break;
				}
			}
			return rest_ensure_response(
				[
					'grade'        => $grade,
					'result_title' => $result_title,
					'products'     => $products,
				]
			);
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
