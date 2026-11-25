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

	foreach ( array( 'upsidly_agent_key', 'upsidly_agent_site_description', 'upsidly_agent_brand_voice', 'upsidly_agent_connection' ) as $upsidly_agent_option ) {
		delete_option( $upsidly_agent_option );
	}
	delete_transient( 'upsidly_agent_status' );
	delete_transient( 'upsidly_agent_activation_redirect' );
	wp_clear_scheduled_hook( 'upsidly_agent_daily_connect' );

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
