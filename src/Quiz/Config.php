<?php

namespace Bgq\Quiz;

defined( 'ABSPATH' ) || exit;

/**
 * The quiz configuration: defaults, validation, storage and the public (browser) view.
 */
class Config {

	const MODES = [ 'score', 'outcome' ];
	const TYPES = [ 'single', 'multiple' ];
	const REWARD_CONDITIONS = [ 'always', 'pass', 'outcome' ];

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

		$labels = $d['labels'];
		if ( is_array( $s['labels'] ?? null ) ) {
			foreach ( $labels as $key => $default ) {
				$val = isset( $s['labels'][ $key ] ) ? sanitize_text_field( wp_strip_all_tags( (string) $s['labels'][ $key ] ) ) : '';
				if ( '' !== $val ) {
					$labels[ $key ] = $val;
				}
			}
		}

		return [
			'timer'             => isset( $s['timer'] ) ? min( 86400, absint( $s['timer'] ) ) : 0,
			'shuffle_questions' => ! empty( $s['shuffle_questions'] ),
			'shuffle_answers'   => ! empty( $s['shuffle_answers'] ),
			'one_attempt'       => ! empty( $s['one_attempt'] ),
			'require_email'     => ! empty( $s['require_email'] ),
			'consent_text'      => isset( $s['consent_text'] ) ? wp_kses( (string) $s['consent_text'], [ 'a' => [ 'href' => [], 'target' => [] ] ] ) : '',
			'accent'            => $accent ? strtolower( $accent ) : $d['accent'],
			'pass_mark'         => isset( $s['pass_mark'] ) ? min( 100, absint( $s['pass_mark'] ) ) : $d['pass_mark'],
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
					'id'   => $aid,
					'text' => isset( $a['text'] ) ? sanitize_text_field( (string) $a['text'] ) : '',
				];
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
		$stored['settings']           = array_merge( $d['settings'], is_array( $stored['settings'] ?? null ) ? $stored['settings'] : [] );
		$stored['settings']['labels'] = array_merge( $d['settings']['labels'], is_array( $stored['settings']['labels'] ?? null ) ? $stored['settings']['labels'] : [] );
		$stored['reward']             = array_merge( $d['reward'], is_array( $stored['reward'] ?? null ) ? $stored['reward'] : [] );

		return array_merge( $d, $stored );
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
