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

		if ( 'outcome' === $config['mode'] ) {
			return self::grade_outcome( $config, $answers );
		}

		return self::grade_score( $config, $answers );
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
