<?php

namespace Bgq\Support;

defined( 'ABSPATH' ) || exit;

class Installer {

	const DB_VERSION_KEY = 'bgq_db_version';

	/**
	 * Activation hook.
	 */
	public static function activate() {
		self::create_tables();
		if ( false === get_option( Options::OPTION ) ) {
			add_option( Options::OPTION, Options::defaults(), '', false );
		}
		update_option( self::DB_VERSION_KEY, BGQ_DB_VERSION );
	}

	/**
	 * Run on every load; upgrades the schema when the stored version is older.
	 */
	public static function maybe_upgrade() {
		if ( version_compare( get_option( self::DB_VERSION_KEY, '0' ), BGQ_DB_VERSION, '<' ) ) {
			self::create_tables();
			self::backfill_recommendations();
			update_option( self::DB_VERSION_KEY, BGQ_DB_VERSION );
		}
	}

	/**
	 * Create or update the attempts table.
	 */
	public static function create_tables() {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$wpdb->prefix}bgq_attempts (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			quiz_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			email varchar(255) NOT NULL DEFAULT '',
			name varchar(255) NOT NULL DEFAULT '',
			score decimal(5,2) NOT NULL DEFAULT 0.00,
			correct_count int(11) NOT NULL DEFAULT 0,
			total_count int(11) NOT NULL DEFAULT 0,
			result_id varchar(64) NOT NULL DEFAULT '',
			answers longtext,
			recommendations longtext,
			ip_hash char(64) NOT NULL DEFAULT '',
			duration_seconds int(11) NOT NULL DEFAULT 0,
			gift_card_id bigint(20) unsigned DEFAULT NULL,
			reward_key char(64) DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY reward_key (reward_key),
			KEY quiz_id (quiz_id),
			KEY quiz_user (quiz_id, user_id),
			KEY quiz_ip (quiz_id, ip_hash),
			KEY quiz_email (quiz_id, email(100))
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Freeze the best available recommendation for attempts created before 1.1.
	 *
	 * Rows are processed in bounded batches. Their stored result and answers are
	 * used, so the backfill does not alter scores or outcome history.
	 */
	public static function backfill_recommendations(): int {
		global $wpdb;
		$table   = $wpdb->prefix . 'bgq_attempts';
		$last_id = 0;
		$updated = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time custom-table migration.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, quiz_id, result_id, answers FROM {$table} WHERE id > %d AND (recommendations IS NULL OR recommendations = '') ORDER BY id ASC LIMIT 500", $last_id ) );
			foreach ( $rows as $row ) {
				$last_id = (int) $row->id;
				$config  = \Bgq\Quiz\Config::load( (int) $row->quiz_id );
				$answers = json_decode( (string) $row->answers, true );
				$details = \Bgq\Quiz\Grader::recommendation_details( $config, is_array( $answers ) ? $answers : [], (string) $row->result_id );
				$snapshot = wp_json_encode(
					[
						'ids'     => array_map( 'intval', (array) $details['ids'] ),
						'reasons' => (array) $details['reasons'],
					]
				);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- One-time custom-table migration.
				if ( false !== $wpdb->update( $table, [ 'recommendations' => $snapshot ], [ 'id' => (int) $row->id ], [ '%s' ], [ '%d' ] ) ) {
					$updated++;
				}
			}
		} while ( 500 === count( $rows ) );

		return $updated;
	}
}
