=== AI Search Summary ===
Contributors: josecastillo
Tags: search, ai, openai, summary, chatgpt
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 8.4
Stable tag: 1.0.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add AI-powered summaries to your WordPress search results using OpenAI's GPT models. Enhance search with intelligent, contextual summaries.

== Description ==

AI Search Summary is a powerful WordPress plugin that adds AI-powered summaries to your search results using OpenAI's GPT models. Enhance your site's search experience with intelligent, contextual summaries that help users find what they're looking for faster.

= Core AI Functionality =

* **AI-Powered Search Summaries** - Generate intelligent summaries from matching posts using OpenAI's GPT models
* **Multiple Model Support** - Choose from GPT-4o, GPT-4, GPT-3.5-turbo, and reasoning models (o1, o3)
* **Non-Blocking Search** - AI summaries load asynchronously without delaying normal search results
* **Smart Content Processing** - Automatic text truncation and HTML stripping for optimal API usage
* **Collapsible Sources** - Display the articles used to generate the summary with expandable source list

= Performance & Caching =

* **Multi-Tier Caching** - Server-side transient cache + browser session cache for optimal performance
* **Configurable Cache TTL** - Set cache duration from 1 minute to 24 hours
* **Cache Management** - Manual cache clear button and automatic namespace-based invalidation
* **Smart API Usage** - Automatically skips AI calls when no matching posts exist (saves costs)

= Analytics Dashboard =

* **Comprehensive Analytics** - Track daily statistics, success rates, and cache performance
* **Top Search Queries** - See what users are searching for most
* **Error Tracking** - Monitor and analyze API errors
* **CSV Export** - Export logs, daily stats, and feedback data for external analysis
* **WordPress Dashboard Widget** - Quick stats overview right on your dashboard

= Rate Limiting & Security =

* **IP-Based Rate Limiting** - Configurable requests per minute per IP address
* **Global AI Rate Limiting** - Control maximum AI calls per minute
* **Bot Detection** - Automatically skip AI processing for known bots
* **Security Headers** - X-Content-Type-Options, X-Frame-Options, Referrer-Policy, X-XSS-Protection
* **Secure API Key Storage** - Store API key via wp-config.php constant (recommended)

= Widgets & Shortcodes =

* **Trending Searches Widget** - Display popular search terms in your sidebar
* **[aiss_trending] Shortcode** - Embed trending searches anywhere on your site

= Customization =

* **Color Theming** - Customize background, text, accent, and border colors
* **Custom CSS Editor** - Add your own styles
* **OpenAI Badge** - Optional "Powered by OpenAI" badge display
* **Feedback Buttons** - Optional thumbs up/down for user feedback on summaries

= Data Management =

* **Automatic Log Purging** - Configurable retention period (7-365 days) with scheduled cleanup
* **GDPR-Friendly** - No user identification stored, IP hashing for feedback
* **Database Optimization** - Efficient queries with proper indexing

= Third-Party Service =

This plugin connects to the OpenAI API to generate AI-powered summaries. When a user performs a search on your site, the search query and relevant post content are sent to OpenAI's servers for processing.

