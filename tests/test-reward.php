<?php
require_once __DIR__ . '/bootstrap.php';

use Bgq\Quiz\Attempts;
use Bgq\Quiz\Config;
use Bgq\Quiz\Grader;
use Bgq\Integrations\GiftCards;

bgq_assert( class_exists( '\\Bgcw\\GiftCard\\GiftCardCreator' ), 'Gift Cards plugin active on the dev site' );
GiftCards::init();

$config = Config::validate( [
	'mode'      => 'score',
	'settings'  => [ 'pass_mark' => 50, 'require_email' => true ],
	'questions' => [ [ 'id' => 'q1', 'text' => 'A', 'type' => 'single', 'answers' => [ [ 'id' => 'a', 'text' => 'x', 'correct' => true ], [ 'id' => 'b', 'text' => 'y' ] ] ] ],
	'results'   => [ [ 'id' => 'hi', 'title' => 'Hi', 'min' => 50, 'max' => 100 ], [ 'id' => 'lo', 'title' => 'Lo', 'min' => 0, 'max' => 49 ] ],
	'reward'    => [ 'enabled' => true, 'amount' => 12.5, 'expiry_days' => 30, 'condition' => 'pass' ],
] );
$quiz = wp_insert_post( [ 'post_type' => 'bgq_quiz', 'post_title' => 'Reward quiz', 'post_status' => 'publish' ] );
$created_cards = [];
bgq_test_register_cleanup( function () use ( $quiz, &$created_cards ) {
	global $wpdb;
	$wpdb->delete( $wpdb->prefix . 'bgq_attempts', [ 'quiz_id' => $quiz ] );
	wp_delete_post( $quiz, true );
	foreach ( $created_cards as $cid ) { \Bgcw\GiftCard\Repository::delete( $cid ); }
} );

function bgq_t_attempt( int $quiz, float $score, string $email ): int {
	return Attempts::insert( [ 'quiz_id' => $quiz, 'email' => $email, 'name' => 'Winner', 'score' => $score, 'correct_count' => $score >= 100 ? 1 : 0, 'total_count' => 1, 'result_id' => $score >= 50 ? 'hi' : 'lo', 'answers' => [] ] );
}

// Passing attempt with email → card created, stored on the attempt, returned in the payload.
$pass_grade = Grader::grade( $config, [ 'q1' => [ 'a' ] ] );
$aid        = bgq_t_attempt( $quiz, 100, 'winner@example.test' );
$reward     = apply_filters( 'bgq_attempt_reward', null, $aid, $config, $pass_grade );
bgq_assert( is_array( $reward ) && ! empty( $reward['code'] ), 'reward returned for a passing attempt' );
bgq_assert_eq( 12.5, (float) $reward['amount'], 'reward amount' );
$card = \Bgcw\GiftCard\Repository::find_by_code( $reward['code'] );
$created_cards[] = (int) $card->id;
bgq_assert_eq( 'promotion', $card->source, 'card source is promotion' );
bgq_assert_eq( 'winner@example.test', $card->recipient_email, 'card goes to the attempt email' );
bgq_assert_eq( 12.5, (float) $card->initial_amount, 'card amount' );
bgq_assert( ! empty( $card->expires_at ) && strtotime( $card->expires_at ) > time() + 29 * DAY_IN_SECONDS, 'expiry from reward setting' );
bgq_assert_eq( (int) $card->id, (int) Attempts::get( $aid )->gift_card_id, 'card id stored on the attempt' );

// Same attempt again → no second card.
$again = apply_filters( 'bgq_attempt_reward', null, $aid, $config, $pass_grade );
bgq_assert_eq( $reward['code'], $again['code'], 'repeat returns the same card' );
bgq_assert_eq( 1, count( \Bgcw\GiftCard\Repository::get_by_recipient( 'winner@example.test' ) ), 'only one card for the attempt' );

// Failing attempt → nothing.
$fail_grade = Grader::grade( $config, [ 'q1' => [ 'b' ] ] );
$fid        = bgq_t_attempt( $quiz, 0, 'loser@example.test' );
bgq_assert_eq( null, apply_filters( 'bgq_attempt_reward', null, $fid, $config, $fail_grade ), 'no reward when failed' );

// No email → nothing.
$nid = bgq_t_attempt( $quiz, 100, '' );
bgq_assert_eq( null, apply_filters( 'bgq_attempt_reward', null, $nid, $config, $pass_grade ), 'no reward without an email' );

// Outcome condition.
$oc = $config; $oc['reward']['condition'] = 'outcome'; $oc['reward']['outcome_ids'] = [ 'lo' ];
$oid = bgq_t_attempt( $quiz, 0, 'outcome@example.test' );
$or  = apply_filters( 'bgq_attempt_reward', null, $oid, $oc, $fail_grade );
bgq_assert( is_array( $or ) && ! empty( $or['code'] ), 'outcome condition matches the lo result' );
$created_cards[] = (int) \Bgcw\GiftCard\Repository::find_by_code( $or['code'] )->id;

// Gift Cards plugin absent → reward null, no error.
add_filter( 'bgq_gift_cards_available', '__return_false' );
$xid = bgq_t_attempt( $quiz, 100, 'nogc@example.test' );
bgq_assert_eq( null, apply_filters( 'bgq_attempt_reward', null, $xid, $config, $pass_grade ), 'reward without plugin: null' );
remove_filter( 'bgq_gift_cards_available', '__return_false' );

// Disabled reward → nothing.
$dc = $config; $dc['reward']['enabled'] = false;
$did = bgq_t_attempt( $quiz, 100, 'disabled@example.test' );
bgq_assert_eq( null, apply_filters( 'bgq_attempt_reward', null, $did, $dc, $pass_grade ), 'disabled reward: null' );
