<?php

namespace Bgq\Rest;

use Bgq\Quiz\Attempts;
use Bgq\Quiz\Config;
use Bgq\Quiz\Grader;
use Bgq\Quiz\PostType;
use Bgq\Quiz\Token;

defined( 'ABSPATH' ) || exit;

/**
 * POST /bgq/v1/attempts — grade a submission and store the attempt.
 */
class AttemptsController {

	const NS         = 'bgq/v1';
	const RATE_LIMIT = 10;
	const RATE_WINDOW = 600;

	public static function register() {
		register_rest_route(
			self::NS,
			'/attempts',
			[
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => [ __CLASS__, 'submit' ],
				'args'                => [
					'quiz_id' => [ 'type' => 'integer', 'required' => true ],
					'token'   => [ 'type' => 'object', 'required' => true ],
					'answers' => [ 'type' => 'object', 'default' => [] ],
					'email'   => [ 'type' => 'string', 'default' => '' ],
					'name'    => [ 'type' => 'string', 'default' => '' ],
					'consent'    => [ 'type' => 'boolean', 'default' => false ],
					'newsletter' => [ 'type' => 'boolean', 'default' => false ],
				],
			]
		);

		register_rest_route(
			self::NS,
			'/quizzes/(?P<id>\d+)/token',
			[
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => [ __CLASS__, 'token' ],
			]
		);
	}

	/**
	 * Issue a start token at the moment the visitor presses Start (never cached with the page).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function token( \WP_REST_Request $request ) {
		$quiz_id = (int) $request['id'];
		$post    = get_post( $quiz_id );
		if ( ! $post || PostType::TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return new \WP_Error( 'bgq_not_found', __( 'This quiz is not available.', 'beltoft-quiz' ), [ 'status' => 404 ] );
		}
		$response = rest_ensure_response( Token::issue( $quiz_id ) );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function submit( \WP_REST_Request $request ) {
		$quiz_id = (int) $request['quiz_id'];
		$post    = get_post( $quiz_id );
		if ( ! $post || PostType::TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return new \WP_Error( 'bgq_not_found', __( 'This quiz is not available.', 'beltoft-quiz' ), [ 'status' => 404 ] );
		}

		$config = Config::load( $quiz_id );
		$token  = (array) $request['token'];
		$timer  = (int) $config['settings']['timer'];

		if ( Token::expired( $token, $timer ) && Token::verify( $quiz_id, $token, 0 ) ) {
			return new \WP_Error( 'bgq_expired', __( 'Time is up for this quiz. Please start again.', 'beltoft-quiz' ), [ 'status' => 410 ] );
		}
		if ( ! Token::verify( $quiz_id, $token, $timer ) ) {
			return new \WP_Error( 'bgq_bad_token', __( 'This quiz session is not valid. Please reload the page.', 'beltoft-quiz' ), [ 'status' => 403 ] );
		}

		$timed_out = $timer > 0 && time() - (int) ( $token['started_at'] ?? 0 ) >= $timer;
		$answers   = self::clean_answers( $config, (array) $request['answers'], $timed_out );
		if ( is_wp_error( $answers ) ) {
			return $answers;
		}

		$ip_hash = Attempts::ip_hash( Attempts::client_ip() );
		$rl_key  = 'bgq_rl_' . $ip_hash;
		$count   = (int) get_transient( $rl_key );
		if ( $count >= self::RATE_LIMIT ) {
			return new \WP_Error( 'bgq_rate_limited', __( 'Too many attempts. Please try again later.', 'beltoft-quiz' ), [ 'status' => 429 ] );
		}
		set_transient( $rl_key, $count + 1, self::RATE_WINDOW );

		// Duplicate submit of the same session by the same visitor (double click): return the stored attempt.
		$dup_key = 'bgq_dup_' . md5( $quiz_id . '|' . (string) $token['hash'] . '|' . $ip_hash . '|' . wp_json_encode( $answers ) );
		$dup_id  = (int) get_transient( $dup_key );
		if ( $dup_id && ( $dup = Attempts::get( $dup_id ) ) ) {
			return rest_ensure_response( self::response( $config, $dup, true ) );
		}

		$email = strtolower( sanitize_email( (string) $request['email'] ) );
		$name  = sanitize_text_field( wp_strip_all_tags( (string) $request['name'] ) );
		if ( ! empty( $config['settings']['require_email'] ) ) {
			if ( '' === $email || ! is_email( $email ) ) {
				return new \WP_Error( 'bgq_email_required', __( 'Please enter a valid email address.', 'beltoft-quiz' ), [ 'status' => 400 ] );
			}
			if ( '' !== trim( (string) $config['settings']['consent_text'] ) && ! $request['consent'] ) {
				return new \WP_Error( 'bgq_consent_required', __( 'Please accept the consent checkbox.', 'beltoft-quiz' ), [ 'status' => 400 ] );
			}
		}

		$user_id = get_current_user_id();
		if ( $user_id && '' === $email ) {
			$user  = wp_get_current_user();
			$email = strtolower( (string) $user->user_email );
		}

		if ( ! empty( $config['settings']['one_attempt'] ) ) {
			$existing = Attempts::find_existing( $quiz_id, $user_id, $ip_hash, $email );
			if ( $existing ) {
				return rest_ensure_response( self::response( $config, $existing, true ) );
			}
		}

		$grade   = Grader::grade( $config, $answers );

		$attempt_id = Attempts::insert(
			[
				'quiz_id'          => $quiz_id,
				'user_id'          => $user_id,
				'email'            => $email,
				'name'             => $name,
				'score'            => $grade['score'],
				'correct_count'    => $grade['correct_count'],
				'total_count'      => $grade['total_count'],
				'result_id'        => $grade['result_id'],
				'answers'          => wp_json_encode( $answers ),
				'recommendations'  => wp_json_encode(
					[
						'ids'     => array_map( 'intval', (array) $grade['product_ids'] ),
						'reasons' => (array) $grade['product_reasons'],
					]
				),
				'ip_hash'          => $ip_hash,
				'duration_seconds' => max( 0, time() - (int) $token['started_at'] ),
			]
		);
		if ( ! $attempt_id ) {
			return new \WP_Error( 'bgq_store_failed', __( 'Your answers could not be saved. Please try again.', 'beltoft-quiz' ), [ 'status' => 500 ] );
		}
		set_transient( $dup_key, $attempt_id, MINUTE_IN_SECONDS );

		$attempt = Attempts::get( $attempt_id );

		/**
		 * Fires once a new attempt is stored (not for duplicates or repeat visits).
		 *
		 * @param int              $attempt_id Attempt row ID.
		 * @param array            $config     Quiz config.
		 * @param object           $attempt    Attempt row.
		 * @param \WP_REST_Request $request    Submit request.
		 */
		do_action( 'bgq_attempt_created', $attempt_id, $config, $attempt, $request );

