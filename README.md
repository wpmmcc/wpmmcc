# WPMMCC — Cross-site content sync for WordPress

**Copy content between WordPress sites you pair. This plugin does not translate.**

**English** | [简体中文](readme/README.zh-CN.md)

WPMMCC copies posts, terms, media, and supported plugin fields between sites an administrator pairs. The wp-admin menu is WPMMCC Sync. The REST namespace is wpmmcc/v1. All features are free. No license key or payment is required. A direct pairing copies content only. The optional WPTSALL Client can also pair as a sync peer; it does not translate through this plugin.

## Links
- Project website — https://www.wpmm.cc/
- Content sync guide — https://www.wpmm.cc/docs/content-sync.html
- FAQ — https://www.wpmm.cc/docs/faq.html
- Client guide — https://www.wpmm.cc/docs/client-guide.html
- This plugin on WordPress.org — https://wordpress.org/plugins/wpmmcc/
- Translation plugin, WPMMCC ATS — https://wordpress.org/plugins/wpmmcc-ats/
- WPMMCC ATS source — https://github.com/wpmmcc/wpmmcc-ats
- WPTSALL Client source — https://github.com/wpmmcc/wptsall-client
- Client releases — https://github.com/wpmmcc/wptsall-client/releases

## What it copies
- Standalone site to standalone site, network subsite to network subsite, and either direction between a subsite and a standalone site
- Posts, taxonomies, post meta, and media, including fields from WooCommerce, Elementor, ACF, bbPress, Yoast SEO, Rank Math, and other registered post types
- Direction can be bidirectional, push only, or pull only
- Payloads are JSON signed with HMAC-SHA256. The plugin does not PHP-unserialize data from a peer
- No central sync server, phone-home, license check, or telemetry

## Translation is a different plugin

Translation is WPMMCC ATS. Its menu is WPTSALL and its REST namespace is wptsall/v2. The direct pairing form on this plugin rejects a sync-and-translate mode. Directory page: https://wordpress.org/plugins/wpmmcc-ats/ .

## Requirements
- WordPress 6.2 or newer
- PHP 7.4 or newer
- No account, subscription, or license key

## Installation
1. Install from WordPress.org: Plugins → Add New, search for WPMMCC, then activate. Directory page: https://wordpress.org/plugins/wpmmcc/
2. Or upload a ZIP built from this repository's source
3. Open WPMMCC Sync

## Pair two sites
1. On one site, generate a pairing code. It is single-use and lasts 10 minutes
2. On the other site, enter that site's URL and the code, then connect
3. Nothing is sent until an administrator connects a peer, runs a sync, or the hourly reconciliation runs for a peer that is already paired

## Optional WPTSALL Client
Direct pairing does not need the client. The same client can pair with this plugin by using the connection pack from WPMMCC Sync: site URL, client token, route secret, and this site's UUID. WebUI is a browser page at 127.0.0.1:8977. Desktop listens on 127.0.0.1:8978 and stays on this computer. Content the client copies is not translated by this plugin. Source: https://github.com/wpmmcc/wptsall-client . Releases: https://github.com/wpmmcc/wptsall-client/releases . Guide: https://www.wpmm.cc/docs/client-guide.html .

## Updating
- Updates install through Dashboard → Updates or the Plugins page
- Replacing the plugin files creates missing sync tables on the next load. Existing sync tables stay in place
- Release notes for the directory package are in the Changelog section of readme.txt

## Uninstalling
- Deleting the plugin removes its sync tables, settings, schedule, and stored credentials
- That includes this plugin's Action Scheduler tables. Shared Action Scheduler tables used by other plugins are left in place
- Posts and media already written into WordPress stay until an administrator deletes them

## Open-source components
- Plain PHP on WordPress core APIs. No third-party PHP library is bundled
- Adapters read data from other plugins. Those plugins are not bundled or modified

## Languages
This README is maintained in English and Simplified Chinese. The link at the top switches between them.

## License
GPL-2.0-or-later. See [LICENSE](LICENSE).

