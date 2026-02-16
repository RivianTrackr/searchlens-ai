<?php
/**
 * Uninstall script for SearchLens AI
 *
 * This file runs when the plugin is deleted via the WordPress admin.
 * It removes all plugin data including options, transients, and database tables
 * unless the user has enabled the "Preserve Data on Uninstall" option.
 *
 * @package SearchLens_AI
 */

// Exit if accessed directly or not called by WordPress uninstall
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Check if the user wants to preserve data
$searchlens_options = get_option( 'searchlens_options', array() );
if ( ! empty( $searchlens_options['preserve_data_on_uninstall'] ) ) {
    // User chose to keep data — only clean up transients and object cache
    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_searchlens_%'
            OR option_name LIKE '_transient_timeout_searchlens_%'"
    );

    delete_option( 'searchlens_models_cache' );
    delete_option( 'searchlens_cache_namespace' );
    delete_option( 'searchlens_cache_keys' );

    wp_cache_flush();
    return;
}

global $wpdb;

// Delete plugin options
delete_option( 'searchlens_options' );
delete_option( 'searchlens_models_cache' );
delete_option( 'searchlens_cache_namespace' );
delete_option( 'searchlens_cache_keys' ); // Legacy option
delete_option( 'searchlens_db_version' );

// Delete all transients created by the plugin
// Transients are stored in options table with _transient_ prefix
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_searchlens_%'
        OR option_name LIKE '_transient_timeout_searchlens_%'"
);

// Drop the logs table
$searchlens_table_name = $wpdb->prefix . 'searchlens_logs';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $searchlens_table_name ) );

// Drop the feedback table
$searchlens_feedback_table = $wpdb->prefix . 'searchlens_feedback';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $searchlens_feedback_table ) );

// Clear any cached data in object cache (if persistent caching is used)
wp_cache_flush();
