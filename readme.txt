=== TSO Admin Notices Manager ===
Contributors: deadko
Tags: admin notices, notices manager, clean admin, plugins notices
Requires at least: 6.1
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Hides annoying plugin admin notices (promotional, backup, update messages) while keeping WordPress core notices intact.

== Description ==

**TSO Admin Notices Manager** eliminates the clutter of promotional, backup-completion, and "please rate us" notices that third-party plugins add to your WordPress admin area — without permanently blocking anything.

**How it works**

* PHP intercepts every callback registered on `admin_notices` / `all_admin_notices`.
* Each non-core callback is identified by its source file using PHP Reflection.
* The output is captured with ob_start() and wrapped in a hidden group div born invisible before the browser paints anything.
* JavaScript manages show/hide via a toggle button in the admin bar.
* An admin-bar button shows the count for **this screen** and lets you reveal notices temporarily with one click.

**Key features**

* No flash — notices are hidden server-side before the browser renders them.
* One-click toggle in the admin bar to show/hide filtered notices.
* Per-plugin whitelist — allow specific plugins to keep showing their notices.
* Catches Freemius, Rank Math, Backuply, WooCommerce and other template-rendered notices via a JS fallback layer.
* 100% compatible with cache plugins (LiteSpeed Cache, WP Rocket, W3 Total Cache, etc.).
* No database tables. No extra queries. Lightweight.

== Installation ==

1. Upload the `tso-admin-notices` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Settings → Plugin Notices** to configure the whitelist.

== Frequently Asked Questions ==

= Will notices be lost? =

No. Notices are generated normally by PHP — they are simply hidden in the browser. Click the admin-bar button to reveal them at any time.

= Why does the count change between screens? =

The counter shows how many notices are hidden **on the current screen**, not a site-wide total. Some plugins only register notices on their own admin pages.

= Does it work with LiteSpeed Cache / WP Rocket / etc.? =

Yes. Notices are hidden server-side (ob_start) so cached pages work correctly too.

= Can I whitelist specific plugins? =

Yes. Go to **Settings → Plugin Notices** and check any plugin whose notices you want to keep visible.

= Does it affect the front-end? =

No. The plugin only runs in the WordPress admin area (`is_admin()`), and never during AJAX or cron requests.

== Screenshots ==

1. Admin bar toggle showing the notice count.
2. Settings page with whitelist.

== Changelog ==

= 1.0.2 =
* Fixed notices still visible while counter showed zero (Backuply, Rank Math, etc.).
* Bootstrap on plugins_loaded so late-registered notice callbacks are wrapped.
* Broader zero-flash CSS under #wpbody-content with safe-zone exclusions.
* Prefix TSOAN_ (≥5 chars) for WordPress.org Plugin Check.
* Replaced inline style tag with wp_add_inline_style.
* Requires WordPress 6.1+; PHP 7.4 compatible.

= 1.0.1 =
* Refactored prefixes and asset names.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.2 =
Critical fix for notices that stayed visible. Update and hard-refresh the admin.
