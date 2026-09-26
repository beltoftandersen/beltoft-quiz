<?php

namespace Bgq\Support;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress privacy tools: export and erase attempts by email; policy text.
 */
class Privacy {

	const GROUP = 'bgq-attempts';

	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', [ __CLASS__, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ __CLASS__, 'register_eraser' ] );
		add_action( 'admin_init', [ __CLASS__, 'policy_content' ] );
	}

	public static function register_exporter( $exporters ) {
		$exporters['beltoft-quiz'] = [ 'exporter_friendly_name' => __( 'Beltoft Quiz', 'beltoft-quiz' ), 'callback' => [ __CLASS__, 'export' ] ];
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['beltoft-quiz'] = [ 'eraser_friendly_name' => __( 'Beltoft Quiz', 'beltoft-quiz' ), 'callback' => [ __CLASS__, 'erase' ] ];
		return $erasers;
	}

	/**
	 * Personal data exporter callback.
	 */
	public static function export( $email, $page = 1 ) {
		global $wpdb;
		$email = strtolower( sanitize_email( (string) $email ) );
		$page  = max( 1, (int) $page );
		$per   = 100;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bgq_attempts WHERE email = %s ORDER BY id ASC LIMIT %d OFFSET %d", $email, $per, ( $page - 1 ) * $per ) );

		$data = [];
		foreach ( (array) $rows as $row ) {
			$data[] = [
				'group_id'    => self::GROUP,
				'group_label' => __( 'Quiz attempts', 'beltoft-quiz' ),
				'item_id'     => 'bgq-attempt-' . (int) $row->id,
				'data'        => [
					[ 'name' => __( 'Quiz', 'beltoft-quiz' ), 'value' => (string) get_post_field( 'post_title', (int) $row->quiz_id, 'raw' ) ],
					[ 'name' => __( 'Date', 'beltoft-quiz' ), 'value' => (string) $row->created_at ],
					[ 'name' => __( 'Name', 'beltoft-quiz' ), 'value' => (string) $row->name ],
					[ 'name' => __( 'Email', 'beltoft-quiz' ), 'value' => (string) $row->email ],
					[ 'name' => __( 'Score', 'beltoft-quiz' ), 'value' => (string) (float) $row->score ],
					[ 'name' => __( 'Result', 'beltoft-quiz' ), 'value' => (string) $row->result_id ],
					[ 'name' => __( 'Answers', 'beltoft-quiz' ), 'value' => (string) $row->answers ],
				],
			];
		}

		return [ 'data' => $data, 'done' => count( $rows ) < $per ];
	}

	/**
	 * Personal data eraser callback.
	 */
	public static function erase( $email, $page = 1 ) {
		global $wpdb;
		$email = strtolower( sanitize_email( (string) $email ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$deleted = $email ? (int) $wpdb->delete( $wpdb->prefix . 'bgq_attempts', [ 'email' => $email ], [ '%s' ] ) : 0;

		return [ 'items_removed' => $deleted > 0, 'items_retained' => false, 'messages' => [], 'done' => true ];
	}

	/**
	 * Suggested privacy policy text.
	 */
	public static function policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		wp_add_privacy_policy_content(
			__( 'Beltoft Quiz', 'beltoft-quiz' ),
			'<p>' . esc_html__( 'When you take a quiz on this site we store your answers, your score or result, the time it took, and a hashed form of your IP address. If the quiz asks for your name and email, we store those too and may email you a gift card. This data is kept until you ask us to delete it or the site owner deletes it.', 'beltoft-quiz' ) . '</p>'
		);
	}
}
