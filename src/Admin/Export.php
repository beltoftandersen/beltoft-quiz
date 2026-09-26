<?php

namespace Bgq\Admin;

use Bgq\Quiz\Attempts;
use Bgq\Quiz\Config;

defined( 'ABSPATH' ) || exit;

/**
 * CSV export of entries (admin-post, manage_options + nonce).
 */
class Export {

	const ACTION = 'bgq_export';

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle' ] );
	}

	public static function url( int $quiz_id = 0 ): string {
		return wp_nonce_url( add_query_arg( [ 'action' => self::ACTION, 'quiz_id' => $quiz_id ], admin_url( 'admin-post.php' ) ), self::ACTION );
	}

	/**
	 * Capability and nonce check.
	 */
	public static function can_export( string $nonce ): bool {
		return current_user_can( 'manage_options' ) && (bool) wp_verify_nonce( $nonce, self::ACTION );
	}

	public static function handle() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The nonce itself; verified in can_export().
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! self::can_export( $nonce ) ) {
			wp_die( esc_html__( 'You are not allowed to export entries.', 'beltoft-quiz' ), '', [ 'response' => 403 ] );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above in can_export().
		$quiz_id = isset( $_GET['quiz_id'] ) ? absint( wp_unslash( $_GET['quiz_id'] ) ) : 0;
		$csv     = self::csv( [ 'quiz_id' => $quiz_id ] );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="quiz-entries-' . gmdate( 'Y-m-d' ) . '.csv"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV body, escaped per cell in csv().
		exit;
	}

	/**
	 * Build the CSV text.
	 *
	 * @param array $args quiz_id, search.
	 */
	public static function csv( array $args ): string {
		$rows   = [ [ 'Date', 'Quiz', 'Name', 'Email', 'Score', 'Correct', 'Total', 'Result', 'Duration (s)' ] ];
		$titles = [];
		$page   = 1;
		do {
			$batch = Attempts::query( array_merge( $args, [ 'page' => $page, 'per_page' => 500, 'orderby' => 'id', 'order' => 'ASC' ] ) );
			foreach ( $batch as $a ) {
				$qid = (int) $a->quiz_id;
				if ( ! isset( $titles[ $qid ] ) ) {
					$titles[ $qid ] = (string) get_post_field( 'post_title', $qid, 'raw' );
				}
				$rows[] = [
					$a->created_at,
					$titles[ $qid ],
					$a->name,
					$a->email,
					(string) (float) $a->score,
					(string) (int) $a->correct_count,
					(string) (int) $a->total_count,
					self::result_title( $qid, (string) $a->result_id ),
					(string) (int) $a->duration_seconds,
				];
			}
			$page++;
		} while ( count( $batch ) === 500 );

		$out = "\xEF\xBB\xBF";
		foreach ( $rows as $row ) {
			$out .= implode( ',', array_map( [ __CLASS__, 'cell' ], $row ) ) . "\r\n";
		}
		return $out;
	}

	private static $result_cache = [];

	private static function result_title( int $quiz_id, string $result_id ): string {
		if ( ! isset( self::$result_cache[ $quiz_id ] ) ) {
			self::$result_cache[ $quiz_id ] = [];
			foreach ( Config::load( $quiz_id )['results'] as $r ) {
				self::$result_cache[ $quiz_id ][ $r['id'] ] = $r['title'];
			}
		}
		return self::$result_cache[ $quiz_id ][ $result_id ] ?? $result_id;
	}

	/**
	 * Quote a CSV cell; neutralise spreadsheet formulas.
	 */
	private static function cell( $value ): string {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], [ '=', '+', '-', '@' ], true ) && ! is_numeric( $value ) ) {
			$value = "'" . $value;
		}
		if ( preg_match( '/[",\r\n]/', $value ) ) {
			return '"' . str_replace( '"', '""', $value ) . '"';
		}
		return $value;
	}
}
