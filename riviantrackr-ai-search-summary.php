<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Plugin Name: AI Search Summary
 * Description: Add an OpenAI powered AI summary to WordPress search results without delaying normal results, with analytics, cache control, and collapsible sources.
 * Version: 1.0.7.1
 * Author: Jose Castillo
 * Author URI: https://github.com/RivianTrackr/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.9
 * Requires PHP: 8.4
 * Text Domain: riviantrackr-ai-search-summary
 * Domain Path: /languages
 */

define( 'RIVIANTRACKR_VERSION', '1.0.7.1' );
define( 'RIVIANTRACKR_MODELS_CACHE_TTL', 7 * DAY_IN_SECONDS );
define( 'RIVIANTRACKR_MIN_CACHE_TTL', 60 );
define( 'RIVIANTRACKR_MAX_CACHE_TTL', 86400 );
define( 'RIVIANTRACKR_DEFAULT_CACHE_TTL', 3600 );
define( 'RIVIANTRACKR_CONTENT_LENGTH', 400 );
define( 'RIVIANTRACKR_EXCERPT_LENGTH', 200 );
define( 'RIVIANTRACKR_MAX_SOURCES_DISPLAY', 5 );
define( 'RIVIANTRACKR_API_TIMEOUT', 60 );
define( 'RIVIANTRACKR_RATE_LIMIT_WINDOW', 70 );
define( 'RIVIANTRACKR_MAX_TOKENS', 1500 ); // Default; overridden by the admin setting
define( 'RIVIANTRACKR_IP_RATE_LIMIT', 10 );         // Summary requests per minute per IP
define( 'RIVIANTRACKR_IP_LOG_RATE_LIMIT', 60 );    // Logging/feedback requests per minute per IP

// Pagination defaults
define( 'RIVIANTRACKR_PER_PAGE_QUERIES', 20 );
define( 'RIVIANTRACKR_PER_PAGE_ERRORS', 10 );
define( 'RIVIANTRACKR_PER_PAGE_EVENTS', 50 );

// Input validation limits
define( 'RIVIANTRACKR_QUERY_MIN_LENGTH', 2 );
define( 'RIVIANTRACKR_QUERY_MAX_LENGTH', 500 );
define( 'RIVIANTRACKR_QUERY_MAX_BYTES', 2000 );
define( 'RIVIANTRACKR_ERROR_MAX_LENGTH', 500 );
define( 'RIVIANTRACKR_CUSTOM_CSS_MAX_LENGTH', 10000 );

// Analytics badge thresholds
define( 'RIVIANTRACKR_BADGE_SUCCESS_HIGH', 90 );
define( 'RIVIANTRACKR_BADGE_SUCCESS_MED', 70 );
define( 'RIVIANTRACKR_BADGE_CACHE_HIGH', 50 );
define( 'RIVIANTRACKR_BADGE_CACHE_MED', 25 );
define( 'RIVIANTRACKR_BADGE_HELPFUL_HIGH', 70 );
define( 'RIVIANTRACKR_BADGE_HELPFUL_MED', 40 );

// Large table optimization threshold
define( 'RIVIANTRACKR_LARGE_TABLE_THRESHOLD', 100000 );

// Error codes for structured API responses
define( 'RIVIANTRACKR_ERROR_BOT_DETECTED', 'bot_detected' );
define( 'RIVIANTRACKR_ERROR_RATE_LIMITED', 'rate_limited' );
define( 'RIVIANTRACKR_ERROR_NOT_CONFIGURED', 'not_configured' );
define( 'RIVIANTRACKR_ERROR_INVALID_QUERY', 'invalid_query' );
define( 'RIVIANTRACKR_ERROR_API_ERROR', 'api_error' );
define( 'RIVIANTRACKR_ERROR_NO_RESULTS', 'no_results' );


