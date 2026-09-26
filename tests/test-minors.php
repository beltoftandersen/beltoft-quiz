<?php
require_once __DIR__ . '/bootstrap.php';

use Bgq\Quiz\Attempts;
use Bgq\Quiz\Config;
use Bgq\Quiz\Stats;
use Bgq\Admin\Export;

global $wpdb;

// 1. Permanently deleting a quiz removes its attempts.
$quiz = wp_insert_post( [ 'post_type' => 'bgq_quiz', 'post_title' => 'Doomed', 'post_status' => 'publish' ] );
Attempts::insert( [ 'quiz_id' => $quiz, 'email' => 'doomed@example.test', 'score' => 1 ] );
bgq_assert_eq( 1, Attempts::count( [ 'quiz_id' => $quiz ] ), 'attempt stored' );
wp_delete_post( $quiz, true );
bgq_assert_eq( 0, Attempts::count( [ 'quiz_id' => $quiz ] ), 'attempts removed with the quiz' );

// 6. Logged-in visitors are matched by user id only, never by IP.
$q2 = wp_insert_post( [ 'post_type' => 'bgq_quiz', 'post_title' => 'Office', 'post_status' => 'publish' ] );
bgq_test_register_cleanup( function () use ( $q2 ) { global $wpdb; $wpdb->delete( $wpdb->prefix . 'bgq_attempts', [ 'quiz_id' => $q2 ] ); wp_delete_post( $q2, true ); } );
Attempts::insert( [ 'quiz_id' => $q2, 'user_id' => 0, 'ip_hash' => 'shared-office-ip', 'score' => 100 ] );
bgq_assert_eq( null, Attempts::find_existing( $q2, 12345, 'shared-office-ip', '' ), 'logged-in user on a shared IP is not matched to the guest attempt' );
bgq_assert( null !== Attempts::find_existing( $q2, 0, 'shared-office-ip', '' ), 'guest on the same IP still matches' );

// 7. "Today" starts at site-local midnight.
$prev_tz = get_option( 'timezone_string' );
update_option( 'timezone_string', 'Pacific/Kiritimati' ); // UTC+14
bgq_test_register_cleanup( function () use ( $prev_tz ) { update_option( 'timezone_string', $prev_tz ); } );
$expected = get_gmt_from_date( wp_date( 'Y-m-d' ) . ' 00:00:00' );
bgq_assert_eq( $expected, Stats::today_start_utc(), 'today boundary is local midnight converted to UTC' );
update_option( 'timezone_string', $prev_tz );

// 9. CSV: a leading tab or carriage return is neutralised like = + - @.
$q3 = wp_insert_post( [ 'post_type' => 'bgq_quiz', 'post_title' => 'CSV', 'post_status' => 'publish' ] );
bgq_test_register_cleanup( function () use ( $q3 ) { global $wpdb; $wpdb->delete( $wpdb->prefix . 'bgq_attempts', [ 'quiz_id' => $q3 ] ); wp_delete_post( $q3, true ); } );
Attempts::insert( [ 'quiz_id' => $q3, 'name' => "\t=HYPERLINK(1)", 'email' => 'csv@example.test', 'score' => 1 ] );
$csv = Export::csv( [ 'quiz_id' => $q3 ] );
bgq_assert( false !== strpos( $csv, "\"'\t=HYPERLINK(1)\"" ), 'leading tab formula neutralised' );

// 10. Button labels equal to the default are stored empty and resolved at load time.
$c = Config::validate( [ 'mode' => 'score', 'settings' => [ 'labels' => [ 'start' => Config::default_labels()['start'], 'next' => 'Onward' ] ],
	'questions' => [ [ 'id' => 'q1', 'text' => 'A', 'type' => 'single', 'answers' => [ [ 'id' => 'a', 'text' => 'x', 'correct' => true ], [ 'id' => 'b', 'text' => 'y' ] ] ] ],
	'results'   => [ [ 'id' => 'r', 'title' => 'R', 'min' => 0, 'max' => 100 ] ] ] );
bgq_assert_eq( '', $c['settings']['labels']['start'], 'default label stored empty' );
bgq_assert_eq( 'Onward', $c['settings']['labels']['next'], 'custom label kept' );
$q4 = wp_insert_post( [ 'post_type' => 'bgq_quiz', 'post_title' => 'Labels', 'post_status' => 'publish' ] );
bgq_test_register_cleanup( function () use ( $q4 ) { wp_delete_post( $q4, true ); } );
Config::save( $q4, $c );
$loaded = Config::load( $q4 );
bgq_assert_eq( Config::default_labels()['start'], $loaded['settings']['labels']['start'], 'empty label resolves to the (translated) default at load' );
bgq_assert_eq( 'Onward', $loaded['settings']['labels']['next'], 'custom label survives load' );
bgq_assert_eq( Config::default_labels()['start'], Config::public_view( $loaded )['settings']['labels']['start'], 'public view carries resolved labels' );
