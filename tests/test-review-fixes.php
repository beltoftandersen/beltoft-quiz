<?php
require_once __DIR__ . '/bootstrap.php';

use Bgq\Quiz\Config;
use Bgq\Quiz\Token;
use Bgq\Quiz\Attempts;
use Bgq\Frontend\Shortcode;
use Bgq\Integrations\GiftCards;

global $wpdb;
$ip = '198.51.100.' . wp_rand( 1, 200 );
delete_transient( 'bgq_rl_' . \Bgq\Quiz\Attempts::ip_hash( $ip ) );
add_filter( 'bgq_client_ip', function () use ( &$ip ) { return $ip; } );

function bgq_r_quiz( array $settings = [], array $extra = [] ): int {
	$raw = array_merge( [
		'mode'      => 'score',
		'settings'  => array_merge( [ 'pass_mark' => 50 ], $settings ),
		'questions' => [ [ 'id' => 'q1', 'text' => 'A', 'type' => 'single', 'answers' => [ [ 'id' => 'a', 'text' => 'x', 'correct' => true ], [ 'id' => 'b', 'text' => 'y' ] ] ] ],
		'results'   => [ [ 'id' => 'hi', 'title' => 'Hi', 'min' => 50, 'max' => 100 ], [ 'id' => 'lo', 'title' => 'Lo', 'min' => 0, 'max' => 49 ] ],
	], $extra );
	$id = wp_insert_post( [ 'post_type' => 'bgq_quiz', 'post_title' => 'Review fixes', 'post_status' => 'publish' ] );
	Config::save( $id, Config::validate( $raw ) );
	bgq_test_register_cleanup( function () use ( $id ) { global $wpdb; $wpdb->delete( $wpdb->prefix . 'bgq_attempts', [ 'quiz_id' => $id ] ); wp_delete_post( $id, true ); } );
	return $id;
}
function bgq_r_post( string $path, array $body ) {
	$req = new WP_REST_Request( 'POST', $path );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( wp_json_encode( $body ) );
	$res = rest_do_request( $req );
	return [ $res->get_status(), $res->get_data(), $res->get_headers() ];
}

// 1. Token is issued by an uncacheable endpoint, not embedded at render time.
$quiz = bgq_r_quiz();
$req  = new WP_REST_Request( 'GET', '/bgq/v1/quizzes/' . $quiz . '/token' );
$res  = rest_do_request( $req );
bgq_assert_eq( 200, $res->get_status(), 'token endpoint responds' );
$tok = $res->get_data();
bgq_assert( isset( $tok['hash'] ) && Token::verify( $quiz, $tok, 0 ), 'token endpoint returns a valid token' );
$headers = $res->get_headers();
bgq_assert( isset( $headers['Cache-Control'] ) && false !== strpos( $headers['Cache-Control'], 'no-store' ), 'token response is uncacheable' );
wp_set_current_user( 0 );
ob_start(); $html = Shortcode::render( [ 'id' => $quiz ] ); ob_end_clean();
global $wp_scripts;
$inline = implode( "\n", (array) $wp_scripts->get_data( 'bgq-quiz', 'before' ) );
bgq_assert( false === strpos( $inline, '"hash"' ), 'no token embedded in the page' );
bgq_assert( false !== strpos( $inline, '"token_url"' ), 'page carries the token endpoint url' );
bgq_assert( false === strpos( $inline, '"nonce"' ), 'guests get no nonce' );

// 3. Logged-in visitors get a REST nonce so the submit runs as them.
wp_set_current_user( bgq_test_admin_id() );
ob_start(); Shortcode::render( [ 'id' => $quiz ] ); ob_end_clean();
$inline = implode( "\n", (array) $wp_scripts->get_data( 'bgq-quiz', 'before' ) );
bgq_assert( false !== strpos( $inline, '"nonce"' ), 'logged-in visitors get a nonce' );
wp_set_current_user( 0 );

