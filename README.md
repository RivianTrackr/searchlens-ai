# AI Search Summary

[![Version](https://img.shields.io/badge/version-1.0.5.1-blue.svg)](https://github.com/RivianTrackr/AI-Search-Summary)
[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.4%2B-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-GPL--2.0%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

A powerful WordPress plugin that adds AI-powered summaries to your search results using OpenAI's GPT models. Enhance your site's search experience with intelligent, contextual summaries that help users find what they're looking for faster.

## Features

### Core AI Functionality

- **AI-Powered Search Summaries** - Generate intelligent summaries from matching posts using OpenAI's GPT models
- **Multiple Model Support** - Choose from GPT-4o, GPT-4, GPT-3.5-turbo, and reasoning models (o1, o3)
- **Non-Blocking Search** - AI summaries load asynchronously without delaying normal search results
- **Smart Content Processing** - Automatic text truncation and HTML stripping for optimal API usage
- **Collapsible Sources** - Display the articles used to generate the summary with expandable source list

### Performance & Caching

- **Multi-Tier Caching** - Server-side transient cache + browser session cache for optimal performance
- **Configurable Cache TTL** - Set cache duration from 1 minute to 24 hours
- **Cache Management** - Manual cache clear button and automatic namespace-based invalidation
- **Smart API Usage** - Automatically skips AI calls when no matching posts exist (saves costs)

### Analytics Dashboard

- **Comprehensive Analytics** - Track daily statistics, success rates, and cache performance
- **Top Search Queries** - See what users are searching for most
- **Error Tracking** - Monitor and analyze API errors
- **CSV Export** - Export logs, daily stats, and feedback data for external analysis
- **WordPress Dashboard Widget** - Quick stats overview right on your dashboard

### Rate Limiting & Security

- **IP-Based Rate Limiting** - Configurable requests per minute per IP address
- **Global AI Rate Limiting** - Control maximum AI calls per minute
- **Bot Detection** - Automatically skip AI processing for known bots
- **Security Headers** - X-Content-Type-Options, X-Frame-Options, Referrer-Policy, X-XSS-Protection
- **Secure API Key Storage** - Store API key via wp-config.php constant (recommended)

### Widgets & Shortcodes

- **Trending Searches Widget** - Display popular search terms in your sidebar
- **`[aiss_trending]` Shortcode** - Embed trending searches anywhere on your site
  - Configurable limit, title, subtitle, colors, and time period
  - Example: `[aiss_trending limit="5" title="Popular Searches" time_period="24" time_unit="hours"]`

### Customization

- **Color Theming** - Customize background, text, accent, and border colors
- **Custom CSS Editor** - Add your own styles with syntax highlighting
- **OpenAI Badge** - Optional "Powered by OpenAI" badge display
- **Feedback Buttons** - Optional thumbs up/down for user feedback on summaries
- **Sources Display** - Toggle visibility of source articles

### Data Management

- **Automatic Log Purging** - Configurable retention period (7-365 days) with scheduled cleanup
- **GDPR-Friendly** - No user identification stored, IP hashing for feedback
- **Database Optimization** - Efficient queries with proper indexing

## Requirements

- WordPress 6.9 or higher
- PHP 8.4 or higher
- MySQL 5.6 or higher
- OpenAI API account

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin in WordPress admin
3. Go to **AI Search → Settings** and add your OpenAI API key
4. Enable AI Search
5. Configure settings as needed

## Configuration

### API Key (Recommended: Secure Method)

Add to your `wp-config.php` for maximum security:

```php
define( 'AISS_API_KEY', 'sk-proj-your-api-key-here' );
```

**Benefits:**
- API key not stored in database (protected from SQL injection/database leaks)
- Key not visible in WordPress admin (protected from admin account compromise)
- Easier to manage across environments (staging/production)

**Using environment variables:**

```php
define( 'AISS_API_KEY', getenv('OPENAI_API_KEY') );
```

### Settings Overview

Navigate to **WP Admin → AI Search → Settings** to configure:

| Section | Options |
|---------|---------|
| **Getting Started** | Enable/Disable, API Key, API Key Validation |
| **Site Configuration** | Site Name, Site Description, Badge/Sources/Feedback visibility, Max Sources Displayed |
| **AI Configuration** | Model selection, Context Size, Content Length Per Post, Post Types, Max Response Tokens |
| **Performance** | Cache TTL, Manual cache clear, Request Timeout, Max Calls Per Minute |
| **Appearance** | Background, Text, Accent, Border colors, Custom CSS |
| **Advanced** | Allow reasoning models (o1, o3), Spam Blocklist, Preserve Data on Uninstall |
| **Log Management** | Auto-purge toggle, Retention days (7-365) |

## REST API Endpoints

The plugin provides REST API endpoints under `/wp-json/aiss/v1/`:

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/summary` | GET | Get AI summary for a search query (param: `q`) |
| `/log-session-hit` | POST | Log frontend session cache hits |
| `/feedback` | POST | Submit user feedback on summary quality |

## Shortcode Reference

### `[aiss_trending]`

Display trending search queries anywhere on your site.

**Attributes:**

| Attribute | Default | Description |
|-----------|---------|-------------|
| `limit` | 5 | Number of searches to show (1-20) |
| `title` | "Trending Searches" | Heading text |
| `subtitle` | "" | Subheading text |
| `color` | "" | Background color (hex) |
| `font_color` | "" | Text color (hex) |
| `time_period` | 24 | Time period value |
| `time_unit` | "hours" | "hours" or "days" |

**Example:**

```
[aiss_trending limit="10" title="What People Are Searching" time_period="7" time_unit="days" color="#f5f5f5"]
```

## Hooks & Filters

The plugin integrates with WordPress through standard hooks:

- `loop_start` - Injects AI summary placeholder
- `template_redirect` - Logs no-results searches
- `rest_api_init` - Registers REST API endpoints
- `widgets_init` - Registers trending widget
- `aiss_daily_log_purge` - Scheduled log cleanup

## Database Tables

The plugin creates two custom tables:

- `wp_aiss_logs` - Stores search events, AI responses, timing, and errors
- `wp_aiss_feedback` - Stores user feedback with IP hashing

## Documentation

- [Security Policy](SECURITY.md)
- [Contributing Guide](CONTRIBUTING.md)
- [Changelog](CHANGELOG.md)

## Support

- [Report Issues](https://github.com/RivianTrackr/aiss-ai-search-summary/issues)
- [Discussions](https://github.com/RivianTrackr/aiss-ai-search-summary/discussions)

## License

GPL v2 or later - see [LICENSE](LICENSE) for details.

---

Made with care for the WordPress community.
