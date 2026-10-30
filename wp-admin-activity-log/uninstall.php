<?php
// Exit if uninstall is not called from WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'waal_events' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
delete_option( 'waal_retention_days' );
wp_clear_scheduled_hook( 'waal_cleanup' );
