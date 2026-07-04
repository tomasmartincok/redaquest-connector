=== Redaquest Connector ===
Contributors: redaquest
Tags: content sync, woocommerce, social media, scheduling
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 3.0.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync WordPress and WooCommerce content with RedaQuest and schedule social posts straight from the block editor.

== Description ==

Redaquest Connector links your WordPress site to [RedaQuest](https://redaquest.com), an AI content marketing and social media platform. It does two things:

**1. Content & product sync (API key).** RedaQuest reads your published content so you can repurpose it into social posts and plans:

* WordPress pages and posts
* Custom Post Types (portfolio, references, services, and more)
* WooCommerce products (when WooCommerce is active)
* Post and product categories
* Custom Fields from ACF, Meta Box, Pods, and native WordPress meta

It can also create and update posts on your site from RedaQuest when you enable writing.

**2. Social scheduling from the editor (Connect).** After you connect your site to a RedaQuest workspace, a panel appears in the block editor. From any article you can:

* Generate per-platform social copy from the article with AI
* Generate a brand image and set it as the featured image
* Pick the connected social accounts to post to
* Schedule the social posts to publish on the article's date, in one click or automatically when you publish the article

The connection uses a secure server-to-server token. No keys are typed into the browser, and you can disconnect at any time.

== External services ==

This plugin connects to RedaQuest to provide content sync and social scheduling. A RedaQuest account is required for these features.

What data is sent, and when:

* Content sync: when RedaQuest requests content through this plugin's REST API (authenticated with your API key), the selected posts, pages, and products are returned to RedaQuest.
* Connect: when you connect a site, the site URL and site name are sent to RedaQuest to issue a connection token that is stored on this site.
* Social scheduling: when you prepare a social post from an article, the article text and metadata (title, URL, excerpt), the chosen platforms and account IDs, the scheduled date, and optionally the featured image URL are sent to RedaQuest. RedaQuest generates the copy/image and schedules the posts through its publishing provider.

Endpoints used: the RedaQuest web app at https://app.redaquest.com (connection approval screen) and the RedaQuest API at https://*.supabase.co/functions/v1 (token exchange and the editor bridge).

Service provider: RedaQuest, https://redaquest.com
Terms of Use: https://redaquest.com/terms
Privacy Policy: https://redaquest.com/privacy

== Installation ==

1. Upload the plugin to `/wp-content/plugins/redaquest-connector` or install it from the Plugins screen.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Settings → Redaquest Connector**.
4. For content sync: paste the API key from your RedaQuest integration (RedaQuest app, Settings → Integrations).
5. For social scheduling: click **Connect RedaQuest** on the Connection tab and approve the connection in RedaQuest.

== Frequently Asked Questions ==

= Where do I get the API key? =

In the RedaQuest app when you create a new WordPress integration (Settings → Integrations).

= Which Custom Post Types are supported? =

The plugin automatically detects all public custom post types on your site. In the settings you choose which ones to sync.

= Can I publish posts to WordPress from RedaQuest? =

Yes. Enable **Content writing** on the Publishing tab and RedaQuest can create and update posts on your site.

= Do I need a RedaQuest account? =

Yes. A RedaQuest account and workspace are required to connect and to schedule social posts.

== Screenshots ==

1. Unified settings page: Connection tab with the Connect button and API key.
2. Sync tab: choose which content types to sync.
3. Block editor panel: generate social copy from an article and schedule it.

== Changelog ==

= 3.0.7 =
* Fix article not appearing in Gutenberg: use resetBlocks + savePost instead of insertBlocks from modal
* HTML fallback to core/html block when rawHandler returns empty
* Save article content before generating images; show warnings when image generation fails

= 3.0.6 =
* Async full article generation with polling — fixes invalid JSON on Generate full article (same 60s proxy issue as outline)

= 3.0.5 =
* Fix empty outline step: validate AI response before showing step 3; poll until outline has title + sections
* Clear error when plugin/backend mismatch leaves outline pending without polling

= 3.0.4 =
* Blog outline uses async job + polling — fixes invalid JSON on hosts with 60s proxy timeout
* Outline start returns in ~1s; editor polls until the AI outline is ready

= 3.0.3 =
* Fix blog outline REST timeout: extend PHP runtime and upstream timeout for slow AI calls
* Sanitize UTF-8 in REST responses so the block editor no longer sees invalid JSON
* Web research off by default in Blog Writer (opt in when you need it)

= 3.0.2 =
* Show connected workspace name (not just ID) after Connect
* Fix admin notices (Settings saved, connect success) to display in standard WordPress position
* Connect onboarding: opt-in to enable content publishing from RedaQuest (default on)
* Auto-set default post author to the WordPress admin who completes the connection
* After connect, open Publishing tab to review writing settings

= 3.0.1 =
* Restored v3 Gutenberg editor (Blog Writer, social scheduling, header panel) as the canonical plugin line.
* New: WordPress post editor metabox to send articles to Redaquest client approval.
* New: GET /redaquest/v1/posts/{id} for fresh content sync before approval.
* Bundled editor translations (sk, cs, de, hu, pl).

= 3.0.0 =
* New: connect the site to a RedaQuest workspace and schedule social posts from the block editor.
* New: AI-generated per-platform social copy and a brand featured image from inside the editor.
* New: unified settings page (Connection, Sync, Publishing, API & Debug); the old separate connection screen is gone.
* Changed: the plugin is now fully translatable (English base) with bundled translations.
* Fixed: output escaping, timezone-safe dates, and other code-quality issues.

= 2.5.0 =
* Update endpoint to modify existing posts from RedaQuest.
* Get-by-RedaQuest-ID lookup endpoint.
* Improved SEO meta updates (Yoast, Rank Math) and category/tag handling.

= 2.4.0 =
* Tab-based settings UI.
* More accurate CPT counts.

= 2.0.0 =
* Full Custom Post Type support and content-type selection in the admin.
* Optional content writing and default author.

== Upgrade Notice ==

= 3.0.7 =
Fixes generated article not inserted into the editor and silent image failures. Update recommended.

= 3.0.6 =
Fixes Generate full article invalid JSON timeout. Update if article generation fails after outline works.

= 3.0.5 =
Fixes Blog Writer showing an empty outline form. Update required if Generate outline skips content.

= 3.0.4 =
Fixes Blog Writer outline on hosts that cut off long requests. Update recommended if Generate Outline shows invalid JSON.

= 3.0.3 =
Fixes Blog Writer outline generation timeouts and invalid JSON responses. Update recommended for all connected sites.

= 3.0.1 =
Restores the Gutenberg editor integration and adds client article approval. Requires Redaquest backend with wp-article-approval deployed.

= 3.0.0 =
Adds social scheduling from the block editor and unifies the settings page. After updating, open Settings → Redaquest Connector and click Connect to enable scheduling.
