<?php
/**
 * Fired when the plugin is uninstalled (deleted from WP admin).
 *
 * Drops the custom database table and removes all plugin options.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop custom table.
$table_name = $wpdb->prefix . 'advt_tables';
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_name ) );

// Remove plugin options.
delete_option( 'advt_db_version' );
delete_option( 'advt_default_options' );
