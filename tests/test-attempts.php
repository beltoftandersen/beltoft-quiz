<?php
require_once __DIR__ . '/bootstrap.php';

use Bgq\Quiz\Config;
use Bgq\Quiz\Token;
use Bgq\Quiz\Attempts;

global $wpdb;
$table = $wpdb->prefix . 'bgq_attempts';

function bgq_t_quiz( array $overrides = [] ): int {
	$raw = [
		'mode'      => 'score',
		'settings'  => array_merge( [ 'pass_mark' => 50, 'timer' => 60 ], $overrides ),
		'questions' => [ [ 'id' => 'q1', 'text' => 'A', 'type' => 'single', 'answers' => [ [ 'id' => 'a', 'text' => 'x', 'correct' => true ], [ 'id' => 'b', 'text' => 'y' ] ] ] ],
		'results'   => [ [ 'id' => 'hi', 'title' => 'Hi', 'text' => '<p>Well done</p>', 'min' => 50, 'max' => 100 ], [ 'id' => 'lo', 'title' => 'Lo', 'min' => 0, 'max' => 49 ] ],
	];
	$id = wp_insert_post( [ 'post_type' => 'bgq_quiz', 'post_title' => 'Attempts test', 'post_status' => 'publish' ] );
	Config::save( $id, Config::validate( $raw ) );
	bgq_test_register_cleanup( function () use ( $id ) { global $wpdb; $wpdb->delete( $wpdb->prefix . 'bgq_attempts', [ 'quiz_id' => $id ] ); wp_delete_post( $id, true ); } );
	return $id;
}
function bgq_t_submit( int $quiz_id, array $body ) {
	$req = new WP_REST_Request( 'POST', '/bgq/v1/attempts' );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( wp_json_encode( array_merge( [ 'quiz_id' => $quiz_id ], $body ) ) );
	$res = rest_do_request( $req );
	return [ $res->get_status(), $res->get_data() ];
}
function bgq_t_rows( int $quiz_id ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bgq_attempts WHERE quiz_id = %d", $quiz_id ) );
}

$ip = '203.0.113.' . wp_rand( 1, 250 );
add_filter( 'bgq_client_ip', function () use ( &$ip ) { return $ip; } );

$quiz = bgq_t_quiz();

// Token.
$t = Token::issue( $quiz );
bgq_assert( isset( $t['started_at'], $t['seed'], $t['hash'] ), 'token has started_at, seed, hash' );
bgq_assert_eq( true, Token::verify( $quiz, $t, 60 ), 'fresh token verifies' );
$bad = $t; $bad['hash'] = 'nope';
bgq_assert_eq( false, Token::verify( $quiz, $bad, 60 ), 'tampered hash rejected' );
bgq_assert_eq( false, Token::verify( $quiz + 1, $t, 60 ), 'token bound to quiz id' );
$old = Token::issue( $quiz, time() - 85 );
bgq_assert_eq( true, Token::verify( $quiz, $old, 60 ), 'token grace: 85 s old with 60 s timer still valid' );
$older = Token::issue( $quiz, time() - 95 );
bgq_assert_eq( false, Token::verify( $quiz, $older, 60 ), 'token expired after timer + 30 s' );
bgq_assert_eq( true, Token::verify( $quiz, Token::issue( $quiz, time() - 3600 ), 0 ), 'no timer: an hour old is fine' );
bgq_assert_eq( false, Token::verify( $quiz, Token::issue( $quiz, time() - 2 * DAY_IN_SECONDS ), 0 ), 'no timer: two days old rejected' );

// Happy path.
list( $status, $data ) = bgq_t_submit( $quiz, [ 'token' => Token::issue( $quiz ), 'answers' => [ 'q1' => [ 'a' ] ] ] );
bgq_assert_eq( 200, $status, 'submit ok' );
bgq_assert_eq( 100.0, (float) $data['score'], 'score returned' );
bgq_assert_eq( 'hi', $data['result']['id'], 'result returned' );
bgq_assert_eq( false, $data['already'], 'first attempt not flagged' );
bgq_assert_eq( 1, bgq_t_rows( $quiz ), 'row inserted' );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE quiz_id = %d", $quiz ) );
bgq_assert_eq( Attempts::ip_hash( $ip ), $row->ip_hash, 'ip hashed' );
bgq_assert_eq( '{"q1":["a"]}', $row->answers, 'answers stored' );
bgq_assert( isset( json_decode( $row->recommendations, true )['ids'] ), 'recommendation snapshot stored with the attempt' );