if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RivianTrackr_AI_Search_Summary {

    private $option_name         = 'riviantrackr_options';
    private $models_cache_option = 'riviantrackr_models_cache';
    private $cache_keys_option      = 'riviantrackr_cache_keys';
    private $cache_namespace_option = 'riviantrackr_cache_namespace';
    private $cache_prefix;
    private $cache_ttl           = 3600;

    private $logs_table_checked  = false;
    private $logs_table_exists   = false;
    private $options_cache       = null;
    private $summary_injected    = false;

    public function __construct() {

        $this->cache_prefix = 'riviantrackr_v' . str_replace( '.', '_', RIVIANTRACKR_VERSION ) . '_';

        // Register settings on admin_init (the recommended hook for Settings API)
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        add_action( 'admin_init', array( $this, 'add_security_headers' ) );
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
        add_action( 'loop_start', array( $this, 'inject_ai_summary_placeholder' ) );
        add_action( 'template_redirect', array( $this, 'log_no_results_search' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_filter( 'rest_post_dispatch', array( $this, 'add_rate_limit_headers' ), 10, 3 );
        add_action( 'wp_ajax_riviantrackr_test_api_key', array( $this, 'ajax_test_api_key' ) );
        add_action( 'wp_ajax_riviantrackr_refresh_models', array( $this, 'ajax_refresh_models' ) );
        add_action( 'wp_ajax_riviantrackr_clear_cache', array( $this, 'ajax_clear_cache' ) );
        add_action( 'wp_ajax_riviantrackr_purge_spam', array( $this, 'ajax_purge_spam' ) );
        add_action( 'wp_ajax_riviantrackr_bulk_delete_logs', array( $this, 'ajax_bulk_delete_logs' ) );
        add_action( 'wp_ajax_riviantrackr_gdpr_purge_queries', array( $this, 'ajax_gdpr_purge_queries' ) );
        add_action( 'admin_post_riviantrackr_export_csv', array( $this, 'handle_csv_export' ) );
        add_action( 'riviantrackr_daily_log_purge', array( $this, 'run_scheduled_purge' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'admin_print_styles-index.php', array( $this, 'enqueue_dashboard_widget_css' ) );
        add_action( 'widgets_init', array( $this, 'register_trending_widget' ) );
        add_shortcode( 'riviantrackr_trending', array( $this, 'render_trending_shortcode' ) );

        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_settings_link' ) );

        // Automatically flush the in-memory options cache whenever the option is
        // updated — regardless of whether it was saved via sanitize_options() or
        // a direct update_option() call elsewhere.
        add_action( 'update_option_' . $this->option_name, array( $this, 'flush_options_cache' ) );

        // Redact API keys from HTTP API debug output to prevent leaking
        // credentials into WP_DEBUG_LOG files.
        add_filter( 'http_api_debug', array( $this, 'redact_api_key_in_debug' ), 10, 5 );
    }

    /**
     * Add security headers for plugin admin pages.
     */
    public function add_security_headers() {
        // Only add headers on our plugin pages
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page routing check, not form processing
        if ( ! isset( $_GET['page'] ) || strpos( sanitize_text_field( wp_unslash( $_GET['page'] ) ), 'riviantrackr' ) !== 0 ) {
            return;
        }

        // Prevent MIME type sniffing
        header( 'X-Content-Type-Options: nosniff' );

        // Prevent clickjacking - allow same origin only
        header( 'X-Frame-Options: SAMEORIGIN' );

        // Control referrer information
        header( 'Referrer-Policy: strict-origin-when-cross-origin' );

        // Prevent XSS attacks in older browsers
        header( 'X-XSS-Protection: 1; mode=block' );

        // Content Security Policy — restrict resources to same-origin plus
        // inline styles/scripts required by WordPress admin.  img-src allows
        // data: URIs for inline badge images.
        header( "Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self' https://api.openai.com" );
    }

    public function add_plugin_settings_link( $links ) {
        $url = admin_url( 'admin.php?page=riviantrackr-settings' );
        $settings_link = '<a href="' . esc_url( $url ) . '">Settings</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    public function enqueue_dashboard_widget_css() {
        wp_enqueue_style(
            'riviantrackr-admin',
            plugin_dir_url( __FILE__ ) . 'assets/riviantrackr-admin.css',
            array(),
            RIVIANTRACKR_VERSION
        );
    }

    private static function get_logs_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'riviantrackr_logs';
    }

    private static function get_feedback_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'riviantrackr_feedback';
    }


    private static function create_logs_table() {
        global $wpdb;

        $table_name      = self::get_logs_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            search_query text NOT NULL,
            results_count int unsigned NOT NULL DEFAULT 0,
            ai_success tinyint(1) NOT NULL DEFAULT 0,
            ai_error text NULL,
            cache_hit tinyint(1) NULL DEFAULT NULL,
            response_time_ms int unsigned NULL DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY search_query_created (search_query(100), created_at),
            KEY ai_success_created (ai_success, created_at),
            KEY cache_hit_created (cache_hit, created_at),
            KEY results_count (results_count)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    private static function create_feedback_table() {
        global $wpdb;

        $table_name      = self::get_feedback_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            search_query varchar(255) NOT NULL,
            helpful tinyint(1) NOT NULL,
            ip_hash varchar(32) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY search_query (search_query),
            KEY helpful (helpful),
            UNIQUE KEY unique_vote (search_query, ip_hash)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    private static function add_missing_indexes() {
        global $wpdb;
        $table_name = self::get_logs_table_name();

        // Check if table exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
        if ( $table_exists !== $table_name ) {
            return false;
        }

        // Get existing indexes
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $indexes = $wpdb->get_results( $wpdb->prepare( 'SHOW INDEX FROM %i', $table_name ) );
        $index_names = array();
        foreach ( $indexes as $index ) {
            $index_names[] = $index->Key_name;
        }

        // Add search_query_created index if missing
        if ( ! in_array( 'search_query_created', $index_names, true ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD INDEX search_query_created (search_query(100), created_at)', $table_name ) );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( '[RivianTrackr AI Search Summary] Added search_query_created index' );
            }
        }

        // Add ai_success_created index if missing
        if ( ! in_array( 'ai_success_created', $index_names, true ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD INDEX ai_success_created (ai_success, created_at)', $table_name ) );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( '[RivianTrackr AI Search Summary] Added ai_success_created index' );
            }
        }

        // Add cache_hit_created index if missing
        if ( ! in_array( 'cache_hit_created', $index_names, true ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD INDEX cache_hit_created (cache_hit, created_at)', $table_name ) );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( '[RivianTrackr AI Search Summary] Added cache_hit_created index' );
            }
        }

        // Add results_count index if missing (for no-results queries)
        if ( ! in_array( 'results_count', $index_names, true ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD INDEX results_count (results_count)', $table_name ) );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( '[RivianTrackr AI Search Summary] Added results_count index' );
            }
        }

        return true;
    }

    private static function add_missing_columns() {
        global $wpdb;
        $table_name = self::get_logs_table_name();

        // Check if table exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
        if ( $table_exists !== $table_name ) {
            return false;
        }

        // Get existing columns
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table_name ) );
        $column_names = array();
        foreach ( $columns as $column ) {
            $column_names[] = $column->Field;
        }

        // Add cache_hit column if missing
        if ( ! in_array( 'cache_hit', $column_names, true ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN cache_hit tinyint(1) NULL DEFAULT NULL AFTER ai_error', $table_name ) );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( '[RivianTrackr AI Search Summary] Added cache_hit column' );
            }
        }

        // Add response_time_ms column if missing
        if ( ! in_array( 'response_time_ms', $column_names, true ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN response_time_ms int unsigned NULL DEFAULT NULL AFTER cache_hit', $table_name ) );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( '[RivianTrackr AI Search Summary] Added response_time_ms column' );
            }
        }

        return true;
    }

    public static function activate() {
        self::create_logs_table();
        self::create_feedback_table();
        self::add_missing_columns(); // Add columns to existing tables
        self::add_missing_indexes(); // Add indexes to existing tables
    }

    /**
     * Clean up scheduled events on plugin deactivation.
     */
    public static function deactivate() {
        $timestamp = wp_next_scheduled( 'riviantrackr_daily_log_purge' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'riviantrackr_daily_log_purge' );
        }
    }

    private function ensure_logs_table() {
        self::create_logs_table();
        self::add_missing_columns(); // Ensure columns exist
        self::add_missing_indexes(); // Ensure indexes exist
        $this->logs_table_checked = false;
        return $this->logs_table_is_available();
    }

    private function logs_table_is_available(): bool {
        if ( $this->logs_table_checked ) {
            return $this->logs_table_exists;
        }

        global $wpdb;
        $table_name = self::get_logs_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

        // Do not attempt to create or repair tables during normal requests.
        // Table creation is handled on plugin activation and via the explicit admin action.
        $this->logs_table_checked = true;
        $this->logs_table_exists  = ( $result === $table_name );

        return $this->logs_table_exists;
    }

    /**
     * Purge logs older than specified number of days.
     *
     * @param int $days Number of days to keep. Logs older than this will be deleted.
     * @return int|false Number of rows deleted, or false on failure.
     */
    private function purge_old_logs( $days = 30 ) {
        if ( ! $this->logs_table_is_available() ) {
            return false;
        }

        global $wpdb;
        $table_name  = self::get_logs_table_name();
        $cutoff_date = gmdate( 'Y-m-d H:i:s', time() - ( absint( $days ) * DAY_IN_SECONDS ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM %i WHERE created_at < %s',
                $table_name,
                $cutoff_date
            )
        );

        return $deleted;
    }

    private function log_search_event( $search_query, $results_count, $ai_success, $ai_error = '', $cache_hit = null, $response_time_ms = null ) {
        if ( empty( $search_query ) ) {
            return;
        }

        if ( ! $this->logs_table_is_available() ) {
            return;
        }

        global $wpdb;
        $table_name = self::get_logs_table_name();

        $now = current_time( 'mysql' );

        // When anonymization is enabled, store a SHA-256 hash instead of
        // the raw query.  This preserves aggregate analytics (unique queries,
        // totals) while removing personally-identifiable search history.
        $options = $this->get_options();
        if ( ! empty( $options['anonymize_queries'] ) ) {
            $search_query = hash( 'sha256', strtolower( trim( $search_query ) ) );
        }

        // Sanitize error message to prevent XSS when displayed in admin
        // Strip tags and limit length to prevent storage of malicious payloads
        $sanitized_error = '';
        if ( ! empty( $ai_error ) ) {
            $sanitized_error = wp_strip_all_tags( $ai_error );
            $sanitized_error = sanitize_text_field( $sanitized_error );
            // Limit error message length to prevent oversized storage
            if ( function_exists( 'mb_substr' ) ) {
                $sanitized_error = mb_substr( $sanitized_error, 0, RIVIANTRACKR_ERROR_MAX_LENGTH, 'UTF-8' );
            } else {
                $sanitized_error = substr( $sanitized_error, 0, 500 );
            }
        }

        $data = array(
            'search_query'  => $search_query,
            'results_count' => (int) $results_count,
            'ai_success'    => $ai_success ? 1 : 0,
            'ai_error'      => $sanitized_error,
            'created_at'    => $now,
        );

        $formats = array( '%s', '%d', '%d', '%s', '%s' );

        // Only include nullable columns when they have values,
        // so MySQL uses DEFAULT NULL instead of receiving an empty string.
        if ( $cache_hit !== null ) {
            $data['cache_hit'] = $cache_hit ? 1 : 0;
            $formats[]         = '%d';
        }

        if ( $response_time_ms !== null ) {
            $data['response_time_ms'] = (int) $response_time_ms;
            $formats[]                = '%d';
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->insert(
            $table_name,
            $data,
            $formats
        );

        if ( false === $result && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log(
                '[RivianTrackr AI Search Summary] Failed to log search event: ' .
                $wpdb->last_error .
                ' | Query: ' . substr( $search_query, 0, 50 )
            );
        }
    }

    /**
     * Record user feedback for a search query.
     *
     * @param string $search_query The search query.
     * @param bool   $helpful      Whether the summary was helpful.
     * @param string $ip           Client IP address.
     * @return bool|string True on success, 'duplicate' if already voted, false on error.
     */
    private function record_feedback( $search_query, $helpful, $ip ) {
        global $wpdb;

        $table_name = self::get_feedback_table_name();
        $ip_hash    = hash( 'sha256', $ip . wp_salt( 'auth' ) );

        // Use INSERT IGNORE to handle the unique constraint gracefully
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->query(
            $wpdb->prepare(
                'INSERT IGNORE INTO %i (search_query, helpful, ip_hash, created_at) VALUES (%s, %d, %s, %s)',
                $table_name,
                substr( $search_query, 0, 255 ),
                $helpful ? 1 : 0,
                $ip_hash,
                current_time( 'mysql' )
            )
        );

        if ( false === $result ) {
            return false;
        }

        // rows_affected = 0 means duplicate (INSERT IGNORE skipped)
        if ( 0 === $wpdb->rows_affected ) {
            return 'duplicate';
        }

        return true;
    }

    /**
     * Get feedback statistics for analytics.
     *
     * @return array Feedback stats.
     */
    private function get_feedback_stats() {
        global $wpdb;

        $table_name = self::get_feedback_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $stats = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT
                    COUNT(*) AS total_votes,
                    SUM(helpful) AS helpful_count,
                    COUNT(*) - SUM(helpful) AS not_helpful_count
                 FROM %i',
                $table_name
            )
        );

        $total   = $stats ? (int) $stats->total_votes : 0;
        $helpful = $stats ? (int) $stats->helpful_count : 0;

        return array(
            'total_votes'       => $total,
            'helpful_count'     => $helpful,
            'not_helpful_count' => $total - $helpful,
            'helpful_rate'      => $total > 0 ? round( ( $helpful / $total ) * 100, 1 ) : 0,
        );
    }

    /**
     * Check if API key is defined via constant.
     *
     * @return bool True if API key constant is defined and not empty.
     */
    public function is_api_key_from_constant() {
        return defined( 'RIVIANTRACKR_API_KEY' ) && ! empty( RIVIANTRACKR_API_KEY );
    }

    public function get_options(): array {
        if ( is_array( $this->options_cache ) ) {
            return $this->options_cache;
        }

        $defaults = array(
            'api_key'              => '',
            'api_key_valid'        => null,
            'model'                => '',
            'max_posts'            => 20,
            'max_tokens'           => RIVIANTRACKR_MAX_TOKENS,
            'enable'               => 0,
            'max_calls_per_minute' => 30,
            'cache_ttl'            => RIVIANTRACKR_DEFAULT_CACHE_TTL,
            'request_timeout'      => 60,
            'site_name'            => get_bloginfo( 'name' ),
            'site_description'     => '',
            'show_openai_badge'    => 0,
            'show_sources'         => 0,
            'show_feedback'        => 0,
            'color_background'     => '#121e2b',
            'color_text'           => '#e5e7eb',
            'color_accent'         => '#fba919',
            'color_border'         => '#94a3b8',
            'custom_css'              => '',
            'allow_reasoning_models'  => 0,
            'anonymize_queries'       => 0,
            'spam_blocklist'          => '',
            'post_types'              => array(),
            'max_sources_display'     => RIVIANTRACKR_MAX_SOURCES_DISPLAY,
            'content_length'          => RIVIANTRACKR_CONTENT_LENGTH,
        );

        $opts = get_option( $this->option_name, array() );
        $this->options_cache = wp_parse_args( is_array( $opts ) ? $opts : array(), $defaults );

        // Override API key if defined via constant (more secure than database storage)
        if ( $this->is_api_key_from_constant() ) {
            $this->options_cache['api_key'] = RIVIANTRACKR_API_KEY;
        }

        return $this->options_cache;
    }

    /**
     * Flush the in-memory options cache.
     *
     * Hooked to `update_option_{$option_name}` so the cache is always
     * invalidated when the option changes, even outside sanitize_options().
     */
    public function flush_options_cache(): void {
        $this->options_cache = null;
    }

    /**
     * Redact API keys from HTTP API debug data.
     *
     * WordPress fires `http_api_debug` after every remote request.  When
     * WP_DEBUG_LOG is on, plugins or drop-ins may log the full request
     * including headers.  This filter strips the Authorization header
     * from requests to api.openai.com so the bearer token never reaches
     * the debug log.
     *
     * @param mixed  $response HTTP response or WP_Error.
     * @param string $context  'response' or 'transports'.
     * @param string $class    Transport class name.
     * @param array  $parsed_args Request arguments.
     * @param string $url      Request URL.
     * @return mixed Unmodified response (filter is used for side-effect only).
     */
    public function redact_api_key_in_debug( $response, $context, $class, $parsed_args, $url ) {
        // Only intercept OpenAI requests
        if ( is_string( $url ) && strpos( $url, 'api.openai.com' ) !== false ) {
            if ( isset( $parsed_args['headers']['Authorization'] ) ) {
                $parsed_args['headers']['Authorization'] = 'Bearer ***REDACTED***';
            }
        }
        return $response;
    }

    public function sanitize_options( array $input ): array {
        if (!is_array($input)) {
            $input = array();
        }
        
        $output = array();

        $output['api_key']   = isset($input['api_key']) ? sanitize_text_field( trim($input['api_key']) ) : '';
        $output['model']     = isset($input['model']) ? sanitize_text_field($input['model']) : '';
        $output['max_posts'] = isset($input['max_posts']) ? max(1, intval($input['max_posts'])) : 20;

        // Max tokens: min 500, max 16000, default 1500
        $output['max_tokens'] = isset($input['max_tokens'])
            ? max(500, min(16000, intval($input['max_tokens'])))
            : RIVIANTRACKR_MAX_TOKENS;

        $output['enable'] = isset($input['enable']) && $input['enable'] ? 1 : 0;

        // Validate API key when it changes
        $old_options = get_option( $this->option_name, array() );
        $old_key     = isset( $old_options['api_key'] ) ? $old_options['api_key'] : '';
        if ( $output['api_key'] !== $old_key && ! empty( $output['api_key'] ) ) {
            $test = $this->test_api_key( $output['api_key'] );
            $output['api_key_valid'] = $test['success'] ? true : false;
            if ( ! $test['success'] ) {
                add_settings_error( $this->option_name, 'invalid_api_key', 'API key validation failed: ' . $test['message'], 'error' );
            }
        } elseif ( ! empty( $output['api_key'] ) ) {
            // Key unchanged — preserve previous status
            $output['api_key_valid'] = isset( $old_options['api_key_valid'] ) ? $old_options['api_key_valid'] : null;
        } else {
            $output['api_key_valid'] = null;
        }
        
        $output['max_calls_per_minute'] = isset($input['max_calls_per_minute'])
            ? max(0, intval($input['max_calls_per_minute']))
            : 30;
            
        if (isset($input['cache_ttl'])) {
            $ttl = intval($input['cache_ttl']);
            if ($ttl < RIVIANTRACKR_MIN_CACHE_TTL) {
                $ttl = RIVIANTRACKR_MIN_CACHE_TTL;
            } elseif ($ttl > RIVIANTRACKR_MAX_CACHE_TTL) {
                $ttl = RIVIANTRACKR_MAX_CACHE_TTL;
            }
            $output['cache_ttl'] = $ttl;
        } else {
            $output['cache_ttl'] = RIVIANTRACKR_DEFAULT_CACHE_TTL;
        }

        // Request timeout: min 10 seconds, max 300 seconds, default 60
        $output['request_timeout'] = isset($input['request_timeout'])
            ? max(10, min(300, intval($input['request_timeout'])))
            : 60;

        // Site name and description for AI prompt
        $output['site_name'] = isset($input['site_name'])
            ? sanitize_text_field( trim($input['site_name']) )
            : get_bloginfo( 'name' );
        $output['site_description'] = isset($input['site_description'])
            ? sanitize_textarea_field( trim($input['site_description']) )
            : '';

        $output['show_openai_badge'] = isset($input['show_openai_badge']) && $input['show_openai_badge'] ? 1 : 0;
        $output['show_sources'] = isset($input['show_sources']) && $input['show_sources'] ? 1 : 0;
        $output['show_feedback'] = isset($input['show_feedback']) && $input['show_feedback'] ? 1 : 0;

        // Color settings
        $output['color_background'] = isset($input['color_background']) ? $this->sanitize_color($input['color_background'], '#121e2b') : '#121e2b';
        $output['color_text'] = isset($input['color_text']) ? $this->sanitize_color($input['color_text'], '#e5e7eb') : '#e5e7eb';
        $output['color_accent'] = isset($input['color_accent']) ? $this->sanitize_color($input['color_accent'], '#fba919') : '#fba919';
        $output['color_border'] = isset($input['color_border']) ? $this->sanitize_color($input['color_border'], '#94a3b8') : '#94a3b8';

        $output['custom_css'] = isset($input['custom_css']) ? $this->sanitize_custom_css($input['custom_css']) : '';
        $output['allow_reasoning_models'] = isset($input['allow_reasoning_models']) && $input['allow_reasoning_models'] ? 1 : 0;
        $output['anonymize_queries'] = isset($input['anonymize_queries']) && $input['anonymize_queries'] ? 1 : 0;

        // Spam blocklist: one term per line, sanitize each line
        if ( isset( $input['spam_blocklist'] ) ) {
            $lines = explode( "\n", $input['spam_blocklist'] );
            $clean_lines = array();
            foreach ( $lines as $line ) {
                $line = sanitize_text_field( trim( $line ) );
                if ( ! empty( $line ) ) {
                    $clean_lines[] = $line;
                }
            }
            $output['spam_blocklist'] = implode( "\n", $clean_lines );
        } else {
            $output['spam_blocklist'] = '';
        }

        // Post type filtering
        if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
            $valid_types = get_post_types( array( 'public' => true ), 'names' );
            $output['post_types'] = array_values( array_intersect( array_map( 'sanitize_key', $input['post_types'] ), $valid_types ) );
        } else {
            $output['post_types'] = array();
        }

        // Max sources display: min 1, max 20, default 5
        $output['max_sources_display'] = isset( $input['max_sources_display'] )
            ? max( 1, min( 20, intval( $input['max_sources_display'] ) ) )
            : 5;

        // Content length per post: min 100, max 2000, default 400
        $output['content_length'] = isset( $input['content_length'] )
            ? max( 100, min( 2000, intval( $input['content_length'] ) ) )
            : 400;

        // Preserve data on uninstall
        $output['preserve_data_on_uninstall'] = isset($input['preserve_data_on_uninstall']) && $input['preserve_data_on_uninstall'] ? 1 : 0;

        // Auto-purge settings
        $output['auto_purge_enabled'] = isset($input['auto_purge_enabled']) && $input['auto_purge_enabled'] ? 1 : 0;
        $output['auto_purge_days'] = isset($input['auto_purge_days'])
            ? max(7, min(365, intval($input['auto_purge_days'])))
            : 90;

        // Schedule or unschedule cron based on auto-purge setting
        $old_purge_enabled = isset( $old_options['auto_purge_enabled'] ) ? $old_options['auto_purge_enabled'] : 0;
        if ( $output['auto_purge_enabled'] && ! $old_purge_enabled ) {
            // Enable: schedule daily purge if not already scheduled
            if ( ! wp_next_scheduled( 'riviantrackr_daily_log_purge' ) ) {
                wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'riviantrackr_daily_log_purge' );
            }
        } elseif ( ! $output['auto_purge_enabled'] && $old_purge_enabled ) {
            // Disable: unschedule the cron
            $timestamp = wp_next_scheduled( 'riviantrackr_daily_log_purge' );
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, 'riviantrackr_daily_log_purge' );
            }
        }

        // Auto-clear cache when model, token limit, or display settings change
        $old_model       = isset( $old_options['model'] ) ? $old_options['model'] : '';
        $old_show_sources = isset( $old_options['show_sources'] ) ? $old_options['show_sources'] : 0;
        $old_max_tokens  = isset( $old_options['max_tokens'] ) ? (int) $old_options['max_tokens'] : RIVIANTRACKR_MAX_TOKENS;

        $cache_invalidating_change = false;
        if ( $output['model'] !== $old_model && ! empty( $output['model'] ) ) {
            $cache_invalidating_change = true;
        }
        if ( $output['show_sources'] !== $old_show_sources ) {
            $cache_invalidating_change = true;
        }
        if ( $output['max_tokens'] !== $old_max_tokens ) {
            $cache_invalidating_change = true;
        }

        if ( $cache_invalidating_change ) {
            $this->bump_cache_namespace();
        }

        $this->flush_options_cache();

        return $output;
    }

    /**
     * Sanitize custom CSS input to prevent XSS and other attacks.
     *
     * @param string $css Raw CSS input.
     * @return string Sanitized CSS.
     */
    private function sanitize_custom_css( string $css ): string {
        if ( empty( $css ) ) {
            return '';
        }

        // Strip HTML tags first
        $css = wp_strip_all_tags( $css );

        // Remove null bytes and other control characters
        $css = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $css );

        // Dangerous patterns to remove (case-insensitive)
        $dangerous_patterns = array(
            '/expression\s*\(/i',           // IE CSS expressions
            '/javascript\s*:/i',            // JavaScript URLs
            '/vbscript\s*:/i',              // VBScript URLs
            '/behavior\s*:/i',              // IE behaviors
            '/-moz-binding\s*:/i',          // Firefox XBL
            '/@import/i',                   // External CSS imports
            '/@charset/i',                  // Charset declarations
            '/binding\s*:/i',               // Generic binding
            '/\\\\[0-9a-f]+/i',             // Escaped unicode (can bypass filters)
        );

        foreach ( $dangerous_patterns as $pattern ) {
            $css = preg_replace( $pattern, '', $css );
        }

        // Remove url() with potentially dangerous schemes
        $css = preg_replace_callback(
            '/url\s*\(\s*["\']?\s*([^)]+?)\s*["\']?\s*\)/i',
            function( $matches ) {
                $url = trim( $matches[1], " \t\n\r\0\x0B\"'" );
                // Only allow relative URLs, http/https, and data:image with specific safe MIME types
                if ( preg_match( '/^https?:/i', $url ) || ! preg_match( '/^[a-z]+:/i', $url ) ) {
                    return $matches[0];
                }
                // Strictly allow only known-safe image data URIs
                if ( preg_match( '/^data:image\/(png|jpe?g|gif|webp|svg\+xml)(;base64)?,/i', $url ) ) {
                    return $matches[0];
                }
                return ''; // Remove dangerous URLs
            },
            $css
        );

        // Limit length to prevent DoS
        $max_length = RIVIANTRACKR_CUSTOM_CSS_MAX_LENGTH;
        if ( strlen( $css ) > $max_length ) {
            $css = substr( $css, 0, $max_length );
        }

        return trim( $css );
    }

    /**
     * Sanitize a color value.
     *
     * @param string $color   The color to sanitize.
     * @param string $default Default color if invalid.
     * @return string Sanitized hex color.
     */
    private function sanitize_color( string $color, string $default = '#000000' ): string {
        $color = trim( $color );

        // Use WordPress sanitize_hex_color if available
        if ( function_exists( 'sanitize_hex_color' ) ) {
            $sanitized = sanitize_hex_color( $color );
            return $sanitized ? $sanitized : $default;
        }

        // Fallback: validate hex color manually
        if ( preg_match( '/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color ) ) {
            return $color;
        }

        return $default;
    }

    /**
     * Generate dynamic CSS for custom colors.
     *
     * @param array $options Plugin options.
     * @return string CSS string.
     */
    private function generate_color_css( array $options ): string {
        $bg     = isset( $options['color_background'] ) ? $options['color_background'] : '#121e2b';
        $text   = isset( $options['color_text'] ) ? $options['color_text'] : '#e5e7eb';
        $accent = isset( $options['color_accent'] ) ? $options['color_accent'] : '#fba919';
        $border = isset( $options['color_border'] ) ? $options['color_border'] : '#94a3b8';

        // Convert hex to rgba for semi-transparent backgrounds
        $bg_rgb = $this->hex_to_rgb( $bg );
        $text_rgb = $this->hex_to_rgb( $text );
        $border_rgb = $this->hex_to_rgb( $border );

        if ( ! $bg_rgb || ! $text_rgb || ! $border_rgb ) {
            return '';
        }

        $border_rgba = "rgba({$border_rgb['r']},{$border_rgb['g']},{$border_rgb['b']},0.4)";

        // Target the summary container and content (badge keeps its default dark style)
        $css = "
.riviantrackr-summary-inner {
    background-color: {$bg};
    border-color: {$border_rgba};
}
.riviantrackr-summary-inner,
.riviantrackr-summary-inner .riviantrackr-summary-header h2,
.riviantrackr-summary-inner .riviantrackr-disclaimer {
    color: {$text};
}
.riviantrackr-openai-badge,
.riviantrackr-openai-badge .riviantrackr-openai-text {
    color: #e5e7eb;
}
.riviantrackr-search-summary-content {
    color: {$text};
}
.riviantrackr-search-summary-content a,
.riviantrackr-search-summary-content a:visited {
    color: {$accent};
}
.riviantrackr-search-summary-content a:hover,
.riviantrackr-search-summary-content a:active,
.riviantrackr-search-summary-content a:focus {
    color: {$accent};
    opacity: 0.8;
}
.riviantrackr-sources-toggle {
    color: {$text};
}
.riviantrackr-sources-toggle:hover,
.riviantrackr-sources-toggle:active,
.riviantrackr-sources-toggle:focus {
    color: {$accent};
}
.riviantrackr-sources-list a,
.riviantrackr-sources-list a:visited {
    color: {$accent};
}
.riviantrackr-sources-list a:hover,
.riviantrackr-sources-list a:active,
.riviantrackr-sources-list a:focus {
    color: {$accent};
    opacity: 0.8;
}
.riviantrackr-sources-list span {
    color: {$text};
    opacity: 0.8;
}
.riviantrackr-spinner {
    border-color: rgba({$text_rgb['r']},{$text_rgb['g']},{$text_rgb['b']},0.3);
    border-top-color: {$accent};
}
.riviantrackr-skeleton-line {
    background-color: rgba({$text_rgb['r']},{$text_rgb['g']},{$text_rgb['b']},0.15);
    background-image: linear-gradient(
        90deg,
        rgba({$text_rgb['r']},{$text_rgb['g']},{$text_rgb['b']},0.1) 25%,
        rgba({$text_rgb['r']},{$text_rgb['g']},{$text_rgb['b']},0.25) 50%,
        rgba({$text_rgb['r']},{$text_rgb['g']},{$text_rgb['b']},0.1) 75%
    );
}";

        return trim( $css );
    }

    /**
     * Convert hex color to RGB array.
     *
     * @param string $hex Hex color code.
     * @return array|false RGB array or false on failure.
     */
    private function hex_to_rgb( string $hex ): array {
        $hex = ltrim( $hex, '#' );

        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if ( strlen( $hex ) !== 6 ) {
            return false;
        }

        return array(
            'r' => hexdec( substr( $hex, 0, 2 ) ),
            'g' => hexdec( substr( $hex, 2, 2 ) ),
            'b' => hexdec( substr( $hex, 4, 2 ) ),
        );
    }

    public function add_settings_page() {
        $capability  = 'manage_options';
        $parent_slug = 'riviantrackr-settings';

        add_menu_page(
            'AI Search Summary',
            'AI Search Summary',
            $capability,
            $parent_slug,
            array( $this, 'render_settings_page' ),
            'dashicons-search',
            65
        );

        add_submenu_page(
            $parent_slug,
            'AI Search Summary Settings',
            'Settings',
            $capability,
            $parent_slug,
            array( $this, 'render_settings_page' )
        );

        add_submenu_page(
            $parent_slug,
            'AI Search Summary Analytics',
            'Analytics',
            $capability,
            'riviantrackr-analytics',
            array( $this, 'render_analytics_page' )
        );
    }

    public function register_settings() {
        register_setting(
            'riviantrackr_group',
            $this->option_name,
            array(
                'type' => 'array',
                'sanitize_callback' => array( $this, 'sanitize_options' ),
                'default' => array(
                    'api_key'              => '',
                    'model'                => '',
                    'max_posts'            => 20,
                    'enable'               => 0,
                    'max_calls_per_minute' => 30,
                    'cache_ttl'            => RIVIANTRACKR_DEFAULT_CACHE_TTL,
                    'request_timeout'      => 60,
                    'site_name'            => '',
                    'site_description'     => '',
                    'custom_css'           => '',
                    'auto_purge_enabled'   => 0,
                    'auto_purge_days'      => 90,
                    'preserve_data_on_uninstall' => 0,
                    'anonymize_queries'    => 0,
                    'post_types'           => array(),
                    'max_sources_display'  => 5,
                    'content_length'       => 400,
                )
            )
        );
        
    }

    private function test_api_key( string $api_key ): array {
        if ( empty( $api_key ) ) {
            return array(
                'success' => false,
                'message' => 'API key is empty.',
            );
        }

        $response = wp_safe_remote_get(
            'https://api.openai.com/v1/models',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                ),
                'timeout' => 10,
            )
        );

        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'message' => 'Connection error: ' . $response->get_error_message(),
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code === 401 ) {
            return array(
                'success' => false,
                'message' => 'Invalid API key. Please check your key and try again.',
            );
        }

        if ( $code === 429 ) {
            return array(
                'success' => false,
                'message' => 'Rate limit exceeded. Your API key works but has hit rate limits.',
            );
        }

        if ( $code < 200 || $code >= 300 ) {
            return array(
                'success' => false,
                'message' => 'API error (HTTP ' . $code . '). Please try again later.',
            );
        }

        $data = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return array(
                'success' => false,
                'message' => 'Could not parse API response.',
            );
        }

        // Count available models
        $model_count = isset( $data['data'] ) ? count( $data['data'] ) : 0;
        
        // Check for chat models specifically
        $chat_models = array();
        if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
            foreach ( $data['data'] as $model ) {
                if ( isset( $model['id'] ) ) {
                    $id = $model['id'];
                    if ( strpos( $id, 'gpt-4' ) === 0 || strpos( $id, 'gpt-3.5' ) === 0 ) {
                        $chat_models[] = $id;
                    }
                }
            }
        }

        return array(
            'success'      => true,
            'message'      => 'API key is valid and working!',
            'model_count'  => $model_count,
            'chat_models'  => count( $chat_models ),
        );
    }

    public function ajax_test_api_key() {
        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        // Verify nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'riviantrackr_test_key' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid nonce.' ) );
        }

        // Get API key - use constant if specified, otherwise from POST
        $api_key = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';

        if ( $api_key === '__USE_CONSTANT__' ) {
            if ( $this->is_api_key_from_constant() ) {
                $api_key = RIVIANTRACKR_API_KEY;
            } else {
                wp_send_json_error( array( 'message' => 'API key constant is not defined.' ) );
                return;
            }
        }

        // Test the key
        $result = $this->test_api_key( $api_key );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    public function ajax_refresh_models() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'riviantrackr_refresh_models' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid security token. Please refresh the page.' ) );
        }

        $options = $this->get_options();
        if ( empty( $options['api_key'] ) ) {
            wp_send_json_error( array( 'message' => 'Cannot refresh models because no API key is set.' ) );
        }

        $refreshed = $this->refresh_model_cache( $options['api_key'] );
        if ( $refreshed ) {
            wp_send_json_success( array( 'message' => 'Model list refreshed from OpenAI.' ) );
        } else {
            wp_send_json_error( array( 'message' => 'Could not refresh models. Check your API key or try again later.' ) );
        }
    }

    public function ajax_clear_cache() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'riviantrackr_clear_cache' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid security token. Please refresh the page.' ) );
        }

        $cleared = $this->clear_ai_cache();
        if ( $cleared ) {
            wp_send_json_success( array( 'message' => 'AI summary cache cleared. New searches will fetch fresh answers.' ) );
        } else {
            wp_send_json_error( array( 'message' => 'Could not clear cache.' ) );
        }
    }

    /**
     * AJAX handler to purge spam entries from analytics logs.
     *
     * Scans the logs table for entries matching spam patterns and deletes them.
     */
    public function ajax_purge_spam() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'riviantrackr_purge_spam' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid security token. Please refresh the page.' ) );
        }

        if ( ! $this->logs_table_is_available() ) {
            wp_send_json_error( array( 'message' => 'Analytics table is not available.' ) );
        }

        global $wpdb;
        $table_name = self::get_logs_table_name();

        // Fetch all distinct search queries
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $queries = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT DISTINCT search_query FROM %i',
                $table_name
            )
        );

        if ( empty( $queries ) ) {
            wp_send_json_success( array(
                'message' => 'No log entries found.',
                'deleted' => 0,
            ) );
        }

        $spam_queries = array();
        foreach ( $queries as $query ) {
            if ( $this->is_spam_query( $query ) || $this->is_sql_injection_attempt( $query ) ) {
                $spam_queries[] = $query;
            }
        }

        if ( empty( $spam_queries ) ) {
            wp_send_json_success( array(
                'message' => 'No spam entries detected in the logs.',
                'deleted' => 0,
            ) );
        }

        // Delete matching entries one query at a time to keep prepare() fully static
        $deleted        = 0;
        $feedback_table = self::get_feedback_table_name();

        foreach ( $spam_queries as $spam_q ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM %i WHERE search_query = %s',
                    $table_name,
                    $spam_q
                )
            );
            if ( is_int( $rows ) ) {
                $deleted += $rows;
            }

            // Also clean up matching feedback entries
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM %i WHERE search_query = %s',
                    $feedback_table,
                    $spam_q
                )
            );
        }

        // Clear the analytics cache so stats refresh
        delete_transient( 'riviantrackr_analytics_overview' );

        wp_send_json_success( array(
            'message' => number_format( $deleted ) . ' spam log entries deleted across ' . count( $spam_queries ) . ' spam queries.',
            'deleted' => $deleted,
            'queries' => count( $spam_queries ),
        ) );
    }

    /**
     * AJAX handler: bulk-delete selected log entries by ID.
     */
    public function ajax_bulk_delete_logs(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'riviantrackr_bulk_delete_logs' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid security token. Please refresh the page.' ) );
        }

        if ( ! $this->logs_table_is_available() ) {
            wp_send_json_error( array( 'message' => 'Analytics table is not available.' ) );
        }

        $raw_ids = isset( $_POST['ids'] ) ? sanitize_text_field( wp_unslash( $_POST['ids'] ) ) : '';
        $ids     = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );

        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => 'No entries selected.' ) );
        }

        global $wpdb;
        $table_name   = self::get_logs_table_name();
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is a safe list of %d tokens, count is dynamic
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM %i WHERE id IN ($placeholders)",
                $table_name,
                ...$ids
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

        delete_transient( 'riviantrackr_analytics_overview' );

        wp_send_json_success( array(
            'message' => number_format( (int) $deleted ) . ' log entries deleted.',
            'deleted' => (int) $deleted,
        ) );
    }

    /**
     * AJAX handler: GDPR-compliant purge of all stored search queries.
     *
     * Replaces every raw search_query value in the logs table with its
     * SHA-256 hash, preserving aggregate analytics while removing
     * personally-identifiable search history.
     */
    public function ajax_gdpr_purge_queries(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'riviantrackr_gdpr_purge' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid security token. Please refresh the page.' ) );
        }

        if ( ! $this->logs_table_is_available() ) {
            wp_send_json_error( array( 'message' => 'Analytics table is not available.' ) );
        }

        global $wpdb;
        $table_name = self::get_logs_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT id, search_query FROM %i WHERE search_query NOT REGEXP %s', $table_name, '^[a-f0-9]{64}$' )
        );

        if ( empty( $rows ) ) {
            wp_send_json_success( array( 'message' => 'No un-anonymized queries found.', 'anonymized' => 0 ) );
        }

        $count = 0;
        foreach ( $rows as $row ) {
            $hashed = hash( 'sha256', strtolower( trim( $row->search_query ) ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update( $table_name, array( 'search_query' => $hashed ), array( 'id' => $row->id ), array( '%s' ), array( '%d' ) );
            $count++;
        }

        delete_transient( 'riviantrackr_analytics_overview' );

        wp_send_json_success( array(
            'message'    => number_format( $count ) . ' search queries anonymized.',
            'anonymized' => $count,
        ) );
    }

    /**
     * Handle CSV export request.
     */
    public function handle_csv_export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Permission denied.', 'Error', array( 'response' => 403 ) );
        }

        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'riviantrackr_export_csv' ) ) {
            wp_die( 'Invalid security token.', 'Error', array( 'response' => 403 ) );
        }

        global $wpdb;

        $export_type = isset( $_POST['export_type'] ) ? sanitize_key( $_POST['export_type'] ) : 'logs';
        $from_date   = isset( $_POST['export_from'] ) ? sanitize_text_field( wp_unslash( $_POST['export_from'] ) ) : gmdate( 'Y-m-d', strtotime( '-30 days' ) );
        $to_date     = isset( $_POST['export_to'] ) ? sanitize_text_field( wp_unslash( $_POST['export_to'] ) ) : gmdate( 'Y-m-d' );

        // Validate dates
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from_date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to_date ) ) {
            wp_die( 'Invalid date format.', 'Error', array( 'response' => 400 ) );
        }

        // Ensure from_date is not after to_date
        if ( $from_date > $to_date ) {
            wp_die( 'Start date must be on or before end date.', 'Error', array( 'response' => 400 ) );
        }

        // Add time to dates for inclusive range
        $from_datetime = $from_date . ' 00:00:00';
        $to_datetime   = $to_date . ' 23:59:59';

        $filename = 'riviantrackr-' . $export_type . '-' . $from_date . '-to-' . $to_date . '.csv';
        $rows     = array();
        $headers  = array();

        $logs_table     = self::get_logs_table_name();
        $feedback_table = self::get_feedback_table_name();

        switch ( $export_type ) {
            case 'feedback':
                $headers = array( 'ID', 'Search Query', 'Helpful', 'Date' );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $results = $wpdb->get_results(
                    $wpdb->prepare(
                        'SELECT id, search_query, helpful, created_at
                         FROM %i
                         WHERE created_at BETWEEN %s AND %s
                         ORDER BY created_at DESC',
                        $feedback_table,
                        $from_datetime,
                        $to_datetime
                    )
                );
                foreach ( $results as $row ) {
                    $rows[] = array(
                        $row->id,
                        $row->search_query,
                        $row->helpful ? 'Yes' : 'No',
                        $row->created_at,
                    );
                }
                break;

            case 'daily':
                $headers = array( 'Date', 'Total Searches', 'Successful', 'Cache Hits', 'Cache Misses', 'Success Rate', 'Cache Hit Rate' );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $results = $wpdb->get_results(
                    $wpdb->prepare(
                        'SELECT
                            DATE(created_at) AS day,
                            COUNT(*) AS total,
                            SUM(ai_success) AS success_count,
                            SUM(CASE WHEN cache_hit IN (1, 2) THEN 1 ELSE 0 END) AS cache_hits,
                            SUM(CASE WHEN cache_hit = 0 THEN 1 ELSE 0 END) AS cache_misses
                         FROM %i
                         WHERE created_at BETWEEN %s AND %s
                         GROUP BY DATE(created_at)
                         ORDER BY day DESC',
                        $logs_table,
                        $from_datetime,
                        $to_datetime
                    )
                );
                foreach ( $results as $row ) {
                    $total       = (int) $row->total;
                    $success     = (int) $row->success_count;
                    $hits        = (int) $row->cache_hits;
                    $misses      = (int) $row->cache_misses;
                    $cache_total = $hits + $misses;

                    $rows[] = array(
                        $row->day,
                        $total,
                        $success,
                        $hits,
                        $misses,
                        $total > 0 ? round( ( $success / $total ) * 100, 1 ) . '%' : '0%',
                        $cache_total > 0 ? round( ( $hits / $cache_total ) * 100, 1 ) . '%' : 'N/A',
                    );
                }
                break;

            default: // logs
                $headers = array( 'ID', 'Search Query', 'Results Count', 'AI Success', 'Cache Hit', 'Response Time (ms)', 'Error', 'Date' );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $results = $wpdb->get_results(
                    $wpdb->prepare(
                        'SELECT id, search_query, results_count, ai_success, cache_hit, response_time_ms, ai_error, created_at
                         FROM %i
                         WHERE created_at BETWEEN %s AND %s
                         ORDER BY created_at DESC',
                        $logs_table,
                        $from_datetime,
                        $to_datetime
                    )
                );
                foreach ( $results as $row ) {
                    $cache_label = $row->cache_hit === null ? '' : ( $row->cache_hit ? 'Yes' : 'No' );
                    $rows[] = array(
                        $row->id,
                        $row->search_query,
                        $row->results_count,
                        $row->ai_success ? 'Yes' : 'No',
                        $cache_label,
                        $row->response_time_ms ?? '',
                        $row->ai_error ?? '',
                        $row->created_at,
                    );
                }
                break;
        }

        // Output CSV
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        // Add BOM for Excel UTF-8 compatibility
        echo "\xEF\xBB\xBF";

        // Build CSV rows manually to avoid fopen/fclose on php://output
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw CSV output with Content-Type: text/csv header
        echo $this->array_to_csv_line( $headers );
        foreach ( $rows as $row ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw CSV output with Content-Type: text/csv header
            echo $this->array_to_csv_line( $row );
        }

        exit;
    }

    /**
     * Convert an array to a CSV-formatted line string.
     *
     * @param array $fields Array of field values.
     * @return string CSV-formatted line with line ending.
     */
    private function array_to_csv_line( $fields ) {
        $escaped = array();
        foreach ( $fields as $field ) {
            $field     = (string) $field;
            $escaped[] = '"' . str_replace( '"', '""', $field ) . '"';
        }
        return implode( ',', $escaped ) . "\n";
    }

    /**
     * Run the scheduled log purge via WP-Cron.
     */
    public function run_scheduled_purge() {
        $options = $this->get_options();

        if ( empty( $options['auto_purge_enabled'] ) ) {
            return;
        }

        $days = isset( $options['auto_purge_days'] ) ? absint( $options['auto_purge_days'] ) : 90;
        $deleted = $this->purge_old_logs( $days );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $deleted !== false ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( '[RivianTrackr AI Search Summary] Auto-purge: deleted ' . $deleted . ' log entries older than ' . $days . ' days.' );
        }
    }

    public function field_custom_css() {
        $options = $this->get_options();
        $custom_css = isset( $options['custom_css'] ) ? $options['custom_css'] : '';
        ?>
        <div class="riviantrackr-css-editor-wrapper">
            <textarea 
                name="<?php echo esc_attr( $this->option_name ); ?>[custom_css]"
                id="riviantrackr-custom-css"
                class="riviantrackr-css-editor"
                rows="15"
                placeholder="/* Add your custom CSS here */
    .riviantrackr-summary {
        /* Your custom styles */
    }"><?php echo esc_textarea( $custom_css ); ?></textarea>
        </div>
        
        <p class="description">
            Add custom CSS to style the AI search summary. This will override the default styles.
            <br>
            <strong>Tip:</strong> Target classes like <code>.riviantrackr-summary</code>, <code>.riviantrackr-summary-inner</code>, <code>.riviantrackr-openai-badge</code>, etc.
        </p>
        
        <div class="riviantrackr-css-buttons">
            <button type="button" id="riviantrackr-reset-css" class="riviantrackr-button riviantrackr-button-secondary">
                Reset to Empty
            </button>
            <button type="button" id="riviantrackr-view-default-css" class="riviantrackr-button riviantrackr-button-secondary">
                View Default CSS
            </button>
        </div>
        
        <!-- Modal HTML -->
        <div id="riviantrackr-default-css-modal" class="riviantrackr-modal-overlay">
            <div class="riviantrackr-modal-content">
                <button type="button" id="riviantrackr-close-modal" class="riviantrackr-modal-close" aria-label="Close">×</button>
                <h2 class="riviantrackr-modal-header">Default CSS Reference</h2>
                <p class="riviantrackr-modal-description">
                    Copy and modify these default styles to customize your AI search summary.
                </p>
                <pre class="riviantrackr-modal-code"><code><?php echo esc_html( $this->get_default_css() ); ?></code></pre>
            </div>
        </div>
        <?php
    }

    private function get_default_css() {
        return '@keyframes riviantrackr-spin {
  to { transform: rotate(360deg); }
}

