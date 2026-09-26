<?php
require_once __DIR__ . '/bootstrap.php';
global $wpdb;
bgq_assert( post_type_exists( 'bgq_quiz' ), 'post type registered' );
$cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}bgq_attempts", 0 );
foreach ( [ 'id', 'quiz_id', 'user_id', 'email', 'name', 'score', 'correct_count', 'total_count', 'result_id', 'answers', 'ip_hash', 'duration_seconds', 'gift_card_id', 'created_at' ] as $c ) {
	bgq_assert( in_array( $c, $cols, true ), "column $c exists" );
}
bgq_assert_eq( BGQ_DB_VERSION, get_option( 'bgq_db_version' ), 'db version stored' );
bgq_assert_eq( '0', Bgq\Support\Options::get( 'cleanup_on_uninstall' ), 'options default' );
