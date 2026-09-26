<?php
require_once __DIR__ . '/bootstrap.php';

use Bgq\Quiz\Config;
use Bgq\Frontend\Shortcode;
use Bgq\Frontend\Results;

$raw = [
	'mode'      => 'score',
	'settings'  => [ 'accent' => '#123456' ],
	'questions' => [ [ 'id' => 'q1', 'text' => 'A', 'type' => 'single', 'answers' => [ [ 'id' => 'a', 'text' => 'x', 'correct' => true ], [ 'id' => 'b', 'text' => 'y' ] ] ] ],
	'results'   => [ [ 'id' => 'hi', 'title' => 'Hi', 'text' => '<p>Done</p>', 'min' => 0, 'max' => 100, 'button_label' => 'Shop', 'button_url' => 'https://example.test/shop' ] ],
];
$id = wp_insert_post( [ 'post_type' => 'bgq_quiz', 'post_title' => 'Shortcode test', 'post_status' => 'publish' ] );
Config::save( $id, Config::validate( $raw ) );
$draft = wp_insert_post( [ 'post_type' => 'bgq_quiz', 'post_title' => 'Draft', 'post_status' => 'draft' ] );
bgq_test_register_cleanup( function () use ( $id, $draft ) { wp_delete_post( $id, true ); wp_delete_post( $draft, true ); } );

wp_set_current_user( 0 );
bgq_assert_eq( '', Shortcode::render( [ 'id' => 999999999 ] ), 'unknown id renders nothing for visitors' );
bgq_assert_eq( '', Shortcode::render( [ 'id' => $draft ] ), 'draft renders nothing for visitors' );
wp_set_current_user( bgq_test_admin_id() );
bgq_assert( false !== strpos( Shortcode::render( [ 'id' => $draft ] ), 'bgq-notice' ), 'draft shows a notice to editors' );
wp_set_current_user( 0 );

$html = Shortcode::render( [ 'id' => $id ] );
bgq_assert( false !== strpos( $html, 'data-quiz="' . $id . '"' ), 'container has data-quiz' );
bgq_assert( false !== strpos( $html, '--bgq-accent:#123456' ), 'accent custom property set' );
bgq_assert( false !== strpos( $html, '<noscript' ), 'noscript fallback' );
bgq_assert( wp_script_is( 'bgq-quiz', 'enqueued' ) && wp_style_is( 'bgq-quiz', 'enqueued' ), 'assets enqueued' );

global $wp_scripts;
$inline = implode( "\n", (array) $wp_scripts->get_data( 'bgq-quiz', 'before' ) );
bgq_assert( false !== strpos( $inline, 'bgq_data_' . $id ), 'inline data object present' );
bgq_assert( false === strpos( $inline, '"correct"' ) && false === strpos( $inline, '"points"' ) && false === strpos( $inline, '"min"' ), 'inline data has no grading info' );
bgq_assert( false !== strpos( $inline, '"token_url"' ) && false !== strpos( $inline, '"rest_url"' ), 'inline data has token url and rest url' );

$cfg = Config::load( $id );
$r   = Results::render( $cfg, 'hi' );
bgq_assert_eq( 'Hi', $r['title'], 'result title' );
bgq_assert_eq( '<p>Done</p>', trim( $r['text'] ), 'result text' );
bgq_assert_eq( 'https://example.test/shop', $r['button_url'], 'button url' );
bgq_assert_eq( null, $r['product'], 'no product' );

if ( function_exists( 'wc_get_product' ) ) {
	$pid = wc_get_products( [ 'type' => 'simple', 'limit' => 1, 'status' => 'publish', 'return' => 'ids' ] );
	if ( $pid ) {
		$cfg['results'][0]['product_id'] = (int) $pid[0];
		$r = Results::render( $cfg, 'hi' );
		bgq_assert( is_array( $r['product'] ) && '?add-to-cart=' . $pid[0] === substr( $r['product']['add_to_cart_url'], -strlen( '?add-to-cart=' . $pid[0] ) ), 'product result has add-to-cart url' );
		bgq_assert( '' !== $r['product']['name'] && '' !== $r['product']['price_html'], 'product name and price' );
	}
}

// The REST response uses Results::render through the filter.
$payload = apply_filters( 'bgq_render_result', null, $cfg, $cfg['results'][0] );
bgq_assert( is_array( $payload ) && 'Shop' === $payload['button_label'], 'REST result payload rendered by Results' );
