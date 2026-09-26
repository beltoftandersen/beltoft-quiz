<?php
require_once __DIR__ . '/bootstrap.php';

use Bgq\Quiz\Attempts;
use Bgq\Support\Privacy;

$quiz = wp_insert_post( [ 'post_type' => 'bgq_quiz', 'post_title' => 'Privacy quiz', 'post_status' => 'publish' ] );
bgq_test_register_cleanup( function () use ( $quiz ) { global $wpdb; $wpdb->delete( $wpdb->prefix . 'bgq_attempts', [ 'quiz_id' => $quiz ] ); wp_delete_post( $quiz, true ); } );
Attempts::insert( [ 'quiz_id' => $quiz, 'email' => 'privacy@example.test', 'name' => 'Priv', 'score' => 50, 'result_id' => 'r' ] );
Attempts::insert( [ 'quiz_id' => $quiz, 'email' => 'other@example.test', 'name' => 'Other', 'score' => 10, 'result_id' => 'r' ] );

$exporters = apply_filters( 'wp_privacy_personal_data_exporters', [] );
$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', [] );
bgq_assert( isset( $exporters['beltoft-quiz'] ) && isset( $erasers['beltoft-quiz'] ), 'exporter and eraser registered' );

$export = Privacy::export( 'privacy@example.test', 1 );
bgq_assert_eq( 1, count( $export['data'] ), 'exporter returns the one attempt for the email' );
bgq_assert_eq( 'bgq-attempts', $export['data'][0]['group_id'], 'export group id' );
bgq_assert( in_array( 'Privacy quiz', array_column( $export['data'][0]['data'], 'value' ), true ), 'export names the quiz' );
bgq_assert_eq( true, $export['done'], 'exporter done' );

$erase = Privacy::erase( 'privacy@example.test', 1 );
bgq_assert_eq( true, $erase['items_removed'], 'eraser removed items' );
bgq_assert_eq( 0, Attempts::count( [ 'quiz_id' => $quiz, 'search' => 'privacy@example.test' ] ), 'attempt gone after erase' );
bgq_assert_eq( 1, Attempts::count( [ 'quiz_id' => $quiz ] ), 'other attempt untouched' );

// Settings page registers the single option with the sanitizer.
do_action( 'admin_init' );
global $wp_registered_settings;
bgq_assert( isset( $wp_registered_settings['bgq_options'] ), 'bgq_options registered as a setting' );
bgq_assert_eq( [ 'cleanup_on_uninstall' => '1' ], \Bgq\Support\Options::sanitize( [ 'cleanup_on_uninstall' => 'on', 'junk' => 'x' ] ), 'sanitize keeps only known keys' );