* **Service Provider:** OpenAI, L.L.C.
* **API Endpoint:** api.openai.com
* **Terms of Use:** [OpenAI Terms of Use](https://openai.com/policies/terms-of-use)
* **Privacy Policy:** [OpenAI Privacy Policy](https://openai.com/policies/privacy-policy)

Your use of this plugin constitutes acceptance of OpenAI's terms. No personal user data is sent to OpenAI - only the search query and excerpts from matching posts.

== Installation ==

1. Upload the `ai-search-summary` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **AI Search > Settings** and add your OpenAI API key
4. Click "Test Connection" to verify your API key works
5. Enable AI Search using the toggle
6. Configure additional settings as needed

= Secure API Key Configuration (Recommended) =

For maximum security, add your API key to `wp-config.php` instead of storing it in the database:

`define( 'AISS_API_KEY', 'sk-proj-your-api-key-here' );`

Benefits:
* API key not stored in database (protected from SQL injection/database leaks)
* Key not visible in WordPress admin (protected from admin account compromise)
* Easier to manage across environments (staging/production)

Using environment variables:

`define( 'AISS_API_KEY', getenv('OPENAI_API_KEY') );`

== Frequently Asked Questions ==

= Do I need an OpenAI API key? =

Yes, you need an OpenAI API account and API key to use this plugin. You can sign up at [OpenAI](https://platform.openai.com/).

= How much does the OpenAI API cost? =

OpenAI charges based on usage (tokens processed). Costs vary by model - GPT-3.5-turbo is the most affordable, while GPT-4 models cost more. The plugin includes caching to minimize API calls and reduce costs.

= Will this slow down my search results? =

No! The plugin loads AI summaries asynchronously. Your normal search results appear immediately, and the AI summary loads in the background without blocking the page.

= Is my data sent to OpenAI? =

When a search is performed, the search query and excerpts from matching posts are sent to OpenAI for processing. No personal user data (names, emails, IPs) is sent. See OpenAI's privacy policy for how they handle data.

= Can I customize the appearance? =

Yes! The settings page includes color pickers for background, text, accent, and border colors. You can also add custom CSS for further customization.

= How does caching work? =

The plugin uses a multi-tier caching system:
1. **Server-side cache** - Stores AI responses as WordPress transients
2. **Browser cache** - Stores responses in sessionStorage for instant repeat searches

Cache duration is configurable from 1 minute to 24 hours.

= What models are supported? =

The plugin supports all OpenAI GPT models including:
* GPT-4o (recommended for best quality/speed balance)
* GPT-4 and GPT-4 Turbo
* GPT-3.5 Turbo (most affordable)
* Reasoning models (o1, o3) - can be enabled in advanced settings

= How do I see analytics? =

Go to **AI Search > Analytics** in your WordPress admin to view:
* Daily search statistics
* Success rates and cache performance
* Top search queries
* Error analysis
* Export data as CSV

= Is this plugin GDPR compliant? =

The plugin is designed with privacy in mind:
* No user IDs or personal data stored in logs
* IP addresses are hashed (not stored in plain text) for feedback
* Automatic log purging available
* Note: Search queries are logged for analytics - configure retention as needed

== Screenshots ==

1. AI-powered search summary displayed above search results
2. Settings page - Getting Started section with API key configuration
3. Settings page - Appearance customization with color pickers
4. Analytics dashboard showing search statistics and trends
5. Trending Searches widget in sidebar
6. WordPress dashboard widget with quick stats

== Changelog ==

= 1.0.5 =
* Security: Custom CSS url() sanitizer now restricts data: URIs to specific image MIME types only
* Security: Rate limiting uses atomic transient locking to prevent race condition bypass
* Security: Feedback and logging endpoints now rate-limited (60 req/min) to prevent database flooding
* Security: Replaced all MD5 hashing with SHA-256 (cache keys, IP hashing, rate limit keys)
* Security: Added Content-Security-Policy header to plugin admin pages
* Security: API key automatically redacted from WP_DEBUG_LOG output via http_api_debug filter
* Security: JS challenge token (HMAC) added to summary requests for enhanced bot detection
* Security: Bulk delete nonce moved from HTML data-attribute to wp_localize_script
* Added: Anonymize Search Queries setting for GDPR/privacy compliance (stores SHA-256 hashes)
* Added: One-click GDPR purge to retroactively anonymize all stored search query text
* Improved: Advanced settings section now hidden by default with a toggle button and warning banner

= 1.0.4.1 =
* Added tablet responsive breakpoint (768px) and keyboard focus states for feedback buttons
* Sources toggle state now persists across page navigations via localStorage
* Progressive status messages shown during slow AI responses (10s, 20s, 30s)
* Full query text shown on hover for truncated queries in analytics tables
* Bulk delete action for selecting and removing multiple event log entries at once
* Badge threshold legend added to analytics page explaining color-coded indicators
* Extracted 18 magic numbers into named constants for maintainability
* Added PHP type hints to 27 core functions

= 1.0.4 =
* Added hook-based options cache invalidation for robust settings flushing across all update paths
* Added pagination to Top Search Queries and Top AI Errors analytics sections
* Refactored analytics pagination into a shared reusable method, reducing code duplication
* Error code constants are now passed to the JavaScript frontend for consistent error matching
* API retry logic now includes attempt count in error messages and debug logs for better diagnostics
* Cleaned up stale inline comments in the API retry code path

= 1.0.3 =
* Fixed no-results searches not being logged to analytics, giving admins complete search visibility
* Fixed premature logging of unconfigured state before query validation; empty queries no longer logged
* Fixed CSV export silently returning empty data when start date is after end date
* Fixed stale cached summaries being served after changing content length setting
* Fixed corrupted cache transients not being cleaned up, causing repeated decode failures
* Fixed rate-limit retry_after header potentially returning negative values
* Removed redundant options loading in the summary REST endpoint
* Session cache hit logging no longer counts against the per-IP rate limit
* Fixed saving auto-purge settings clearing selected post types

= 1.0.1 =
* Added post type filtering - choose which post types are included in AI search results
* Added configurable max sources displayed beneath summaries (1-20)
* Added configurable content length per post sent to the AI (100-2000 characters)
* Added option to preserve plugin data on uninstall for easy reinstallation

= 1.0.0 =
* Initial release
* AI-powered search summaries using OpenAI GPT models
* Support for GPT-4o, GPT-4, GPT-3.5-turbo, and reasoning models
* Non-blocking async loading for fast search results
* Multi-tier caching system (server + browser)
* Comprehensive analytics dashboard
* IP-based and global rate limiting
* Bot detection to prevent API abuse
* Trending Searches widget and shortcode
* Color theming and custom CSS support
* CSV export for logs and statistics
* GDPR-friendly data handling
* Security headers and prepared statements

== Upgrade Notice ==

= 1.0.5 =
Security hardening release: stricter CSS sanitization, atomic rate limiting, SHA-256 hashing, CSP headers, API key log redaction, JS bot challenge tokens, GDPR query anonymization, and nonce handling improvements.

= 1.0.4.1 =
Polish update: tablet responsive layout, sources toggle persistence, slow-response progress indicator, bulk log deletion, badge legend, named constants, and PHP type hints.

= 1.0.4 =
Improved analytics with paginated Top Queries and Top Errors sections, more robust options caching, and better API retry diagnostics.

= 1.0.3 =
Bug fixes for analytics logging, cache correctness, CSV export validation, rate limiting fairness, and settings persistence.

= 1.0.1 =
New settings: post type filtering, configurable max sources and content length per post, and an option to preserve data on uninstall.

= 1.0.0 =
Initial release of AI Search Summary. Requires WordPress 6.9+, PHP 8.4+, and an OpenAI API key.
