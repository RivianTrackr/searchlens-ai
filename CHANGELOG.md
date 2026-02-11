# Changelog

All notable changes to AI Search Summary will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.4] - 2026-02-11

### Improved

- **Analytics: Top Queries pagination** - The "Top Search Queries" section now supports pagination instead of being hard-limited to 20 entries, allowing admins to browse the full list of unique queries on high-traffic sites.
- **Analytics: Top Errors pagination** - The "Top AI Errors" section now supports pagination instead of being hard-limited to 10 entries.
- **Analytics: Shared pagination component** - All analytics table pagination (recent events, top queries, top errors) now uses a single reusable method with consistent styling and cross-section state preservation.
- **Options cache: Hook-based invalidation** - The in-memory options cache is now automatically flushed via the `update_option_{$option}` WordPress hook, ensuring consistency even when options are updated outside of the settings sanitization flow.
- **JS error codes from PHP** - Error code constants (`AISS_ERROR_NO_RESULTS`, etc.) are now passed to the frontend via `wp_localize_script`, replacing the previously hardcoded string comparison in the JavaScript.
- **API retry diagnostics** - When API retries occur, the attempt count is now included in error messages logged to analytics and in debug log entries, helping admins diagnose intermittent API issues.

---

## [1.0.3] - 2026-02-10

### Fixed

- **Analytics: No-results searches now logged** - Searches that match zero posts are now recorded in analytics, giving admins complete visibility into what users are searching for.
- **Analytics: Premature logging removed** - Empty/missing queries are no longer logged to the analytics table; the not-configured state now sanitizes the query before logging.
- **CSV Export: Date range validation** - Exporting with a start date after the end date now shows a clear error instead of silently returning an empty file.
- **Cache: Content length included in cache key** - Changing the "Content Length Per Post" setting now correctly invalidates stale cached summaries instead of serving results generated with the old length.
- **Cache: Corrupted transients cleaned up** - If a cached transient contains invalid JSON, it is now deleted immediately rather than failing on every subsequent request until expiry.
- **Rate Limiting: retry_after header clamped** - The `Retry-After` value in 429 responses is now guaranteed to be at least 1 second, preventing invalid zero or negative values.
- **Rate Limiting: Session cache logging decoupled** - The `/log-session-hit` endpoint now uses a lightweight permission check (bot detection only) so browser cache hit logging no longer counts against the per-IP rate limit.
- **Settings: Auto-purge form preserves post types** - Saving the automatic purging settings no longer clears selected post types (array-valued options were dropped by the hidden-field loop).

### Changed

- Removed redundant `get_options()` calls in the summary REST endpoint for cleaner code flow.

---

## [1.0.1] - 2026-02-09

### Added

- **Post Type Filtering** - Choose which post types (posts, pages, custom types) are included in AI search results. When none are selected, all public post types are included (previous default behavior).
- **Max Sources Displayed** - Configure how many source articles appear beneath the AI summary (1–20, default 5). Previously hardcoded to 5.
- **Content Length Per Post** - Control how many characters of post content are sent to the AI per article (100–2,000, default 400). Allows tuning the balance between summary quality and API token cost.
- **Preserve Data on Uninstall** - Option in Advanced settings to keep all plugin data (settings, analytics logs, feedback) when the plugin is deleted, so data is retained if you reinstall later.

---

## [1.0.0] - 2026-02-08

### Added

#### Core Features
- AI-powered search summaries using OpenAI's GPT models (GPT-4o, GPT-4, GPT-3.5-turbo)
- Support for OpenAI reasoning models (o1, o3) with configurable toggle
- Non-blocking async loading - search results display immediately while AI summary loads
- Collapsible sources section showing articles used for summary generation
- Smart content truncation for optimal API usage

#### Admin Interface
- Comprehensive settings page with organized sections
- API key validation with test connection button
- Dynamic model selection populated from OpenAI API
- Custom CSS editor with syntax highlighting
- Color theming (background, text, accent, border colors)

#### Analytics & Monitoring
- Full analytics dashboard with daily statistics
- Success rate and cache performance tracking
- Top search queries ranking
- Error analysis and tracking
- CSV export for logs, daily stats, and feedback
- WordPress dashboard widget for quick stats overview

#### Performance & Caching
- Multi-tier caching system (server-side transients + browser session cache)
- Configurable cache TTL (1 minute to 24 hours)
- Namespace-based cache invalidation
- Manual cache clear functionality
- Smart API usage - skips calls when no matching posts exist

#### Rate Limiting & Security
- IP-based rate limiting (configurable requests per minute)
- Global AI call rate limiting
- Bot detection to prevent unnecessary API calls
- Security headers (X-Content-Type-Options, X-Frame-Options, Referrer-Policy, X-XSS-Protection)
- Secure API key storage via wp-config.php constant
- SQL injection prevention with prepared statements
- XSS prevention with proper output escaping
- Nonce verification for all admin actions

#### Widgets & Shortcodes
- Trending Searches sidebar widget with customizable appearance
- `[aiss_trending]` shortcode for embedding trending searches anywhere
- Configurable time periods, limits, colors, and titles

#### REST API
- `/wp-json/aiss/v1/summary` - Get AI summary for search queries
- `/wp-json/aiss/v1/log-session-hit` - Log frontend cache hits
- `/wp-json/aiss/v1/feedback` - Submit user feedback

#### Data Management
- Automatic log purging with configurable retention (7-365 days)
- Scheduled cleanup via WP-Cron
- GDPR-friendly design - no user identification stored
- IP hashing for feedback (not full IP storage)

#### User Experience
- Optional "Powered by OpenAI" badge
- Optional thumbs up/down feedback buttons
- Configurable sources display
- Responsive design
- Smooth loading animations

---

This is the first official release of AI Search Summary, consolidating all development work into a stable 1.0.0 version.
