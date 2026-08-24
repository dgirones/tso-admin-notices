<?php
/**
 * Uninstall TSO Admin Notices Manager.
 *
 * @package TSO_Admin_Notices
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$tsoan_option_keys = array(
	'tso_admin_notices_settings',
	'tso_an_settings', // legacy
);

foreach ( $tsoan_option_keys as $tsoan_key ) {
	delete_option( $tsoan_key );
}

if ( is_multisite() ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall only.
	$tsoan_blog_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs}" );
	foreach ( $tsoan_blog_ids as $tsoan_blog_id ) {
		switch_to_blog( (int) $tsoan_blog_id );
		foreach ( $tsoan_option_keys as $tsoan_key ) {
			delete_option( $tsoan_key );
		}
		restore_current_blog();
	}
}
