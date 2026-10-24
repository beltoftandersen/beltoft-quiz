<?php

namespace Bgq\Quiz;

defined( 'ABSPATH' ) || exit;

/**
 * The quiz configuration: defaults, validation, storage and the public (browser) view.
 */
class Config {

	const MODES = [ 'score', 'outcome', 'product' ];
	const TYPES = [ 'single', 'multiple' ];
	const PRODUCT_RECOMMENDATIONS = [ 'result_products', 'answer_matches', 'product_rules' ];
	const REWARD_CONDITIONS = [ 'always', 'pass', 'outcome' ];
	const MAX_PRODUCTS      = 12;

	/**
	 * Product ids from raw input: positive integers, each once, in order, at most MAX_PRODUCTS.
	 *
	 * @param mixed $raw List of ids.
	 * @return int[]
	 */
	public static function product_ids( $raw ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', is_array( $raw ) ? $raw : [] ) ) ) );
		return array_slice( $ids, 0, self::MAX_PRODUCTS );
	}

	/**
	 * Default configuration.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return [
			'mode'      => 'score',
			'settings'  => [
				'timer'             => 0,
				'shuffle_questions' => false,
				'shuffle_answers'   => false,
				'one_attempt'       => false,
				'require_email'     => false,
				'consent_text'      => '',
				'accent'            => '#1f4a36',
				'pass_mark'         => 50,
				'max_products'      => 3,
				'product_recommendation' => 'result_products',
				'labels'            => self::default_labels(),
			],
			'questions' => [],
			'results'   => [],
			'reward'    => [
				'enabled'     => false,
				'amount'      => 10,
				'expiry_days' => 365,
				'condition'   => 'always',
				'outcome_ids' => [],
			],
		];
	}

	/**
	 * Default button labels (translated at load time).
	 *
	 * @return array<string,string>
	 */
	public static function default_labels(): array {
		return [
			'start'  => __( 'Start', 'beltoft-quiz' ),
			'next'   => __( 'Next', 'beltoft-quiz' ),
			'back'   => __( 'Back', 'beltoft-quiz' ),
			'submit' => __( 'See result', 'beltoft-quiz' ),
			'retry'  => __( 'Try again', 'beltoft-quiz' ),
		];
	}

