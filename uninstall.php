<?php
/**
 * Uninstall script for AI Search Summary
 *
 * This file runs when the plugin is deleted via the WordPress admin.
 * It removes all plugin data including options, transients, and database tables
 * unless the user has enabled the "Preserve Data on Uninstall" option.
 *
 * @package AI_Search_Summary
 */

// Exit if accessed directly or not called by WordPress uninstall
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Check if the user wants to preserve data
$aiss_options = get_option( 'aiss_options', array() );
if ( ! empty( $aiss_options['preserve_data_on_uninstall'] ) ) {
    // User chose to keep data — only clean up transients and object cache
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_aiss_%'
            OR option_name LIKE '_transient_timeout_aiss_%'"
    );

    delete_option( 'aiss_models_cache' );
    delete_option( 'aiss_cache_namespace' );
    delete_option( 'aiss_cache_keys' );

    wp_cache_flush();
    return;
}

global $wpdb;

// Delete plugin options
delete_option( 'aiss_options' );
delete_option( 'aiss_models_cache' );
delete_option( 'aiss_cache_namespace' );
delete_option( 'aiss_cache_keys' ); // Legacy option
delete_option( 'aiss_db_version' );

// Delete all transients created by the plugin
// Transients are stored in options table with _transient_ prefix
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_aiss_%'
        OR option_name LIKE '_transient_timeout_aiss_%'"
);

// Drop the logs table
$aiss_table_name = $wpdb->prefix . 'aiss_logs';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $aiss_table_name ) );

// Drop the feedback table
$aiss_feedback_table = $wpdb->prefix . 'aiss_feedback';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $aiss_feedback_table ) );

// Clear any cached data in object cache (if persistent caching is used)
wp_cache_flush();
