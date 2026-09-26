<?php

namespace Bgq\Quiz;

defined( 'ABSPATH' ) || exit;

/**
 * Signed start token: proves the quiz was started, bounds the timer, and seeds shuffling.
 */
class Token {

	const GRACE = 30;

	/**
	 * Issue a token.
	 *
	 * @param int      $quiz_id    Quiz ID.
	 * @param int|null $started_at Unix timestamp (defaults to now).
	 * @return array{started_at:int,seed:string,hash:string}
	 */
	public static function issue( int $quiz_id, ?int $started_at = null ): array {
		$started_at = null === $started_at ? time() : $started_at;
		$seed       = substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 12 );

		return [
			'started_at' => $started_at,
			'seed'       => $seed,
			'hash'       => self::hash( $quiz_id, $started_at, $seed ),
		];
	}

	/**
	 * Verify a token for a quiz, honouring the timer plus a grace period.
	 *
	 * @param int   $quiz_id Quiz ID.
	 * @param array $token   Token from the client.
	 * @param int   $timer   Timer in seconds (0 = none).
	 */
	public static function verify( int $quiz_id, $token, int $timer ): bool {
		if ( ! is_array( $token ) || ! isset( $token['started_at'], $token['seed'], $token['hash'] ) ) {
			return false;
		}
		$started_at = (int) $token['started_at'];
		$seed       = preg_replace( '/[^a-f0-9]/', '', (string) $token['seed'] );
		if ( '' === $seed || ! hash_equals( self::hash( $quiz_id, $started_at, $seed ), (string) $token['hash'] ) ) {
			return false;
		}
		$max_age = $timer > 0 ? $timer + self::GRACE : DAY_IN_SECONDS;
		$age     = time() - $started_at;

		return $age >= -60 && $age <= $max_age;
	}

	/**
	 * Whether the token's timer has run out (ignoring the grace period).
	 */
	public static function expired( array $token, int $timer ): bool {
		return $timer > 0 && ( time() - (int) ( $token['started_at'] ?? 0 ) ) > $timer + self::GRACE;
	}

	private static function hash( int $quiz_id, int $started_at, string $seed ): string {
		return wp_hash( $quiz_id . '|' . $started_at . '|' . $seed, 'nonce' );
	}
}