	/**
	 * Generate a short id like q_ab12cd.
	 */
	public static function new_id( string $prefix ): string {
		return $prefix . '_' . substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 6 );
	}

	/**
	 * Validate and sanitize a raw config.
	 *
	 * @param array $raw Raw config (from the builder or an import).
	 * @return array|\WP_Error Sanitized config, or WP_Error with data['errors'] keyed by field path.
	 */
	public static function validate( array $raw ) {
		$errors = [];
		$out    = self::defaults();

		$mode = isset( $raw['mode'] ) ? sanitize_key( (string) $raw['mode'] ) : '';
		if ( ! in_array( $mode, self::MODES, true ) ) {
			$errors['mode'] = __( 'Choose a quiz mode.', 'beltoft-quiz' );
			$mode           = 'score';
		}
		$out['mode'] = $mode;

		$out['settings'] = self::sanitize_settings( is_array( $raw['settings'] ?? null ) ? $raw['settings'] : [] );

		$results        = is_array( $raw['results'] ?? null ) ? array_values( $raw['results'] ) : [];
		$out['results'] = self::sanitize_results( $results, $mode, $errors );
		if ( empty( $out['results'] ) ) {
			$errors['results'] = __( 'Add at least one result.', 'beltoft-quiz' );
		}
		$result_ids = array_column( $out['results'], 'id' );

		$questions        = is_array( $raw['questions'] ?? null ) ? array_values( $raw['questions'] ) : [];
		$out['questions'] = self::sanitize_questions( $questions, $mode, $result_ids, $errors );
		if ( empty( $out['questions'] ) ) {
			$errors['questions'] = __( 'Add at least one question.', 'beltoft-quiz' );
		}
		if ( ! in_array( $out['settings']['product_recommendation'], self::PRODUCT_RECOMMENDATIONS, true ) ) {
			$out['settings']['product_recommendation'] = self::infer_product_recommendation( $out['questions'] );
		}
		if ( 'product' === $mode ) {
			$out['settings']['product_recommendation'] = 'product_rules';
			if ( 1 !== count( $out['results'] ) ) {
				$errors['results'] = __( 'Product Finder needs exactly one result screen.', 'beltoft-quiz' );
			}
			if ( ! self::has_product_source( $out['questions'], $out['results'] ) ) {
				$errors['product_rules'] = __( 'Add at least one boost rule or fallback product.', 'beltoft-quiz' );
			}
		}

		$out['reward'] = self::sanitize_reward( is_array( $raw['reward'] ?? null ) ? $raw['reward'] : [], $result_ids, $errors );

		if ( ! empty( $errors ) ) {
			return new \WP_Error( 'bgq_invalid_config', __( 'The quiz has errors.', 'beltoft-quiz' ), [ 'errors' => $errors ] );
		}

		return $out;
	}

	/**
	 * Settings block.
	 */
	private static function sanitize_settings( array $s ): array {
		$d = self::defaults()['settings'];

		$accent = isset( $s['accent'] ) ? sanitize_hex_color( (string) $s['accent'] ) : '';
		if ( $accent && 4 === strlen( $accent ) ) {
			$accent = '#' . $accent[1] . $accent[1] . $accent[2] . $accent[2] . $accent[3] . $accent[3];
		}

		// Only custom labels are stored; empty means "use the translated default" at load time.
		$labels = array_fill_keys( array_keys( $d['labels'] ), '' );
		if ( is_array( $s['labels'] ?? null ) ) {
			foreach ( $labels as $key => $unused ) {
				$val = isset( $s['labels'][ $key ] ) ? sanitize_text_field( wp_strip_all_tags( (string) $s['labels'][ $key ] ) ) : '';
				if ( '' !== $val && $val !== $d['labels'][ $key ] ) {
					$labels[ $key ] = $val;
				}
			}
		}

		$recommendation = isset( $s['product_recommendation'] ) ? sanitize_key( (string) $s['product_recommendation'] ) : '';

		return [
			'timer'             => isset( $s['timer'] ) ? min( 86400, absint( $s['timer'] ) ) : 0,
			'shuffle_questions' => ! empty( $s['shuffle_questions'] ),
			'shuffle_answers'   => ! empty( $s['shuffle_answers'] ),
			'one_attempt'       => ! empty( $s['one_attempt'] ),
			'require_email'     => ! empty( $s['require_email'] ),
			'consent_text'      => isset( $s['consent_text'] ) ? wp_kses( (string) $s['consent_text'], [ 'a' => [ 'href' => [], 'target' => [] ] ] ) : '',
			'accent'            => $accent ? strtolower( $accent ) : $d['accent'],
			'pass_mark'         => isset( $s['pass_mark'] ) ? min( 100, absint( $s['pass_mark'] ) ) : $d['pass_mark'],
			'max_products'      => isset( $s['max_products'] ) ? max( 1, min( self::MAX_PRODUCTS, absint( $s['max_products'] ) ) ) : $d['max_products'],
			'product_recommendation' => in_array( $recommendation, self::PRODUCT_RECOMMENDATIONS, true ) ? $recommendation : '',
			'labels'            => $labels,
		];
	}

	/**
	 * Results block.
	 */
	private static function sanitize_results( array $results, string $mode, array &$errors ): array {
		$out  = [];
		$seen = [];
		foreach ( $results as $i => $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$id = isset( $r['id'] ) ? sanitize_key( (string) $r['id'] ) : '';
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				$id = self::new_id( 'r' );
			}
			$seen[ $id ] = true;

			$item = [
				'id'           => $id,
				'title'        => isset( $r['title'] ) ? sanitize_text_field( (string) $r['title'] ) : '',
				'text'         => isset( $r['text'] ) ? wp_kses_post( (string) $r['text'] ) : '',
				'image_id'     => isset( $r['image_id'] ) ? absint( $r['image_id'] ) : 0,
				'button_label' => isset( $r['button_label'] ) ? sanitize_text_field( (string) $r['button_label'] ) : '',
				'button_url'   => isset( $r['button_url'] ) ? esc_url_raw( (string) $r['button_url'] ) : '',
				'product_id'   => isset( $r['product_id'] ) ? absint( $r['product_id'] ) : 0,
			];
			// Several products per result; an older single product_id becomes the first of them. product_id stays the first, for older readers.
			$ids = self::product_ids( $r['product_ids'] ?? [] );
			if ( $item['product_id'] && ! in_array( $item['product_id'], $ids, true ) ) {
				array_unshift( $ids, $item['product_id'] );
				$ids = array_slice( $ids, 0, self::MAX_PRODUCTS );
			}
			$item['product_ids'] = $ids;
			$item['product_id']  = $ids ? $ids[0] : 0;
			if ( 'score' === $mode ) {
				$item['min'] = isset( $r['min'] ) ? min( 100, absint( $r['min'] ) ) : 0;
				$item['max'] = isset( $r['max'] ) ? min( 100, absint( $r['max'] ) ) : 100;
				if ( $item['min'] > $item['max'] ) {
					$errors[ "results.$i.range" ] = __( 'The minimum score must not exceed the maximum.', 'beltoft-quiz' );
				}
			}
			if ( '' === $item['title'] ) {
				$errors[ "results.$i.title" ] = __( 'Give the result a title.', 'beltoft-quiz' );
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Questions block.
	 */
	private static function sanitize_questions( array $questions, string $mode, array $result_ids, array &$errors ): array {
		$out  = [];
		$seen = [];
		foreach ( $questions as $qi => $q ) {
			if ( ! is_array( $q ) ) {
				continue;
			}
			$qid = isset( $q['id'] ) ? sanitize_key( (string) $q['id'] ) : '';
			if ( '' === $qid || isset( $seen[ $qid ] ) ) {
				$qid = self::new_id( 'q' );
			}
			$seen[ $qid ] = true;

			$type = isset( $q['type'] ) ? sanitize_key( (string) $q['type'] ) : 'single';
			if ( ! in_array( $type, self::TYPES, true ) ) {
				$type = 'single';
			}

			$answers   = [];
			$seen_a    = [];
			$n_correct = 0;
			foreach ( ( is_array( $q['answers'] ?? null ) ? array_values( $q['answers'] ) : [] ) as $a ) {
				if ( ! is_array( $a ) ) {
					continue;
				}
				$aid = isset( $a['id'] ) ? sanitize_key( (string) $a['id'] ) : '';
				if ( '' === $aid || isset( $seen_a[ $aid ] ) ) {
					$aid = self::new_id( 'a' );
				}
				$seen_a[ $aid ] = true;

				$answer = [
					'id'       => $aid,
					'text'     => isset( $a['text'] ) ? sanitize_text_field( (string) $a['text'] ) : '',
					'products' => self::product_ids( $a['products'] ?? [] ), // the products this answer fits (product recommendations)
				];
				if ( '' === $answer['text'] ) {
					$errors[ "questions.$qi.answers." . count( $answers ) . '.text' ] = __( 'Enter the answer text.', 'beltoft-quiz' );
				}
				$answer['effects'] = self::sanitize_product_effects( $a['effects'] ?? [] );
				if ( 'score' === $mode ) {
					$answer['correct'] = ! empty( $a['correct'] );
					if ( $answer['correct'] ) {
						$n_correct++;
					}
				} else {
					$points = [];
					foreach ( ( is_array( $a['points'] ?? null ) ? $a['points'] : [] ) as $rid => $pts ) {
						$rid = sanitize_key( (string) $rid );
						if ( in_array( $rid, $result_ids, true ) && (int) $pts > 0 ) {
							$points[ $rid ] = (int) $pts;
						}
					}
					$answer['points'] = $points;
				}
				$answers[] = $answer;
			}

			if ( count( $answers ) < 2 ) {
				$errors[ "questions.$qi.answers" ] = __( 'Each question needs at least two answers.', 'beltoft-quiz' );
			} elseif ( 'score' === $mode && 0 === $n_correct ) {
				$errors[ "questions.$qi.answers" ] = __( 'Mark at least one answer as correct.', 'beltoft-quiz' );
			}

			$text = isset( $q['text'] ) ? sanitize_text_field( (string) $q['text'] ) : '';
			if ( '' === $text ) {
				$errors[ "questions.$qi.text" ] = __( 'Enter the question text.', 'beltoft-quiz' );
			}

			$out[] = [
				'id'       => $qid,
				'text'     => $text,
				'image_id' => isset( $q['image_id'] ) ? absint( $q['image_id'] ) : 0,
				'type'     => $type,
				'answers'  => $answers,
			];
		}
		return $out;
	}

	/**
	 * Whether Product Finder can produce at least one recommendation.
	 */
	private static function has_product_source( array $questions, array $results ): bool {
		foreach ( $questions as $question ) {
			foreach ( $question['answers'] as $answer ) {
				foreach ( (array) ( $answer['effects'] ?? [] ) as $effect ) {
					if ( 'boost' === ( $effect['action'] ?? '' ) ) {
						return true;
					}
				}
			}
		}
		foreach ( $results as $result ) {
			if ( ! empty( $result['product_ids'] ) || ! empty( $result['product_id'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Product Finder rules attached to an answer.
	 *
	 * Product targets contain product_id. Term targets contain a WooCommerce
	 * product taxonomy and term_id. Boosts have a 1–10 weight; excludes always
	 * remove matching products.
	 */
	private static function sanitize_product_effects( $raw ): array {
		$out = [];
		foreach ( array_slice( is_array( $raw ) ? array_values( $raw ) : [], 0, 24 ) as $effect ) {
			if ( ! is_array( $effect ) ) {
				continue;
			}
			$target = sanitize_key( (string) ( $effect['target'] ?? 'product' ) );
			$action = sanitize_key( (string) ( $effect['action'] ?? 'boost' ) );
			if ( ! in_array( $target, [ 'product', 'term' ], true ) || ! in_array( $action, [ 'boost', 'exclude' ], true ) ) {
				continue;
			}
			$item = [
				'target' => $target,
				'action' => $action,
				'weight' => 'exclude' === $action ? 0 : max( 1, min( 10, absint( $effect['weight'] ?? 1 ) ) ),
			];
			if ( 'product' === $target ) {
				$item['product_id'] = absint( $effect['product_id'] ?? 0 );
				if ( ! $item['product_id'] ) {
					continue;
				}
			} else {
				$item['taxonomy'] = sanitize_key( (string) ( $effect['taxonomy'] ?? '' ) );
				$item['term_id']  = absint( $effect['term_id'] ?? 0 );
				if ( ! $item['term_id'] || ! preg_match( '/^(product_cat|product_tag|pa_[a-z0-9_-]+)$/', $item['taxonomy'] ) ) {
					continue;
				}
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Reward block.
	 */
	private static function sanitize_reward( array $r, array $result_ids, array &$errors ): array {
		$d         = self::defaults()['reward'];
		$condition = isset( $r['condition'] ) ? sanitize_key( (string) $r['condition'] ) : $d['condition'];
		if ( ! in_array( $condition, self::REWARD_CONDITIONS, true ) ) {
			$condition = $d['condition'];
		}
		$out = [
			'enabled'     => ! empty( $r['enabled'] ),
			'amount'      => isset( $r['amount'] ) ? round( (float) $r['amount'], 2 ) : $d['amount'],
			'expiry_days' => isset( $r['expiry_days'] ) ? absint( $r['expiry_days'] ) : $d['expiry_days'],
			'condition'   => $condition,
			'outcome_ids' => array_values( array_intersect( array_map( 'sanitize_key', (array) ( $r['outcome_ids'] ?? [] ) ), $result_ids ) ),
		];
		if ( $out['enabled'] && $out['amount'] <= 0 ) {
			$errors['reward.amount'] = __( 'The reward amount must be greater than zero.', 'beltoft-quiz' );
		}
		return $out;
	}

	/**
	 * Stored config merged over defaults.
	 */
	public static function load( int $quiz_id ): array {
		$stored = get_post_meta( $quiz_id, PostType::META, true );
		$stored = is_string( $stored ) ? json_decode( $stored, true ) : $stored;
		$d      = self::defaults();
		if ( ! is_array( $stored ) ) {
			return $d;
		}
		$stored_settings              = is_array( $stored['settings'] ?? null ) ? $stored['settings'] : [];
		$has_recommendation           = in_array( $stored_settings['product_recommendation'] ?? '', self::PRODUCT_RECOMMENDATIONS, true );
		$stored['settings']           = array_merge( $d['settings'], $stored_settings );
		if ( ! $has_recommendation ) {
			$stored['settings']['product_recommendation'] = self::infer_product_recommendation( is_array( $stored['questions'] ?? null ) ? $stored['questions'] : [] );
		}
		if ( 'product' === ( $stored['mode'] ?? '' ) ) {
			$stored['settings']['product_recommendation'] = 'product_rules';
		}
		$labels = is_array( $stored['settings']['labels'] ?? null ) ? $stored['settings']['labels'] : [];
		foreach ( $d['settings']['labels'] as $key => $default ) {
			$stored['settings']['labels'][ $key ] = '' !== trim( (string) ( $labels[ $key ] ?? '' ) ) ? (string) $labels[ $key ] : $default;
		}
		$stored['reward']             = array_merge( $d['reward'], is_array( $stored['reward'] ?? null ) ? $stored['reward'] : [] );

		return array_merge( $d, $stored );
	}

	/**
	 * Legacy quizzes did not name their recommendation method. If any answer has
	 * products, preserve the old answer-matching behaviour; otherwise use the
	 * products assigned to the winning result.
	 */
	private static function infer_product_recommendation( array $questions ): string {
		foreach ( $questions as $question ) {
			foreach ( (array) ( $question['answers'] ?? [] ) as $answer ) {
				if ( ! empty( $answer['products'] ) ) {
					return 'answer_matches';
				}
			}
		}
		return 'result_products';
	}

	/**
	 * Persist a validated config.
	 */
	public static function save( int $quiz_id, array $config ): void {
		update_post_meta( $quiz_id, PostType::META, wp_slash( wp_json_encode( $config ) ) );
	}

	/**
	 * The config as the browser may see it: no correct flags, points, ranges or reward.
	 */
	public static function public_view( array $config ): array {
		$view = [
			'mode'      => $config['mode'],
			'settings'  => $config['settings'],
			'questions' => [],
			'results'   => [],
		];
		foreach ( $config['questions'] as $q ) {
			$view['questions'][] = [
				'id'      => $q['id'],
				'text'    => $q['text'],
				'image'   => $q['image_id'] ? (string) wp_get_attachment_image_url( $q['image_id'], 'large' ) : '',
				'type'    => $q['type'],
				'answers' => array_map(
					function ( $a ) {
						return [ 'id' => $a['id'], 'text' => $a['text'] ];
					},
					$q['answers']
				),
			];
		}
		foreach ( $config['results'] as $r ) {
			$view['results'][] = [ 'id' => $r['id'], 'title' => $r['title'] ];
		}
		return $view;
	}
}
