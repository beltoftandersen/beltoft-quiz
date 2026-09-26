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
			ip_hash char(64) NOT NULL DEFAULT '',
			duration_seconds int(11) NOT NULL DEFAULT 0,
			gift_card_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY quiz_id (quiz_id),
			KEY quiz_user (quiz_id, user_id),
			KEY quiz_ip (quiz_id, ip_hash),
			KEY quiz_email (quiz_id, email(100))
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
