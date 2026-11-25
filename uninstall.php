<?php
/**
 * Désinstallation : options, transient, table du journal et cron.
 * Les brouillons et pages créés, ainsi que les métas _upsidly_agent_*, sont conservés.
 *
 * @package Upsidly_Agent
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Nettoie le site courant.
 */
function upsidly_agent_uninstall_site() {
	global $wpdb;

	foreach ( array( 'upsidly_agent_key', 'upsidly_agent_site_description', 'upsidly_agent_brand_voice', 'upsidly_agent_connection', 'upsidly_agent_agent_enabled', 'upsidly_agent_schema' ) as $upsidly_agent_option ) {
		delete_option( $upsidly_agent_option );
	}
	delete_transient( 'upsidly_agent_status' );
	delete_transient( 'upsidly_agent_activation_redirect' );
	wp_clear_scheduled_hook( 'upsidly_agent_daily_connect' );
	wp_clear_scheduled_hook( 'upsidly_agent_daily_purge' );
	delete_metadata( 'user', 0, 'upsidly_agent_ack', '', true );

	$upsidly_agent_role = get_role( 'administrator' );
	if ( $upsidly_agent_role ) {
		$upsidly_agent_role->remove_cap( 'upsidly_agent_use' );
	}

	foreach ( array( 'conversations', 'messages', 'actions', 'backups' ) as $upsidly_agent_table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tables propres au plugin.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}upsidly_agent_{$upsidly_agent_table}" );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table propre au plugin.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}upsidly_agent_log" );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $upsidly_agent_blog_id ) {
		switch_to_blog( $upsidly_agent_blog_id );
		upsidly_agent_uninstall_site();
		restore_current_blog();
	}
} else {
	upsidly_agent_uninstall_site();
}
