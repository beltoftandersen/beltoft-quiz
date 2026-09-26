<?php

namespace Bgq\Quiz;

defined( 'ABSPATH' ) || exit;

/**
 * Per-quiz analytics computed from the attempts table.
 */
class Stats {

	const SAMPLE = 1000;

	/**
	 * @return array { attempts, today, last7, last30, avg_score, pass_rate, outcomes, questions }
	 */
	public static function for_quiz( int $quiz_id ): array {
		global $wpdb;
		$config = Config::load( $quiz_id );

		$now  = time();
		$day  = self::today_start_utc();
		$d7   = gmdate( 'Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS );
		$d30  = gmdate( 'Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS );
		$pass = (float) $config['settings']['pass_mark'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table aggregate.
		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS attempts,
					SUM(created_at >= %s) AS today,
					SUM(created_at >= %s) AS last7,
					SUM(created_at >= %s) AS last30,
					AVG(score) AS avg_score,
					SUM(score >= %f) AS passed
				FROM {$wpdb->prefix}bgq_attempts WHERE quiz_id = %d",
				$day,
				$d7,
				$d30,
				$pass,
				$quiz_id
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table aggregate.
		$outcome_rows = $wpdb->get_results( $wpdb->prepare( "SELECT result_id, COUNT(*) AS n FROM {$wpdb->prefix}bgq_attempts WHERE quiz_id = %d GROUP BY result_id ORDER BY n DESC", $quiz_id ) );
		$outcomes     = [];
		foreach ( (array) $outcome_rows as $row ) {
			$outcomes[ (string) $row->result_id ] = (int) $row->n;
		}

		$attempts = (int) ( $totals->attempts ?? 0 );

		return [
			'attempts'  => $attempts,
			'today'     => (int) ( $totals->today ?? 0 ),
			'last7'     => (int) ( $totals->last7 ?? 0 ),
			'last30'    => (int) ( $totals->last30 ?? 0 ),
			'avg_score' => $attempts ? round( (float) $totals->avg_score, 1 ) : null,
			'pass_rate' => ( $attempts && 'score' === $config['mode'] ) ? round( (int) $totals->passed / $attempts * 100, 1 ) : null,
			'outcomes'  => $outcomes,
			'questions' => self::question_rates( $quiz_id, $config ),
		];
	}

	/**
	 * Start of today in the site's timezone, as a UTC MySQL datetime.
	 */
	public static function today_start_utc(): string {
		return (string) get_gmt_from_date( wp_date( 'Y-m-d' ) . ' 00:00:00' );
	}

	/**
	 * Correct rate per question over the most recent attempts (score mode only).
	 *
	 * @return array<string,float>
	 */
	private static function question_rates( int $quiz_id, array $config ): array {
		global $wpdb;
		if ( 'score' !== $config['mode'] ) {
			return [];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, bounded sample.
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT answers FROM {$wpdb->prefix}bgq_attempts WHERE quiz_id = %d ORDER BY id DESC LIMIT %d", $quiz_id, self::SAMPLE ) );
		if ( empty( $rows ) ) {
			return [];
		}
		$correct = [];
		$n       = 0;
		foreach ( $rows as $json ) {
			$answers = json_decode( (string) $json, true );
			if ( ! is_array( $answers ) ) {
				continue;
			}
			$n++;
			$grade = Grader::grade( $config, $answers );
			foreach ( $grade['per_question'] as $qid => $ok ) {
				$correct[ $qid ] = ( $correct[ $qid ] ?? 0 ) + ( $ok ? 1 : 0 );
			}
		}
		$out = [];
		foreach ( $config['questions'] as $q ) {
			$out[ $q['id'] ] = $n ? round( ( $correct[ $q['id'] ] ?? 0 ) / $n * 100, 1 ) : 0.0;
		}
		return $out;
	}
}
