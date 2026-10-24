<?php
require_once __DIR__ . '/bootstrap.php';

use Bgq\Quiz\Config;

$raw = [
	'mode'      => 'score',
	'settings'  => [ 'timer' => '90', 'pass_mark' => '60', 'accent' => '#abc', 'labels' => [ 'start' => '<b>Go</b>' ] ],
	'questions' => [ [ 'id' => 'q1', 'text' => 'Capital of Portugal?', 'type' => 'single', 'answers' => [ [ 'id' => 'a1', 'text' => 'Lisbon', 'correct' => true ], [ 'id' => 'a2', 'text' => 'Porto' ] ] ] ],
	'results'   => [ [ 'id' => 'r1', 'title' => 'Great', 'min' => 50, 'max' => 100 ], [ 'id' => 'r0', 'title' => 'Study', 'min' => 0, 'max' => 49 ] ],
];
$c = Config::validate( $raw );
bgq_assert( ! is_wp_error( $c ), 'valid config accepted' );
bgq_assert_eq( 90, $c['settings']['timer'], 'timer cast to int' );
bgq_assert_eq( '#aabbcc', $c['settings']['accent'], 'accent normalised to 6-digit hex' );
bgq_assert_eq( 'Go', $c['settings']['labels']['start'], 'labels stripped of tags' );
bgq_assert_eq( '', $c['settings']['labels']['next'], 'missing labels stored empty (resolved to defaults at load)' );
bgq_assert_eq( true, $c['questions'][0]['answers'][0]['correct'], 'correct kept' );
bgq_assert_eq( false, $c['questions'][0]['answers'][1]['correct'], 'missing correct is false' );

$bad = Config::validate( [ 'mode' => 'weird', 'questions' => [], 'results' => [] ] );
bgq_assert( is_wp_error( $bad ), 'invalid mode rejected' );
$errors = $bad->get_error_data()['errors'];
bgq_assert( isset( $errors['mode'] ) && isset( $errors['questions'] ), 'field-level errors for mode and empty questions' );

$no_correct = $raw;
$no_correct['questions'][0]['answers'][0]['correct'] = false;
bgq_assert( is_wp_error( Config::validate( $no_correct ) ), 'score question without a correct answer rejected' );

$one_answer = $raw;
$one_answer['questions'][0]['answers'] = [ $raw['questions'][0]['answers'][0] ];
bgq_assert( is_wp_error( Config::validate( $one_answer ) ), 'question with one answer rejected' );

$outcome = [ 'mode' => 'outcome', 'questions' => [ [ 'id' => 'q1', 'text' => 'Pick', 'type' => 'single', 'answers' => [ [ 'id' => 'a1', 'text' => 'A', 'points' => [ 'r1' => 2, 'zzz' => 5 ] ], [ 'id' => 'a2', 'text' => 'B' ] ] ] ], 'results' => [ [ 'id' => 'r1', 'title' => 'Result A' ] ] ];
$oc = Config::validate( $outcome );
bgq_assert( ! is_wp_error( $oc ) && [ 'r1' => 2 ] === $oc['questions'][0]['answers'][0]['points'], 'points for unknown results dropped' );
bgq_assert_eq( 'result_products', $oc['settings']['product_recommendation'], 'legacy quiz without answer products infers result-product recommendations' );

$answer_product = $outcome;
$answer_product['questions'][0]['answers'][0]['products'] = [ 42 ];
$apc = Config::validate( $answer_product );
bgq_assert_eq( 'answer_matches', $apc['settings']['product_recommendation'], 'legacy quiz with answer products infers answer matching' );

$answer_product['settings']['product_recommendation'] = 'result_products';
$apc = Config::validate( $answer_product );
bgq_assert_eq( 'result_products', $apc['settings']['product_recommendation'], 'explicit recommendation method is preserved' );

