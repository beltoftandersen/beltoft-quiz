<?php

namespace Bgq\Quiz;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side grading for both quiz modes.
 */
class Grader {

	/**
	 * Grade submitted answers.
	 *
	 * @param array $config  Validated config.
	 * @param array $answers [ question_id => [ answer_id, ... ] ].
	 * @return array { score: float, correct_count: int, total_count: int, result_id: string, passed: bool|null, per_question: array }
	 */
	public static function grade( array $config, array $answers ): array {
		$answers = is_array( $answers ) ? $answers : [];

		if ( 'product' === $config['mode'] ) {
			$grade = self::grade_product( $config, $answers );
		} else {
			$grade = 'outcome' === $config['mode'] ? self::grade_outcome( $config, $answers ) : self::grade_score( $config, $answers );
		}
		$details = self::recommendation_details( $config, $answers, (string) $grade['result_id'] );
		$grade['product_ids']     = $details['ids'];
		$grade['product_reasons'] = $details['reasons'];
		return $grade;
	}

	/**
	 * Full recommendation details for admin previews and result explanations.
	 */
	public static function recommendation_details( array $config, array $answers, string $result_id ): array {
		$method = (string) ( $config['settings']['product_recommendation'] ?? 'result_products' );
		if ( 'product_rules' === $method || 'product' === ( $config['mode'] ?? '' ) ) {
			return ProductMatcher::match( $config, $answers, $result_id );
		}
		return [ 'ids' => self::recommend( $config, $answers, $result_id ), 'scores' => [], 'reasons' => [], 'excluded' => [] ];
	}

	/**
	 * Products to recommend. In result mode, use the winning result's products in
	 * their configured order. In answer-match mode, each selected answer adds one
	 * match to its products; most matches wins and ties keep question/product
	 * order. The winning result's products are the fallback when no answer maps to
	 * a product. At most settings.max_products are returned.
	 *
	 * @return int[]
	 */
	public static function recommend( array $config, array $answers, string $result_id ): array {
		$method = (string) ( $config['settings']['product_recommendation'] ?? 'result_products' );
		if ( 'product_rules' === $method || 'product' === ( $config['mode'] ?? '' ) ) {
			return ProductMatcher::match( $config, $answers, $result_id )['ids'];
		}
		$own = [];
		foreach ( $config['results'] as $r ) {
			if ( $r['id'] === $result_id ) {
				$own = array_map( 'intval', (array) ( $r['product_ids'] ?? ( ! empty( $r['product_id'] ) ? [ $r['product_id'] ] : [] ) ) );
			}
		}
		$counts = [];
		$order  = [];
		foreach ( $config['questions'] as $q ) {
			$chosen = self::selected( $answers, $q['id'], array_column( $q['answers'], 'id' ) );
			foreach ( $q['answers'] as $a ) {
				if ( ! in_array( $a['id'], $chosen, true ) ) {
					continue;
				}
				foreach ( (array) ( $a['products'] ?? [] ) as $pid ) {
					$pid = (int) $pid;
					if ( $pid <= 0 ) {
						continue;
					}
					if ( ! isset( $counts[ $pid ] ) ) {
						$counts[ $pid ] = 0;
						$order[ $pid ]  = count( $order );
					}
					$counts[ $pid ]++;
				}
			}
		}
		$max = max( 1, (int) ( $config['settings']['max_products'] ?? 3 ) );
		if ( 'answer_matches' !== $method ) {
			return array_slice( $own, 0, $max );
		}
		if ( ! $counts ) {
			return array_slice( $own, 0, $max );
		}
		$pool = array_keys( $counts );
		usort( $pool, static function ( $a, $b ) use ( $counts, $order ) {
			return [ -$counts[ $a ], $order[ $a ] ] <=> [ -$counts[ $b ], $order[ $b ] ];
		} );
		return array_slice( $pool, 0, $max );
	}

