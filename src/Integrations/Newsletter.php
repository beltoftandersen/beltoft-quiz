<?php

namespace Bgq\Integrations;

use Bgq\Support\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Optional newsletter sign-up: an unticked checkbox on the details screen; when ticked, the visitor's
 * email is posted as JSON to a webhook from Quizzes > Settings, in the background after the attempt is saved.
 *
 * Payload: { email, list_id, api_key, source } (list_id and api_key only when filled in).
 */
class Newsletter {

	const HOOK = 'bgq_send_newsletter';

	public static function init() {
		add_action( 'bgq_attempt_created', [ __CLASS__, 'maybe_queue' ], 10, 4 );
		add_action( self::HOOK, [ __CLASS__, 'send' ], 10, 2 );
	}

	/**
	 * Whether the sign-up is switched on and has somewhere to send to.
	 */
	public static function enabled(): bool {
		return '1' === Options::get( 'newsletter_enabled' ) && '' !== (string) Options::get( 'newsletter_url' );
	}

	/**
	 * Checkbox label for a quiz, or '' when the checkbox is not shown (it needs the email step).
	 */
	public static function label_for( array $config ): string {
		if ( ! self::enabled() || empty( $config['settings']['require_email'] ) ) {
			return '';
		}
		$label = trim( (string) Options::get( 'newsletter_label' ) );
		return '' !== $label ? $label : __( 'Send me the newsletter', 'beltoft-quiz' );
	}

	/**
	 * Queue the webhook when the visitor ticked the box.
	 *
	 * @param int              $attempt_id Attempt row ID.
	 * @param array            $config     Quiz config.
	 * @param object           $attempt    Attempt row.
	 * @param \WP_REST_Request $request    Submit request.
	 */
	public static function maybe_queue( $attempt_id, array $config, $attempt, $request ) {
		if ( ! $request instanceof \WP_REST_Request || empty( $request['newsletter'] ) || '' === self::label_for( $config ) ) {
			return;
		}
		$email = sanitize_email( (string) ( $attempt->email ?? '' ) );
		if ( ! is_email( $email ) ) {
			return;
		}
		$args = [ $email, (int) $attempt_id ];
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, $args, 'beltoft-quiz' );
			return;
		}
		wp_schedule_single_event( time() + 5, self::HOOK, $args );
	}

	/**
	 * Post the sign-up to the webhook (runs in the background).
	 *
	 * @param string $email      Email address.
	 * @param int    $attempt_id Attempt row ID, for the log.
	 */
	public static function send( $email, $attempt_id = 0 ) {
		$email = sanitize_email( (string) $email );
		$url   = (string) Options::get( 'newsletter_url' );
		if ( ! is_email( $email ) || '' === $url ) {
			return;
		}
		$payload = [ 'email' => $email ];
		$list_id = trim( (string) Options::get( 'newsletter_list_id' ) );
		if ( '' !== $list_id ) {
			$payload['list_id'] = ctype_digit( $list_id ) ? (int) $list_id : $list_id;
		}
		$api_key = (string) Options::get( 'newsletter_api_key' );
		if ( '' !== $api_key ) {
			$payload['api_key'] = $api_key;
		}
		$payload['source'] = (string) Options::get( 'newsletter_source' ) ?: 'quiz';

		$response = wp_safe_remote_post(
			$url,
			[
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $payload ),
				'timeout' => 10,
			]
		);
		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			$detail = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . $code;
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error( 'Newsletter webhook failed: ' . $detail, [ 'source' => 'beltoft-quiz', 'attempt_id' => (int) $attempt_id ] );
			}
		}
	}
}