$finder = Config::validate( [
	'mode'      => 'product',
	'questions' => [ [ 'id' => 'qf', 'text' => 'Hair?', 'type' => 'single', 'answers' => [
		[ 'id' => 'dry', 'text' => 'Dry', 'effects' => [ [ 'target' => 'product', 'product_id' => 123, 'action' => 'boost', 'weight' => 20 ], [ 'target' => 'term', 'taxonomy' => 'product_cat', 'term_id' => 4, 'action' => 'exclude' ], [ 'target' => 'term', 'taxonomy' => 'category', 'term_id' => 8, 'action' => 'boost' ] ] ],
		[ 'id' => 'normal', 'text' => 'Normal' ],
	] ] ],
	'results'   => [ [ 'id' => 'recommendations', 'title' => 'Your products' ] ],
] );
bgq_assert( ! is_wp_error( $finder ), 'product finder config accepted' );
bgq_assert_eq( 'product_rules', $finder['settings']['product_recommendation'], 'product finder always uses product rules' );
bgq_assert_eq( 10, $finder['questions'][0]['answers'][0]['effects'][0]['weight'], 'product rule strength capped at ten' );
bgq_assert_eq( 2, count( $finder['questions'][0]['answers'][0]['effects'] ), 'invalid product taxonomy rule removed' );

$finder_blank = [
	'mode'      => 'product',
	'questions' => [ [ 'id' => 'qf', 'text' => 'Pick', 'type' => 'single', 'answers' => [ [ 'id' => 'a', 'text' => '', 'effects' => [ [ 'target' => 'product', 'product_id' => 1, 'action' => 'boost' ] ] ], [ 'id' => 'b', 'text' => 'B' ] ] ] ],
	'results'   => [ [ 'id' => 'r', 'title' => 'R' ] ],
];
bgq_assert( is_wp_error( Config::validate( $finder_blank ) ), 'blank answer text is rejected server-side' );
$finder_blank['questions'][0]['answers'][0]['text'] = 'A';
$finder_blank['questions'][0]['answers'][0]['effects'] = [];
bgq_assert( is_wp_error( Config::validate( $finder_blank ) ), 'product finder without a boost or fallback is rejected server-side' );
$finder_blank['results'][] = [ 'id' => 'r2', 'title' => 'Second', 'product_ids' => [ 1 ] ];
$multiple_results = Config::validate( $finder_blank );
bgq_assert( is_wp_error( $multiple_results ) && isset( $multiple_results->get_error_data()['errors']['results'] ), 'product finder with multiple result screens is rejected server-side' );

$dupe = $raw;
$dupe['questions'][0]['answers'][1]['id'] = 'a1';
$dc = Config::validate( $dupe );
bgq_assert( ! is_wp_error( $dc ) && $dc['questions'][0]['answers'][0]['id'] !== $dc['questions'][0]['answers'][1]['id'], 'duplicate ids regenerated' );

$reward = $raw;
$reward['reward'] = [ 'enabled' => true, 'amount' => 0 ];
bgq_assert( is_wp_error( Config::validate( $reward ) ), 'enabled reward needs an amount' );

$pub = Config::public_view( $c );
bgq_assert( ! isset( $pub['questions'][0]['answers'][0]['correct'] ) && ! isset( $pub['results'][0]['min'] ) && ! isset( $pub['reward'] ), 'public view strips grading data' );
bgq_assert( isset( $pub['questions'][0]['answers'][0]['text'] ) && isset( $pub['settings']['labels'] ), 'public view keeps text and labels' );

$post_id = wp_insert_post( [ 'post_type' => 'bgq_quiz', 'post_title' => 'T', 'post_status' => 'publish' ] );
bgq_test_register_cleanup( function () use ( $post_id ) { wp_delete_post( $post_id, true ); } );
Config::save( $post_id, $c );
bgq_assert_eq( 'score', Config::load( $post_id )['mode'], 'save/load round trip' );
bgq_assert_eq( Config::default_labels()['next'], Config::load( 999999999 )['settings']['labels']['next'], 'load of unknown quiz returns defaults' );
bgq_assert( (bool) preg_match( '/^q_[a-z0-9]{6}$/', Config::new_id( 'q' ) ), 'new_id format' );
