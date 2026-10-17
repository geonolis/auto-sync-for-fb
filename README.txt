=== Auto Sync for FB ===
Contributors: Geonolis
Tags: facebook, auto-post, sync
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically clone Facebook Page posts into WordPress blog posts with image sideloading into the WP Media Library and WP-Cron scheduling.

== Description ==

Auto Sync for FB connects your Facebook Page to your WordPress site via the Facebook Graph API. 

Key Features:
* Automatically imports Facebook Page posts as WordPress blog posts.
* Downloads post images directly into the WordPress Media Library and sets them as Featured Images (`_thumbnail_id`).
* Deduplication Engine: Tracks `_fb_post_id` in post meta so posts are never imported twice.
* Batch Historical Importer: Migrate hundreds of older posts with cursor-based pagination and a live progress bar without server timeouts.
* Native WP-Cron scheduling (Hourly, Every 2 Hours, Twice Daily, or Daily).
* Manual "Sync Latest Posts (Quick)" and "Test Connection" buttons in WP Admin.
* Choice of Published vs Draft status, default category, and author.
* Token Exchange Tool: Easily exchange short-lived tokens for permanent (never-expiring) Facebook Page Access Tokens.

== Installation ==

1. Upload the `auto-sync-for-fb` folder to your `/wp-content/plugins/` directory (or zip the folder and upload via **Plugins > Add New > Upload Plugin**).
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to **Settings > Auto Sync for FB** in your WordPress dashboard.
4. Enter your **Facebook Page ID** and **Page Access Token**.
5. Click **Test FB Connection** to confirm connectivity.
6. Click **Sync Now** to immediately import your latest posts, or let WP-Cron run automatically on your chosen schedule.

== Frequently Asked Questions ==

= Does this plugin require external paid services? =
No. The plugin communicates directly between your WordPress site and the official Facebook Graph API.

= Will this plugin slow down my site? =
No. The sync runs asynchronously via WP-Cron in short bursts (less than 2 seconds) and exits immediately if no new Facebook posts are detected.

= How do I prevent Facebook tokens from expiring? =
Use the built-in Token Exchange feature in the settings page. Enter your Facebook App ID and App Secret to convert short-lived tokens into permanent, non-expiring Page Access Tokens.

= Can I import posts as drafts first? =
Yes. You can select "Save as Draft" in the settings if you prefer to review posts before publishing them to your blog.

== How to Get Facebook Credentials ==

1. Go to [Facebook for Developers](https://developers.facebook.com/).
2. Create an App (Type: Other / Business).
3. Select Use Case "Manage everything on your Page". Add business_management to minimum proposed permissions.
4. Select Tools/Graph API Explorer and create User token with pages_show_list, business_management, pages_read_engagement permissions.
5. Get Page Access Token for your page, with the same permissions as above.
6. Copy your Facebook Page ID (found in your Page's **About** section or URL).

== How to Get a Never-Expiring (Permanent) Token ==

Short-lived Facebook tokens expire in 1-2 hours. To get a permanent token that never expires:

1. Copy your **App ID** and **App Secret** from your Facebook Developer App Settings (Settings > Basic).
2. Enter your **App ID**, **App Secret**, and temporary **Page Access Token** into the plugin settings.
3. Click **Exchange for Never-Expiring Token**.
4. The plugin will exchange your short-lived token via Facebook Graph API for a **permanent Page Access Token** and automatically save it into your settings!

== Screenshots ==

1. Plugin Settings panel in WP Admin with Facebook configuration, Token Exchanger, and Manual Sync buttons.

== Changelog ==

= 1.1.0 =
* Added Batch Historical Importer with live progress bar and pause/stop controls for migrating full post histories.
* Added cursor-based pagination (`after` cursor) in Facebook API client (`fetch_posts_page`).
* Added `fb2wp_batch_sync` AJAX endpoint with non-blocking batch execution.

= 1.0.1 =
* Improved WP-Cron deactivation hook using wp_clear_scheduled_hook.

= 1.0.0 =
* Initial release of Auto Sync for FB.

== Upgrade Notice ==

= 1.1.0 =
Adds Batch Historical Importer with live progress bar to migrate all older Facebook posts without server timeouts.
