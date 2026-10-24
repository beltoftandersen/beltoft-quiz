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

$recommend = Config::validate( [
	'mode'      => 'outcome',
	'settings'  => [ 'max_products' => 2, 'product_recommendation' => 'answer_matches' ],
	'questions' => [
		[ 'id' => 'q1', 'text' => 'One', 'type' => 'single', 'answers' => [ [ 'id' => 'a1', 'text' => 'A', 'points' => [ 'r1' => 1 ], 'products' => [ 10, 20 ] ], [ 'id' => 'a2', 'text' => 'B' ] ] ],
		[ 'id' => 'q2', 'text' => 'Two', 'type' => 'single', 'answers' => [ [ 'id' => 'a3', 'text' => 'C', 'points' => [ 'r1' => 1 ], 'products' => [ 20, 30 ] ], [ 'id' => 'a4', 'text' => 'D' ] ] ],
	],
	'results'   => [ [ 'id' => 'r1', 'title' => 'Result', 'product_ids' => [ 99, 98 ] ] ],
] );
bgq_assert_eq( [ 20, 10 ], Grader::recommend( $recommend, [ 'q1' => [ 'a1' ], 'q2' => [ 'a3' ] ], 'r1' ), 'answer matching ranks by match count, then question/product order' );
bgq_assert_eq( [ 99, 98 ], Grader::recommend( $recommend, [], 'r1' ), 'answer matching falls back to winning result products' );

$recommend['settings']['product_recommendation'] = 'result_products';
bgq_assert_eq( [ 99, 98 ], Grader::recommend( $recommend, [ 'q1' => [ 'a1' ], 'q2' => [ 'a3' ] ], 'r1' ), 'result-product method ignores answer product mappings' );

$product_one = wp_insert_post( [ 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Finder one' ] );
$product_two = wp_insert_post( [ 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Finder two' ] );
$product_hidden = wp_insert_post( [ 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Finder hidden' ] );
$product_oos = wp_insert_post( [ 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Finder out of stock' ] );
$hidden_object = wc_get_product( $product_hidden );
$hidden_object->set_catalog_visibility( 'hidden' );
$hidden_object->save();
$oos_object = wc_get_product( $product_oos );
$oos_object->set_stock_status( 'outofstock' );
$oos_object->save();
$term = wp_insert_term( 'Finder category ' . wp_rand(), 'product_cat' );
bgq_test_register_cleanup( function () use ( $product_one, $product_two, $product_hidden, $product_oos, $term ) {
	wp_delete_post( $product_one, true );
	wp_delete_post( $product_two, true );
	wp_delete_post( $product_hidden, true );
	wp_delete_post( $product_oos, true );
	if ( ! is_wp_error( $term ) ) { wp_delete_term( $term['term_id'], 'product_cat' ); }
} );
wp_set_object_terms( $product_one, [ $term['term_id'] ], 'product_cat' );
wp_set_object_terms( $product_two, [ $term['term_id'] ], 'product_cat' );
wp_set_object_terms( $product_hidden, [ $term['term_id'] ], 'product_cat' );
wp_set_object_terms( $product_oos, [ $term['term_id'] ], 'product_cat' );
$finder = Config::validate( [
	'mode'      => 'product',
	'settings'  => [ 'max_products' => 3 ],
	'questions' => [
		[ 'id' => 'q1', 'text' => 'Need', 'type' => 'multiple', 'answers' => [
			[ 'id' => 'specific', 'text' => 'Specific need', 'effects' => [ [ 'target' => 'product', 'product_id' => $product_one, 'action' => 'boost', 'weight' => 3 ] ] ],
			[ 'id' => 'category', 'text' => 'Category need', 'effects' => [ [ 'target' => 'term', 'taxonomy' => 'product_cat', 'term_id' => $term['term_id'], 'action' => 'boost', 'weight' => 1 ] ] ],
			[ 'id' => 'avoid', 'text' => 'Avoid one', 'effects' => [ [ 'target' => 'product', 'product_id' => $product_one, 'action' => 'exclude' ] ] ],
			[ 'id' => 'hidden', 'text' => 'Hidden product', 'effects' => [ [ 'target' => 'product', 'product_id' => $product_hidden, 'action' => 'boost', 'weight' => 10 ] ] ],
		] ],
	],
	'results'   => [ [ 'id' => 'finder', 'title' => 'Matches', 'product_ids' => [ $product_one, $product_two ] ] ],
] );
$term_query_limit = null;
$capture_term_query = function ( $query ) use ( &$term_query_limit ) {
	if ( 'product' === $query->get( 'post_type' ) && $query->get( 'tax_query' ) ) {
		$term_query_limit = $query->get( 'posts_per_page' );
	}
};
add_action( 'pre_get_posts', $capture_term_query );
$matched = Grader::grade( $finder, [ 'q1' => [ 'specific', 'category' ] ] );
remove_action( 'pre_get_posts', $capture_term_query );
bgq_assert_eq( [ $product_one, $product_two ], $matched['product_ids'], 'product finder ranks weighted direct and catalog rules' );
bgq_assert_eq( -1, $term_query_limit, 'catalog rules do not silently stop after 200 products' );
bgq_assert_eq( [ 'Specific need', 'Category need' ], $matched['product_reasons'][ $product_one ], 'product finder records recommendation reasons' );
$unavailable = Grader::grade( $finder, [ 'q1' => [ 'category', 'hidden' ] ] );
bgq_assert( ! in_array( $product_hidden, $unavailable['product_ids'], true ) && ! in_array( $product_oos, $unavailable['product_ids'], true ), 'hidden and out-of-stock products are not recommended' );
$excluded = Grader::grade( $finder, [ 'q1' => [ 'specific', 'category', 'avoid' ] ] );
bgq_assert_eq( [ $product_two ], $excluded['product_ids'], 'exclude rule overrides every boost' );
bgq_assert_eq( 'finder', $excluded['result_id'], 'product finder uses its presentation result' );