// A crafted request cannot omit a question or select multiple radio answers.
list( $status, $data ) = bgq_t_submit( $quiz, [ 'token' => Token::issue( $quiz ), 'answers' => [] ] );
bgq_assert_eq( 400, $status, 'missing answers rejected' );
bgq_assert_eq( 'bgq_invalid_answers', $data['code'], 'missing answers use the validation error code' );
list( $status ) = bgq_t_submit( $quiz, [ 'token' => Token::issue( $quiz ), 'answers' => [ 'q1' => [ 'a', 'b' ] ] ] );
bgq_assert_eq( 400, $status, 'multiple answers for a single-choice question rejected' );
list( $status ) = bgq_t_submit( $quiz, [ 'token' => Token::issue( $quiz ), 'answers' => [ 'q1' => [ 'not-an-answer' ] ] ] );
bgq_assert_eq( 400, $status, 'unknown answer id rejected' );
bgq_assert_eq( 1, bgq_t_rows( $quiz ), 'invalid answer payloads insert nothing' );

// A timer that genuinely elapsed may store the questions reached so far.
$timed_token = Token::issue( $quiz, time() - 61 );
list( $status ) = bgq_t_submit( $quiz, [ 'token' => $timed_token, 'answers' => [] ] );
bgq_assert_eq( 200, $status, 'elapsed timed quiz may submit incomplete answers' );
bgq_assert_eq( 2, bgq_t_rows( $quiz ), 'timed-out partial attempt is stored once' );

// Errors.
list( $status, $data ) = bgq_t_submit( $quiz, [ 'token' => $bad, 'answers' => [] ] );
bgq_assert_eq( 403, $status, 'bad token 403' );
bgq_assert_eq( 'bgq_bad_token', $data['code'], 'bad token code' );
list( $status, $data ) = bgq_t_submit( $quiz, [ 'token' => $older, 'answers' => [] ] );
bgq_assert_eq( 410, $status, 'expired 410' );
list( $status, $data ) = bgq_t_submit( 999999999, [ 'token' => $t, 'answers' => [] ] );
bgq_assert_eq( 404, $status, 'unknown quiz 404' );
bgq_assert_eq( 2, bgq_t_rows( $quiz ), 'errors insert nothing' );

// Duplicate submit: same token twice → one row, second flagged already.
$dup = Token::issue( $quiz );
bgq_t_submit( $quiz, [ 'token' => $dup, 'answers' => [ 'q1' => [ 'b' ] ] ] );
list( $status, $data ) = bgq_t_submit( $quiz, [ 'token' => $dup, 'answers' => [ 'q1' => [ 'b' ] ] ] );
bgq_assert_eq( 200, $status, 'duplicate submit ok' );
bgq_assert_eq( true, $data['already'], 'duplicate submit flagged' );
bgq_assert_eq( 3, bgq_t_rows( $quiz ), 'duplicate submit: one row for the pair' );

// Email required + consent.
$quiz2 = bgq_t_quiz( [ 'require_email' => true, 'consent_text' => 'I agree' ] );
list( $status, $data ) = bgq_t_submit( $quiz2, [ 'token' => Token::issue( $quiz2 ), 'answers' => [ 'q1' => [ 'a' ] ] ] );
bgq_assert_eq( 'bgq_email_required', $data['code'] ?? '', 'email required' );
list( $status, $data ) = bgq_t_submit( $quiz2, [ 'token' => Token::issue( $quiz2 ), 'answers' => [ 'q1' => [ 'a' ] ], 'email' => 'lead@example.test' ] );
bgq_assert_eq( 'bgq_consent_required', $data['code'] ?? '', 'consent required when consent text set' );
list( $status, $data ) = bgq_t_submit( $quiz2, [ 'token' => Token::issue( $quiz2 ), 'answers' => [ 'q1' => [ 'a' ] ], 'email' => 'Lead@Example.test', 'name' => ' Ana <b>S</b> ', 'consent' => true ] );
bgq_assert_eq( 200, $status, 'email + consent accepted' );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE quiz_id = %d", $quiz2 ) );
bgq_assert_eq( 'lead@example.test', $row->email, 'email lowercased' );
bgq_assert_eq( 'Ana S', $row->name, 'name sanitized' );

