<?php
require_once __DIR__ . '/bootstrap.php';

use Bgq\Quiz\Config;
use Bgq\Quiz\Grader;

$score = Config::validate( [
	'mode'      => 'score',
	'settings'  => [ 'pass_mark' => 50 ],
	'questions' => [
		[ 'id' => 'q1', 'text' => 'A', 'type' => 'single', 'answers' => [ [ 'id' => 'a', 'text' => 'x', 'correct' => true ], [ 'id' => 'b', 'text' => 'y' ] ] ],
		[ 'id' => 'q2', 'text' => 'B', 'type' => 'multiple', 'answers' => [ [ 'id' => 'c', 'text' => 'x', 'correct' => true ], [ 'id' => 'd', 'text' => 'y', 'correct' => true ], [ 'id' => 'e', 'text' => 'z' ] ] ],
	],
	'results'   => [ [ 'id' => 'hi', 'title' => 'Hi', 'min' => 60, 'max' => 100 ], [ 'id' => 'lo', 'title' => 'Lo', 'min' => 0, 'max' => 40 ] ],
] );
bgq_assert( ! is_wp_error( $score ), 'fixture valid' );

$g = Grader::grade( $score, [ 'q1' => [ 'a' ], 'q2' => [ 'c', 'd' ] ] );
bgq_assert_eq( 100.0, $g['score'], 'all correct = 100' );
bgq_assert_eq( 'hi', $g['result_id'], 'high result' );
bgq_assert_eq( true, $g['passed'], 'passed' );
bgq_assert_eq( [ 'q1' => true, 'q2' => true ], $g['per_question'], 'per question flags' );

$g = Grader::grade( $score, [ 'q1' => [ 'a' ], 'q2' => [ 'c' ] ] );
bgq_assert_eq( 50.0, $g['score'], 'multiple needs the full set' );
bgq_assert_eq( 'lo', $g['result_id'], 'gap in ranges: 50 falls back to nearest lower range' );
bgq_assert_eq( true, $g['passed'], 'pass mark inclusive' );

$g = Grader::grade( $score, [ 'q1' => [ 'zzz' ], 'q9' => [ 'a' ] ] );
bgq_assert_eq( 0.0, $g['score'], 'unknown ids ignored and scored wrong' );
bgq_assert_eq( 2, $g['total_count'], 'total counts all questions' );
bgq_assert_eq( 'lo', $g['result_id'], 'zero score gets lowest range' );
bgq_assert_eq( false, $g['passed'], 'zero fails' );

$g = Grader::grade( $score, [ 'q1' => [ 'a', 'b' ], 'q2' => [ 'c', 'd', 'e' ] ] );
bgq_assert_eq( 0.0, $g['score'], 'selecting extra answers is wrong' );

$outcome = Config::validate( [
	'mode'      => 'outcome',
	'questions' => [
		[ 'id' => 'q1', 'text' => 'A', 'type' => 'single', 'answers' => [ [ 'id' => 'a', 'text' => 'x', 'points' => [ 'r1' => 2 ] ], [ 'id' => 'b', 'text' => 'y', 'points' => [ 'r2' => 2 ] ] ] ],
		[ 'id' => 'q2', 'text' => 'B', 'type' => 'single', 'answers' => [ [ 'id' => 'c', 'text' => 'x', 'points' => [ 'r2' => 1 ] ], [ 'id' => 'd', 'text' => 'y', 'points' => [ 'r1' => 2 ] ] ] ],
	],
	'results'   => [ [ 'id' => 'r1', 'title' => 'One' ], [ 'id' => 'r2', 'title' => 'Two' ] ],
] );
$g = Grader::grade( $outcome, [ 'q1' => [ 'a' ], 'q2' => [ 'c' ] ] );
bgq_assert_eq( 'r1', $g['result_id'], 'outcome: highest points wins (2 vs 1)' );
bgq_assert_eq( null, $g['passed'], 'outcome: passed is null' );
bgq_assert_eq( 0.0, $g['score'], 'outcome: score is 0' );
bgq_assert_eq( 'r2', Grader::grade( $outcome, [ 'q1' => [ 'b' ], 'q2' => [ 'c' ] ] )['result_id'], 'outcome: r2 with 3 points' );
bgq_assert_eq( 'r1', Grader::grade( $outcome, [] )['result_id'], 'outcome: no answers → first result' );
bgq_assert_eq( 'r1', Grader::grade( $outcome, [ 'q1' => [ 'b' ], 'q2' => [ 'd' ] ] )['result_id'], 'outcome: tie (2 vs 2) → first result in list' );
