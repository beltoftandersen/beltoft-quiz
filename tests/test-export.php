<?php
require_once __DIR__ . '/bootstrap.php';

use Bgq\Quiz\Attempts;
use Bgq\Admin\Export;
use Bgq\Admin\EntriesTable;

$id = wp_insert_post( [ 'post_type' => 'bgq_quiz', 'post_title' => 'Export "quoted" quiz', 'post_status' => 'publish' ] );
bgq_test_register_cleanup( function () use ( $id ) { global $wpdb; $wpdb->delete( $wpdb->prefix . 'bgq_attempts', [ 'quiz_id' => $id ] ); wp_delete_post( $id, true ); } );

Attempts::insert( [ 'quiz_id' => $id, 'email' => 'one@example.test', 'name' => 'One, Person', 'score' => 100, 'correct_count' => 3, 'total_count' => 3, 'result_id' => 'hi', 'answers' => [ 'q1' => [ 'a' ] ], 'duration_seconds' => 42 ] );
Attempts::insert( [ 'quiz_id' => $id, 'email' => 'two@example.test', 'name' => 'Two', 'score' => 33.3, 'correct_count' => 1, 'total_count' => 3, 'result_id' => 'lo', 'answers' => [], 'duration_seconds' => 7 ] );

$csv   = Export::csv( [ 'quiz_id' => $id ] );
$lines = array_values( array_filter( explode( "\n", trim( $csv ) ) ) );
bgq_assert_eq( 3, count( $lines ), 'header plus two rows' );
bgq_assert_eq( "\xEF\xBB\xBF" . 'Date,Quiz,Name,Email,Score,Correct,Total,Result,Duration (s)', rtrim( $lines[0], "\r" ), 'header row with BOM' );
bgq_assert( false !== strpos( $csv, '"One, Person"' ), 'value with a comma is quoted' );
bgq_assert( false !== strpos( $csv, 'two@example.test' ), 'email present' );
bgq_assert( false !== strpos( $csv, '"Export ""quoted"" quiz"' ), 'quotes escaped' );
bgq_assert( false !== strpos( $csv, ',33.3,1,3,lo,7' ), 'numeric columns' );

// Entries table lists both, supports the quiz filter and search.
$_GET['quiz_id'] = $id;
$_GET['s']       = 'two@';
$table = new EntriesTable();
$table->prepare_items();
bgq_assert_eq( 1, count( $table->items ), 'search narrows to one entry' );
unset( $_GET['s'] );
$table = new EntriesTable();
$table->prepare_items();
bgq_assert_eq( 2, count( $table->items ), 'quiz filter lists both' );
unset( $_GET['quiz_id'] );

// Export handler requires capability and nonce.
wp_set_current_user( 0 );
bgq_assert_eq( false, Export::can_export( 'bad' ), 'guest cannot export' );
wp_set_current_user( bgq_test_admin_id() );
bgq_assert_eq( false, Export::can_export( 'bad' ), 'bad nonce rejected' );
bgq_assert_eq( true, Export::can_export( wp_create_nonce( 'bgq_export' ) ), 'admin with nonce allowed' );
