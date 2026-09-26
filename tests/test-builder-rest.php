<?php
require_once __DIR__ . '/bootstrap.php';

use Bgq\Quiz\Config;

$id = wp_insert_post( [ 'post_type' => 'bgq_quiz', 'post_title' => 'Builder test', 'post_status' => 'draft' ] );
bgq_test_register_cleanup( function () use ( $id ) { wp_delete_post( $id, true ); } );

function bgq_t_cfg( int $id, string $method, $body = null ) {
	$req = new WP_REST_Request( $method, '/bgq/v1/quizzes/' . $id . '/config' );
	if ( null !== $body ) {
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $req );
	return [ $res->get_status(), $res->get_data() ];
}

$valid = [ 'mode' => 'score', 'questions' => [ [ 'id' => 'q1', 'text' => 'A', 'type' => 'single', 'answers' => [ [ 'id' => 'a', 'text' => 'x', 'correct' => true ], [ 'id' => 'b', 'text' => 'y' ] ] ] ], 'results' => [ [ 'id' => 'r', 'title' => 'R', 'min' => 0, 'max' => 100 ] ] ];

// Subscriber: forbidden.
$sub = wp_insert_user( [ 'user_login' => 'bgq_sub_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
bgq_test_register_cleanup( function () use ( $sub ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $sub ); } );
wp_set_current_user( $sub );
list( $status ) = bgq_t_cfg( $id, 'POST', $valid );
bgq_assert_eq( 403, $status, 'subscriber cannot save' );
list( $status ) = bgq_t_cfg( $id, 'GET' );
bgq_assert_eq( 403, $status, 'subscriber cannot read' );

// Admin: save, read, validation errors.
wp_set_current_user( bgq_test_admin_id() );
list( $status, $data ) = bgq_t_cfg( $id, 'POST', $valid );
bgq_assert_eq( 200, $status, 'admin saves' );
bgq_assert_eq( 'score', Config::load( $id )['mode'], 'config persisted' );
bgq_assert_eq( 'R', $data['results'][0]['title'], 'sanitized config returned' );
list( $status, $data ) = bgq_t_cfg( $id, 'GET' );
bgq_assert_eq( 200, $status, 'admin reads' );
bgq_assert_eq( 'q1', $data['questions'][0]['id'], 'read returns stored config' );
list( $status, $data ) = bgq_t_cfg( $id, 'POST', [ 'mode' => 'nope', 'questions' => [], 'results' => [] ] );
bgq_assert_eq( 400, $status, 'invalid config rejected' );
bgq_assert( isset( $data['data']['errors']['mode'] ), 'field errors returned' );
bgq_assert_eq( 'score', Config::load( $id )['mode'], 'invalid save does not overwrite' );
list( $status ) = bgq_t_cfg( 999999999, 'POST', $valid );
bgq_assert_eq( 404, $status, 'unknown quiz 404' );

// Builder page is enqueued for the quiz edit screen and the Quizzes list gets a shortcode column.
bgq_assert( has_filter( 'replace_editor', [ 'Bgq\\Admin\\Builder', 'replace_editor' ] ) !== false, 'builder replaces the editor' );
$cols = apply_filters( 'manage_bgq_quiz_posts_columns', [ 'title' => 'Title', 'date' => 'Date' ] );
bgq_assert( isset( $cols['bgq_shortcode'] ), 'shortcode column added' );
ob_start(); do_action( 'manage_bgq_quiz_posts_custom_column', 'bgq_shortcode', $id ); $cell = ob_get_clean();
bgq_assert( false !== strpos( $cell, '[beltoft_quiz id="' . $id . '"]' ), 'shortcode column shows the shortcode' );
