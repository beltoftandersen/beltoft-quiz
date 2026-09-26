<?php
require_once __DIR__ . '/bootstrap.php';

use Bgq\Quiz\Attempts;
use Bgq\Quiz\Config;
use Bgq\Quiz\Stats;

global $wpdb;
$id = wp_insert_post( [ 'post_type' => 'bgq_quiz', 'post_title' => 'Stats quiz', 'post_status' => 'publish' ] );
Config::save( $id, Config::validate( [
	'mode'      => 'score',
	'settings'  => [ 'pass_mark' => 50 ],
	'questions' => [
		[ 'id' => 'q1', 'text' => 'A', 'type' => 'single', 'answers' => [ [ 'id' => 'a', 'text' => 'x', 'correct' => true ], [ 'id' => 'b', 'text' => 'y' ] ] ],
		[ 'id' => 'q2', 'text' => 'B', 'type' => 'single', 'answers' => [ [ 'id' => 'c', 'text' => 'x', 'correct' => true ], [ 'id' => 'd', 'text' => 'y' ] ] ],
	],
	'results'   => [ [ 'id' => 'hi', 'title' => 'Hi', 'min' => 50, 'max' => 100 ], [ 'id' => 'lo', 'title' => 'Lo', 'min' => 0, 'max' => 49 ] ],
] ) );
bgq_test_register_cleanup( function () use ( $id ) { global $wpdb; $wpdb->delete( $wpdb->prefix . 'bgq_attempts', [ 'quiz_id' => $id ] ); wp_delete_post( $id, true ); } );

$rows = [
	[ 100, 'hi', [ 'q1' => [ 'a' ], 'q2' => [ 'c' ] ], 0 ],          // today
	[ 50,  'hi', [ 'q1' => [ 'a' ], 'q2' => [ 'd' ] ], 0 ],          // today
	[ 0,   'lo', [ 'q1' => [ 'b' ], 'q2' => [ 'd' ] ], 3 ],          // 3 days ago
	[ 100, 'hi', [ 'q1' => [ 'a' ], 'q2' => [ 'c' ] ], 40 ],         // 40 days ago
];
foreach ( $rows as $r ) {
	$aid = Attempts::insert( [ 'quiz_id' => $id, 'score' => $r[0], 'correct_count' => $r[0] / 50, 'total_count' => 2, 'result_id' => $r[1], 'answers' => $r[2] ] );
	if ( $r[3] ) {
		$wpdb->update( $wpdb->prefix . 'bgq_attempts', [ 'created_at' => gmdate( 'Y-m-d H:i:s', time() - $r[3] * DAY_IN_SECONDS ) ], [ 'id' => $aid ] );
	}
}

$s = Stats::for_quiz( $id );
bgq_assert_eq( 4, $s['attempts'], 'attempts' );
bgq_assert_eq( 2, $s['today'], 'today' );
bgq_assert_eq( 3, $s['last7'], 'last 7 days' );
bgq_assert_eq( 3, $s['last30'], 'last 30 days' );
bgq_assert_eq( 62.5, $s['avg_score'], 'average score' );
bgq_assert_eq( 75.0, $s['pass_rate'], 'pass rate with pass mark 50' );
bgq_assert_eq( [ 'hi' => 3, 'lo' => 1 ], $s['outcomes'], 'outcome distribution' );
bgq_assert_eq( 75.0, $s['questions']['q1'], 'q1 correct rate' );
bgq_assert_eq( 50.0, $s['questions']['q2'], 'q2 correct rate' );

$empty = wp_insert_post( [ 'post_type' => 'bgq_quiz', 'post_title' => 'Empty', 'post_status' => 'publish' ] );
bgq_test_register_cleanup( function () use ( $empty ) { wp_delete_post( $empty, true ); } );
$e = Stats::for_quiz( $empty );
bgq_assert_eq( 0, $e['attempts'], 'empty quiz: zero attempts' );
bgq_assert_eq( null, $e['avg_score'], 'empty quiz: no average' );
bgq_assert_eq( [], $e['outcomes'], 'empty quiz: no outcomes' );
