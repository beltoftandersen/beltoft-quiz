<?php

namespace Bgq\Integrations;

use Bgq\Quiz\Attempts;

defined( 'ABSPATH' ) || exit;

/**
 * Optional reward: issue a Beltoft Gift Cards card when an attempt qualifies.
 */
class GiftCards {

	public static function init() {
		add_filter( 'bgq_attempt_reward', [ __CLASS__, 'maybe_reward' ], 10, 4 );
	}

	/**
	 * Whether the Gift Cards plugin can issue cards.
	 */
	public static function available(): bool {
		$available = class_exists( '\\Bgcw\\GiftCard\\GiftCardCreator' ) && class_exists( '\\Bgcw\\GiftCard\\Repository' );

		/**
		 * Filter whether gift card rewards are available (tests use this to simulate the plugin being absent).
		 *
		 * @param bool $available Whether cards can be issued.
		 */
		return (bool) apply_filters( 'bgq_gift_cards_available', $available );
	}

	/**
	 * @param array|null $reward     Reward from an earlier filter.
	 * @param int        $attempt_id Attempt row ID.
	 * @param array      $config     Quiz config.
	 * @param array      $grade      Grader output.
	 * @return array|null { code, amount }
	 */
	public static function maybe_reward( $reward, $attempt_id, array $config, array $grade ) {
		if ( null !== $reward || ! self::available() ) {
			return $reward;
		}
		$rw = $config['reward'] ?? [];
		if ( empty( $rw['enabled'] ) || (float) ( $rw['amount'] ?? 0 ) <= 0 ) {
			return null;
		}

		$attempt = Attempts::get( (int) $attempt_id );
		if ( ! $attempt ) {
			return null;
		}

		// One card per attempt.
		if ( ! empty( $attempt->gift_card_id ) ) {
			$existing = \Bgcw\GiftCard\Repository::find( (int) $attempt->gift_card_id );
			return $existing ? [ 'code' => $existing->code, 'amount' => (float) $existing->initial_amount ] : null;
		}

		if ( ! self::qualifies( $rw, $grade ) ) {
			return null;
		}

		$email = (string) $attempt->email;
		if ( '' === $email || ! is_email( $email ) ) {
			return null;
		}

		// One reward per email per quiz: a repeat run by the same person returns the card already issued.
		$previous = self::previous_card( (int) $attempt->quiz_id, $email, (int) $attempt_id );
		if ( $previous ) {
			Attempts::update( (int) $attempt_id, [ 'gift_card_id' => (int) $previous->id ] );
			return [ 'code' => $previous->code, 'amount' => (float) $previous->initial_amount ];
		}

		$expiry = (int) ( $rw['expiry_days'] ?? 0 );
		$args   = [
			'amount'          => (float) $rw['amount'],
			'source'          => 'promotion',
			'recipient_email' => $email,
			'recipient_name'  => (string) $attempt->name,
			'sender_name'     => get_bloginfo( 'name' ),
			'message'         => get_the_title( (int) $attempt->quiz_id ),
			'expires_at'      => $expiry > 0 ? gmdate( 'Y-m-d H:i:s', time() + $expiry * DAY_IN_SECONDS ) : null,
			'send_email'      => true,
		];

		try {
			$card_id = \Bgcw\GiftCard\GiftCardCreator::create_manual( $args );
		} catch ( \Throwable $e ) {
			self::log( 'Gift card reward failed for attempt ' . (int) $attempt_id . ': ' . $e->getMessage() );
			return null;
		}
		if ( ! $card_id ) {
			self::log( 'Gift card reward failed for attempt ' . (int) $attempt_id . '.' );
			return null;
		}

		Attempts::update( (int) $attempt_id, [ 'gift_card_id' => (int) $card_id ] );
		$card = \Bgcw\GiftCard\Repository::find( (int) $card_id );

		return $card ? [ 'code' => $card->code, 'amount' => (float) $card->initial_amount ] : null;
	}

	/**
	 * Card already issued to this email for this quiz, if any.
	 *
	 * @return object|null
	 */
	private static function previous_card( int $quiz_id, string $email, int $except_attempt ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$card_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT gift_card_id FROM {$wpdb->prefix}bgq_attempts WHERE quiz_id = %d AND email = %s AND id <> %d AND gift_card_id IS NOT NULL ORDER BY id ASC LIMIT 1", $quiz_id, $email, $except_attempt ) );
		if ( ! $card_id ) {
			return null;
		}
		$card = \Bgcw\GiftCard\Repository::find( $card_id );
		return $card ? $card : null;
	}

	/**
	 * Whether the grade meets the reward condition.
	 */
	private static function qualifies( array $rw, array $grade ): bool {
		switch ( $rw['condition'] ?? 'always' ) {
			case 'pass':
				return ! empty( $grade['passed'] );
			case 'outcome':
				return in_array( (string) ( $grade['result_id'] ?? '' ), array_map( 'strval', (array) ( $rw['outcome_ids'] ?? [] ) ), true );
			default:
				return true;
		}
	}

	private static function log( string $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, [ 'source' => 'bgq' ] );
		} else {
			error_log( '[beltoft-quiz] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fallback when WooCommerce's logger is absent.
		}
	}
}
