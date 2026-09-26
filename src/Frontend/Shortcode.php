<?php

namespace Bgq\Frontend;

use Bgq\Quiz\Config;
use Bgq\Quiz\PostType;
use Bgq\Quiz\Token;

defined( 'ABSPATH' ) || exit;

/**
 * [beltoft_quiz id="123"]
 */
class Shortcode {

	const TAG = 'beltoft_quiz';

	public static function init() {
		add_shortcode( self::TAG, [ __CLASS__, 'render' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_assets' ] );
	}

	/**
	 * Register (not enqueue) the player assets; the shortcode enqueues on use.
	 */
	public static function register_assets() {
		wp_register_style( 'bgq-quiz', BGQ_URL . 'assets/css/quiz.css', [], self::asset_version( 'assets/css/quiz.css' ) );
		wp_register_script( 'bgq-quiz', BGQ_URL . 'assets/js/quiz.js', [], self::asset_version( 'assets/js/quiz.js' ), true );
	}

	private static function asset_version( string $relative ): string {
		$file = BGQ_PATH . $relative;
		return file_exists( $file ) ? (string) filemtime( $file ) : BGQ_VERSION;
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts ) {
		$atts    = shortcode_atts( [ 'id' => 0 ], (array) $atts, self::TAG );
		$quiz_id = absint( $atts['id'] );
		$post    = $quiz_id ? get_post( $quiz_id ) : null;

		if ( ! $post || PostType::TYPE !== $post->post_type ) {
			return self::editor_notice( __( 'Beltoft Quiz: no quiz found with this ID.', 'beltoft-quiz' ) );
		}
		if ( 'publish' !== $post->post_status ) {
			return self::editor_notice( __( 'Beltoft Quiz: this quiz is not published yet.', 'beltoft-quiz' ) );
		}

		$config = Config::load( $quiz_id );
		if ( empty( $config['questions'] ) || empty( $config['results'] ) ) {
			return self::editor_notice( __( 'Beltoft Quiz: this quiz has no questions or results yet.', 'beltoft-quiz' ) );
		}

		if ( ! wp_script_is( 'bgq-quiz', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'bgq-quiz' );
		wp_enqueue_script( 'bgq-quiz' );

		$data = [
			'id'       => $quiz_id,
			'title'    => get_the_title( $post ),
			'config'   => Config::public_view( $config ),
			'token'    => Token::issue( $quiz_id ),
			'rest_url' => esc_url_raw( rest_url( 'bgq/v1/attempts' ) ),
			'i18n'     => [
				/* translators: 1: current question number, 2: total questions */
				'question_of'   => __( 'Question %1$s of %2$s', 'beltoft-quiz' ),
				/* translators: %s: number of questions */
				'questions'     => __( '%s questions', 'beltoft-quiz' ),
				/* translators: %s: time limit as m:ss */
				'time_limit'    => __( 'Time limit: %s', 'beltoft-quiz' ),
				'time_left'     => __( 'Time left', 'beltoft-quiz' ),
				'select_answer' => __( 'Please choose an answer.', 'beltoft-quiz' ),
				'your_details'  => __( 'Almost there', 'beltoft-quiz' ),
				'name'          => __( 'Your name', 'beltoft-quiz' ),
				'email'         => __( 'Your email', 'beltoft-quiz' ),
				'consent'       => __( 'I agree', 'beltoft-quiz' ),
				'email_invalid' => __( 'Please enter a valid email address.', 'beltoft-quiz' ),
				'consent_req'   => __( 'Please accept the consent checkbox.', 'beltoft-quiz' ),
				'sending'       => __( 'Checking your answers…', 'beltoft-quiz' ),
				'error'         => __( 'Something went wrong. Please try again.', 'beltoft-quiz' ),
				/* translators: 1: score percentage, 2: correct answers, 3: total questions */
				'score_text'    => __( 'You scored %1$s%% (%2$s of %3$s correct).', 'beltoft-quiz' ),
				'already'       => __( 'You have already taken this quiz. Here is your result.', 'beltoft-quiz' ),
				/* translators: %s: gift card code */
				'reward'        => __( 'Your gift card code: %s', 'beltoft-quiz' ),
				'reward_sent'   => __( 'We have also emailed it to you.', 'beltoft-quiz' ),
				'add_to_cart'   => __( 'Add to cart', 'beltoft-quiz' ),
				'view_product'  => __( 'View product', 'beltoft-quiz' ),
				'times_up'      => __( 'Time is up, sending your answers…', 'beltoft-quiz' ),
			],
		];
		wp_add_inline_script( 'bgq-quiz', 'window.bgq_data_' . $quiz_id . ' = ' . wp_json_encode( $data ) . ';', 'before' );

		$accent = sanitize_hex_color( $config['settings']['accent'] ) ?: '#1f4a36';

		return sprintf(
			'<div class="bgq" id="bgq-%1$d" data-quiz="%1$d" style="--bgq-accent:%2$s"><noscript><p>%3$s</p></noscript></div>',
			$quiz_id,
			esc_attr( $accent ),
			esc_html__( 'This quiz needs JavaScript to run.', 'beltoft-quiz' )
		);
	}

	/**
	 * Visible to users who can edit posts; empty for everyone else.
	 */
	private static function editor_notice( string $message ): string {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}
		return '<p class="bgq-notice">' . esc_html( $message ) . '</p>';
	}
}
