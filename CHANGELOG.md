# Changelog

All notable changes to AI Search Summary will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.5.3] - 2026-02-16

### Changed

- **Credits are now opt-in** — The OpenAI badge, source links, and feedback buttons are now disabled by default. You must turn them on in **Settings → AI Search Summary** to display them, in line with WordPress plugin directory guidelines.

### Fixed

- **Direct file access protection** — Added security checks to plugin files (`ai-search-summary.php`, `index.php`, `uninstall.php`) so they cannot be loaded directly outside of WordPress.
- **Safer database queries** — The bulk-delete query now uses WordPress's built-in escaping for the table name instead of inserting it directly, eliminating a plugin-check warning.
- **Code-quality warnings** — Corrected the placement and scope of several code-analysis suppression rules so they cover the intended lines and resolve false-positive warnings.

---

## [1.0.5] - 2026-02-12

### Security

- **Custom CSS: Strict data URI MIME type filtering** - The `url()` sanitizer now only allows `data:image/(png|jpeg|gif|webp|svg+xml)` URIs. Previously `data:text/html` and `data:application/javascript` could bypass the filter, enabling potential XSS via the custom CSS field.
- **Rate limiting: Atomic locking** - The per-IP rate limiter now acquires a short-lived transient lock before read-modify-write, preventing concurrent requests from bypassing the limit via a race condition.
- **Rate limiting: Feedback/logging endpoints** - The `/log-session-hit` and `/feedback` REST endpoints now enforce a separate, higher-threshold rate limit (60/min) to prevent database flooding. Previously these had no rate limit.
- **Hashing: MD5 replaced with SHA-256** - All internal hashing (cache keys, IP rate-limit keys, feedback IP hashes) now uses `hash('sha256', …)` instead of `md5()`.
- **CSP header on admin pages** - Plugin admin pages now include a `Content-Security-Policy` header restricting scripts, styles, images, and connect sources to same-origin plus required OpenAI API domain.
- **API key redaction in debug logs** - A filter on `http_api_debug` automatically strips the `Authorization: Bearer` header from OpenAI request data before WordPress writes it to `WP_DEBUG_LOG`.
- **JS challenge token for bot detection** - The frontend now sends an HMAC-based challenge token (`bt`/`bts` parameters) with summary requests. Tokens are generated server-side in `enqueue_frontend_assets()` and validated in the REST permission check, blocking bots that skip JavaScript execution. Valid for 10 minutes.
- **Bulk delete nonce moved to `wp_localize_script`** - The AJAX nonce for bulk-deleting log entries is no longer embedded as an HTML `data-` attribute. It is now passed via `wp_localize_script` through the `AISSAdmin` JavaScript object, reducing DOM exposure of security tokens.

### Added

- **Privacy: Anonymize Search Queries setting** - New toggle in Advanced settings. When enabled, search queries are stored as SHA-256 hashes instead of plain text, preserving aggregate analytics while removing personally-identifiable search history.
- **Privacy: GDPR Purge Existing Queries** - One-click button to retroactively replace all stored search query text with SHA-256 hashes. Includes confirmation prompt and AJAX handler with full security checks.

### Improved

- **Settings: Collapsible Advanced section** - The Advanced settings section is now hidden by default behind a "Show Advanced Settings" toggle button. Expanding it reveals a warning banner advising caution, reducing the chance of accidental changes to sensitive options like reasoning models, spam blocklists, and data retention.

---

## [1.0.4.1] - 2026-02-11

### Improved

- **CSS: Tablet responsive breakpoint** - Added missing `768px` media query so the summary widget properly adapts for tablets in portrait mode (641-768px range was previously unstyled).
- **CSS: Keyboard focus states** - Feedback buttons now show a visible `focus-visible` outline for keyboard navigation, improving WCAG 2.1 AA compliance.
- **JS: Sources toggle persistence** - The expanded/collapsed state of the sources list is now remembered across page navigations via `localStorage`.
- **JS: Slow response progress indicator** - When the AI summary takes longer than 10 seconds, progressive status messages ("Still working...", "Taking a bit longer...", "Almost there...") are shown inside the skeleton loader with an ARIA live region for screen reader support.
- **Analytics: Query cell tooltips** - Truncated search queries in the Top Queries and Recent Events tables now show the full text on hover via `title` attribute.
- **Analytics: Bulk delete for event logs** - Admins can now select multiple log entries via checkboxes and delete them in bulk with a single click, with confirmation prompt and AJAX handling.
- **Analytics: Badge threshold legend** - A visual legend above the Daily Stats table now explains what the green/yellow/red badge colors mean for AI success, cache hit, and helpfulness rates.
- **Code: Named constants for magic numbers** - Extracted 18 hardcoded values (pagination sizes, validation limits, badge thresholds, table size threshold, CSS max length, error max length) into named `define()` constants for maintainability.
- **Code: PHP type hints** - Added parameter and return type declarations to 27 core functions, improving IDE support and leveraging the existing `declare(strict_types=1)`.

---

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