.riviantrackr-summary-content {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-top: 0.75rem;
}

.riviantrackr-spinner {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  border: 2px solid rgba(148,163,184,0.5);
  border-top-color: #fba919;
  display: inline-block;
  animation: riviantrackr-spin 0.7s linear infinite;
  flex-shrink: 0;
}

.riviantrackr-loading-text {
  margin: 0;
  opacity: 0.8;
}

.riviantrackr-summary-content.riviantrackr-loaded {
  display: block;
}

.riviantrackr-openai-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.15rem 0.55rem;
  border-radius: 999px;
  border: 1px solid rgba(148,163,184,0.5);
  background: rgba(15,23,42,0.9);
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  white-space: nowrap;
  opacity: 0.95;
}

.riviantrackr-openai-mark {
  width: 10px;
  height: 10px;
  border-radius: 999px;
  border: 1px solid rgba(148,163,184,0.8);
  position: relative;
  flex-shrink: 0;
}

.riviantrackr-openai-mark::after {
  content: "";
  position: absolute;
  inset: 2px;
  border-radius: 999px;
  background: linear-gradient(135deg,#fba919,#3b82f6);
}

.riviantrackr-sources {
  margin-top: 1rem;
  font-size: 0.85rem;
}

.riviantrackr-sources-toggle {
  border: none;
  background: none;
  padding: 0;
  margin: 0 0 0.4rem 0;
  font-size: 0.85rem;
  cursor: pointer;
  text-decoration: underline;
  text-underline-offset: 2px;
  opacity: 0.95;
  color: #e5e7eb;
}

.riviantrackr-sources-list {
  margin: 0;
  padding-left: 1.1rem;
  font-size: 0.85rem;
}

.riviantrackr-sources-list li {
  margin-bottom: 0.4rem;
}

.riviantrackr-sources-list li:last-child {
  margin-bottom: 0;
}

.riviantrackr-sources-list a {
  color: #fba919;
  text-decoration: underline;
  text-underline-offset: 2px;
}

.riviantrackr-sources-list a:hover {
  opacity: 0.9;
}

.riviantrackr-sources-list span {
  display: block;
  opacity: 0.8;
  color: #cbd5f5;
}';
    }

    /**
     * Check if a model ID is a reasoning model (slow, expensive, not suited for search summaries).
     * Matches: o1, o3, o4, etc. and gpt-5* (reasoning-class models).
     * Does NOT match: gpt-4o (the "o" is part of the model name, not the o-series).
     */
    private static function is_reasoning_model( string $model_id ): bool {
        // o-series reasoning models: o1, o3, o4-mini, etc.
        if ( preg_match( '/^o\d/', $model_id ) ) {
            return true;
        }
        // GPT-5 class models are reasoning models
        if ( strpos( $model_id, 'gpt-5' ) === 0 ) {
            return true;
        }
        return false;
    }

    private function fetch_models_from_openai( $api_key ) {
        if ( empty( $api_key ) ) {
            return array();
        }

        $response = wp_remote_get(
            'https://api.openai.com/v1/models',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                ),
                'timeout' => 5, // Short timeout to avoid blocking admin page render
            )
        );

        if ( is_wp_error( $response ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( '[RivianTrackr AI Search Summary] Model list error: ' . $response->get_error_message() );
            }
            return array();
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( '[RivianTrackr AI Search Summary] Model list HTTP error ' . $code . ' body: ' . $body );
            }
            return array();
        }

        $data = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE || empty( $data['data'] ) ) {
            return array();
        }

        $models = array();

        foreach ( $data['data'] as $model ) {
            if ( empty( $model['id'] ) ) {
                continue;
            }

            $id = $model['id'];

            // Include chat/completion models and o-series reasoning models
            if (
                strpos( $id, 'gpt-5' ) === 0 ||
                strpos( $id, 'gpt-4.1' ) === 0 ||
                strpos( $id, 'gpt-4o' ) === 0 ||
                strpos( $id, 'gpt-4-turbo' ) === 0 ||
                strpos( $id, 'gpt-4-' ) === 0 ||
                strpos( $id, 'gpt-4' ) === 0 ||
                strpos( $id, 'gpt-3.5-turbo' ) === 0 ||
                preg_match( '/^o\d/', $id )
            ) {
                $models[] = $id;
            }
        }

        $models = array_unique( $models );
        sort( $models );

        return $models;
    }

    private function get_available_models_for_dropdown( $api_key ) {
        // Clean, curated default list - only chat completion models
        $default_models = array(
            'gpt-4o-mini',
            'gpt-4o',
            'gpt-4-turbo',
            'gpt-4.1-mini',
            'gpt-4.1-nano',
            'gpt-4.1',
            'gpt-4',
            'gpt-3.5-turbo',
        );

        if ( empty( $api_key ) ) {
            return $default_models;
        }

        $cache         = get_option( $this->models_cache_option );
        $cached_models = ( is_array( $cache ) && ! empty( $cache['models'] ) ) ? $cache['models'] : array();
        $updated_at    = ( is_array( $cache ) && ! empty( $cache['updated_at'] ) ) ? absint( $cache['updated_at'] ) : 0;

        $models = null;

        // Use cached models if they exist and are still within TTL.
        if ( ! empty( $cached_models ) && $updated_at > 0 ) {
            $age = time() - $updated_at;
            if ( $age >= 0 && $age < RIVIANTRACKR_MODELS_CACHE_TTL ) {
                $models = $cached_models;
            }
        }

        // Cache is missing or stale, try to refresh from OpenAI.
        if ( null === $models ) {
            $fetched = $this->fetch_models_from_openai( $api_key );

            if ( ! empty( $fetched ) ) {
                update_option(
                    $this->models_cache_option,
                    array(
                        'models'     => $fetched,
                        'updated_at' => time(),
                    )
                );
                $models = $fetched;
            } elseif ( ! empty( $cached_models ) ) {
                $models = $cached_models;
            } else {
                $models = $default_models;
            }
        }

        // Filter out reasoning models unless the advanced setting is enabled
        $options = $this->get_options();
        $allow_reasoning = ! empty( $options['allow_reasoning_models'] );

        if ( ! $allow_reasoning ) {
            $models = array_values( array_filter( $models, function( $id ) {
                return ! self::is_reasoning_model( $id );
            } ) );
        }

        return $models;
    }

    private function refresh_model_cache( $api_key ) {
        if ( empty( $api_key ) ) {
            return false;
        }

        $models = $this->fetch_models_from_openai( $api_key );

        if ( empty( $models ) ) {
            return false;
        }

        update_option(
            $this->models_cache_option,
            array(
                'models'     => $models,
                'updated_at' => time(),
            )
        );

        return true;
    }

    private function get_cache_namespace(): int {
        $ns = (int) get_option( $this->cache_namespace_option, 1 );
        if ( $ns < 1 ) {
            $ns = 1;
            update_option( $this->cache_namespace_option, $ns );
        }
        return $ns;
    }

    private function bump_cache_namespace(): int {
        $ns = $this->get_cache_namespace();
        $ns++;
        update_option( $this->cache_namespace_option, $ns );
        return $ns;
    }

    private function clear_ai_cache(): bool {
        // Namespace based invalidation: bump namespace so all previous cache keys become unreachable.
        $this->bump_cache_namespace();

        // Backward compatibility cleanup: if older versions stored explicit transient keys, delete them too.
        $keys = get_option( $this->cache_keys_option, array() );
        if ( is_array( $keys ) ) {
            foreach ( $keys as $key ) {
                delete_transient( $key );
            }
        }
        delete_option( $this->cache_keys_option );

        return true;
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $options = $this->get_options();
        $cache   = get_option( $this->models_cache_option );

        // Check if setup is complete
        $has_api_key = ! empty( $options['api_key'] );
        $is_enabled  = ! empty( $options['enable'] );
        $setup_complete = $has_api_key && $is_enabled;
        ?>
        
        <div class="riviantrackr-settings-wrap">
            <!-- Header -->
            <div class="riviantrackr-header">
                <h1>RivianTrackr AI Search Summary Settings</h1>
                <p>Configure OpenAI-powered search summaries for your site.</p>
            </div>

            <!-- Status Card -->
            <div class="riviantrackr-status-card <?php echo $setup_complete ? 'active' : ''; ?>">
                <div class="riviantrackr-status-icon">
                    <?php echo $setup_complete ? '✓' : '○'; ?>
                </div>
                <div class="riviantrackr-status-content">
                    <h3><?php echo $setup_complete ? 'RivianTrackr AI Search Summary Active' : 'Setup Required'; ?></h3>
                    <p>
                        <?php 
                        if ( $setup_complete ) {
                            echo 'Your AI search is configured and running.';
                        } elseif ( ! $has_api_key ) {
                            echo 'Add your OpenAI API key to get started.';
                        } else {
                            echo 'Enable AI search to start generating summaries.';
                        }
                        ?>
                    </p>
                </div>
            </div>

            <?php
            // WordPress settings API handles success/error messages automatically
            settings_errors( 'riviantrackr_group' );
            ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'riviantrackr_group' ); ?>

                <!-- Section 1: Getting Started (Most Important) -->
                <div class="riviantrackr-section">
                    <div class="riviantrackr-section-header">
                        <h2>Getting Started</h2>
                        <p>Essential settings to enable AI search</p>
                    </div>
                    <div class="riviantrackr-section-content">
                        <!-- Enable Toggle -->
                        <div class="riviantrackr-field">
                            <div class="riviantrackr-field-label">
                                <label>RivianTrackr AI Search Summary</label>
                            </div>
                            <div class="riviantrackr-field-description">
                                Enable or disable AI-powered search summaries site-wide
                            </div>
                            <div class="riviantrackr-toggle-wrapper">
                                <label class="riviantrackr-toggle">
                                    <input type="checkbox" 
                                           name="<?php echo esc_attr( $this->option_name ); ?>[enable]"
                                           value="1" 
                                           <?php checked( $options['enable'], 1 ); ?> />
                                    <span class="riviantrackr-toggle-slider"></span>
                                </label>
                                <span class="riviantrackr-toggle-label">
                                    <?php echo $options['enable'] ? 'Enabled' : 'Disabled'; ?>
                                </span>
                            </div>
                        </div>

                        <!-- API Key -->
                        <div class="riviantrackr-field">
                            <div class="riviantrackr-field-label">
                                <label for="riviantrackr-api-key">OpenAI API Key</label>
                                <?php
                                $key_valid = isset( $options['api_key_valid'] ) ? $options['api_key_valid'] : null;
                                if ( $this->is_api_key_from_constant() || ( ! empty( $options['api_key'] ) && $key_valid === true ) ) : ?>
                                    <span style="color: #10b981; font-size: 13px; font-weight: 500; margin-left: 8px;">&#10003; Valid</span>
                                <?php elseif ( ! empty( $options['api_key'] ) && $key_valid === false ) : ?>
                                    <span style="color: #ef4444; font-size: 13px; font-weight: 500; margin-left: 8px;">&#10007; Invalid</span>
                                <?php endif; ?>
                            </div>
                            <?php if ( $this->is_api_key_from_constant() ) : ?>
                                <div class="riviantrackr-field-description" style="background: #d1fae5; border: 1px solid #10b981; padding: 12px; border-radius: 6px; margin-bottom: 12px;">
                                    <strong style="color: #065f46;">&#x1F512; Secure Mode:</strong>
                                    API key is defined via <code>RIVIANTRACKR_API_KEY</code> constant in wp-config.php.
                                    <br><span style="color: #047857;">This is more secure than storing in the database.</span>
                                </div>
                                <div class="riviantrackr-field-input">
                                    <input type="password"
                                           id="riviantrackr-api-key"
                                           value="<?php echo esc_attr( str_repeat( '•', 20 ) ); ?>"
                                           disabled
                                           style="background: #f3f4f6; cursor: not-allowed;" />
                                    <input type="hidden"
                                           name="<?php echo esc_attr( $this->option_name ); ?>[api_key]"
                                           value="" />
                                </div>
                            <?php else : ?>
                                <div class="riviantrackr-field-description">
                                    Get your API key from <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Platform</a>.
                                    <br><em style="color: #6b7280; font-size: 12px;">Tip: For better security, define <code>RIVIANTRACKR_API_KEY</code> in wp-config.php instead.</em>
                                </div>
                                <div class="riviantrackr-field-input">
                                    <input type="password"
                                           id="riviantrackr-api-key"
                                           name="<?php echo esc_attr( $this->option_name ); ?>[api_key]"
                                           value="<?php echo esc_attr( $options['api_key'] ); ?>"
                                           placeholder="sk-proj-..."
                                           autocomplete="off" />
                                </div>
                            <?php endif; ?>
                            <div class="riviantrackr-field-actions">
                                <button type="button"
                                        id="riviantrackr-test-key-btn"
                                        class="riviantrackr-button riviantrackr-button-secondary">
                                    Test Connection
                                </button>
                            </div>
                            <div id="riviantrackr-test-result" style="margin-top: 12px;"></div>
                        </div>
                    </div>
                </div>

                <!-- Section: Site Configuration -->
                <div class="riviantrackr-section">
                    <div class="riviantrackr-section-header">
                        <h2>Site Configuration</h2>
                        <p>Configure how your site is described to the AI</p>
                    </div>
                    <div class="riviantrackr-section-content">
                        <!-- Site Name -->
                        <div class="riviantrackr-field">
                            <div class="riviantrackr-field-label">
                                <label for="riviantrackr-site-name">Site Name</label>
                            </div>
                            <div class="riviantrackr-field-description">
                                The name of your site (used in AI responses and loading text)
                            </div>
                            <div class="riviantrackr-field-input">
                                <input type="text"
                                       id="riviantrackr-site-name"
                                       name="<?php echo esc_attr( $this->option_name ); ?>[site_name]"
                                       value="<?php echo esc_attr( isset( $options['site_name'] ) ? $options['site_name'] : get_bloginfo( 'name' ) ); ?>"
                                       placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
                            </div>
                        </div>

                        <!-- Site Description -->
                        <div class="riviantrackr-field">
                            <div class="riviantrackr-field-label">
                                <label for="riviantrackr-site-description">Site Description</label>
                            </div>
                            <div class="riviantrackr-field-description">
                                Describe your site's focus/niche (e.g., "a technology news site" or "a cooking and recipe blog"). This helps the AI understand context.
                            </div>
                            <div class="riviantrackr-field-input">
                                <input type="text"
                                       id="riviantrackr-site-description"
                                       name="<?php echo esc_attr( $this->option_name ); ?>[site_description]"
                                       value="<?php echo esc_attr( isset( $options['site_description'] ) ? $options['site_description'] : '' ); ?>"
                                       placeholder="e.g., a technology news and reviews site" />
                            </div>
                        </div>

                        <!-- Show OpenAI Badge -->
                        <div class="riviantrackr-field">
                            <div class="riviantrackr-field-label">
                                <label>Show "Powered by OpenAI" Badge</label>
                            </div>
                            <div class="riviantrackr-field-description">
                                Display the OpenAI attribution badge on search summaries
                            </div>
                            <div class="riviantrackr-toggle-wrapper">
                                <label class="riviantrackr-toggle">
                                    <input type="checkbox"
                                           name="<?php echo esc_attr( $this->option_name ); ?>[show_openai_badge]"
                                           value="1"
                                           <?php checked( isset( $options['show_openai_badge'] ) ? $options['show_openai_badge'] : 0, 1 ); ?> />
                                    <span class="riviantrackr-toggle-slider"></span>
                                </label>
                                <span class="riviantrackr-toggle-label">
                                    <?php echo ( isset( $options['show_openai_badge'] ) && $options['show_openai_badge'] ) ? 'Visible' : 'Hidden'; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Show Sources -->
                        <div class="riviantrackr-field">
                            <div class="riviantrackr-field-label">
                                <label>Show Sources</label>
                            </div>
                            <div class="riviantrackr-field-description">
                                Display a collapsible list of source articles used to generate the summary
                            </div>
                            <div class="riviantrackr-toggle-wrapper">
                                <label class="riviantrackr-toggle">
                                    <input type="checkbox"
                                           name="<?php echo esc_attr( $this->option_name ); ?>[show_sources]"
                                           value="1"
                                           <?php checked( isset( $options['show_sources'] ) ? $options['show_sources'] : 0, 1 ); ?> />
                                    <span class="riviantrackr-toggle-slider"></span>
                                </label>
                                <span class="riviantrackr-toggle-label">
                                    <?php echo ( isset( $options['show_sources'] ) && $options['show_sources'] ) ? 'Visible' : 'Hidden'; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Max Sources Display -->
                        <div class="riviantrackr-field">
                            <div class="riviantrackr-field-label">
                                <label>Max Sources Displayed</label>
                            </div>
                            <div class="riviantrackr-field-description">
                                Maximum number of source articles shown beneath the AI summary (1 &ndash; 20)
                            </div>
                            <div class="riviantrackr-field-input">
                                <input type="number"
                                       name="<?php echo esc_attr( $this->option_name ); ?>[max_sources_display]"
                                       value="<?php echo esc_attr( isset( $options['max_sources_display'] ) ? $options['max_sources_display'] : 5 ); ?>"
                                       min="1"
                                       max="20" />
                                <span style="margin-left: 8px; color: #86868b; font-size: 14px;">sources</span>
                            </div>
                        </div>

                        <!-- Show Feedback -->
                        <div class="riviantrackr-field">
                            <div class="riviantrackr-field-label">
                                <label>Show Feedback Prompt</label>
                            </div>
                            <div class="riviantrackr-field-description">
                                Display "Was this helpful?" voting buttons after summaries
                            </div>
                            <div class="riviantrackr-toggle-wrapper">
                                <label class="riviantrackr-toggle">
                                    <input type="checkbox"
                                           name="<?php echo esc_attr( $this->option_name ); ?>[show_feedback]"
                                           value="1"
                                           <?php checked( isset( $options['show_feedback'] ) ? $options['show_feedback'] : 0, 1 ); ?> />
                                    <span class="riviantrackr-toggle-slider"></span>
                                </label>
                                <span class="riviantrackr-toggle-label">
                                    <?php echo ( isset( $options['show_feedback'] ) && $options['show_feedback'] ) ? 'Visible' : 'Hidden'; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 2: AI Configuration -->
                <div class="riviantrackr-section">
                    <div class="riviantrackr-section-header">
                        <h2>AI Configuration</h2>
                        <p>Customize how AI generates search summaries</p>
                    </div>
                    <div class="riviantrackr-section-content">
                        <!-- Model Selection -->
                        <div class="riviantrackr-field">
                            <div class="riviantrackr-field-label">
                                <label>AI Model</label>
                            </div>
                            <div class="riviantrackr-field-input">
                                <?php
                                $models = $this->get_available_models_for_dropdown( $options['api_key'] );
                                if ( ! empty( $options['model'] ) && ! in_array( $options['model'], $models, true ) ) {
                                    $models[] = $options['model'];
                                }
                                $models = array_unique( $models );
                                sort( $models );
                                ?>
                                <select name="<?php echo esc_attr( $this->option_name ); ?>[model]">
                                    <?php foreach ( $models as $model_id ) : ?>
                                        <option value="<?php echo esc_attr( $model_id ); ?>" 
                                                <?php selected( $options['model'], $model_id ); ?>>
                                            <?php echo esc_html( $model_id ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="riviantrackr-field-actions">
                                <button type="button" id="riviantrackr-refresh-models-btn"
                                        class="riviantrackr-button riviantrackr-button-secondary"
                                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'riviantrackr_refresh_models' ) ); ?>">
                                    Refresh Models
                                </button>
                                <span id="riviantrackr-refresh-models-result" style="margin-left: 12px;"></span>
                            </div>
                            <?php if ( is_array( $cache ) && ! empty( $cache['updated_at'] ) ) : ?>
                                <div style="margin-top: 8px; font-size: 13px; color: #86868b;">
                                    Last updated: <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), intval( $cache['updated_at'] ) ) ); ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Max Posts -->
                        <div class="riviantrackr-field">
                            <div class="riviantrackr-field-label">
                                <label>Context Size</label>
                            </div>
                            <div class="riviantrackr-field-description">
                                Number of posts to send as context (more posts = better answers, higher cost)
                            </div>
                            <div class="riviantrackr-field-input">
                                <input type="number"
                                       name="<?php echo esc_attr( $this->option_name ); ?>[max_posts]"
                                       value="<?php echo esc_attr( $options['max_posts'] ); ?>"
                                       min="1"
                                       max="50" />
                            </div>
                        </div>

                        <!-- Content Length -->
                        <div class="riviantrackr-field">
                            <div class="riviantrackr-field-label">
                                <label>Content Length Per Post</label>
                            </div>
                            <div class="riviantrackr-field-description">
                                Characters of post content sent to the AI per article (100 &ndash; 2,000). Higher values give the AI more context but increase token usage and cost.
                            </div>
                            <div class="riviantrackr-field-input">
                                <input type="number"
                                       name="<?php echo esc_attr( $this->option_name ); ?>[content_length]"
                                       value="<?php echo esc_attr( isset( $options['content_length'] ) ? $options['content_length'] : RIVIANTRACKR_CONTENT_LENGTH ); ?>"
                                       min="100"
                                       max="2000"
                                       step="50" />
                                <span style="margin-left: 8px; color: #86868b; font-size: 14px;">characters</span>
                            </div>
                        </div>

                        <!-- Post Types -->
                        <div class="riviantrackr-field">
                            <div class="riviantrackr-field-label">
                                <label>Post Types</label>
                            </div>
                            <div class="riviantrackr-field-description">
                                Choose which post types to include in AI search results. If none are selected, all public post types are included.
                            </div>
                            <div class="riviantrackr-field-input">
                                <?php
                                $public_post_types = get_post_types( array( 'public' => true ), 'objects' );
                                $selected_types    = isset( $options['post_types'] ) && is_array( $options['post_types'] ) ? $options['post_types'] : array();
                                foreach ( $public_post_types as $pt ) :
                                ?>
                                    <label style="display: block; margin-bottom: 6px; cursor: pointer;">
                                        <input type="checkbox"
                                               name="<?php echo esc_attr( $this->option_name ); ?>[post_types][]"
                                               value="<?php echo esc_attr( $pt->name ); ?>"
                                               <?php checked( in_array( $pt->name, $selected_types, true ) ); ?> />
                                        <?php echo esc_html( $pt->labels->singular_name ); ?>
                                        <span style="color: #86868b; font-size: 13px;">(<?php echo esc_html( $pt->name ); ?>)</span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Max Tokens -->
                        <div class="riviantrackr-field">
                            <div class="riviantrackr-field-label">
                                <label>Max Response Tokens</label>
                            </div>
                            <div class="riviantrackr-field-description">
                                Maximum tokens in the AI response (500 &ndash; 16,000). Lower values = shorter answers and lower cost.
                            </div>
                            <div class="riviantrackr-field-input">
                                <input type="number"
                                       name="<?php echo esc_attr( $this->option_name ); ?>[max_tokens]"
                                       value="<?php echo esc_attr( isset( $options['max_tokens'] ) ? $options['max_tokens'] : RIVIANTRACKR_MAX_TOKENS ); ?>"
                                       min="500"
                                       max="16000"
                                       step="100" />
                                <span style="margin-left: 8px; color: #86868b; font-size: 14px;">tokens</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 3: Performance -->
                <div class="riviantrackr-section">
                    <div class="riviantrackr-section-header">
                        <h2>Performance</h2>
                        <p>Control rate limits and caching behavior</p>
                    </div>
                    <div class="riviantrackr-section-content">
                        <!-- Cache TTL -->
                        <div class="riviantrackr-field">
                            <div class="riviantrackr-field-label">
                                <label>Cache Duration</label>
                            </div>
                            <div class="riviantrackr-field-description">
                                How long to cache AI summaries (60 seconds to 24 hours)
                            </div>
                            <div class="riviantrackr-field-input">
                                <input type="number"
                                       name="<?php echo esc_attr( $this->option_name ); ?>[cache_ttl]"
                                       value="<?php echo esc_attr( isset( $options['cache_ttl'] ) ? $options['cache_ttl'] : 3600 ); ?>"
                                       min="60"
                                       max="86400"
                                       step="60" />
                                <span style="margin-left: 8px; color: #86868b; font-size: 14px;">seconds</span>
                            </div>
                            <div class="riviantrackr-field-actions">
                                <button type="button" id="riviantrackr-clear-cache-btn"
                                        class="riviantrackr-button riviantrackr-button-secondary"
                                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'riviantrackr_clear_cache' ) ); ?>">
                                    Clear Cache Now
                                </button>
                                <span id="riviantrackr-clear-cache-result" style="margin-left: 12px;"></span>
                            </div>
                        </div>

                        <!-- Rate Limit -->
                        <div class="riviantrackr-field">
                            <div class="riviantrackr-field-label">
                                <label>Rate Limit</label>
                            </div>
                            <div class="riviantrackr-field-description">
                                Maximum AI calls per minute across the entire site (0 = unlimited)
                            </div>
                            <div class="riviantrackr-field-input">
                                <input type="number"
                                       name="<?php echo esc_attr( $this->option_name ); ?>[max_calls_per_minute]"
                                       value="<?php echo esc_attr( isset( $options['max_calls_per_minute'] ) ? $options['max_calls_per_minute'] : 30 ); ?>"
                                       min="0"
                                       step="1" />
                                <span style="margin-left: 8px; color: #86868b; font-size: 14px;">calls/minute</span>
                            </div>
                        </div>

                        <!-- Request Timeout -->
                        <div class="riviantrackr-field">
                            <div class="riviantrackr-field-label">
                                <label>Request Timeout</label>
                            </div>
                            <div class="riviantrackr-field-description">
                                How long to wait for AI response before timing out (10-300 seconds). Reasoning models like GPT-5, o1, and o3 may need 120-300 seconds.
                            </div>
                            <div class="riviantrackr-field-input">
                                <input type="number"
                                       name="<?php echo esc_attr( $this->option_name ); ?>[request_timeout]"
                                       value="<?php echo esc_attr( isset( $options['request_timeout'] ) ? $options['request_timeout'] : 60 ); ?>"
                                       min="10"
                                       max="300"
                                       step="5" />
                                <span style="margin-left: 8px; color: #86868b; font-size: 14px;">seconds</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 4: Appearance -->
                <div class="riviantrackr-section">
                    <div class="riviantrackr-section-header">
                        <h2>Appearance</h2>
                        <p>Customize the look of AI summaries to match your theme</p>
                    </div>
                    <div class="riviantrackr-section-content">
                        <div class="riviantrackr-field">
                            <div class="riviantrackr-field-label">
                                <label for="riviantrackr-color-background">Background Color</label>
                            </div>
                            <div class="riviantrackr-field-description">
                                Hex color code (e.g. #121e2b)
                            </div>
                            <div class="riviantrackr-field-input">
                                <input type="text"
                                       id="riviantrackr-color-background"
                                       name="<?php echo esc_attr( $this->option_name ); ?>[color_background]"
                                       value="<?php echo esc_attr( isset( $options['color_background'] ) ? $options['color_background'] : '#121e2b' ); ?>"
                                       placeholder="#121e2b" />
                            </div>
                        </div>
                        <div class="riviantrackr-field">
                            <div class="riviantrackr-field-label">
                                <label for="riviantrackr-color-text">Text Color</label>
                            </div>
                            <div class="riviantrackr-field-input">
                                <input type="text"
                                       id="riviantrackr-color-text"
                                       name="<?php echo esc_attr( $this->option_name ); ?>[color_text]"
                                       value="<?php echo esc_attr( isset( $options['color_text'] ) ? $options['color_text'] : '#e5e7eb' ); ?>"
                                       placeholder="#e5e7eb" />
                            </div>
                        </div>
                        <div class="riviantrackr-field">
                            <div class="riviantrackr-field-label">
                                <label for="riviantrackr-color-accent">Accent Color</label>
                            </div>
                            <div class="riviantrackr-field-description">
                                Used for links and highlights
                            </div>
                            <div class="riviantrackr-field-input">
                                <input type="text"
                                       id="riviantrackr-color-accent"
                                       name="<?php echo esc_attr( $this->option_name ); ?>[color_accent]"
                                       value="<?php echo esc_attr( isset( $options['color_accent'] ) ? $options['color_accent'] : '#fba919' ); ?>"
                                       placeholder="#fba919" />
                            </div>
                        </div>
                        <div class="riviantrackr-field">
                            <div class="riviantrackr-field-label">
                                <label for="riviantrackr-color-border">Border Color</label>
                            </div>
                            <div class="riviantrackr-field-input">
                                <input type="text"
                                       id="riviantrackr-color-border"
                                       name="<?php echo esc_attr( $this->option_name ); ?>[color_border]"
                                       value="<?php echo esc_attr( isset( $options['color_border'] ) ? $options['color_border'] : '#94a3b8' ); ?>"
                                       placeholder="#94a3b8" />
                            </div>
                        </div>

                        <!-- Custom CSS -->
                        <div class="riviantrackr-field">
                            <div class="riviantrackr-field-label">
                                <label>Custom CSS</label>
                            </div>
                            <div class="riviantrackr-field-description">
                                Override default styles with your own CSS for complete control
                            </div>
                            <?php $this->field_custom_css(); ?>
                        </div>
                    </div>
                </div>

                <!-- Section 5: Advanced -->
                <div class="riviantrackr-section">
                    <div class="riviantrackr-section-header">
                        <h2>Advanced</h2>
                        <p>Settings for advanced users</p>
                    </div>
                    <div class="riviantrackr-section-content">
                        <div id="riviantrackr-advanced-toggle-wrap" style="padding: 20px 24px;">
                            <button type="button" id="riviantrackr-advanced-toggle" class="riviantrackr-button riviantrackr-button-secondary" style="font-size: 13px; padding: 8px 16px;">
                                Show Advanced Settings
                            </button>
                        </div>
                        <div id="riviantrackr-advanced-settings" style="display: none;">
                            <div style="background: #fef3c7; border: 1px solid #f59e0b; border-radius: 6px; padding: 12px 16px; margin: 0 24px; font-size: 13px; color: #92400e;">
                                <strong>Warning:</strong> These settings are intended for advanced users. Incorrect changes may affect plugin behavior, API costs, or data retention. Proceed with caution.
                            </div>
                        <div class="riviantrackr-field">
                            <div class="riviantrackr-field-label">
                                <label>Allow Reasoning Models</label>
                            </div>
                            <div class="riviantrackr-field-description">
                                Enable reasoning models (GPT-5, o1, o3, etc.) in the model dropdown. These models are significantly slower (60-300s) and more expensive due to hidden reasoning tokens. They do not produce better search summaries than standard models like GPT-4o or GPT-4.1.
                            </div>
                            <div class="riviantrackr-toggle-wrapper">
                                <label class="riviantrackr-toggle">
                                    <input type="checkbox"
                                           name="<?php echo esc_attr( $this->option_name ); ?>[allow_reasoning_models]"
                                           value="1"
                                           <?php checked( ! empty( $options['allow_reasoning_models'] ), true ); ?> />
                                    <span class="riviantrackr-toggle-slider"></span>
                                </label>
                                <span class="riviantrackr-toggle-label">
                                    <?php echo ! empty( $options['allow_reasoning_models'] ) ? 'Enabled' : 'Disabled'; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Spam Blocklist -->
                        <div class="riviantrackr-field">
                            <div class="riviantrackr-field-label">
                                <label for="riviantrackr-spam-blocklist">Spam Blocklist</label>
                            </div>
                            <div class="riviantrackr-field-description">
                                Block search queries containing these terms. One term per line (case-insensitive). Built-in spam detection already blocks URLs, emails, phone numbers, and common spam keywords automatically.
                            </div>
                            <div class="riviantrackr-field-input">
                                <textarea
                                    id="riviantrackr-spam-blocklist"
                                    name="<?php echo esc_attr( $this->option_name ); ?>[spam_blocklist]"
                                    rows="6"
                                    style="width: 100%; max-width: 500px; font-family: monospace; font-size: 13px;"
                                    placeholder="example-spam-term&#10;another blocked phrase&#10;unwanted keyword"><?php echo esc_textarea( isset( $options['spam_blocklist'] ) ? $options['spam_blocklist'] : '' ); ?></textarea>
                            </div>
                        </div>

                        <!-- Preserve Data on Uninstall -->
                        <div class="riviantrackr-field">
                            <div class="riviantrackr-field-label">
                                <label>Preserve Data on Uninstall</label>
                            </div>
                            <div class="riviantrackr-field-description">
                                Keep all plugin data (settings, analytics logs, and feedback) when the plugin is deleted. Enable this if you plan to reinstall the plugin later and want to retain your existing data.
                            </div>
                            <div class="riviantrackr-toggle-wrapper">
                                <label class="riviantrackr-toggle">
                                    <input type="checkbox"
                                           name="<?php echo esc_attr( $this->option_name ); ?>[preserve_data_on_uninstall]"
                                           value="1"
                                           <?php checked( ! empty( $options['preserve_data_on_uninstall'] ), true ); ?> />
                                    <span class="riviantrackr-toggle-slider"></span>
                                </label>
                                <span class="riviantrackr-toggle-label">
                                    <?php echo ! empty( $options['preserve_data_on_uninstall'] ) ? 'Enabled' : 'Disabled'; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Privacy: Anonymize Search Queries -->
                        <div class="riviantrackr-field">
                            <div class="riviantrackr-field-label">
                                <label>Anonymize Search Queries</label>
                            </div>
                            <div class="riviantrackr-field-description">
                                When enabled, new search queries are stored as SHA-256 hashes instead of plain text. Aggregate analytics (totals, success rates) are preserved, but individual query text is not recoverable. Recommended for GDPR/privacy compliance.
                            </div>
                            <div class="riviantrackr-toggle-wrapper">
                                <label class="riviantrackr-toggle">
                                    <input type="checkbox"
                                           name="<?php echo esc_attr( $this->option_name ); ?>[anonymize_queries]"
                                           value="1"
                                           <?php checked( ! empty( $options['anonymize_queries'] ), true ); ?> />
                                    <span class="riviantrackr-toggle-slider"></span>
                                </label>
                                <span class="riviantrackr-toggle-label">
                                    <?php echo ! empty( $options['anonymize_queries'] ) ? 'Enabled' : 'Disabled'; ?>
                                </span>
                            </div>
                            <div style="margin-top: 12px;">
                                <button type="button" id="riviantrackr-gdpr-purge-btn" class="riviantrackr-button riviantrackr-button-secondary" style="font-size: 13px; padding: 6px 12px;">
                                    Anonymize Existing Queries
                                </button>
                                <span id="riviantrackr-gdpr-purge-result" style="font-size: 13px; margin-left: 8px;"></span>
                                <p style="font-size: 12px; color: #6e6e73; margin-top: 4px;">
                                    Retroactively replace all stored query text with SHA-256 hashes. This cannot be undone.
                                </p>
                            </div>
                        </div>
                        </div><!-- /#riviantrackr-advanced-settings -->
                    </div>
                </div>

                <div class="riviantrackr-footer-actions">
                    <?php submit_button( 'Save Settings', 'primary riviantrackr-button riviantrackr-button-primary', 'submit', false ); ?>
                </div>
            </form>
        </div>

        <?php
    }

    public function render_analytics_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $logs_built      = false;
        $logs_error      = '';
        $logs_purged     = false;
        $purge_count     = 0;
        $purge_error     = '';

        // Handle the create/repair action (POST for security)
        if (
            isset( $_POST['riviantrackr_build_logs'] ) &&
            isset( $_POST['_wpnonce'] ) &&
            wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'riviantrackr_build_logs' )
        ) {
            $logs_built = $this->ensure_logs_table();
            if ( ! $logs_built ) {
                $logs_error = 'Could not create or repair the analytics table. Check error logs for details.';
            }
        }

        // Handle the purge old logs action (POST for security)
        if (
            isset( $_POST['riviantrackr_purge_logs'] ) &&
            isset( $_POST['riviantrackr_purge_days'] ) &&
            isset( $_POST['_wpnonce'] ) &&
            wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'riviantrackr_purge_logs' )
        ) {
            $days = absint( $_POST['riviantrackr_purge_days'] );
            if ( $days < 1 ) {
                $days = 30;
            }
            $result = $this->purge_old_logs( $days );
            if ( false === $result ) {
                $purge_error = 'Could not purge logs. The analytics table may not exist.';
            } else {
                $logs_purged = true;
                $purge_count = $result;
            }
        }
        ?>

        <div class="riviantrackr-settings-wrap">
            <!-- Header -->
            <div class="riviantrackr-header">
                <h1>Analytics</h1>
                <p>Track AI search usage, success rates, and identify trends.</p>
            </div>

            <!-- Notifications -->
            <?php if ( $logs_built && empty( $logs_error ) ) : ?>
                <div class="riviantrackr-notice riviantrackr-notice-success">
                    Analytics table has been created or repaired successfully.
                </div>
            <?php elseif ( ! empty( $logs_error ) ) : ?>
                <div class="riviantrackr-notice riviantrackr-notice-error">
                    <?php echo esc_html( $logs_error ); ?>
                </div>
            <?php endif; ?>

            <?php if ( $logs_purged ) : ?>
                <div class="riviantrackr-notice riviantrackr-notice-success">
                    <?php echo esc_html( number_format( $purge_count ) ); ?> old log entries have been deleted.
                </div>
            <?php elseif ( ! empty( $purge_error ) ) : ?>
                <div class="riviantrackr-notice riviantrackr-notice-error">
                    <?php echo esc_html( $purge_error ); ?>
                </div>
            <?php endif; ?>

            <?php if ( ! $this->logs_table_is_available() ) : ?>
                <!-- No Data State -->
                <div class="riviantrackr-empty-state">
                    <div class="riviantrackr-empty-icon">📊</div>
                    <h3>No Analytics Data Yet</h3>
                    <p>After visitors use search, analytics data will appear here.</p>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field( 'riviantrackr_build_logs' ); ?>
                        <button type="submit" name="riviantrackr_build_logs" value="1"
                                class="riviantrackr-button riviantrackr-button-primary">
                            Create Analytics Table
                        </button>
                    </form>
                </div>
            <?php else : ?>
                <?php $this->render_analytics_content(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Get estimated row count for a table using INFORMATION_SCHEMA.
     * Falls back to COUNT(*) for accuracy if estimate is unavailable.
     *
     * @param string $table_name Table name (without prefix).
     * @return int Estimated row count.
     */
    private function get_estimated_row_count( string $table_name ): int {
        global $wpdb;

        // Try to get estimate from INFORMATION_SCHEMA (fast for InnoDB)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $estimate = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT TABLE_ROWS FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
                $table_name
            )
        );

        if ( $estimate !== null ) {
            return (int) $estimate;
        }

        // Fallback to actual count (slower but accurate)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name ) );
    }

    /**
     * Render pagination controls for analytics tables.
     *
     * @param int    $current_page Current page number.
     * @param int    $total_pages  Total number of pages.
     * @param string $param_name   Query parameter name for this pagination.
     */
    private function render_analytics_pagination( int $current_page, int $total_pages, string $param_name ): void {
        if ( $total_pages <= 1 ) {
            return;
        }

        $base_url = admin_url( 'admin.php?page=riviantrackr-analytics' );

        // Preserve other pagination params when navigating
        $preserve_params = array( 'queries_page', 'errors_page', 'events_page' );
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only pagination params on admin page
        foreach ( $preserve_params as $param ) {
            if ( $param !== $param_name && isset( $_GET[ $param ] ) ) {
                $base_url = add_query_arg( $param, absint( wp_unslash( $_GET[ $param ] ) ), $base_url );
            }
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="riviantrackr-pagination" style="margin: 20px; padding-top: 16px; border-top: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center;">
            <div class="riviantrackr-pagination-info" style="font-size: 13px; color: #6e6e73;">
                Page <?php echo esc_html( $current_page ); ?> of <?php echo esc_html( $total_pages ); ?>
            </div>
            <div class="riviantrackr-pagination-buttons" style="display: flex; gap: 8px;">
                <?php if ( $current_page > 1 ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( $param_name, $current_page - 1, $base_url ) ); ?>"
                       style="display: inline-block; padding: 8px 16px; font-size: 13px; font-weight: 500; color: #374151; background: #fff; border: 1px solid #d1d5db; border-radius: 6px; text-decoration: none; transition: all 0.15s ease;">
                        &laquo; Previous
                    </a>
                <?php else : ?>
                    <span style="display: inline-block; padding: 8px 16px; font-size: 13px; font-weight: 500; color: #9ca3af; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; cursor: not-allowed;">
                        &laquo; Previous
                    </span>
                <?php endif; ?>

                <?php if ( $current_page < $total_pages ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( $param_name, $current_page + 1, $base_url ) ); ?>"
                       style="display: inline-block; padding: 8px 16px; font-size: 13px; font-weight: 500; color: #fff; background: #0071e3; border: 1px solid #0071e3; border-radius: 6px; text-decoration: none; transition: all 0.15s ease;">
                        Next &raquo;
                    </a>
                <?php else : ?>
                    <span style="display: inline-block; padding: 8px 16px; font-size: 13px; font-weight: 500; color: #9ca3af; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; cursor: not-allowed;">
                        Next &raquo;
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function render_analytics_content() {
        global $wpdb;
        $table_name = self::get_logs_table_name();

        // Get estimated row count to optimize queries for large datasets
        $estimated_rows = $this->get_estimated_row_count( $table_name );
        $is_large_table = $estimated_rows > RIVIANTRACKR_LARGE_TABLE_THRESHOLD;

        // Get cached overview stats (5-minute TTL)
        $cache_key = 'riviantrackr_analytics_overview';
        $cached_stats = get_transient( $cache_key );

        if ( false === $cached_stats ) {
            // cache_hit values: 0 = miss, 1 = server cache hit, 2 = session/browser cache hit
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $totals = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT
                        COUNT(*) AS total,
                        SUM(ai_success) AS success_count,
                        SUM(CASE WHEN ai_success = 0 AND (ai_error IS NOT NULL AND ai_error <> \'\') THEN 1 ELSE 0 END) AS error_count,
                        SUM(CASE WHEN cache_hit IN (1, 2) THEN 1 ELSE 0 END) AS cache_hits,
                        SUM(CASE WHEN cache_hit = 0 THEN 1 ELSE 0 END) AS cache_misses,
                        AVG(response_time_ms) AS avg_response_time
                     FROM %i',
                    $table_name
                )
            );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $no_results_count = (int) $wpdb->get_var(
                $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE results_count = 0', $table_name )
            );

            $cached_stats = array(
                'totals'           => $totals,
                'no_results_count' => $no_results_count,
            );
            set_transient( $cache_key, $cached_stats, 5 * MINUTE_IN_SECONDS );
        }

        $totals           = $cached_stats['totals'];
        $no_results_count = $cached_stats['no_results_count'];

        $total_searches      = $totals ? (int) $totals->total : 0;
        $success_count       = $totals ? (int) $totals->success_count : 0;
        $error_count         = $totals ? (int) $totals->error_count : 0;
        $cache_hits          = $totals ? (int) $totals->cache_hits : 0;
        $cache_misses        = $totals ? (int) $totals->cache_misses : 0;
        $avg_response_time   = $totals && $totals->avg_response_time !== null ? (int) round( (float) $totals->avg_response_time ) : null;
        $cache_total         = $cache_hits + $cache_misses;
        $cache_hit_rate      = $cache_total > 0 ? round( ( $cache_hits / $cache_total ) * 100, 1 ) : 0;
        $success_rate        = $this->calculate_success_rate( $success_count, $total_searches );

        // Get feedback stats
        $feedback_stats = $this->get_feedback_stats();

        $since_24h = gmdate( 'Y-m-d H:i:s', time() - 24 * 60 * 60 );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $last_24   = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE created_at >= %s',
                $table_name,
                $since_24h
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $daily_stats = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT
                    DATE(created_at) AS day,
                    COUNT(*) AS total,
                    SUM(ai_success) AS success_count,
                    SUM(CASE WHEN cache_hit IN (1, 2) THEN 1 ELSE 0 END) AS cache_hits,
                    SUM(CASE WHEN cache_hit = 0 THEN 1 ELSE 0 END) AS cache_misses
                 FROM %i
                 GROUP BY DATE(created_at)
                 ORDER BY day DESC
                 LIMIT 14',
                $table_name
            )
        );

        // Pagination for Top Queries
        $queries_per_page = RIVIANTRACKR_PER_PAGE_QUERIES;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination parameter on admin page
        $queries_page     = isset( $_GET['queries_page'] ) ? max( 1, absint( wp_unslash( $_GET['queries_page'] ) ) ) : 1;
        $queries_offset   = ( $queries_page - 1 ) * $queries_per_page;

        $feedback_table_name = self::get_feedback_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total_unique_queries = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(DISTINCT search_query) FROM %i', $table_name )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $top_queries = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT l.search_query,
                        COUNT(*) AS total,
                        SUM(l.ai_success) AS success_count,
                        f.vote_count,
                        f.helpful_count
                 FROM %i l
                 LEFT JOIN (
                     SELECT search_query,
                            COUNT(*) AS vote_count,
                            SUM(helpful) AS helpful_count
                     FROM %i
                     GROUP BY search_query
                 ) f ON l.search_query = f.search_query
                 GROUP BY l.search_query
                 ORDER BY total DESC
                 LIMIT %d OFFSET %d',
                $table_name,
                $feedback_table_name,
                $queries_per_page,
                $queries_offset
            )
        );

        $total_queries_pages = max( 1, (int) ceil( $total_unique_queries / $queries_per_page ) );

        // Pagination for Top Errors
        $errors_per_page = RIVIANTRACKR_PER_PAGE_ERRORS;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination parameter on admin page
        $errors_page     = isset( $_GET['errors_page'] ) ? max( 1, absint( wp_unslash( $_GET['errors_page'] ) ) ) : 1;
        $errors_offset   = ( $errors_page - 1 ) * $errors_per_page;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total_unique_errors = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(DISTINCT ai_error) FROM %i WHERE ai_error IS NOT NULL AND ai_error <> \'\'',
                $table_name
            )
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $top_errors = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT ai_error, COUNT(*) AS total
                 FROM %i
                 WHERE ai_error IS NOT NULL AND ai_error <> \'\'
                 GROUP BY ai_error
                 ORDER BY total DESC
                 LIMIT %d OFFSET %d',
                $table_name,
                $errors_per_page,
                $errors_offset
            )
        );

        // Pagination for recent events
        $events_per_page = RIVIANTRACKR_PER_PAGE_EVENTS;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a read-only pagination parameter on an admin page
        $current_page    = isset( $_GET['events_page'] ) ? max( 1, absint( wp_unslash( $_GET['events_page'] ) ) ) : 1;
        $events_offset   = ( $current_page - 1 ) * $events_per_page;

        // For large tables, limit how far back users can paginate
        $max_pages = $is_large_table ? 20 : 100;
        if ( $current_page > $max_pages ) {
            $current_page = $max_pages;
            $events_offset = ( $current_page - 1 ) * $events_per_page;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $recent_events = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT *
                 FROM %i
                 ORDER BY created_at DESC
                 LIMIT %d OFFSET %d',
                $table_name,
                $events_per_page,
                $events_offset
            )
        );

        // Calculate total pages (use cached total_searches for efficiency)
        $total_events = $total_searches;
        $total_pages  = min( $max_pages, (int) ceil( $total_events / $events_per_page ) );
        ?>

        <!-- Overview Stats Grid -->
        <div class="riviantrackr-stats-grid">
            <div class="riviantrackr-stat-card">
                <div class="riviantrackr-stat-label">Total Searches</div>
                <div class="riviantrackr-stat-value"><?php echo number_format( $total_searches ); ?></div>
            </div>
            <div class="riviantrackr-stat-card">
                <div class="riviantrackr-stat-label">Success Rate</div>
                <div class="riviantrackr-stat-value"><?php echo esc_html( $success_rate ); ?>%</div>
            </div>
            <div class="riviantrackr-stat-card">
                <div class="riviantrackr-stat-label">Cache Hit Rate</div>
                <div class="riviantrackr-stat-value"><?php echo esc_html( $cache_hit_rate ); ?>%</div>
            </div>
            <div class="riviantrackr-stat-card">
                <div class="riviantrackr-stat-label">Cache Hits</div>
                <div class="riviantrackr-stat-value"><?php echo number_format( $cache_hits ); ?></div>
            </div>
            <div class="riviantrackr-stat-card">
                <div class="riviantrackr-stat-label">Cache Misses</div>
                <div class="riviantrackr-stat-value"><?php echo number_format( $cache_misses ); ?></div>
            </div>
            <div class="riviantrackr-stat-card">
                <div class="riviantrackr-stat-label">Last 24 Hours</div>
                <div class="riviantrackr-stat-value"><?php echo number_format( $last_24 ); ?></div>
            </div>
            <div class="riviantrackr-stat-card">
                <div class="riviantrackr-stat-label">Total Errors</div>
                <div class="riviantrackr-stat-value"><?php echo number_format( $error_count ); ?></div>
            </div>
            <div class="riviantrackr-stat-card">
                <div class="riviantrackr-stat-label">No Results</div>
                <div class="riviantrackr-stat-value"><?php echo number_format( $no_results_count ); ?></div>
            </div>
            <div class="riviantrackr-stat-card">
                <div class="riviantrackr-stat-label">Avg Response Time</div>
                <div class="riviantrackr-stat-value"><?php
                    if ( $avg_response_time !== null ) {
                        if ( $avg_response_time >= 1000 ) {
                            echo esc_html( number_format( $avg_response_time / 1000, 1 ) . 's' );
                        } else {
                            echo esc_html( $avg_response_time . 'ms' );
                        }
                    } else {
                        echo '&mdash;';
                    }
                ?></div>
            </div>
            <div class="riviantrackr-stat-card">
                <div class="riviantrackr-stat-label">Helpful Rate</div>
                <div class="riviantrackr-stat-value"><?php
                    if ( $feedback_stats['total_votes'] > 0 ) {
                        echo esc_html( $feedback_stats['helpful_rate'] . '%' );
                    } else {
                        echo '&mdash;';
                    }
                ?></div>
            </div>
            <div class="riviantrackr-stat-card">
                <div class="riviantrackr-stat-label">Total Votes</div>
                <div class="riviantrackr-stat-value"><?php echo number_format( $feedback_stats['total_votes'] ); ?></div>
            </div>
        </div>

        <!-- Badge Legend -->
        <div style="margin-bottom: 16px; padding: 12px 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 12px; color: #6e6e73; display: flex; flex-wrap: wrap; gap: 16px; align-items: center;">
            <span style="font-weight: 600; color: #374151;">Badge Thresholds:</span>
            <span><span class="riviantrackr-badge riviantrackr-badge-success" style="font-size: 11px; padding: 2px 6px;">AI Success</span> &ge;<?php echo esc_html( RIVIANTRACKR_BADGE_SUCCESS_HIGH ); ?>%</span>
            <span><span class="riviantrackr-badge riviantrackr-badge-warning" style="font-size: 11px; padding: 2px 6px;">AI Success</span> &ge;<?php echo esc_html( RIVIANTRACKR_BADGE_SUCCESS_MED ); ?>%</span>
            <span><span class="riviantrackr-badge riviantrackr-badge-success" style="font-size: 11px; padding: 2px 6px;">Cache</span> &ge;<?php echo esc_html( RIVIANTRACKR_BADGE_CACHE_HIGH ); ?>%</span>
            <span><span class="riviantrackr-badge riviantrackr-badge-warning" style="font-size: 11px; padding: 2px 6px;">Cache</span> &ge;<?php echo esc_html( RIVIANTRACKR_BADGE_CACHE_MED ); ?>%</span>
            <span><span class="riviantrackr-badge riviantrackr-badge-success" style="font-size: 11px; padding: 2px 6px;">Helpful</span> &ge;<?php echo esc_html( RIVIANTRACKR_BADGE_HELPFUL_HIGH ); ?>%</span>
            <span><span class="riviantrackr-badge riviantrackr-badge-warning" style="font-size: 11px; padding: 2px 6px;">Helpful</span> &ge;<?php echo esc_html( RIVIANTRACKR_BADGE_HELPFUL_MED ); ?>%</span>
            <span><span class="riviantrackr-badge riviantrackr-badge-error" style="font-size: 11px; padding: 2px 6px;">Any</span> below thresholds</span>
        </div>

        <!-- Daily Stats Section -->
        <div class="riviantrackr-section">
            <div class="riviantrackr-section-header">
                <h2>Last 14 Days</h2>
                <p>Daily search volume and success rates</p>
            </div>
            <div class="riviantrackr-section-content">
                <?php if ( ! empty( $daily_stats ) ) : ?>
                    <div class="riviantrackr-table-wrapper">
                        <table class="riviantrackr-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Total Searches</th>
                                    <th>Success Rate</th>
                                    <th>Cache Hit Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $daily_stats as $row ) : ?>
                                    <?php
                                    $day_total = (int) $row->total;
                                    $day_success = (int) $row->success_count;
                                    $day_rate = $this->calculate_success_rate( $day_success, $day_total );
                                    $day_cache_hits = (int) $row->cache_hits;
                                    $day_cache_misses = (int) $row->cache_misses;
                                    $day_cache_total = $day_cache_hits + $day_cache_misses;
                                    $day_cache_rate = $day_cache_total > 0 ? round( ( $day_cache_hits / $day_cache_total ) * 100, 1 ) : 0;
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->day ) ) ); ?></td>
                                        <td><?php echo number_format( $day_total ); ?></td>
                                        <td>
                                            <span class="riviantrackr-badge riviantrackr-badge-<?php echo $day_rate >= RIVIANTRACKR_BADGE_SUCCESS_HIGH ? 'success' : ( $day_rate >= RIVIANTRACKR_BADGE_SUCCESS_MED ? 'warning' : 'error' ); ?>">
                                                <?php echo esc_html( $day_rate ); ?>%
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ( $day_cache_total > 0 ) : ?>
                                                <span class="riviantrackr-badge riviantrackr-badge-<?php echo $day_cache_rate >= RIVIANTRACKR_BADGE_CACHE_HIGH ? 'success' : ( $day_cache_rate >= RIVIANTRACKR_BADGE_CACHE_MED ? 'warning' : 'error' ); ?>">
                                                    <?php echo esc_html( $day_cache_rate ); ?>%
                                                </span>
                                            <?php else : ?>
                                                <span class="riviantrackr-badge">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else : ?>
                    <div class="riviantrackr-empty-message">No recent activity yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Top Queries Section -->
        <div class="riviantrackr-section">
            <div class="riviantrackr-section-header">
                <h2>Top Search Queries</h2>
                <p>
                    <?php
                    if ( $total_unique_queries > 0 ) {
                        $q_start = $queries_offset + 1;
                        $q_end   = min( $queries_offset + $queries_per_page, $total_unique_queries );
                        printf(
                            'Showing %s-%s of %s unique queries',
                            number_format( $q_start ),
                            number_format( $q_end ),
                            number_format( $total_unique_queries )
                        );
                    } else {
                        echo 'Most frequently searched terms';
                    }
                    ?>
                </p>
            </div>
            <div class="riviantrackr-section-content">
                <?php if ( ! empty( $top_queries ) ) : ?>
                    <div class="riviantrackr-table-wrapper">
                        <table class="riviantrackr-table">
                            <thead>
                                <tr>
                                    <th>Query</th>
                                    <th>Searches</th>
                                    <th>AI Success</th>
                                    <th>Helpful</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $top_queries as $row ) : ?>
                                    <?php
                                    $total_q = (int) $row->total;
                                    $success_q = (int) $row->success_count;
                                    $success_q_rate = $this->calculate_success_rate( $success_q, $total_q );
                                    $vote_count = isset( $row->vote_count ) ? (int) $row->vote_count : 0;
                                    $helpful_count = isset( $row->helpful_count ) ? (int) $row->helpful_count : 0;
                                    $helpful_rate = $vote_count > 0 ? round( ( $helpful_count / $vote_count ) * 100 ) : null;
                                    ?>
                                    <tr>
                                        <td class="riviantrackr-query-cell" title="<?php echo esc_attr( $row->search_query ); ?>"><?php echo esc_html( $row->search_query ); ?></td>
                                        <td><?php echo number_format( $total_q ); ?></td>
                                        <td>
                                            <span class="riviantrackr-badge riviantrackr-badge-<?php echo $success_q_rate >= RIVIANTRACKR_BADGE_SUCCESS_HIGH ? 'success' : ( $success_q_rate >= RIVIANTRACKR_BADGE_SUCCESS_MED ? 'warning' : 'error' ); ?>">
                                                <?php echo esc_html( $success_q_rate ); ?>%
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ( $helpful_rate !== null ) : ?>
                                                <span class="riviantrackr-badge riviantrackr-badge-<?php echo $helpful_rate >= RIVIANTRACKR_BADGE_HELPFUL_HIGH ? 'success' : ( $helpful_rate >= RIVIANTRACKR_BADGE_HELPFUL_MED ? 'warning' : 'error' ); ?>">
                                                    <?php echo esc_html( $helpful_rate ); ?>%
                                                </span>
                                                <span style="font-size:0.75rem; opacity:0.6;">(<?php echo esc_html( $vote_count ); ?>)</span>
                                            <?php else : ?>
                                                <span style="opacity:0.4;">&mdash;</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ( $total_queries_pages > 1 ) : ?>
                        <?php $this->render_analytics_pagination( $queries_page, $total_queries_pages, 'queries_page' ); ?>
                    <?php endif; ?>

                <?php else : ?>
                    <div class="riviantrackr-empty-message">No search data yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <?php $total_errors_pages = max( 1, (int) ceil( $total_unique_errors / $errors_per_page ) ); ?>

        <!-- Top Errors Section -->
        <?php if ( ! empty( $top_errors ) || $errors_page > 1 ) : ?>
            <div class="riviantrackr-section">
                <div class="riviantrackr-section-header">
                    <h2>Top AI Errors</h2>
                    <p>
                        <?php
                        if ( $total_unique_errors > 0 ) {
                            $e_start = $errors_offset + 1;
                            $e_end   = min( $errors_offset + $errors_per_page, $total_unique_errors );
                            printf(
                                'Showing %s-%s of %s unique errors',
                                number_format( $e_start ),
                                number_format( $e_end ),
                                number_format( $total_unique_errors )
                            );
                        } else {
                            echo 'Most common error messages';
                        }
                        ?>
                    </p>
                </div>
                <div class="riviantrackr-section-content">
                    <?php if ( ! empty( $top_errors ) ) : ?>
                        <div class="riviantrackr-table-wrapper">
                            <table class="riviantrackr-table">
                                <thead>
                                    <tr>
                                        <th>Error Message</th>
                                        <th>Occurrences</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ( $top_errors as $err ) : ?>
                                        <tr>
                                            <td class="riviantrackr-error-cell">
                                                <?php
                                                $msg = (string) $err->ai_error;
                                                if ( strlen( $msg ) > 80 ) {
                                                    $msg = substr( $msg, 0, 77 ) . '...';
                                                }
                                                echo esc_html( $msg );
                                                ?>
                                            </td>
                                            <td><?php echo number_format( (int) $err->total ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ( $total_errors_pages > 1 ) : ?>
                            <?php $this->render_analytics_pagination( $errors_page, $total_errors_pages, 'errors_page' ); ?>
                        <?php endif; ?>

                    <?php else : ?>
                        <div class="riviantrackr-empty-message">No errors on this page.</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Recent Events Section -->
        <div class="riviantrackr-section">
            <div class="riviantrackr-section-header">
                <h2>Recent RivianTrackr AI Search Summary Events</h2>
                <p>
                    <?php
                    $start_num = $events_offset + 1;
                    $end_num   = min( $events_offset + $events_per_page, $total_events );
                    if ( $total_events > 0 ) {
                        printf(
                            'Showing %s-%s of %s events',
                            number_format( $start_num ),
                            number_format( $end_num ),
                            number_format( $total_events )
                        );
                    } else {
                        echo 'No events recorded yet';
                    }
                    ?>
                </p>
            </div>
            <div class="riviantrackr-section-content">
                <?php if ( ! empty( $recent_events ) ) : ?>
                    <div style="margin: 16px 20px 8px; display: flex; align-items: center; gap: 12px;">
                        <button type="button" id="riviantrackr-bulk-delete-btn"
                                class="riviantrackr-button riviantrackr-button-secondary" style="font-size: 13px; padding: 6px 12px; display: none;">
                            Delete Selected
                        </button>
                        <span id="riviantrackr-bulk-delete-result" style="font-size: 13px;"></span>
                    </div>
                    <div class="riviantrackr-table-wrapper">
                        <table class="riviantrackr-table riviantrackr-table-compact" id="riviantrackr-events-table">
                            <thead>
                                <tr>
                                    <th style="width: 32px; text-align: center;"><input type="checkbox" id="riviantrackr-select-all" title="Select all" /></th>
                                    <th>Query</th>
                                    <th>Status</th>
                                    <th>Cache</th>
                                    <th>Time</th>
                                    <th>Error</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $recent_events as $event ) : ?>
                                    <tr>
                                        <td style="text-align: center;"><input type="checkbox" class="riviantrackr-row-check" value="<?php echo esc_attr( $event->id ); ?>" /></td>
                                        <td class="riviantrackr-query-cell" title="<?php echo esc_attr( $event->search_query ); ?>"><?php echo esc_html( $event->search_query ); ?></td>
                                        <td>
                                            <?php if ( (int) $event->ai_success === 1 ) : ?>
                                                <span class="riviantrackr-badge riviantrackr-badge-success">Success</span>
                                            <?php else : ?>
                                                <span class="riviantrackr-badge riviantrackr-badge-error">Error</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $cache_val = $event->cache_hit !== null ? (int) $event->cache_hit : null;
                                            if ( $cache_val === 1 ) : ?>
                                                <span class="riviantrackr-badge riviantrackr-badge-success" title="Server cache hit">Hit</span>
                                            <?php elseif ( $cache_val === 2 ) : ?>
                                                <span class="riviantrackr-badge riviantrackr-badge-info" title="Browser session cache hit">Session</span>
                                            <?php elseif ( $cache_val === 0 ) : ?>
                                                <span class="riviantrackr-badge riviantrackr-badge-warning">Miss</span>
                                            <?php else : ?>
                                                <span class="riviantrackr-badge riviantrackr-badge-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="riviantrackr-date-cell"><?php
                                            if ( isset( $event->response_time_ms ) && $event->response_time_ms !== null ) {
                                                $rt = (int) $event->response_time_ms;
                                                if ( $rt >= 1000 ) {
                                                    echo esc_html( number_format( $rt / 1000, 1 ) . 's' );
                                                } else {
                                                    echo esc_html( $rt . 'ms' );
                                                }
                                            } else {
                                                echo '&mdash;';
                                            }
                                        ?></td>
                                        <td class="riviantrackr-error-cell" <?php if ( ! empty( $event->ai_error ) ) : ?>title="<?php echo esc_attr( $event->ai_error ); ?>"<?php endif; ?>>
                                            <?php echo esc_html( $event->ai_error ); ?>
                                        </td>
                                        <td class="riviantrackr-date-cell">
                                            <?php echo esc_html( date_i18n( 'M j, g:i a', strtotime( $event->created_at ) ) ); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ( $total_pages > 1 ) : ?>
                        <?php $this->render_analytics_pagination( $current_page, $total_pages, 'events_page' ); ?>
                        <?php if ( $is_large_table ) : ?>
                            <div style="margin: 0 20px 10px; font-size: 11px;">
                                <span style="padding: 2px 8px; background: #fef3c7; color: #92400e; border-radius: 4px;">
                                    Large dataset - showing recent <?php echo esc_html( number_format( $max_pages * $events_per_page ) ); ?> events
                                </span>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                <?php else : ?>
                    <div class="riviantrackr-empty-message">No recent search events logged yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Data Management Section -->
        <div class="riviantrackr-section">
            <div class="riviantrackr-section-header">
                <h2>Data Management</h2>
                <p>Manage analytics log data</p>
            </div>
            <div class="riviantrackr-section-content">
                <!-- Spam Cleanup -->
                <div class="riviantrackr-field">
                    <div class="riviantrackr-field-label">
                        <label>Spam Cleanup</label>
                    </div>
                    <div class="riviantrackr-field-description">
                        Scan log entries for spam patterns (URLs, emails, phone numbers, known spam keywords, and your blocklist) and remove them.
                    </div>
                    <div style="margin-top: 12px; display: flex; align-items: center; gap: 12px;">
                        <button type="button" id="riviantrackr-purge-spam-btn"
                                class="riviantrackr-button riviantrackr-button-secondary"
                                data-nonce="<?php echo esc_attr( wp_create_nonce( 'riviantrackr_purge_spam' ) ); ?>">
                            Scan &amp; Remove Spam
                        </button>
                        <span id="riviantrackr-purge-spam-result"></span>
                    </div>
                </div>

                <div class="riviantrackr-field" style="margin-top: 24px;">
                    <div class="riviantrackr-field-label">
                        <label>Purge Old Logs</label>
                    </div>
                    <div class="riviantrackr-field-description">
                        Delete log entries older than the specified number of days to free up database space.
                    </div>
                    <form method="post" style="display: flex; align-items: center; gap: 12px; margin-top: 12px;">
                        <?php wp_nonce_field( 'riviantrackr_purge_logs' ); ?>
                        <span>Delete logs older than</span>
                        <input type="number" name="riviantrackr_purge_days" value="30" min="1" max="365"
                               style="width: 80px;" />
                        <span>days</span>
                        <button type="submit" name="riviantrackr_purge_logs" value="1"
                                class="riviantrackr-button riviantrackr-button-secondary"
                                onclick="return confirm('Are you sure you want to delete old log entries? This action cannot be undone.');">
                            Purge Old Logs
                        </button>
                    </form>
                </div>
                <div class="riviantrackr-field" style="margin-top: 24px;">
                    <div class="riviantrackr-field-label">
                        <label>Automatic Purging</label>
                    </div>
                    <div class="riviantrackr-field-description">
                        Automatically delete old logs on a daily schedule to keep your database clean.
                    </div>
                    <?php
                    $options          = $this->get_options();
                    $auto_purge       = ! empty( $options['auto_purge_enabled'] );
                    $auto_purge_days  = isset( $options['auto_purge_days'] ) ? absint( $options['auto_purge_days'] ) : 90;
                    $next_scheduled   = wp_next_scheduled( 'riviantrackr_daily_log_purge' );
                    ?>
                    <form method="post" action="options.php" style="margin-top: 12px;">
                        <?php settings_fields( 'riviantrackr_group' ); ?>
                        <?php
                        // Preserve all existing options as hidden fields
                        foreach ( $options as $key => $value ) {
                            if ( $key !== 'auto_purge_enabled' && $key !== 'auto_purge_days' ) {
                                if ( is_array( $value ) ) {
                                    foreach ( $value as $item ) {
                                        echo '<input type="hidden" name="' . esc_attr( $this->option_name ) . '[' . esc_attr( $key ) . '][]" value="' . esc_attr( $item ) . '" />';
                                    }
                                    continue;
                                }
                                echo '<input type="hidden" name="' . esc_attr( $this->option_name ) . '[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" />';
                            }
                        }
                        ?>
                        <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 12px;">
                            <label style="display: flex; align-items: center; gap: 6px;">
                                <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[auto_purge_enabled]" value="1" <?php checked( $auto_purge ); ?> />
                                <span>Enable automatic purging</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px;">
                                <span>Keep logs for</span>
                                <input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[auto_purge_days]" value="<?php echo esc_attr( $auto_purge_days ); ?>" min="7" max="365" style="width: 80px;" />
                                <span>days</span>
                            </label>
                            <button type="submit" class="riviantrackr-button riviantrackr-button-secondary">Save</button>
                        </div>
                        <?php if ( $auto_purge && $next_scheduled ) : ?>
                            <p style="margin-top: 8px; font-size: 12px; color: #6e6e73;">
                                Next scheduled purge: <?php echo esc_html( date_i18n( 'M j, Y g:i a', $next_scheduled ) ); ?>
                            </p>
                        <?php endif; ?>
                    </form>
                </div>
                <div class="riviantrackr-field" style="margin-top: 24px;">
                    <div class="riviantrackr-field-label">
                        <label>Export Data</label>
                    </div>
                    <div class="riviantrackr-field-description">
                        Download analytics data as CSV for external analysis. Choose date range and data type.
                    </div>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 12px;">
                        <?php wp_nonce_field( 'riviantrackr_export_csv' ); ?>
                        <input type="hidden" name="action" value="riviantrackr_export_csv" />
                        <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 12px;">
                            <label style="display: flex; align-items: center; gap: 6px;">
                                <span>From:</span>
                                <input type="date" name="export_from" value="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( '-30 days' ) ) ); ?>" />
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px;">
                                <span>To:</span>
                                <input type="date" name="export_to" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" />
                            </label>
                            <label style="display: flex; align-items: center; gap: 6px;">
                                <span>Data:</span>
                                <select name="export_type">
                                    <option value="logs">Search Logs</option>
                                    <option value="feedback">User Feedback</option>
                                    <option value="daily">Daily Summary</option>
                                </select>
                            </label>
                            <button type="submit" class="riviantrackr-button riviantrackr-button-secondary">
                                Export CSV
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php
    }

    /* ---------------------------------------------------------
     *  Analytics helper methods
     * --------------------------------------------------------- */

    /**
     * Calculate success rate percentage from success count and total.
     *
     * @param int $success_count Number of successful operations.
     * @param int $total Total number of operations.
     * @return int Success rate as a percentage (0-100).
     */
    private function calculate_success_rate( int $success_count, int $total ): int {
        if ( $total <= 0 ) {
            return 0;
        }
        
        return (int) round( ( $success_count / $total ) * 100 );
    }

    /* ---------------------------------------------------------
     *  Dashboard widget
     * --------------------------------------------------------- */

    public function register_dashboard_widget() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        wp_add_dashboard_widget(
            'riviantrackr_dashboard_widget',
            'RivianTrackr AI Search Summary',
            array( $this, 'render_dashboard_widget' )
        );
    }

    public function render_dashboard_widget() {
        if ( ! $this->logs_table_is_available() ) {
            ?>
            <div style="padding: 20px; text-align: center;">
                <div style="font-size: 48px; opacity: 0.3; margin-bottom: 12px;">📊</div>
                <p style="margin: 0 0 8px 0; font-size: 15px; font-weight: 600; color: #1d1d1f;">
                    No Analytics Data Yet
                </p>
                <p style="margin: 0 0 16px 0; font-size: 13px; color: #6e6e73;">
                    Once visitors use search, stats will appear here.
                </p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=riviantrackr-settings' ) ); ?>"
                   style="display: inline-block; padding: 6px 14px; background: #0071e3; color: #fff; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: 500;">
                    Configure Plugin
                </a>
            </div>
            <?php
            return;
        }

        global $wpdb;
        $table_name = self::get_logs_table_name();

        // Dashboard widget uses cached stats with 5-minute TTL
        $cache_key = 'riviantrackr_dashboard_widget_stats';
        $cached = get_transient( $cache_key );

        if ( false === $cached ) {
            // Limit dashboard queries to last 30 days for performance
            $since_30d = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
            $since_24h = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $totals = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT COUNT(*) AS total, SUM(ai_success) AS success_count, AVG(response_time_ms) AS avg_response_time
                     FROM %i
                     WHERE created_at >= %s',
                    $table_name,
                    $since_30d
                )
            );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $last_24 = (int) $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM %i WHERE created_at >= %s',
                    $table_name,
                    $since_24h
                )
            );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $top_queries = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT search_query, COUNT(*) AS total, SUM(ai_success) AS success_count
                     FROM %i
                     WHERE created_at >= %s
                     GROUP BY search_query
                     ORDER BY total DESC
                     LIMIT 5',
                    $table_name,
                    $since_30d
                )
            );

            $cached = array(
                'totals'      => $totals,
                'last_24'     => $last_24,
                'top_queries' => $top_queries,
            );
            set_transient( $cache_key, $cached, 5 * MINUTE_IN_SECONDS );
        }

        $totals      = $cached['totals'];
        $last_24     = $cached['last_24'];
        $top_queries = $cached['top_queries'];

        $total_searches     = $totals ? (int) $totals->total : 0;
        $success_count      = $totals ? (int) $totals->success_count : 0;
        $success_rate       = $this->calculate_success_rate( $success_count, $total_searches );
        $widget_avg_rt      = $totals && $totals->avg_response_time !== null ? (int) round( (float) $totals->avg_response_time ) : null;
        ?>
        
        <div class="riviantrackr-widget-container">
            <!-- Stats Grid -->
            <div class="riviantrackr-widget-stats-grid">
                <div class="riviantrackr-widget-stat">
                    <span class="riviantrackr-widget-stat-value"><?php echo number_format( $total_searches ); ?></span>
                    <span class="riviantrackr-widget-stat-label">Total Searches</span>
                </div>
                <div class="riviantrackr-widget-stat">
                    <span class="riviantrackr-widget-stat-value"><?php echo esc_html( $success_rate ); ?>%</span>
                    <span class="riviantrackr-widget-stat-label">Success Rate</span>
                </div>
                <div class="riviantrackr-widget-stat">
                    <span class="riviantrackr-widget-stat-value"><?php echo number_format( $last_24 ); ?></span>
                    <span class="riviantrackr-widget-stat-label">Last 24 Hours</span>
                </div>
                <div class="riviantrackr-widget-stat">
                    <span class="riviantrackr-widget-stat-value"><?php
                        if ( $widget_avg_rt !== null ) {
                            if ( $widget_avg_rt >= 1000 ) {
                                echo esc_html( number_format( $widget_avg_rt / 1000, 1 ) . 's' );
                            } else {
                                echo esc_html( $widget_avg_rt . 'ms' );
                            }
                        } else {
                            echo '&mdash;';
                        }
                    ?></span>
                    <span class="riviantrackr-widget-stat-label">Avg Response</span>
                </div>
            </div>

            <div class="riviantrackr-widget-section">
                <h4 class="riviantrackr-widget-section-title">Top Search Queries</h4>
                
                <?php if ( ! empty( $top_queries ) ) : ?>
                    <table class="riviantrackr-widget-table">
                        <thead>
                            <tr>
                                <th>Query</th>
                                <th style="text-align: center; width: 60px;">Count</th>
                                <th style="text-align: center; width: 80px;">Success</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $top_queries as $row ) : ?>
                                <?php
                                $total_q = (int) $row->total;
                                $success_q = (int) $row->success_count;
                                $success_q_rate = $this->calculate_success_rate( $success_q, $total_q );
                                
                                // Determine badge class
                                if ( $success_q_rate >= RIVIANTRACKR_BADGE_SUCCESS_HIGH ) {
                                    $badge_class = 'riviantrackr-widget-badge-success';
                                } elseif ( $success_q_rate >= RIVIANTRACKR_BADGE_SUCCESS_MED ) {
                                    $badge_class = 'riviantrackr-widget-badge-warning';
                                } else {
                                    $badge_class = 'riviantrackr-widget-badge-error';
                                }
                                ?>
                                <tr>
                                    <td class="riviantrackr-widget-query">
                                        <?php
                                        $query_display = mb_strlen( $row->search_query ) > 35
                                            ? mb_substr( $row->search_query, 0, 32 ) . '...'
                                            : $row->search_query;
                                        echo esc_html( $query_display );
                                        ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="riviantrackr-widget-count"><?php echo number_format( $total_q ); ?></span>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="riviantrackr-widget-badge <?php echo esc_attr( $badge_class ); ?>">
                                            <?php echo esc_html( $success_q_rate ); ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <div class="riviantrackr-widget-empty">
                        No search data yet. Waiting for visitors to use AI search.
                    </div>
                <?php endif; ?>
            </div>

            <div class="riviantrackr-widget-footer">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=riviantrackr-analytics' ) ); ?>"
                   class="riviantrackr-widget-link">
                    View Full Analytics →
                </a>
            </div>
        </div>
        <?php
    }

    public function enqueue_frontend_assets() {
        if ( is_admin() || ! is_search() ) {
            return;
        }

        $options = $this->get_options();
        if ( empty( $options['enable'] ) || empty( $options['api_key'] ) ) {
            return;
        }

        // Add preconnect hint for REST API (helps with subdomains or CDN setups)
        $rest_url = rest_url();
        $parsed = wp_parse_url( $rest_url );
        if ( ! empty( $parsed['host'] ) ) {
            $origin = ( ! empty( $parsed['scheme'] ) ? $parsed['scheme'] : 'https' ) . '://' . $parsed['host'];
            echo '<link rel="preconnect" href="' . esc_url( $origin ) . '" crossorigin>' . "\n";
            echo '<link rel="dns-prefetch" href="' . esc_url( $origin ) . '">' . "\n";
        }

        $version = RIVIANTRACKR_VERSION;

        wp_enqueue_style(
            'riviantrackr',
            plugin_dir_url( __FILE__ ) . 'assets/riviantrackr.css',
            array(),
            $version
        );

        // Add dynamic color styles
        $color_css = $this->generate_color_css( $options );
        if ( ! empty( $color_css ) ) {
            wp_add_inline_style( 'riviantrackr', $color_css );
        }

        if ( ! empty( $options['custom_css'] ) ) {
            // Defense in depth: sanitize again on output
            $custom_css = $this->sanitize_custom_css( $options['custom_css'] );
            wp_add_inline_style( 'riviantrackr', $custom_css );
        }

        wp_enqueue_script(
            'riviantrackr',
            plugin_dir_url( __FILE__ ) . 'assets/riviantrackr.js',
            array(),
            $version,
            true
        );

        // Generate a JS challenge token: an HMAC of a timestamp + nonce salt
        // that the frontend must echo back.  Bots that skip JS execution
        // will not have the token and are rejected in the permission check.
        $bot_challenge_ts    = time();
        $bot_challenge_token = hash_hmac( 'sha256', (string) $bot_challenge_ts, wp_salt( 'nonce' ) );

        wp_localize_script(
            'riviantrackr',
            'RivianTrackrAI',
            array(
                'endpoint'         => rest_url( 'riviantrackr/v1/summary' ),
                'feedbackEndpoint' => rest_url( 'riviantrackr/v1/feedback' ),
                'logEndpoint'      => rest_url( 'riviantrackr/v1/log-session-hit' ),
                'nonce'            => wp_create_nonce( 'wp_rest' ),
                'query'            => get_search_query(),
                'cacheVersion'     => $this->get_cache_namespace(),
                'requestTimeout'   => isset( $options['request_timeout'] ) ? (int) $options['request_timeout'] : 60,
                'botToken'         => $bot_challenge_token,
                'botTokenTs'       => $bot_challenge_ts,
                'errorCodes'       => array(
                    'noResults'    => RIVIANTRACKR_ERROR_NO_RESULTS,
                    'apiError'     => RIVIANTRACKR_ERROR_API_ERROR,
                    'rateLimited'  => RIVIANTRACKR_ERROR_RATE_LIMITED,
                    'notConfigured' => RIVIANTRACKR_ERROR_NOT_CONFIGURED,
                ),
            )
        );
    }

    /**
     * Log searches that return no results server-side, since the widget
     * is not rendered and JS never fires the REST endpoint.
     */
    public function log_no_results_search() {
        if ( ! is_search() || is_admin() ) {
            return;
        }

        global $wp_query;
        if ( $wp_query->found_posts > 0 ) {
            return;
        }

        $search_query = get_search_query();
        if ( empty( $search_query ) ) {
            return;
        }

        $this->log_search_event( $search_query, 0, 0, 'No matching articles found', null );
    }

    public function inject_ai_summary_placeholder( $query ) {
        if ( ! $query->is_main_query() || ! $query->is_search() || is_admin() ) {
            return;
        }

        if ( $this->summary_injected ) {
            return;
        }

        $this->render_summary_widget();
    }

    /**
     * Render the AI summary widget HTML.
     */
    private function render_summary_widget() {
        $options = $this->get_options();
        if ( empty( $options['enable'] ) || empty( $options['api_key'] ) ) {
            return;
        }

        $paged = get_query_var( 'paged' ) ? get_query_var( 'paged' ) : 1;
        if ( $paged > 1 ) {
            return;
        }

        $this->summary_injected = true;

        $search_query = get_search_query();
        $site_name = ! empty( $options['site_name'] ) ? $options['site_name'] : get_bloginfo( 'name' );
        $show_badge = isset( $options['show_openai_badge'] ) ? $options['show_openai_badge'] : 0;
        $show_feedback = isset( $options['show_feedback'] ) ? $options['show_feedback'] : 0;
        ?>
        <div class="riviantrackr-summary" style="margin-bottom: 1.5rem;">
            <div class="riviantrackr-summary-inner" style="padding: 1.25rem 1.25rem; border-radius: 10px; border-width: 1px; border-style: solid; display:flex; flex-direction:column; gap:0.6rem;">
                <div class="riviantrackr-summary-header" style="display:flex; align-items:center; justify-content:space-between; gap:0.75rem;">
                    <h2 style="margin:0; font-size:1.1rem;">
                        AI summary for "<?php echo esc_html( $search_query ); ?>"
                    </h2>
                    <?php if ( $show_badge ) : ?>
                    <span class="riviantrackr-openai-badge" aria-label="Powered by OpenAI">
                        <span class="riviantrackr-openai-mark" aria-hidden="true"></span>
                        <span class="riviantrackr-openai-text">Powered by OpenAI</span>
                    </span>
                    <?php endif; ?>
                </div>

                <div id="riviantrackr-search-summary-content" class="riviantrackr-search-summary-content" aria-live="polite">
                    <span class="riviantrackr-spinner" role="status" aria-label="Loading AI summary"></span>
                    <p class="riviantrackr-loading-text">Generating summary based on your search and <?php echo esc_html( $site_name ); ?> articles...</p>
                </div>

                <?php if ( $show_feedback ) : ?>
                <div id="riviantrackr-feedback" class="riviantrackr-feedback" style="display:none; margin-top:0.75rem; padding-top:0.75rem; border-top:1px solid rgba(128,128,128,0.3);">
                    <div class="riviantrackr-feedback-prompt" style="display:flex; align-items:center; gap:0.75rem; flex-wrap:wrap;">
                        <span style="font-size:0.85rem;">Was this summary helpful?</span>
                        <div class="riviantrackr-feedback-buttons" style="display:flex; gap:0.5rem;">
                            <button type="button" class="riviantrackr-feedback-btn" data-helpful="1" aria-label="Yes, helpful" style="padding:0.25rem 0.75rem; border:1px solid currentColor; border-radius:4px; background:transparent; color:inherit; cursor:pointer; font-size:0.85rem;">
                                &#128077; Yes
                            </button>
                            <button type="button" class="riviantrackr-feedback-btn" data-helpful="0" aria-label="No, not helpful" style="padding:0.25rem 0.75rem; border:1px solid currentColor; border-radius:4px; background:transparent; color:inherit; cursor:pointer; font-size:0.85rem;">
                                &#128078; No
                            </button>
                        </div>
                    </div>
                    <div class="riviantrackr-feedback-thanks" style="display:none; font-size:0.85rem;">
                        Thanks for your feedback!
                    </div>
                </div>
                <?php endif; ?>

                <div class="riviantrackr-disclaimer" style="margin-top:0.75rem; font-size:0.75rem; line-height:1.4; opacity:0.65;">
                    AI summaries are generated automatically based on <?php echo esc_html( $site_name ); ?> articles and may be inaccurate or incomplete. Always verify important details.
                </div>
            </div>
        </div>
        <?php
    }

    public function enqueue_admin_assets( $hook ) {
        $allowed_hooks = array(
            'toplevel_page_riviantrackr-settings',
            'riviantrackr-settings_page_riviantrackr-analytics',
        );

        $is_our_page = in_array( $hook, $allowed_hooks, true ) ||
                       strpos( $hook, 'riviantrackr' ) !== false;

        if ( ! $is_our_page ) {
            return;
        }

        $version = RIVIANTRACKR_VERSION;

        wp_enqueue_style(
            'riviantrackr-admin',
            plugin_dir_url( __FILE__ ) . 'assets/riviantrackr-admin.css',
            array(),
            $version
        );

        wp_enqueue_script(
            'riviantrackr-admin',
            plugin_dir_url( __FILE__ ) . 'assets/riviantrackr-admin.js',
            array( 'jquery' ),
            $version,
            true
        );

        // Pass security nonces and dynamic values to admin JS.
        wp_localize_script(
            'riviantrackr-admin',
            'RivianTrackrAdmin',
            array(
                'bulkDeleteNonce'    => wp_create_nonce( 'riviantrackr_bulk_delete_logs' ),
                'testKeyNonce'       => wp_create_nonce( 'riviantrackr_test_key' ),
                'gdprPurgeNonce'     => wp_create_nonce( 'riviantrackr_gdpr_purge' ),
                'useApiKeyConstant'  => $this->is_api_key_from_constant(),
            )
        );
    }

    private function is_likely_bot(): bool {
        // No user agent = definitely suspicious
        if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) || empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
            return true;
        }

        $user_agent = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) );

        // Very short user agents are suspicious (real browsers have long UA strings)
        if ( strlen( $user_agent ) < 20 ) {
            return true;
        }

        // Common bot patterns in user agent
        $bot_patterns = array(
            'bot', 'crawl', 'spider', 'slurp', 'scanner',
            'scraper', 'curl', 'wget', 'python', 'java/',
            'libwww', 'httpunit', 'nutch', 'phpcrawl',
            'msnbot', 'adidxbot', 'blekkobot', 'teoma',
            'gigabot', 'dotbot', 'yandex', 'seokicks',
            'ahrefsbot', 'semrushbot', 'mj12bot', 'baiduspider',
            'headless', 'phantom', 'selenium', 'puppeteer',
            'playwright', 'webdriver', 'httpclient', 'okhttp',
            'go-http-client', 'apache-httpclient', 'node-fetch',
            'axios', 'request/', 'postman', 'insomnia',
        );

        foreach ( $bot_patterns as $pattern ) {
            if ( strpos( $user_agent, $pattern ) !== false ) {
                return true;
            }
        }

        // Check for missing standard browser headers
        // Real browsers always send Accept-Language
        if ( empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
            return true;
        }

        // Real browsers send Accept header with text/html or */*
        if ( empty( $_SERVER['HTTP_ACCEPT'] ) ) {
            return true;
        }

        // Check for headless browser indicators
        // Headless Chrome often has specific patterns
        if ( strpos( $user_agent, 'headlesschrome' ) !== false ) {
            return true;
        }

        // Check if user agent claims to be a browser but lacks typical browser headers
        $claims_browser = (
            strpos( $user_agent, 'mozilla' ) !== false ||
            strpos( $user_agent, 'chrome' ) !== false ||
            strpos( $user_agent, 'safari' ) !== false ||
            strpos( $user_agent, 'firefox' ) !== false ||
            strpos( $user_agent, 'edge' ) !== false
        );

        if ( $claims_browser ) {
            // Browsers should have Accept-Encoding header
            if ( empty( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an IP is rate limited and track the request.
     *
     * Uses a single transient per IP with timestamp tracking for efficiency.
     * This avoids transient bloat from multiple window-based keys per IP.
     *
     * @param string $ip Client IP address.
     * @return bool True if rate limited.
     */
    private function is_ip_rate_limited( string $ip ): bool {
        $ip_hash  = hash( 'sha256', $ip );
        $key      = 'riviantrackr_ip_rate_' . substr( $ip_hash, 0, 32 );
        $lock_key = $key . '_lock';
        $limit    = RIVIANTRACKR_IP_RATE_LIMIT;
        $now      = time();
        $cutoff   = $now - 60;

        // Acquire a short-lived lock to prevent race conditions between
        // concurrent requests from the same IP.  The lock is stored as a
        // transient with a 5-second TTL so it self-clears even if something
        // goes wrong.
        $lock_attempts = 0;
        while ( get_transient( $lock_key ) && $lock_attempts < 5 ) {
            usleep( 50000 ); // 50 ms
            $lock_attempts++;
        }
        set_transient( $lock_key, 1, 5 );

        $timestamps = get_transient( $key );
        if ( ! is_array( $timestamps ) ) {
            $timestamps = array();
        }

        // Prune timestamps older than the 60-second window
        $timestamps = array_values( array_filter( $timestamps, function ( $ts ) use ( $cutoff ) {
            return $ts > $cutoff;
        } ) );

        if ( count( $timestamps ) >= $limit ) {
            // Over the limit — release lock and reject
            delete_transient( $lock_key );
            return true;
        }

        // Under the limit — record this request
        $timestamps[] = $now;
        set_transient( $key, $timestamps, RIVIANTRACKR_RATE_LIMIT_WINDOW );
        delete_transient( $lock_key );

        return false;
    }

    /**
     * Get rate limit information for an IP.
     *
     * Uses timestamp-based sliding window for accurate rate limiting without
     * creating multiple transients per IP.
     *
     * @param string $ip Client IP address.
     * @return array Rate limit info with 'limit', 'remaining', 'used', and 'reset' keys.
     */
    private function get_rate_limit_info( $ip ) {
        $limit   = RIVIANTRACKR_IP_RATE_LIMIT;
        $ip_hash = hash( 'sha256', $ip );
        $key     = 'riviantrackr_ip_rate_' . substr( $ip_hash, 0, 32 );

        $timestamps = get_transient( $key );
        if ( ! is_array( $timestamps ) ) {
            $timestamps = array();
        }

        // Filter to timestamps within the last 60 seconds
        $now    = time();
        $cutoff = $now - 60;
        $recent = array_filter( $timestamps, function ( $ts ) use ( $cutoff ) {
            return $ts > $cutoff;
        } );

        $used = count( $recent );

        // Calculate reset time based on oldest timestamp in window
        $reset = $now + 60;
        if ( ! empty( $recent ) ) {
            $oldest = min( $recent );
            $reset  = $oldest + 60;
        }

        return array(
            'limit'     => $limit,
            'remaining' => max( 0, $limit - $used ),
            'used'      => $used,
            'reset'     => $reset,
        );
    }

    /**
     * Lightweight rate limiter for logging and feedback endpoints.
     *
     * Uses a separate, higher-threshold counter so analytics logging
     * cannot be abused to flood the database while not consuming the
     * user's main summary rate-limit quota.
     *
     * @param string $ip Client IP address.
     * @return bool True if the IP has exceeded the log rate limit.
     */
    private function is_log_rate_limited( string $ip ): bool {
        $ip_hash = hash( 'sha256', $ip );
        $key     = 'riviantrackr_log_rate_' . substr( $ip_hash, 0, 32 );
        $limit   = RIVIANTRACKR_IP_LOG_RATE_LIMIT;
        $now     = time();
        $cutoff  = $now - 60;

        $timestamps = get_transient( $key );
        if ( ! is_array( $timestamps ) ) {
            $timestamps = array();
        }

        $timestamps = array_values( array_filter( $timestamps, function ( $ts ) use ( $cutoff ) {
            return $ts > $cutoff;
        } ) );

        if ( count( $timestamps ) >= $limit ) {
            return true;
        }

        $timestamps[] = $now;
        set_transient( $key, $timestamps, RIVIANTRACKR_RATE_LIMIT_WINDOW );

        return false;
    }

    private function get_client_ip(): string {
        // Use REMOTE_ADDR by default - it's the only non-spoofable source.
        // Sites behind trusted reverse proxies can define RIVIANTRACKR_TRUSTED_PROXY_HEADER
        // to read from X-Forwarded-For or similar headers.
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';

        // Allow sites behind trusted proxies to use forwarded headers
        if ( defined( 'RIVIANTRACKR_TRUSTED_PROXY_HEADER' ) && RIVIANTRACKR_TRUSTED_PROXY_HEADER ) {
            $header = 'HTTP_' . strtoupper( str_replace( '-', '_', RIVIANTRACKR_TRUSTED_PROXY_HEADER ) );
            if ( ! empty( $_SERVER[ $header ] ) ) {
                // Take the first IP in the list (original client)
                $ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
                $forwarded_ip = trim( $ips[0] );
                if ( filter_var( $forwarded_ip, FILTER_VALIDATE_IP ) ) {
                    $ip = $forwarded_ip;
                }
            }
        }

        // Validate IP
        if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return $ip;
        }

        return 'unknown';
    }

    public function register_rest_routes() {
        register_rest_route(
            'riviantrackr/v1',
            '/summary',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_get_summary' ),
                'permission_callback' => array( $this, 'rest_permission_check' ),
                'args'                => array(
                    'q' => array(
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => array( $this, 'validate_search_query' ),
                    ),
                    'bt' => array(
                        'required'          => false,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                    'bts' => array(
                        'required'          => false,
                        'sanitize_callback' => 'absint',
                    ),
                ),
            )
        );

        // Lightweight endpoint for logging frontend (session) cache hits.
        // Uses a dedicated permission check that skips IP rate limiting so
        // analytics logging does not consume the user's rate-limit quota.
        register_rest_route(
            'riviantrackr/v1',
            '/log-session-hit',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_log_session_cache_hit' ),
                'permission_callback' => array( $this, 'rest_log_permission_check' ),
                'args'                => array(
                    'q' => array(
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => array( $this, 'validate_search_query' ),
                    ),
                    'results_count' => array(
                        'required'          => false,
                        'sanitize_callback' => 'absint',
                        'default'           => 0,
                    ),
                ),
            )
        );

        // Feedback endpoint for thumbs up/down
        register_rest_route(
            'riviantrackr/v1',
            '/feedback',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_submit_feedback' ),
                'permission_callback' => array( $this, 'rest_feedback_permission_check' ),
                'args'                => array(
                    'q' => array(
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => array( $this, 'validate_search_query' ),
                    ),
                    'helpful' => array(
                        'required'          => true,
                        'validate_callback' => function( $value ) {
                            return in_array( $value, array( 0, 1, '0', '1', true, false ), true );
                        },
                    ),
                ),
            )
        );
    }

    /**
     * Handle feedback submission from frontend.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response object.
     */
    public function rest_submit_feedback( $request ) {
        $search_query = $request->get_param( 'q' );
        $helpful      = (bool) $request->get_param( 'helpful' );
        $ip           = $this->get_client_ip();

        $result = $this->record_feedback( $search_query, $helpful, $ip );

        if ( 'duplicate' === $result ) {
            return rest_ensure_response( array(
                'success' => false,
                'message' => 'You have already submitted feedback for this search.',
            ) );
        }

        if ( false === $result ) {
            return rest_ensure_response( array(
                'success' => false,
                'message' => 'Failed to record feedback.',
            ) );
        }

        return rest_ensure_response( array(
            'success' => true,
            'message' => 'Thank you for your feedback!',
        ) );
    }

    /**
     * Log a frontend session cache hit to analytics.
     * This is a lightweight endpoint that only logs - no AI processing.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response object.
     */
    public function rest_log_session_cache_hit( $request ) {
        $search_query  = $request->get_param( 'q' );
        $results_count = $request->get_param( 'results_count' );

        if ( empty( $search_query ) ) {
            return rest_ensure_response( array( 'logged' => false ) );
        }

        // Log as a session cache hit (cache_hit = 2 to distinguish from server cache hits)
        // We use 2 to indicate "session/browser cache hit" vs 1 for "server cache hit"
        $this->log_search_event( $search_query, $results_count, 1, '', 2 );

        return rest_ensure_response( array( 'logged' => true ) );
    }

    /**
     * Add rate limit headers to REST API responses.
     *
     * @param WP_REST_Response $response Response object.
     * @param WP_REST_Server   $server   Server instance.
     * @param WP_REST_Request  $request  Request object.
     * @return WP_REST_Response Modified response.
     */
    public function add_rate_limit_headers( $response, $server, $request ) {
        // Only add headers to our plugin's endpoints
        $route = $request->get_route();
        if ( strpos( $route, '/riviantrackr/' ) === false ) {
            return $response;
        }

        $client_ip  = $this->get_client_ip();
        $rate_info  = $this->get_rate_limit_info( $client_ip );

        $response->header( 'X-RateLimit-Limit', $rate_info['limit'] );
        $response->header( 'X-RateLimit-Remaining', max( 0, $rate_info['remaining'] - 1 ) );
        $response->header( 'X-RateLimit-Reset', $rate_info['reset'] );

        return $response;
    }

    /**
     * Permission callback for REST API endpoint.
     *
     * @param WP_REST_Request $request Request object.
     * @return bool|WP_Error True if allowed, WP_Error if blocked.
     */
    public function rest_permission_check( WP_REST_Request $request ) {
        // Block obvious bots to save API costs
        if ( $this->is_likely_bot() ) {
            return new WP_Error(
                RIVIANTRACKR_ERROR_BOT_DETECTED,
                'AI search is not available for automated requests.',
                array( 'status' => 403 )
            );
        }

        // JS challenge token verification — bots that skip JS execution
        // will not have the token generated in enqueue_frontend_assets().
        // Token is valid for 10 minutes to allow for slow page loads.
        $bt  = $request->get_param( 'bt' );
        $bts = $request->get_param( 'bts' );
        if ( $bt && $bts ) {
            $expected = hash_hmac( 'sha256', (string) $bts, wp_salt( 'nonce' ) );
            $age      = time() - (int) $bts;
            if ( ! hash_equals( $expected, $bt ) || $age < 0 || $age > 600 ) {
                return new WP_Error(
                    RIVIANTRACKR_ERROR_BOT_DETECTED,
                    'Invalid challenge token. Please refresh the page.',
                    array( 'status' => 403 )
                );
            }
        }

        // Per-IP rate limiting (more aggressive than global limit)
        $client_ip = $this->get_client_ip();
        if ( $this->is_ip_rate_limited( $client_ip ) ) {
            $rate_info = $this->get_rate_limit_info( $client_ip );
            return new WP_Error(
                RIVIANTRACKR_ERROR_RATE_LIMITED,
                'Too many requests from your IP address. Please try again in a minute.',
                array(
                    'status'     => 429,
                    'retry_after' => max( 1, $rate_info['reset'] - time() ),
                )
            );
        }

        return true;
    }

    /**
     * Lightweight permission check for the log-session-hit endpoint.
     *
     * Only performs bot detection — intentionally skips IP rate limiting so
     * that analytics logging does not consume the user's rate-limit quota.
     *
     * @param WP_REST_Request $request Request object.
     * @return bool|WP_Error True if allowed, WP_Error if blocked.
     */
    public function rest_log_permission_check( WP_REST_Request $request ) {
        if ( $this->is_likely_bot() ) {
            return new WP_Error(
                RIVIANTRACKR_ERROR_BOT_DETECTED,
                'Logging is not available for automated requests.',
                array( 'status' => 403 )
            );
        }

        // Lightweight rate limit to prevent database flooding
        if ( $this->is_log_rate_limited( $this->get_client_ip() ) ) {
            return new WP_Error(
                RIVIANTRACKR_ERROR_RATE_LIMITED,
                'Too many logging requests. Please slow down.',
                array( 'status' => 429 )
            );
        }

        return true;
    }

    /**
     * Permission check for feedback endpoint with CSRF protection.
     *
     * @param WP_REST_Request $request Request object.
     * @return bool|WP_Error True if permitted, WP_Error otherwise.
     */
    public function rest_feedback_permission_check( WP_REST_Request $request ) {
        // Verify nonce for CSRF protection
        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_Error(
                'rest_cookie_invalid_nonce',
                'Invalid or missing security token. Please refresh the page and try again.',
                array( 'status' => 403 )
            );
        }

        // Block obvious bots
        if ( $this->is_likely_bot() ) {
            return new WP_Error(
                RIVIANTRACKR_ERROR_BOT_DETECTED,
                'Feedback is not available for automated requests.',
                array( 'status' => 403 )
            );
        }

        // Lightweight rate limit to prevent feedback spam
        if ( $this->is_log_rate_limited( $this->get_client_ip() ) ) {
            return new WP_Error(
                RIVIANTRACKR_ERROR_RATE_LIMITED,
                'Too many requests. Please slow down.',
                array( 'status' => 429 )
            );
        }

        return true;
    }

    /**
     * Validate search query parameter.
     *
     * @param mixed           $value   Query value.
     * @param WP_REST_Request $request Request object.
     * @param string          $param   Parameter name.
     * @return bool True if valid.
     */
    public function validate_search_query( $value, $request, $param ) {
        // Query must be a string
        if ( ! is_string( $value ) ) {
            return false;
        }

        // Must not be empty after trimming
        if ( empty( trim( $value ) ) ) {
            return false;
        }

        // Reasonable length limits (prevent abuse)
        // Use mb_strlen for proper multi-byte character support
        $length = function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
        if ( $length < RIVIANTRACKR_QUERY_MIN_LENGTH || $length > RIVIANTRACKR_QUERY_MAX_LENGTH ) {
            return false;
        }

        // Also check byte length to prevent oversized payloads
        if ( strlen( $value ) > RIVIANTRACKR_QUERY_MAX_BYTES ) {
            return false;
        }

        // Block SQL injection attempts
        if ( $this->is_sql_injection_attempt( $value ) ) {
            return false;
        }

        // Block spam queries (URLs, emails, phone numbers, known spam, blocklist)
        if ( $this->is_spam_query( $value ) ) {
            return false;
        }

        return true;
    }

    /**
     * Detect SQL injection patterns in input.
     *
     * @param string $value Input value to check.
     * @return bool True if SQL injection pattern detected.
     */
    private function is_sql_injection_attempt( string $value ): bool {
        // Normalize: lowercase and decode URL encoding
        $normalized = strtolower( urldecode( $value ) );

        // Remove SQL comment obfuscation (/**/)
        $normalized = preg_replace( '/\/\*.*?\*\//', ' ', $normalized );

        // Normalize whitespace (including encoded whitespace chars)
        $normalized = preg_replace( '/[\s\x00-\x1f]+/', ' ', $normalized );

        // SQL injection patterns to block
        $sql_patterns = array(
            // SQL keywords with operators
            'select.*from',
            'union.*select',
            'insert.*into',
            'delete.*from',
            'update.*set',
            'drop.*table',
            'create.*table',
            'alter.*table',
            'exec.*\(',
            'execute.*\(',

            // SQL functions commonly used in injection
            'concat\s*\(',
            'char\s*\(',
            'chr\s*\(',
            'substring\s*\(',
            'ascii\s*\(',
            'hex\s*\(',
            'unhex\s*\(',
            'load_file\s*\(',
            'outfile',
            'dumpfile',
            'benchmark\s*\(',
            'sleep\s*\(',
            'waitfor.*delay',

            // Oracle-specific (common in automated scanners)
            'ctxsys\.',
            'drithsx',
            'from\s+dual',
            'dbms_',
            'utl_',

            // SQL Server specific
            'xp_cmdshell',
            'sp_executesql',
            'information_schema',
            'sysobjects',
            'syscolumns',

            // Boolean-based injection patterns
            '\band\b.*=.*\bcase\b',
            '\bor\b.*=.*\bcase\b',
            'when.*then.*else.*end',

            // Comment-based injection
            '--\s*$',
            '#\s*$',

            // Stacked queries
            ';\s*select',
            ';\s*insert',
            ';\s*update',
            ';\s*delete',
            ';\s*drop',
        );

        foreach ( $sql_patterns as $pattern ) {
            if ( preg_match( '/' . $pattern . '/i', $normalized ) ) {
                // Log the attempt for security monitoring
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log( '[RivianTrackr AI Search Summary] Blocked SQL injection attempt: ' . substr( $value, 0, 100 ) );
                }
                return true;
            }
        }

        // Check for excessive special characters (sign of injection attempts)
        $special_char_count = preg_match_all( '/[\'"\(\)\|\=\;\%]/', $value );
        if ( $special_char_count > 10 ) {
            return true;
        }

        return false;
    }

    /**
     * Detect spam patterns in search queries.
     *
     * Blocks queries containing URLs, email addresses, phone numbers,
     * excessive repetition, and known spam keywords. Also checks the
     * admin-configurable blocklist.
     *
     * @param string $value Input value to check.
     * @return bool True if spam pattern detected.
     */
    private function is_spam_query( string $value ): bool {
        $normalized = strtolower( trim( $value ) );

        // 1. URLs (http/https/www)
        if ( preg_match( '#https?://|www\.#i', $normalized ) ) {
            return true;
        }

        // 2. Email addresses
        if ( preg_match( '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $normalized ) ) {
            return true;
        }

        // 3. Phone numbers (7+ consecutive digits, optionally separated by dashes/spaces/dots)
        $digits_only = preg_replace( '/[\s\-\.\(\)]+/', '', $normalized );
        if ( preg_match( '/\d{7,}/', $digits_only ) ) {
            return true;
        }

        // 4. Excessive character repetition (e.g., "aaaaaa", "123123123")
        if ( preg_match( '/(.)\1{5,}/', $normalized ) ) {
            return true;
        }
        // Repeated word/phrase patterns (e.g., "buy buy buy buy")
        if ( preg_match( '/\b(\w+)\b(?:\s+\1\b){3,}/i', $normalized ) ) {
            return true;
        }

        // 5. Common spam keywords/phrases
        $spam_patterns = array(
            'buy cheap',
            'order now',
            'free shipping',
            'click here',
            'act now',
            'limited time offer',
            'viagra',
            'cialis',
            'casino',
            'poker online',
            'slot machine',
            'payday loan',
            'earn money fast',
            'work from home',
            'make money online',
            'weight loss',
            'diet pill',
            'enlargement',
            'nigerian prince',
            'cryptocurrency investment',
            'binary option',
            'forex trading',
            'seo service',
            'backlink',
            'guest post service',
            'telegram',
            'whatsapp.*group',
            'join.*channel',
        );

        foreach ( $spam_patterns as $pattern ) {
            if ( preg_match( '/' . $pattern . '/i', $normalized ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log( '[RivianTrackr AI Search Summary] Blocked spam query (pattern: ' . $pattern . '): ' . substr( $value, 0, 100 ) );
                }
                return true;
            }
        }

        // 6. Server variable / vulnerability scanner probes
        // Bots send CGI environment variable names to test if the site echoes them back.
        $scanner_probes = array(
            'QUERY_STRING',
            'DOCUMENT_ROOT',
            'SERVER_NAME',
            'SERVER_ADDR',
            'REMOTE_ADDR',
            'REMOTE_HOST',
            'HTTP_HOST',
            'HTTP_USER_AGENT',
            'HTTP_REFERER',
            'HTTP_ACCEPT',
            'PATH_INFO',
            'SCRIPT_FILENAME',
            'SCRIPT_NAME',
            'PHP_SELF',
            'REQUEST_URI',
            'REQUEST_METHOD',
            'CONTENT_TYPE',
            'CONTENT_LENGTH',
            'SERVER_SOFTWARE',
            'SERVER_PROTOCOL',
            'GATEWAY_INTERFACE',
            'SERVER_PORT',
            'PATH_TRANSLATED',
            'AUTH_TYPE',
        );
        foreach ( $scanner_probes as $probe ) {
            if ( stripos( $normalized, strtolower( $probe ) ) !== false ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log( '[RivianTrackr AI Search Summary] Blocked scanner probe query (variable: ' . $probe . '): ' . substr( $value, 0, 100 ) );
                }
                return true;
            }
        }

        // 7. High ratio of non-alphanumeric characters (gibberish)
        $alpha_count = preg_match_all( '/[a-z0-9]/i', $value );
        $total_len   = max( 1, strlen( $value ) );
        if ( $total_len > 10 && ( $alpha_count / $total_len ) < 0.5 ) {
            return true;
        }

        // 8. Admin-configurable blocklist
        $options   = $this->get_options();
        $blocklist = isset( $options['spam_blocklist'] ) ? $options['spam_blocklist'] : '';
        if ( ! empty( $blocklist ) ) {
            $blocked_terms = array_filter( array_map( 'trim', explode( "\n", strtolower( $blocklist ) ) ) );
            foreach ( $blocked_terms as $term ) {
                if ( empty( $term ) ) {
                    continue;
                }
                if ( strpos( $normalized, $term ) !== false ) {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                        error_log( '[RivianTrackr AI Search Summary] Blocked query via blocklist (term: ' . $term . '): ' . substr( $value, 0, 100 ) );
                    }
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Intelligently truncate text at sentence boundaries.
     *
     * Attempts to cut at the last complete sentence within the limit.
     * Falls back to word boundary if no sentence ending is found.
     *
     * @param string $text Text to truncate.
     * @param int    $limit Maximum length in characters.
     * @return string Truncated text.
     */

    private function safe_substr( string $text, int $start, int $length ): string {
        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $text, $start, $length );
        }
        return substr( $text, $start, $length );
    }
    
    private function smart_truncate( string $text, int $limit ): string {
        if ( empty( $text ) ) {
            return '';
        }

        // Use safe_substr for multibyte support
        if ( $this->safe_substr( $text, 0, $limit ) === $text ) {
            // Text is already shorter than limit
            return $text;
        }

        // Get text up to limit
        $truncated = $this->safe_substr( $text, 0, $limit );

        // Try to find last sentence ending (., !, ?)
        $sentence_endings = array( '. ', '! ', '? ', '."', '!"', '?"', ".'", "!'", "?'" );
        $last_sentence_pos = 0;

        foreach ( $sentence_endings as $ending ) {
            $pos = strrpos( $truncated, $ending );
            if ( $pos !== false && $pos > $last_sentence_pos ) {
                $last_sentence_pos = $pos + strlen( $ending );
            }
        }

        // If we found a sentence ending and it's not too early (at least 50% of limit)
        if ( $last_sentence_pos > 0 && $last_sentence_pos >= ( $limit * 0.5 ) ) {
            return trim( $this->safe_substr( $truncated, 0, $last_sentence_pos ) );
        }

        // Fall back to word boundary
        $last_space = strrpos( $truncated, ' ' );
        if ( $last_space !== false && $last_space >= ( $limit * 0.7 ) ) {
            return trim( $this->safe_substr( $truncated, 0, $last_space ) ) . '...';
        }

        // Last resort: hard cut with ellipsis
        return $truncated . '...';
    }

    // Updated rest_get_summary() to use smart truncation
    public function rest_get_summary( WP_REST_Request $request ) {
        $options      = $this->get_options();
        $search_query = $request->get_param( 'q' );

        if ( empty( $options['enable'] ) || empty( $options['api_key'] ) ) {
            $this->log_search_event( sanitize_text_field( (string) $search_query ), 0, 0, 'AI search not enabled or API key missing' );

            return rest_ensure_response(
                array(
                    'answer_html' => '',
                    'error'       => 'AI search is not enabled.',
                    'error_code'  => RIVIANTRACKR_ERROR_NOT_CONFIGURED,
                )
            );
        }

        if ( ! $search_query ) {
            return rest_ensure_response(
                array(
                    'answer_html' => '',
                    'error'       => 'Missing search query.',
                    'error_code'  => RIVIANTRACKR_ERROR_INVALID_QUERY,
                )
            );
        }

        $max_posts = (int) $options['max_posts'];
        if ( $max_posts < 1 ) {
            $max_posts = 20;
        }

        $post_types = isset( $options['post_types'] ) && is_array( $options['post_types'] ) && ! empty( $options['post_types'] )
            ? $options['post_types']
            : 'any';

        // Single optimized query that gets all posts sorted by relevance and recency
        $search_args = array(
            's'              => $search_query,
            'post_type'      => $post_types,
            'posts_per_page' => $max_posts,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        $search_results = new WP_Query( $search_args );

        $posts_for_ai = array();

        if ( $search_results->have_posts() ) {
            foreach ( $search_results->posts as $post ) {
                $content = wp_strip_all_tags( $post->post_content );
                
                // Use smart truncation for better sentence boundaries
                $content_length    = isset( $options['content_length'] ) ? (int) $options['content_length'] : RIVIANTRACKR_CONTENT_LENGTH;
                $truncated_content = $this->smart_truncate( $content, $content_length );
                $excerpt = $this->smart_truncate( $content, RIVIANTRACKR_EXCERPT_LENGTH );

                $posts_for_ai[] = array(
                    'id'      => $post->ID,
                    'title'   => get_the_title( $post ),
                    'url'     => get_permalink( $post ),
                    'excerpt' => $excerpt,
                    'content' => $truncated_content,
                    'type'    => $post->post_type,
                    'date'    => get_the_date( 'Y-m-d', $post ),
                );
            }
        }

        $results_count = count( $posts_for_ai );

        // Short-circuit when no articles match — log the search and skip the API call.
        if ( 0 === $results_count ) {
            $site_name = ! empty( $options['site_name'] ) ? $options['site_name'] : get_bloginfo( 'name' );

            $this->log_search_event( $search_query, 0, 0, 'No matching posts found' );

            return rest_ensure_response(
                array(
                    'answer_html'   => '',
                    'results_count' => 0,
                    'error'         => 'No articles on ' . $site_name . ' matched your search. Try different keywords or a broader search term.',
                    'error_code'    => RIVIANTRACKR_ERROR_NO_RESULTS,
                )
            );
        }

        $ai_error      = '';
        $cache_hit     = null;
        $start_time    = microtime( true );
        $ai_data       = $this->get_ai_data_for_search( $search_query, $posts_for_ai, $ai_error, $cache_hit );
        $response_time_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );

        if ( ! $ai_data ) {
            $this->log_search_event( $search_query, $results_count, 0, $ai_error ? $ai_error : 'AI summary not available', $cache_hit, $response_time_ms );

            return rest_ensure_response(
                array(
                    'answer_html' => '',
                    'error'       => $ai_error ? $ai_error : 'AI summary is not available right now.',
                    'error_code'  => RIVIANTRACKR_ERROR_API_ERROR,
                )
            );
        }

        $this->log_search_event( $search_query, $results_count, 1, '', $cache_hit, $response_time_ms );

        $answer_html = isset( $ai_data['answer_html'] ) ? (string) $ai_data['answer_html'] : '';
        $sources     = isset( $ai_data['results'] ) && is_array( $ai_data['results'] ) ? $ai_data['results'] : array();

        $allowed_tags = array(
            'p'  => array(),
            'br' => array(),
            'strong' => array(),
            'em' => array(),
            'ul' => array(),
            'ol' => array(),
            'li' => array(),
            'h3' => array(),
            'h4' => array(),
            'a'  => array(
                'href'   => array(),
                'title'  => array(),
                'target' => array(),
                'rel'    => array(),
            ),
        );

        $answer_html = wp_kses( $answer_html, $allowed_tags );

        // Add sources if enabled in settings
        $show_sources = isset( $options['show_sources'] ) ? $options['show_sources'] : 0;
        if ( $show_sources && ! empty( $sources ) ) {
            $answer_html .= $this->render_sources_html( $sources );
        }

        return rest_ensure_response(
            array(
                'answer_html'   => $answer_html,
                'results_count' => $results_count,
                'error'         => '',
            )
        );
    }

    private function is_rate_limited_for_ai_calls(): bool {
        $options = $this->get_options();
        $limit   = isset( $options['max_calls_per_minute'] ) ? (int) $options['max_calls_per_minute'] : 0;

        if ( $limit <= 0 ) {
            return false;
        }

        $key   = 'riviantrackr_rate_' . gmdate( 'YmdHi' );
        $count = (int) get_transient( $key );

        if ( $count >= $limit ) {
            return true;
        }

        $count++;
        set_transient( $key, $count, RIVIANTRACKR_RATE_LIMIT_WINDOW );

        return false;
    }

    private function get_ai_data_for_search( $search_query, $posts_for_ai, &$ai_error = '', &$cache_hit = null ) {
        $options = $this->get_options();
        if ( empty( $options['api_key'] ) || empty( $options['enable'] ) ) {
            $ai_error = 'AI search is not configured. Please contact the site administrator.';
            $cache_hit = null; // Not applicable - config error
            return null;
        }

        $normalized_query = strtolower( trim( $search_query ) );
        $namespace        = $this->get_cache_namespace();

        $content_length = isset( $options['content_length'] ) ? (int) $options['content_length'] : RIVIANTRACKR_CONTENT_LENGTH;
        $cache_key_data = implode( '|', array(
            $options['model'],
            $options['max_posts'],
            $content_length,
            $normalized_query
        ) );

        $cache_key        = $this->cache_prefix . 'ns' . $namespace . '_' . hash( 'sha256', $cache_key_data );
        $cached_raw       = get_transient( $cache_key );

        if ( $cached_raw ) {
            $ai_data = json_decode( $cached_raw, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $ai_data ) ) {
                $cache_hit = true;
                return $ai_data;
            }

            // Corrupted cache entry — remove it so subsequent requests don't
            // repeatedly attempt to decode the same bad data.
            delete_transient( $cache_key );
        }

        // Cache miss - will make API call
        $cache_hit = false;

        if ( $this->is_rate_limited_for_ai_calls() ) {
            $ai_error = 'Too many AI requests right now. Please try again in a minute.';
            return null;
        }

        $api_response = $this->call_openai_for_search(
            $options['api_key'],
            $options['model'],
            $search_query,
            $posts_for_ai
        );

        if ( isset( $api_response['error'] ) ) {
            // Log detailed error for debugging, but show generic message to users
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( '[RivianTrackr AI Search Summary] API error: ' . $api_response['error'] );
            }
            $ai_error = 'The AI service encountered an error. Please try again later.';
            return null;
        }

        // Check for model refusal (newer models)
        if ( ! empty( $api_response['choices'][0]['message']['refusal'] ) ) {
            $ai_error = 'The AI model declined to answer this query.';
            return null;
        }

        // Get content - check multiple possible locations
        $raw_content = null;
        if ( ! empty( $api_response['choices'][0]['message']['content'] ) ) {
            $raw_content = $api_response['choices'][0]['message']['content'];
        } elseif ( ! empty( $api_response['choices'][0]['text'] ) ) {
            // Legacy completion format
            $raw_content = $api_response['choices'][0]['text'];
        } elseif ( ! empty( $api_response['output'] ) ) {
            // Some newer models use 'output' field
            $raw_content = is_array( $api_response['output'] )
                ? wp_json_encode( $api_response['output'] )
                : $api_response['output'];
        }

        if ( empty( $raw_content ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( '[RivianTrackr AI Search Summary] Empty response. Full API response: ' . wp_json_encode( $api_response ) );
            }
            // Check if there's a finish_reason that explains the empty response
            $finish_reason = $api_response['choices'][0]['finish_reason'] ?? 'unknown';
            // Log detailed reason for debugging
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( '[RivianTrackr AI Search Summary] Empty response with finish_reason: ' . $finish_reason );
            }
            if ( $finish_reason === 'content_filter' ) {
                $ai_error = 'The response was filtered by content policy. Please try a different search.';
            } elseif ( $finish_reason === 'length' ) {
                $ai_error = 'The response was truncated. Please try a simpler search.';
            } else {
                $ai_error = 'AI summary is not available for this search. Please try again.';
            }
            return null;
        }

        if ( is_array( $raw_content ) ) {
            $decoded = $raw_content;
        } else {
            $decoded = json_decode( $raw_content, true );

            if ( json_last_error() !== JSON_ERROR_NONE ) {
                $first = strpos( $raw_content, '{' );
                $last  = strrpos( $raw_content, '}' );
                if ( $first !== false && $last !== false && $last > $first ) {
                    $json_candidate = substr( $raw_content, $first, $last - $first + 1 );
                    $decoded        = json_decode( $json_candidate, true );
                }
            }
        }

        if ( ! is_array( $decoded ) ) {
            $ai_error = 'Could not parse AI response. The service may be experiencing issues.';
            return null;
        }

        if ( isset( $decoded['answer_html'] ) && is_string( $decoded['answer_html'] ) ) {
            $inner = trim( $decoded['answer_html'] );
            if ( strlen( $inner ) > 0 && $inner[0] === '{' && strpos( $inner, '"answer_html"' ) !== false ) {
                $inner_decoded = json_decode( $inner, true );
                if ( json_last_error() === JSON_ERROR_NONE && is_array( $inner_decoded ) && isset( $inner_decoded['answer_html'] ) ) {
                    $decoded = $inner_decoded;
                }
            }
        }

        if ( empty( $decoded['answer_html'] ) ) {
            $decoded['answer_html'] = '<p>AI summary did not return a valid answer.</p>';
        }

        if ( empty( $decoded['results'] ) || ! is_array( $decoded['results'] ) ) {
            $decoded['results'] = array();
        }

        $ttl_option = isset( $options['cache_ttl'] ) ? (int) $options['cache_ttl'] : 0;
        $ttl        = $ttl_option > 0 ? $ttl_option : $this->cache_ttl;

        set_transient( $cache_key, wp_json_encode( $decoded ), $ttl );

        return $decoded;
    }

    /**
     * Call the OpenAI API with retry logic for transient errors.
     * Returns the decoded API response on success, or an array with 'error' key on failure.
     * Includes '_retry_count' metadata when retries were needed.
     */
    private function call_openai_for_search( $api_key, $model, $user_query, $posts ) {
        if ( empty( $api_key ) ) {
            return array( 'error' => 'API key is missing. Please configure the plugin settings.' );
        }

        $endpoint = 'https://api.openai.com/v1/chat/completions';

        $posts_text = '';
        foreach ( $posts as $p ) {
            $date = isset( $p['date'] ) ? $p['date'] : '';
            $posts_text .= "ID: {$p['id']}\n";
            $posts_text .= "Title: {$p['title']}\n";
            $posts_text .= "URL: {$p['url']}\n";
            $posts_text .= "Type: {$p['type']}\n";
            if ( $date ) {
                $posts_text .= "Published: {$date}\n";
            }
            $posts_text .= "Content: {$p['content']}\n";
            $posts_text .= "-----\n";
        }

        // Get site configuration for the prompt
        $options = $this->get_options();
        $site_name = ! empty( $options['site_name'] ) ? $options['site_name'] : get_bloginfo( 'name' );
        $site_desc = ! empty( $options['site_description'] ) ? ', ' . $options['site_description'] : '';

        $system_message = "You are the AI search engine for {$site_name}{$site_desc}.
    Use the provided posts as your entire knowledge base.
    Answer the user query based only on these posts.
    Prefer newer posts over older ones when there is conflicting or overlapping information, especially for news, software updates, or product changes.
    If something is not covered, say that the site does not have that information yet instead of making something up.

    IMPORTANT: This is a one-way search interface - users cannot reply or provide clarification. Never ask follow-up questions, never ask the user to clarify, and never suggest they tell you more. Instead, provide the most comprehensive answer possible covering all likely interpretations of their query. If a query is ambiguous, briefly cover the most relevant possibilities.

    Always respond as a single JSON object using this structure:
    {
      \"answer_html\": \"HTML formatted summary answer for the user\",
      \"results\": [
         {
           \"id\": 123,
           \"title\": \"Post title\",
           \"url\": \"https://...\",
           \"excerpt\": \"Short snippet\",
           \"type\": \"post or page\"
         }
      ]
    }

    The results array should list up to 5 of the most relevant posts you used when creating the summary, so they can be shown as sources under the answer.";

        $user_message  = "User search query: {$user_query}\n\n";
        $user_message .= "Here are the posts from the site (with newer posts listed first where possible):\n\n{$posts_text}";

        // Determine model capabilities
        $is_reasoning = self::is_reasoning_model( $model );

        // GPT-4o and GPT-4.1 support json_object response format
        // Reasoning models may have different requirements
        $supports_response_format = (
            strpos( $model, 'gpt-4o' ) === 0 ||
            strpos( $model, 'gpt-4.1' ) === 0
        );

        $body = array(
            'model'    => $model,
            'messages' => array(
                array(
                    'role'    => 'system',
                    'content' => $system_message,
                ),
                array(
                    'role'    => 'user',
                    'content' => $user_message,
                ),
            ),
        );

        // Use the admin-configured max tokens setting
        $configured_tokens = isset( $options['max_tokens'] ) ? (int) $options['max_tokens'] : RIVIANTRACKR_MAX_TOKENS;

        // Reasoning models use max_completion_tokens and need a higher limit
        // to leave room for hidden reasoning tokens
        if ( $is_reasoning ) {
            $body['max_completion_tokens'] = max( $configured_tokens, 16000 );
        } else {
            $body['max_tokens'] = $configured_tokens;
        }

        // Reasoning models don't support temperature
        if ( ! $is_reasoning ) {
            $body['temperature'] = 0.2;
        }

        if ( $supports_response_format ) {
            $body['response_format'] = array( 'type' => 'json_object' );
        }

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => isset( $options['request_timeout'] ) ? (int) $options['request_timeout'] : RIVIANTRACKR_API_TIMEOUT,
        );

        // Retry logic: attempt up to 3 times with exponential backoff for transient errors
        $max_retries = 2; // 2 retries = 3 total attempts
        $attempt = 0;
        $last_error = null;

        while ( $attempt <= $max_retries ) {
            $result = $this->make_openai_request( $endpoint, $args );

            // Success - return the decoded response with retry metadata
            if ( isset( $result['success'] ) && $result['success'] ) {
                $data = $result['data'];
                if ( $attempt > 0 ) {
                    $data['_retry_count'] = $attempt;
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                        error_log( '[RivianTrackr AI Search Summary] Request succeeded after ' . $attempt . ' retry(ies)' );
                    }
                }
                return $data;
            }

            // Check if error is retryable
            $is_retryable = isset( $result['retryable'] ) && $result['retryable'];
            $last_error = $result;

            if ( ! $is_retryable || $attempt >= $max_retries ) {
                // Non-retryable error or max retries reached
                break;
            }

            // Exponential backoff: 1s, 2s
            $delay = pow( 2, $attempt );
            sleep( $delay );

            $attempt++;

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( '[RivianTrackr AI Search Summary] Retry attempt ' . ( $attempt + 1 ) . ' after ' . $delay . 's delay' );
            }
        }

        // Return the last error with retry metadata
        $error_msg = $last_error['error'] ?? 'Unknown error occurred.';
        if ( $attempt > 0 ) {
            $error_msg .= ' (after ' . ( $attempt + 1 ) . ' attempts)';
        }
        return array( 'error' => $error_msg );
    }

    /**
     * Make the actual HTTP request to OpenAI.
     * Returns array with 'success', 'data'/'error', and 'retryable' flag.
     */
    private function make_openai_request( $endpoint, $args ) {
        $response = wp_safe_remote_post( $endpoint, $args );

        // Connection/network errors
        if ( is_wp_error( $response ) ) {
            $error_msg = $response->get_error_message();
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( '[RivianTrackr AI Search Summary] API request error: ' . $error_msg );
            }

            // Timeouts and connection errors are retryable
            $is_timeout = strpos( $error_msg, 'cURL error 28' ) !== false || strpos( $error_msg, 'timed out' ) !== false;
            $is_connection = strpos( $error_msg, 'cURL error 6' ) !== false || strpos( $error_msg, 'resolve host' ) !== false;

            if ( $is_timeout ) {
                return array(
                    'success'   => false,
                    'error'     => 'Request timed out. The AI service may be slow right now. Please try again.',
                    'retryable' => true,
                );
            }
            if ( $is_connection ) {
                return array(
                    'success'   => false,
                    'error'     => 'Could not connect to AI service. Please check your internet connection.',
                    'retryable' => true,
                );
            }

            // Generic message - detailed error already logged above
            return array(
                'success'   => false,
                'error'     => 'Could not connect to AI service. Please try again.',
                'retryable' => true, // Most connection errors are worth retrying
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        // HTTP errors
        if ( $code < 200 || $code >= 300 ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( '[RivianTrackr AI Search Summary] API HTTP error ' . $code . ' body: ' . $body );
            }

            $decoded_error = json_decode( $body, true );
            $api_error = isset( $decoded_error['error']['message'] ) ? $decoded_error['error']['message'] : null;

            // 429 Rate limit - retryable
            if ( $code === 429 ) {
                return array(
                    'success'   => false,
                    'error'     => 'OpenAI rate limit exceeded. Please try again in a few moments.',
                    'retryable' => true,
                );
            }

            // 5xx Server errors - retryable
            if ( $code >= 500 && $code < 600 ) {
                return array(
                    'success'   => false,
                    'error'     => 'OpenAI service temporarily unavailable. Please try again later.',
                    'retryable' => true,
                );
            }

            // 401 Invalid API key - NOT retryable
            if ( $code === 401 ) {
                return array(
                    'success'   => false,
                    'error'     => 'Invalid API key. Please check your plugin settings.',
                    'retryable' => false,
                );
            }

            // 400 Bad request - NOT retryable
            if ( $code === 400 ) {
                return array(
                    'success'   => false,
                    'error'     => 'The request could not be processed. Please try a different search.',
                    'retryable' => false,
                );
            }

            // Other errors - details already logged above
            return array(
                'success'   => false,
                'error'     => 'AI service error. Please try again later.',
                'retryable' => false,
            );
        }

        // Parse JSON response
        $decoded = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( '[RivianTrackr AI Search Summary] Failed to decode OpenAI response: ' . json_last_error_msg() );
            }
            return array(
                'success'   => false,
                'error'     => 'Could not understand AI response. Please try again.',
                'retryable' => true, // Malformed responses might be transient
            );
        }

        // Success
        return array(
            'success' => true,
            'data'    => $decoded,
        );
    }

    private function render_sources_html( array $sources ): string {
        if ( empty( $sources ) || ! is_array( $sources ) ) {
            return '';
        }

        $options     = $this->get_options();
        $max_sources = (int) $options['max_sources_display'];
        $sources     = array_slice( $sources, 0, $max_sources );
        $count   = count( $sources );

        $show_label = 'Show sources (' . intval( $count ) . ')';
        $hide_label = 'Hide sources';

        $html  = '<div class="riviantrackr-sources">';
        $html .= '<button type="button" class="riviantrackr-sources-toggle" aria-expanded="false" aria-controls="riviantrackr-sources-list" data-label-show="' . esc_attr( $show_label ) . '" data-label-hide="' . esc_attr( $hide_label ) . '">';
        $html .= esc_html( $show_label );
        $html .= '</button>';
        $html .= '<ul id="riviantrackr-sources-list" class="riviantrackr-sources-list" hidden>';

        foreach ( $sources as $src ) {
            $title   = isset( $src['title'] ) ? $src['title'] : '';
            $url     = isset( $src['url'] ) ? $src['url'] : '';
            $excerpt = isset( $src['excerpt'] ) ? $src['excerpt'] : '';

            if ( ! $title && ! $url ) {
                continue;
            }

            $html .= '<li>';

            if ( $url ) {
                $html .= '<a href="' . esc_url( $url ) . '">';
                $html .= $title ? esc_html( $title ) : esc_html( $url );
                $html .= '</a>';
            } else {
                $html .= esc_html( $title );
            }

            if ( $excerpt ) {
                $html .= '<span>' . esc_html( $excerpt ) . '</span>';
            }

            $html .= '</li>';
        }

        $html .= '</ul></div>';

        return $html;
    }

    /* ---------------------------------------------------------
     *  Trending Searches Widget & Shortcode
     * --------------------------------------------------------- */

    /**
     * Register the trending searches widget.
     */
    public function register_trending_widget() {
        register_widget( 'RivianTrackr_Trending_Widget' );
    }

    /**
     * Render the trending searches shortcode.
     *
     * Usage: [riviantrackr_trending limit="5" title="Trending Searches" time_period="24" time_unit="hours"]
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_trending_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'limit'       => 5,
            'title'       => '',
            'subtitle'    => '',
            'color'       => '',
            'font_color'  => '',
            'time_period' => 24,
            'time_unit'   => 'hours',
        ), $atts, 'riviantrackr_trending' );

        // Validate time_unit
        $time_unit = in_array( $atts['time_unit'], array( 'hours', 'days' ), true ) ? $atts['time_unit'] : 'hours';

        return $this->render_trending_searches( (int) $atts['limit'], $atts['title'], $atts['subtitle'], $atts['color'], $atts['font_color'], (int) $atts['time_period'], $time_unit );
    }

    /**
     * Get trending search keywords from a configurable time period.
     *
     * @param int $limit Number of keywords to return.
     * @param int $time_period Time period value (default 24).
     * @param string $time_unit Time unit: 'hours' or 'days' (default 'hours').
     * @return array Array of trending keywords with counts.
     */
    public function get_trending_keywords( $limit = 5, $time_period = 24, $time_unit = 'hours' ) {
        if ( ! $this->logs_table_is_available() ) {
            return array();
        }

        global $wpdb;
        $table_name = self::get_logs_table_name();

        // Calculate seconds based on time unit
        $seconds = ( 'days' === $time_unit ) ? $time_period * DAY_IN_SECONDS : $time_period * HOUR_IN_SECONDS;
        $since   = gmdate( 'Y-m-d H:i:s', time() - $seconds );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT search_query, COUNT(*) AS search_count
                 FROM %i
                 WHERE created_at >= %s
                   AND results_count > 0
                   AND ai_success = 1
                 GROUP BY search_query
                 ORDER BY search_count DESC
                 LIMIT %d',
                $table_name,
                $since,
                $limit
            )
        );

        return $results ? $results : array();
    }

    /**
     * Render trending searches HTML.
     *
     * @param int    $limit Number of keywords to show.
     * @param string $title Optional title.
     * @param string $subtitle Optional subtitle/description.
     * @param string $bg_color Optional background color (hex).
     * @param string $font_color Optional font color (hex).
     * @param int    $time_period Time period value (default 24).
     * @param string $time_unit Time unit: 'hours' or 'days' (default 'hours').
     * @return string HTML output.
     */
    public function render_trending_searches( $limit = 5, $title = '', $subtitle = '', $bg_color = '', $font_color = '', $time_period = 24, $time_unit = 'hours' ) {
        $keywords = $this->get_trending_keywords( $limit, $time_period, $time_unit );
        $options  = $this->get_options();

        // Use provided background color, fall back to accent color from settings
        if ( empty( $bg_color ) ) {
            $bg_color = isset( $options['color_accent'] ) ? $options['color_accent'] : '#fba919';
        }

        // Default font color
        if ( empty( $font_color ) ) {
            $font_color = '#1a1a1a';
        }

        if ( empty( $keywords ) ) {
            return '';
        }

        // Default title if not provided
        if ( empty( $title ) ) {
            $title = 'Trending Searches';
        }

        // Font Awesome icon (primary) and SVG fallback
        $icon_fa = '<i class="fa-solid fa-magnifying-glass riviantrackr-trending-fa-icon" style="font-size: 32px; opacity: 0.9; flex-shrink: 0; width: 48px; text-align: center; display: none;"></i>';
        $icon_svg = '<svg class="riviantrackr-trending-svg-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" style="width: 32px; height: 32px; opacity: 0.9;"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>';

        $html = '<div class="riviantrackr-trending-widget" style="
            background: ' . esc_attr( $bg_color ) . ';
            color: ' . esc_attr( $font_color ) . ';
            border-radius: 20px;
            padding: 24px;
            font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Oxygen, Ubuntu, sans-serif;
            box-sizing: border-box;
        ">';

        // Header with icon
        $html .= '<div class="riviantrackr-trending-header" style="
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
        ">';

        // Icon (Font Awesome with SVG fallback)
        $html .= '<div class="riviantrackr-trending-icon" style="
            flex-shrink: 0;
            width: 48px;
            text-align: center;
        ">' . $icon_fa . $icon_svg . '</div>';

        // Title and subtitle
        $html .= '<div class="riviantrackr-trending-header-text">';
        $html .= '<h3 class="riviantrackr-trending-title" style="
            margin: 0 0 4px 0;
            font-size: 20px;
            font-weight: 800;
            color: ' . esc_attr( $font_color ) . ';
            line-height: 1.2;
        ">' . esc_html( $title ) . '</h3>';

        if ( ! empty( $subtitle ) ) {
            $html .= '<p class="riviantrackr-trending-subtitle" style="
                margin: 0;
                font-size: 14px;
                font-weight: 400;
                color: ' . esc_attr( $font_color ) . ';
                opacity: 0.8;
                line-height: 1.4;
            ">' . esc_html( $subtitle ) . '</p>';
        }

        $html .= '</div></div>';

        // Keywords list
        $html .= '<ul class="riviantrackr-trending-list" style="
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
        ">';

        foreach ( $keywords as $keyword ) {
            $search_url = home_url( '/?s=' . urlencode( $keyword->search_query ) );

            $html .= '<li class="riviantrackr-trending-item">';
            $html .= '<a href="' . esc_url( $search_url ) . '" class="riviantrackr-trending-link" style="
                display: block;
                text-decoration: none;
                color: ' . esc_attr( $font_color ) . ';
                padding: 10px 14px;
                background: rgba(0, 0, 0, 0.08);
                border-radius: 12px;
                transition: background 0.15s ease;
                font-size: 15px;
                font-weight: 500;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            ">' . esc_html( $keyword->search_query ) . '</a></li>';
        }

        $html .= '</ul></div>';

        // Enqueue responsive styles via wp_add_inline_style
        $trending_css = '.riviantrackr-trending-widget { max-width: 100%; }
            .riviantrackr-trending-link:hover { background: rgba(0, 0, 0, 0.15) !important; }
            @media (max-width: 480px) {
                .riviantrackr-trending-widget { padding: 20px !important; border-radius: 16px !important; }
                .riviantrackr-trending-header { gap: 12px !important; margin-bottom: 16px !important; }
                .riviantrackr-trending-icon { width: 40px !important; }
                .riviantrackr-trending-icon svg { width: 28px !important; height: 28px !important; }
                .riviantrackr-trending-icon .riviantrackr-trending-fa-icon { font-size: 28px !important; width: 40px !important; }
                .riviantrackr-trending-title { font-size: 18px !important; }
                .riviantrackr-trending-subtitle { font-size: 13px !important; }
                .riviantrackr-trending-link { padding: 8px 12px !important; gap: 10px !important; }
                .riviantrackr-trending-query { font-size: 14px !important; }
            }';
        wp_register_style( 'riviantrackr-trending', false, array(), RIVIANTRACKR_VERSION );
        wp_enqueue_style( 'riviantrackr-trending' );
        wp_add_inline_style( 'riviantrackr-trending', $trending_css );

        // Enqueue Font Awesome detection script
        wp_enqueue_script(
            'riviantrackr-trending',
            plugin_dir_url( __FILE__ ) . 'assets/riviantrackr-trending.js',
            array(),
            RIVIANTRACKR_VERSION,
            true
        );

        return $html;
    }
}

