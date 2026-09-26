<?php

namespace Bgq\Quiz;

defined( 'ABSPATH' ) || exit;

/**
 * Storage for quiz attempts (custom table).
 */
class Attempts {

	/**
	 * Insert an attempt.
	 *
	 * @param array $row Column => value.
	 * @return int Row ID (0 on failure).
	 */
	public static function insert( array $row ): int {
		global $wpdb;

		$data = [
			'quiz_id'          => (int) ( $row['quiz_id'] ?? 0 ),
			'user_id'          => (int) ( $row['user_id'] ?? 0 ),
			'email'            => (string) ( $row['email'] ?? '' ),
			'name'             => (string) ( $row['name'] ?? '' ),
			'score'            => (float) ( $row['score'] ?? 0 ),
			'correct_count'    => (int) ( $row['correct_count'] ?? 0 ),
			'total_count'      => (int) ( $row['total_count'] ?? 0 ),
			'result_id'        => (string) ( $row['result_id'] ?? '' ),
			'answers'          => is_string( $row['answers'] ?? null ) ? $row['answers'] : wp_json_encode( $row['answers'] ?? [] ),
			'ip_hash'          => (string) ( $row['ip_hash'] ?? '' ),
			'duration_seconds' => (int) ( $row['duration_seconds'] ?? 0 ),
			'created_at'       => current_time( 'mysql', true ),
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$ok = $wpdb->insert( $wpdb->prefix . 'bgq_attempts', $data, [ '%d', '%d', '%s', '%s', '%f', '%d', '%d', '%s', '%s', '%s', '%d', '%s' ] );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Hash an IP so it is not stored in clear.
	 */
	public static function ip_hash( string $ip ): string {
		return hash( 'sha256', $ip . wp_salt( 'nonce' ) );
	}

	/**
	 * Client IP, filterable for proxies and tests.
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filter the client IP used for rate limiting and one-attempt checks.
		 *
		 * @param string $ip IP address.
		 */
		return (string) apply_filters( 'bgq_client_ip', $ip );
	}

	/**
	 * An earlier attempt by the same visitor, if any.
	 *
	 * @return object|null
	 */
	public static function find_existing( int $quiz_id, int $user_id, string $ip_hash, string $email ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bgq_attempts';

		if ( $user_id > 0 ) {
			// Logged-in visitors are identified by their account only (a shared IP must not link them to a guest).
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bgq_attempts WHERE quiz_id = %d AND user_id = %d ORDER BY id ASC LIMIT 1", $quiz_id, $user_id ) );
			return $row ? $row : null;
		}
		if ( '' !== $email ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bgq_attempts WHERE quiz_id = %d AND email = %s ORDER BY id ASC LIMIT 1", $quiz_id, $email ) );
			if ( $row ) {
				return $row;
			}
		}
		if ( '' !== $ip_hash ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bgq_attempts WHERE quiz_id = %d AND ip_hash = %s ORDER BY id ASC LIMIT 1", $quiz_id, $ip_hash ) );
			if ( $row ) {
				return $row;
			}
		}
		unset( $table );

		return null;
	}

	/**
	 * @return object|null
	 */
	public static function get( int $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bgq_attempts WHERE id = %d", $id ) );
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return (bool) $wpdb->delete( $wpdb->prefix . 'bgq_attempts', [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * Update columns on an attempt.
	 */
	public static function update( int $id, array $fields ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return false !== $wpdb->update( $wpdb->prefix . 'bgq_attempts', $fields, [ 'id' => $id ] );
	}

	/**
	 * WHERE clause + values from query args (quiz_id, search).
	 *
	 * @return array{0:string,1:array}
	 */
	private static function where( array $args ): array {
		global $wpdb;
		$sql    = [];
		$values = [];
		if ( ! empty( $args['quiz_id'] ) ) {
			$sql[]    = 'quiz_id = %d';
			$values[] = (int) $args['quiz_id'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$sql[]    = '(email LIKE %s OR name LIKE %s)';
			$values[] = $like;
			$values[] = $like;
		}
		return [ $sql ? 'WHERE ' . implode( ' AND ', $sql ) : '', $values ];
	}

	/**
	 * Paginated rows.
	 *
	 * @param array $args quiz_id, search, page, per_page, orderby (id|score|created_at), order (ASC|DESC).
	 * @return object[]
	 */
	public static function query( array $args = [] ): array {
		global $wpdb;
		list( $where, $values ) = self::where( $args );
		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$offset   = ( max( 1, (int) ( $args['page'] ?? 1 ) ) - 1 ) * $per_page;
		$orderby  = in_array( $args['orderby'] ?? '', [ 'id', 'score', 'created_at' ], true ) ? $args['orderby'] : 'id';
		$order    = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
		$values[] = $per_page;
		$values[] = $offset;

		$sql = "SELECT * FROM {$wpdb->prefix}bgq_attempts {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; WHERE/ORDER BY built from whitelisted literals, values bound via prepare().
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
	}

	public static function count( array $args = [] ): int {
		global $wpdb;
		list( $where, $values ) = self::where( $args );
		$sql = "SELECT COUNT(*) FROM {$wpdb->prefix}bgq_attempts {$where}";
		if ( ! $values ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; no dynamic values in this branch.
			return (int) $wpdb->get_var( $sql );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; WHERE built from whitelisted literals, values bound via prepare().
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
	}
}