// 1b. Duplicate guard is per visitor: the same token from another IP is a new attempt, not A's result.
$t = Token::issue( $quiz );
list( $s1, $d1 ) = bgq_r_post( '/bgq/v1/attempts', [ 'quiz_id' => $quiz, 'token' => $t, 'answers' => [ 'q1' => [ 'a' ] ] ] );
$ip = '198.51.100.' . wp_rand( 201, 254 );
delete_transient( 'bgq_rl_' . Attempts::ip_hash( $ip ) );
list( $s2, $d2 ) = bgq_r_post( '/bgq/v1/attempts', [ 'quiz_id' => $quiz, 'token' => $t, 'answers' => [ 'q1' => [ 'b' ] ] ] );
bgq_assert_eq( 200, $s2, 'second visitor accepted' );
bgq_assert_eq( false, $d2['already'], 'second visitor is not handed the first result' );
bgq_assert_eq( 0.0, (float) $d2['score'], 'second visitor graded on their own answers' );
list( $s3, $d3 ) = bgq_r_post( '/bgq/v1/attempts', [ 'quiz_id' => $quiz, 'token' => $t, 'answers' => [ 'q1' => [ 'b' ] ] ] );
bgq_assert_eq( true, $d3['already'], 'same visitor, same token, same answers → duplicate guard' );
bgq_assert_eq( 2, Attempts::count( [ 'quiz_id' => $quiz ] ), 'two rows total' );

// 2. Contributors cannot publish through the config endpoint.
$contrib = wp_insert_user( [ 'user_login' => 'bgq_contrib_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'contributor' ] );
bgq_test_register_cleanup( function () use ( $contrib ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $contrib ); } );
$own = wp_insert_post( [ 'post_type' => 'bgq_quiz', 'post_title' => 'Contrib', 'post_status' => 'draft', 'post_author' => $contrib ] );
bgq_test_register_cleanup( function () use ( $own ) { wp_delete_post( $own, true ); } );
wp_set_current_user( $contrib );
$valid = [ 'mode' => 'score', 'status' => 'publish', 'questions' => [ [ 'id' => 'q1', 'text' => 'A', 'type' => 'single', 'answers' => [ [ 'id' => 'a', 'text' => 'x', 'correct' => true ], [ 'id' => 'b', 'text' => 'y' ] ] ] ], 'results' => [ [ 'id' => 'r', 'title' => 'R', 'min' => 0, 'max' => 100 ] ] ];
list( $cs, $cd ) = bgq_r_post( '/bgq/v1/quizzes/' . $own . '/config', $valid );
bgq_assert_eq( 200, $cs, 'contributor can save the config' );
bgq_assert_eq( 'draft', get_post_status( $own ), 'contributor cannot publish' );
bgq_assert_eq( false, $cd['can_publish'], 'payload tells the builder publishing is not allowed' );
wp_set_current_user( 0 );

// 6. Translations load from the plugin folder.
switch_to_locale( 'pt_PT' );
bgq_assert_eq( 'Participações', __( 'Entries', 'beltoft-quiz' ), 'pt_PT catalog loaded from languages/' );
restore_previous_locale();

// 7. One reward per email per quiz.
if ( class_exists( '\\Bgcw\\GiftCard\\GiftCardCreator' ) ) {
	GiftCards::init();
	$rq = bgq_r_quiz( [ 'require_email' => true ], [ 'reward' => [ 'enabled' => true, 'amount' => 5, 'condition' => 'always' ] ] );
	$cfg = Config::load( $rq );
	$grade = \Bgq\Quiz\Grader::grade( $cfg, [ 'q1' => [ 'a' ] ] );
	$cards = [];
	foreach ( \Bgcw\GiftCard\Repository::get_by_recipient( 'drain@example.test' ) as $stale ) { \Bgcw\GiftCard\Repository::delete( $stale->id ); }
	bgq_test_register_cleanup( function () use ( &$cards ) { foreach ( $cards as $c ) { \Bgcw\GiftCard\Repository::delete( $c ); } } );
	$a1 = Attempts::insert( [ 'quiz_id' => $rq, 'email' => 'drain@example.test', 'score' => 100, 'result_id' => 'hi', 'answers' => [] ] );
	$r1 = apply_filters( 'bgq_attempt_reward', null, $a1, $cfg, $grade );
	$cards[] = (int) \Bgcw\GiftCard\Repository::find_by_code( $r1['code'] )->id;
	$a2 = Attempts::insert( [ 'quiz_id' => $rq, 'email' => 'drain@example.test', 'score' => 100, 'result_id' => 'hi', 'answers' => [] ] );
	$r2 = apply_filters( 'bgq_attempt_reward', null, $a2, $cfg, $grade );
	bgq_assert_eq( $r1['code'], $r2['code'] ?? null, 'second attempt with the same email gets the same card, not a new one' );
	bgq_assert_eq( 1, count( \Bgcw\GiftCard\Repository::get_by_recipient( 'drain@example.test' ) ), 'one card per email per quiz' );
}