// One attempt per visitor (guest by ip).
$quiz3 = bgq_t_quiz( [ 'one_attempt' => true ] );
bgq_t_submit( $quiz3, [ 'token' => Token::issue( $quiz3 ), 'answers' => [ 'q1' => [ 'a' ] ] ] );
list( $status, $data ) = bgq_t_submit( $quiz3, [ 'token' => Token::issue( $quiz3 ), 'answers' => [ 'q1' => [ 'b' ] ] ] );
bgq_assert_eq( true, $data['already'], 'second attempt returns stored result' );
bgq_assert_eq( 100.0, (float) $data['score'], 'stored result is the first one' );
bgq_assert_eq( 1, bgq_t_rows( $quiz3 ), 'one row only' );

// Rate limit: 10 per 10 minutes per ip.
$ip    = '203.0.113.251';
$quiz4 = bgq_t_quiz();
$last  = 0;
for ( $i = 0; $i < 11; $i++ ) {
	list( $last ) = bgq_t_submit( $quiz4, [ 'token' => Token::issue( $quiz4 ), 'answers' => [ 'q1' => [ 'a' ] ] ] );
}
bgq_assert_eq( 429, $last, '11th submit rate limited' );
delete_transient( 'bgq_rl_' . Attempts::ip_hash( $ip ) );

// Store helpers.
bgq_assert_eq( 10, Attempts::count( [ 'quiz_id' => $quiz4 ] ), 'count' );
$page = Attempts::query( [ 'quiz_id' => $quiz4, 'per_page' => 3, 'page' => 2 ] );
bgq_assert_eq( 3, count( $page ), 'query pagination' );
$first = Attempts::get( (int) $page[0]->id );
bgq_assert_eq( $quiz4, (int) $first->quiz_id, 'get' );
Attempts::delete( (int) $first->id );
bgq_assert_eq( 9, Attempts::count( [ 'quiz_id' => $quiz4 ] ), 'delete' );

// Stored recommendation snapshots survive later edits to the quiz rules.
$snapshot_one = new WC_Product_Simple();
$snapshot_one->set_name( 'Snapshot one' );
$snapshot_one->set_status( 'publish' );
$snapshot_one->set_regular_price( '10' );
$snapshot_one_id = $snapshot_one->save();
$snapshot_two = new WC_Product_Simple();
$snapshot_two->set_name( 'Snapshot two' );
$snapshot_two->set_status( 'publish' );
$snapshot_two->set_regular_price( '12' );
$snapshot_two_id = $snapshot_two->save();
bgq_test_register_cleanup( function () use ( $snapshot_one_id, $snapshot_two_id ) { wp_delete_post( $snapshot_one_id, true ); wp_delete_post( $snapshot_two_id, true ); } );
$snapshot_config = Config::validate( [
	'mode'      => 'product',
	'questions' => [ [ 'id' => 'pick', 'text' => 'Pick', 'type' => 'single', 'answers' => [
		[ 'id' => 'one', 'text' => 'One', 'effects' => [ [ 'target' => 'product', 'product_id' => $snapshot_two_id, 'action' => 'boost' ] ] ],
		[ 'id' => 'two', 'text' => 'Two' ],
	] ] ],
	'results'   => [ [ 'id' => 'result', 'title' => 'Products' ] ],
] );
$snapshot_attempt = (object) [
	'result_id'       => 'result',
	'score'           => 0,
	'correct_count'   => 0,
	'total_count'     => 1,
	'answers'         => wp_json_encode( [ 'pick' => [ 'one' ] ] ),
	'recommendations' => wp_json_encode( [ 'ids' => [ $snapshot_one_id ], 'reasons' => [ $snapshot_one_id => [ 'Original choice' ] ] ] ),
];
$snapshot_response = \Bgq\Rest\AttemptsController::response( $snapshot_config, $snapshot_attempt, true );
bgq_assert_eq( $snapshot_one_id, $snapshot_response['result']['products'][0]['id'], 'response uses the stored recommendation instead of current rules' );
bgq_assert_eq( 'Original choice', $snapshot_response['result']['products'][0]['reason'], 'stored recommendation reason is preserved' );
