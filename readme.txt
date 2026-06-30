=== Redaquest Connector ===
Contributors: tomarco
Tags: redaquest, woocommerce, content marketing, sync, publishing, ai
Requires at least: 5.3
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.8.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Official Redaquest connector — link WordPress & WooCommerce to your content marketing workspace with OAuth.

== Description ==

**Redaquest Connector** is the official WordPress plugin from [Redaquest](https://redaquest.com), the AI-powered content marketing platform for teams in Central Europe and beyond.

Install the plugin, connect your site in one click, and let Redaquest read (and optionally publish) content on your WordPress site through a secure REST API — without copying API keys by hand.

= Who is this for? =

* **Marketing teams** using Redaquest for social posts, articles, and campaigns who need live data from their WordPress site
* **Agencies** managing multiple client websites from one Redaquest workspace
* **WooCommerce shops** that want product catalog sync for AI-assisted product descriptions and ads
* **Site owners** with custom post types (portfolio, references, services) who want them available in Redaquest

= What you can sync =

* **Posts and pages** — titles, content, excerpts, featured images, categories, tags
* **Public custom post types** — portfolio, references, services, and any other public CPT on your site
* **WooCommerce products** — when WooCommerce is active (title, description, price, categories, images)
* **Taxonomies** — categories, tags, and product categories
* **Custom fields** — ACF, Meta Box, Pods, and native WordPress meta (with sensitive fields filtered out)

= Key features =

* **OAuth connection** — sign in to Redaquest, pick a workspace, done. No manual API key copy-paste.
* **Granular sync controls** — choose which post types Redaquest can access; enable write access only when you need publishing back to WordPress.
* **WooCommerce toggle** — include or exclude products independently from posts and pages.
* **Rate-limited REST API** — protects your site from excessive automated requests (120 req/min).
* **Admin diagnostics** — connection test, REST checks, and a clear disconnect flow.
* **Security-first** — API keys stored locally; sensitive meta masked; SSRF protection for media imports.

= How it works =

1. Install and activate the plugin on your WordPress site.
2. Go to **Settings → Redaquest** and click **Connect to Redaquest**.
3. Sign in, authorize the connection, and select your workspace.
4. Choose post types (and optional write access), then run **Test connection**.
5. In Redaquest, your site content is available for planning, AI generation, and publishing workflows.

= External service =

This plugin requires a [Redaquest](https://redaquest.com) account (free trial available). When connected, the plugin communicates with Redaquest servers (`app.redaquest.com` and related API endpoints) to authenticate your site and enable synchronization.

By connecting, you agree to Redaquest's [Terms of Use](https://app.redaquest.com/legal/terms) and [Privacy Policy](https://app.redaquest.com/legal/privacy).

= Privacy =

The plugin stores an API key and connection metadata locally in your WordPress database. When connected, Redaquest accesses only the content types you explicitly enable. The plugin does **not** track site visitors or inject frontend analytics scripts.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/redaquest-connector`, or install the ZIP via **Plugins → Add New → Upload Plugin**.
2. Activate **Redaquest Connector**.
3. Open **Settings → Redaquest**.
4. Click **Connect to Redaquest**, sign in, and select your workspace.
5. Enable the post types you want to sync and save settings.
6. Click **Test connection** to verify the link works.

For manual setup (advanced): generate an API key in Redaquest under **Settings → Integrations → WordPress** and paste it on the Connection tab.

== Frequently Asked Questions ==

= Do I need a Redaquest account? =

Yes. This plugin connects your WordPress site to the Redaquest platform. Sign up at [redaquest.com](https://redaquest.com).

= Is WooCommerce required? =

No. The plugin works with standard WordPress. WooCommerce product sync is an optional feature when WooCommerce is installed and enabled in plugin settings.

= Which custom post types are supported? =

All **public** post types registered on your site. Select which ones to expose under **Settings → Redaquest → Synchronization**.

= Can Redaquest publish content back to my site? =

Yes. Enable **Write access** in plugin settings. Redaquest can then create or update posts, pages, and products via the REST API according to your workspace permissions.

= Can I use a manual API key instead of OAuth? =

OAuth is recommended. Advanced users can paste an API key from **Redaquest → Settings → Integrations → WordPress** if needed.

= Does the plugin slow down my site? =

No frontend scripts are added. The REST API is only called when Redaquest requests data from your workspace, with built-in rate limiting.

= What happens when I disconnect? =

The local API key is removed from WordPress. Redaquest can no longer access your site until you connect again.

= Is my data sent to third parties other than Redaquest? =

No. Content is transmitted only between your WordPress site and Redaquest servers when you have an active connection.

== Screenshots ==

1. Connect your WordPress site to Redaquest with secure OAuth — no API key copy-paste.
2. Choose which post types and WooCommerce products Redaquest can access.
3. Run connection diagnostics to verify REST API, tokens, and webhooks.

== Changelog ==

= 2.8.3 =
* WordPress.org directory assets: icon, banner, and screenshots
* Improved plugin readme and marketing descriptions

= 2.8.2 =
* Fix Terms and Privacy URLs in readme (app.redaquest.com/legal/*)

= 2.8.1 =
* WordPress.org readiness: English readme, Tested up to 7.0, coding standards fixes
* Use wp_delete_file(), wp_parse_url(), wp_strip_all_tags(), wp_date()

= 2.8.0 =
* OAuth connection via Redaquest (login + workspace selection)
* Setup checklist, connection test, disconnect, diagnostics tab
* WooCommerce sync toggle, custom fields checkbox, admin UX improvements

= 2.7.0 =
* Custom fields class with sensitive meta filtering
* REST API rate limiting (120 requests/minute)
* Full i18n (sk_SK, en_US), PHPUnit tests, PHPCS CI

== Upgrade Notice ==

= 2.8.3 =
Updated WordPress.org listing with new branding assets and improved descriptions. No code changes required.

= 2.8.2 =
Fix for WordPress.org review: corrected legal URLs in readme.

= 2.8.1 =
Maintenance release for WordPress.org compatibility. Recommended before publishing.