		/**
		 * Filter the reward for a completed attempt. Return [ 'code' => ..., 'amount' => ... ] or null.
		 *
		 * @param array|null $reward     Reward.
		 * @param int        $attempt_id Attempt row ID.
		 * @param array      $config     Quiz config.
		 * @param array      $grade      Grader output.
		 */
		$reward = apply_filters( 'bgq_attempt_reward', null, $attempt_id, $config, $grade );

		return rest_ensure_response( self::response( $config, $attempt, false, $reward ) );
	}

	/**
	 * Validate a complete submission and return canonical answer arrays.
	 *
	 * @return array|\WP_Error
	 */
	private static function clean_answers( array $config, array $raw, bool $allow_incomplete = false ) {
		$clean = [];
		$known_questions = array_column( $config['questions'], 'id' );
		foreach ( array_keys( $raw ) as $question_id ) {
			if ( ! in_array( (string) $question_id, $known_questions, true ) ) {
				return new \WP_Error( 'bgq_invalid_answers', __( 'Please answer every question using one of the available choices.', 'beltoft-quiz' ), [ 'status' => 400 ] );
			}
		}
		foreach ( $config['questions'] as $q ) {
			$submitted = isset( $raw[ $q['id'] ] ) ? array_values( array_map( 'sanitize_key', (array) $raw[ $q['id'] ] ) ) : [];
			$submitted = array_values( array_unique( array_filter( $submitted, 'strlen' ) ) );
			$known     = array_column( $q['answers'], 'id' );
			if ( ! $submitted && $allow_incomplete ) {
				continue;
			}
			if ( ! $submitted || array_diff( $submitted, $known ) || ( 'single' === $q['type'] && 1 !== count( $submitted ) ) ) {
				return new \WP_Error( 'bgq_invalid_answers', __( 'Please answer every question using one of the available choices.', 'beltoft-quiz' ), [ 'status' => 400 ] );
			}
			$clean[ $q['id'] ] = $submitted;
		}
		return $clean;
	}

	/**
	 * Response body for an attempt row.
	 */
	public static function response( array $config, $attempt, bool $already, $reward = null ): array {
		$result = null;
		foreach ( $config['results'] as $r ) {
			if ( $r['id'] === $attempt->result_id ) {
				$result = $r;
				break;
			}
		}
		/**
		 * Filter the rendered result payload (Frontend\Results replaces this with images/product data).
		 *
		 * @param array $rendered Result payload.
		 * @param array $config   Quiz config.
		 * @param array|null $result Result config.
		 */
		$rendered = apply_filters( 'bgq_render_result', $result ? [ 'id' => $result['id'], 'title' => $result['title'], 'text' => $result['text'] ?? '' ] : null, $config, $result );
		if ( is_array( $rendered ) && $result && array_key_exists( 'products', $rendered ) ) {
			// New attempts carry an immutable recommendation snapshot. Older rows
			// fall back to recomputing from their saved answers.
			$snapshot = json_decode( (string) ( $attempt->recommendations ?? '' ), true );
			if ( is_array( $snapshot ) && array_key_exists( 'ids', $snapshot ) ) {
				$details = [
					'ids'     => array_map( 'intval', (array) $snapshot['ids'] ),
					'reasons' => is_array( $snapshot['reasons'] ?? null ) ? $snapshot['reasons'] : [],
				];
			} else {
				$stored  = json_decode( (string) ( $attempt->answers ?? '' ), true );
				$details = \Bgq\Quiz\Grader::recommendation_details( $config, is_array( $stored ) ? $stored : [], (string) $result['id'] );
			}
			$ids      = $details['ids'];
			$products = array_values( array_filter( array_map( [ \Bgq\Frontend\Results::class, 'product' ], $ids ) ) );
			foreach ( $products as &$product ) {
				$product['reason'] = implode( ', ', (array) ( $details['reasons'][ $product['id'] ] ?? [] ) );
			}
			unset( $product );
			$rendered['products'] = $products;
			$rendered['product']  = $products ? $products[0] : null;
		}

		$passed = 'score' === $config['mode'] ? ( (float) $attempt->score >= (float) $config['settings']['pass_mark'] ) : null;

		return [
			'result'        => $rendered,
			'score'         => (float) $attempt->score,
			'correct_count' => (int) $attempt->correct_count,
			'total_count'   => (int) $attempt->total_count,
			'passed'        => $passed,
			'reward'        => $reward,
			'already'       => $already,
		];
	}
}
