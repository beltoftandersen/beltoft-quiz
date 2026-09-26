<?php
/**
 * Beltoft Quiz - Uninstall. Removes data only when the owner opted in.
 *
 * @package Bgq
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$bgq_options = get_option( 'bgq_options', [] );
if ( ( $bgq_options['cleanup_on_uninstall'] ?? '' ) !== '1' ) {
	return;
}

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall cleanup; hardcoded table name.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bgq_attempts" );

$bgq_posts = get_posts( [ 'post_type' => 'bgq_quiz', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ] );
foreach ( $bgq_posts as $bgq_post_id ) {
	wp_delete_post( $bgq_post_id, true );
}

delete_option( 'bgq_options' );
delete_option( 'bgq_db_version' );