	/**
	 * Product Finder mode has one presentation result; answers only rank products.
	 */
	private static function grade_product( array $config, array $answers ): array {
		$first = reset( $config['results'] );
		return [
			'score'         => 0.0,
			'correct_count' => 0,
			'total_count'   => count( $config['questions'] ),
			'result_id'     => $first ? (string) $first['id'] : '',
			'passed'        => null,
			'per_question'  => array_fill_keys( array_column( $config['questions'], 'id' ), true ),
		];
	}

	/**
	 * Score mode: a question is correct only when the selected set equals the correct set.
	 */
	private static function grade_score( array $config, array $answers ): array {
		$per_question = [];
		$correct      = 0;
		$total        = count( $config['questions'] );

		foreach ( $config['questions'] as $q ) {
			$known   = array_column( $q['answers'], 'id' );
			$right   = [];
			foreach ( $q['answers'] as $a ) {
				if ( ! empty( $a['correct'] ) ) {
					$right[] = $a['id'];
				}
			}
			$chosen = self::selected( $answers, $q['id'], $known );
			sort( $right );
			$ok                    = ! empty( $right ) && $chosen === $right;
			$per_question[ $q['id'] ] = $ok;
			if ( $ok ) {
				$correct++;
			}
		}

		$score  = $total > 0 ? round( $correct / $total * 100, 1 ) : 0.0;
		$passed = $score >= (float) $config['settings']['pass_mark'];

		return [
			'score'         => $score,
			'correct_count' => $correct,
			'total_count'   => $total,
			'result_id'     => self::result_for_score( $config['results'], $score ),
			'passed'        => $passed,
			'per_question'  => $per_question,
		];
	}

	/**
	 * Outcome mode: sum points per result; highest wins, ties go to list order.
	 */
	private static function grade_outcome( array $config, array $answers ): array {
		$totals = [];
		foreach ( $config['results'] as $r ) {
			$totals[ $r['id'] ] = 0;
		}
		$per_question = [];

		foreach ( $config['questions'] as $q ) {
			$known  = array_column( $q['answers'], 'id' );
			$chosen = self::selected( $answers, $q['id'], $known );
			$per_question[ $q['id'] ] = ! empty( $chosen );
			foreach ( $q['answers'] as $a ) {
				if ( ! in_array( $a['id'], $chosen, true ) ) {
					continue;
				}
				foreach ( ( $a['points'] ?? [] ) as $rid => $pts ) {
					if ( isset( $totals[ $rid ] ) ) {
						$totals[ $rid ] += (int) $pts;
					}
				}
			}
		}

		$best_id  = '';
		$best_pts = -1;
		foreach ( $config['results'] as $r ) {
			if ( $totals[ $r['id'] ] > $best_pts ) {
				$best_pts = $totals[ $r['id'] ];
				$best_id  = $r['id'];
			}
		}

		return [
			'score'         => 0.0,
			'correct_count' => 0,
			'total_count'   => count( $config['questions'] ),
			'result_id'     => $best_id,
			'passed'        => null,
			'per_question'  => $per_question,
		];
	}

	/**
	 * Selected answer ids for a question, limited to ids that exist, sorted.
	 *
	 * @return string[]
	 */
	private static function selected( array $answers, string $qid, array $known ): array {
		$raw = isset( $answers[ $qid ] ) ? (array) $answers[ $qid ] : [];
		$ids = array_values( array_unique( array_filter( array_map( 'strval', $raw ), function ( $id ) use ( $known ) {
			return in_array( $id, $known, true );
		} ) ) );
		sort( $ids );
		return $ids;
	}

	/**
	 * Result whose range contains the score; otherwise the nearest range below; otherwise the last result.
	 */
	private static function result_for_score( array $results, float $score ): string {
		foreach ( $results as $r ) {
			if ( $score >= (float) $r['min'] && $score <= (float) $r['max'] ) {
				return $r['id'];
			}
		}
		$best    = '';
		$best_max = -1;
		foreach ( $results as $r ) {
			if ( (float) $r['max'] < $score && (float) $r['max'] > $best_max ) {
				$best_max = (float) $r['max'];
				$best     = $r['id'];
			}
		}
		if ( '' !== $best ) {
			return $best;
		}
		$last = end( $results );
		return $last ? $last['id'] : '';
	}
}