/**
 * Trending Searches Widget Class.
 */
class RivianTrackr_Trending_Widget extends WP_Widget {

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(
            'riviantrackr_trending_widget',
            'RivianTrackr AI Search Summary - Trending Searches',
            array(
                'description' => 'Display trending search keywords from a configurable time period.',
                'classname'   => 'riviantrackr-trending-widget-container',
            )
        );
    }

    /**
     * Front-end display of the widget.
     *
     * @param array $args     Widget arguments.
     * @param array $instance Saved values from database.
     */
    public function widget( $args, $instance ) {
        $title       = ! empty( $instance['title'] ) ? $instance['title'] : '';
        $subtitle    = ! empty( $instance['subtitle'] ) ? $instance['subtitle'] : '';
        $limit       = ! empty( $instance['limit'] ) ? (int) $instance['limit'] : 5;
        $bg_color    = ! empty( $instance['bg_color'] ) ? $instance['bg_color'] : '';
        $font_color  = ! empty( $instance['font_color'] ) ? $instance['font_color'] : '';
        $time_period = ! empty( $instance['time_period'] ) ? (int) $instance['time_period'] : 24;
        $time_unit   = ! empty( $instance['time_unit'] ) ? $instance['time_unit'] : 'hours';

        // Get the main plugin instance
        global $riviantrackr_instance;
        if ( ! isset( $riviantrackr_instance ) ) {
            $riviantrackr_instance = new RivianTrackr_AI_Search_Summary();
        }

        $content = $riviantrackr_instance->render_trending_searches( $limit, $title, $subtitle, $bg_color, $font_color, $time_period, $time_unit );

        if ( empty( $content ) ) {
            return; // Don't show widget if no trending searches
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Widget args from WordPress core are pre-escaped
        echo $args['before_widget'];
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Content is built with esc_html/esc_attr in render_trending_searches
        echo $content;
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Widget args from WordPress core are pre-escaped
        echo $args['after_widget'];
    }

    /**
     * Back-end widget form.
     *
     * @param array $instance Previously saved values from database.
     */
    public function form( $instance ) {
        $title       = ! empty( $instance['title'] ) ? $instance['title'] : 'Trending Searches';
        $subtitle    = ! empty( $instance['subtitle'] ) ? $instance['subtitle'] : 'Popular searches in the last 24 hours';
        $limit       = ! empty( $instance['limit'] ) ? (int) $instance['limit'] : 5;
        $bg_color    = ! empty( $instance['bg_color'] ) ? $instance['bg_color'] : '#fba919';
        $font_color  = ! empty( $instance['font_color'] ) ? $instance['font_color'] : '#1a1a1a';
        $time_period = ! empty( $instance['time_period'] ) ? (int) $instance['time_period'] : 24;
        $time_unit   = ! empty( $instance['time_unit'] ) ? $instance['time_unit'] : 'hours';
        ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">Title:</label>
            <input class="widefat"
                   id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
                   type="text"
                   value="<?php echo esc_attr( $title ); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'subtitle' ) ); ?>">Subtitle:</label>
            <input class="widefat"
                   id="<?php echo esc_attr( $this->get_field_id( 'subtitle' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'subtitle' ) ); ?>"
                   type="text"
                   value="<?php echo esc_attr( $subtitle ); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>">Number of searches to show:</label>
            <input class="tiny-text"
                   id="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'limit' ) ); ?>"
                   type="number"
                   min="1"
                   max="20"
                   value="<?php echo esc_attr( $limit ); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'time_period' ) ); ?>">Time period:</label>
            <input class="tiny-text"
                   id="<?php echo esc_attr( $this->get_field_id( 'time_period' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'time_period' ) ); ?>"
                   type="number"
                   min="1"
                   max="365"
                   value="<?php echo esc_attr( $time_period ); ?>"
                   style="width: 60px;">
            <select id="<?php echo esc_attr( $this->get_field_id( 'time_unit' ) ); ?>"
                    name="<?php echo esc_attr( $this->get_field_name( 'time_unit' ) ); ?>">
                <option value="hours" <?php selected( $time_unit, 'hours' ); ?>>Hours</option>
                <option value="days" <?php selected( $time_unit, 'days' ); ?>>Days</option>
            </select>
            <br><small class="description">Show searches from the last X hours or days</small>
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'bg_color' ) ); ?>">Background Color:</label><br>
            <input id="<?php echo esc_attr( $this->get_field_id( 'bg_color' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'bg_color' ) ); ?>"
                   type="color"
                   value="<?php echo esc_attr( $bg_color ); ?>"
                   style="width: 50px; height: 30px; padding: 0; border: 1px solid #ccc; cursor: pointer;"
                   oninput="document.getElementById('<?php echo esc_attr( $this->get_field_id( 'bg_color_text' ) ); ?>').value = this.value;">
            <input type="text"
                   id="<?php echo esc_attr( $this->get_field_id( 'bg_color_text' ) ); ?>"
                   value="<?php echo esc_attr( $bg_color ); ?>"
                   style="width: 80px; margin-left: 8px;"
                   onchange="document.getElementById('<?php echo esc_attr( $this->get_field_id( 'bg_color' ) ); ?>').value = this.value;">
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'font_color' ) ); ?>">Font Color:</label><br>
            <input id="<?php echo esc_attr( $this->get_field_id( 'font_color' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'font_color' ) ); ?>"
                   type="color"
                   value="<?php echo esc_attr( $font_color ); ?>"
                   style="width: 50px; height: 30px; padding: 0; border: 1px solid #ccc; cursor: pointer;"
                   oninput="document.getElementById('<?php echo esc_attr( $this->get_field_id( 'font_color_text' ) ); ?>').value = this.value;">
            <input type="text"
                   id="<?php echo esc_attr( $this->get_field_id( 'font_color_text' ) ); ?>"
                   value="<?php echo esc_attr( $font_color ); ?>"
                   style="width: 80px; margin-left: 8px;"
                   onchange="document.getElementById('<?php echo esc_attr( $this->get_field_id( 'font_color' ) ); ?>').value = this.value;">
        </p>
        <p class="description">
            Shortcode: <code>[riviantrackr_trending limit="5" time_period="24" time_unit="hours"]</code>
        </p>
        <?php
    }

    /**
     * Sanitize widget form values as they are saved.
     *
     * @param array $new_instance Values just sent to be saved.
     * @param array $old_instance Previously saved values from database.
     * @return array Updated safe values to be saved.
     */
    public function update( $new_instance, $old_instance ) {
        $instance                = array();
        $instance['title']       = ! empty( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : '';
        $instance['subtitle']    = ! empty( $new_instance['subtitle'] ) ? sanitize_text_field( $new_instance['subtitle'] ) : '';
        $instance['limit']       = ! empty( $new_instance['limit'] ) ? min( 20, max( 1, (int) $new_instance['limit'] ) ) : 5;
        $instance['bg_color']    = ! empty( $new_instance['bg_color'] ) ? sanitize_hex_color( $new_instance['bg_color'] ) : '#fba919';
        $instance['font_color']  = ! empty( $new_instance['font_color'] ) ? sanitize_hex_color( $new_instance['font_color'] ) : '#1a1a1a';
        $instance['time_period'] = ! empty( $new_instance['time_period'] ) ? min( 365, max( 1, (int) $new_instance['time_period'] ) ) : 24;
        $instance['time_unit']   = ! empty( $new_instance['time_unit'] ) && in_array( $new_instance['time_unit'], array( 'hours', 'days' ), true ) ? $new_instance['time_unit'] : 'hours';

        return $instance;
    }
}

register_activation_hook( __FILE__, array( 'RivianTrackr_AI_Search_Summary', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RivianTrackr_AI_Search_Summary', 'deactivate' ) );

new RivianTrackr_AI_Search_Summary();
