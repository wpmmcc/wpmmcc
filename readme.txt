=== WPMMCC ===
Contributors: wpmmcc
Tags: sync, synchronization, multisite, cross-site
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.1.10
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Copy posts, terms, and media between paired WordPress sites. Direct pairing only syncs. The WPTSALL Client can translate the title, content, and excerpt while syncing.

== Description ==

WPMMCC copies content between WordPress sites an administrator pairs. The wp-admin menu is **WPMMCC Sync**. The REST namespace is `wpmmcc/v1`. All features are free. No license key or payment is required.

= Project links =

* Website: https://www.wpmm.cc/
* Documentation: https://www.wpmm.cc/docs/content-sync.html
* FAQ and privacy: https://www.wpmm.cc/docs/faq.html
* This plugin on WordPress.org: https://wordpress.org/plugins/wpmmcc/
* Plugin source: https://github.com/wpmmcc/wpmmcc
* Optional WPTSALL Client source: https://github.com/wpmmcc/wptsall-client
* Client releases: https://github.com/wpmmcc/wptsall-client/releases
* Client guide: https://www.wpmm.cc/docs/client-guide.html

Supported layouts:

* Standalone site to standalone site
* Network subsite to network subsite
* Network subsite to standalone site
* Standalone site to network subsite

Direction can be bidirectional, push only, or pull only. Conflict handling can be last-write-wins, source wins, target wins, manual review, or a field merge. Source and target locales are recorded with the pairing. This plugin does not translate those locales.

All features are free. No license key or payment is required.

= What it copies =

Posts (title, content, excerpt, and related fields), taxonomies, post meta, and media (images, video, audio, and documents). Adapters cover WooCommerce, Elementor, Advanced Custom Fields (ACF), bbPress, Yoast SEO, Rank Math, LMS courses, galleries, podcasts, downloads, and other registered post types.

Payloads are JSON signed with HMAC-SHA256. The plugin does not PHP-unserialize data received from a peer. A rapid series of saves is coalesced for 5 seconds. An hourly job compares content fingerprints and repairs drift. The plugin refuses to pair a site with itself. Private and local peer addresses are refused unless an administrator opts in. Redirects are not followed.

= What it does not do =

* It does not call a translation vendor. In-site translation is the separate plugin WPMMCC ATS. Its wp-admin menu is **WPTSALL**.
* The direct pairing form only offers content sync. A "sync and translate" mode is rejected on that form. The WPTSALL Client can run that mode: it translates the title, content, and excerpt, then pushes the result.
* It does not contact wpmm.cc, www.wpmm.cc, or any translation vendor. There is no phone-home, license check, telemetry, or language-pack download.
* It does not use a central sync server.

= Optional standalone client =

Direct pairing does not need the WPTSALL Client. Two sites with this plugin installed can pair with a pairing code.

The same client can also be paired to this plugin. WPMMCC Sync can show a client token, a route secret, and a connection pack (site URL, token, route secret, and this site's UUID). An administrator pastes that pack into the client. The client then calls this site's `wpmmcc/v1` routes. Content the client pulls is processed on the computer where the client runs, and can be pushed to another site the administrator paired. That computer is not wpmm.cc.

The client's WebUI is a browser page that defaults to 127.0.0.1:8977. Desktop listens on 127.0.0.1:8978 and stays on this computer. When the pairing mode is sync_and_translate, the client translates the title, content, and excerpt on that computer and then pushes them. This plugin does not call the translation vendor. Client source: https://github.com/wpmmcc/wptsall-client . Signed releases: https://github.com/wpmmcc/wptsall-client/releases . Setup guide: https://www.wpmm.cc/docs/client-guide.html .

Generate a client token in WPMMCC Sync before using the client. Until a token exists, `GET /wp-json/wpmmcc/v1/sync/ping` answers without a token and returns only the plugin name, version, and protocol numbers. It does not return site content. After a token is generated, that request requires the token.

== Installation ==

1. Upload the plugin ZIP through Plugins → Add New → Upload Plugin, or upload the `wpmmcc` directory to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins menu.
3. Open **WPMMCC Sync**.
4. On one site, generate a pairing code. On the other site, enter that site's URL and the code, then connect. The code is single-use and lasts 10 minutes.
5. Optional: generate a client token and copy the connection pack into the WPTSALL Client. Skip this step if the two sites pair directly.

== Frequently Asked Questions ==

= Does WPMMCC require a central server? =

No. Paired sites call each other. If you use the optional client, that program calls the sites you configured. Nothing is sent to wpmm.cc.

= Does WPMMCC translate content? =

The plugin does not call a translation vendor. Locales stored on a direct pairing are labels only, and that form rejects sync_and_translate. Pair the sites through the WPTSALL Client to translate the title, content, and excerpt while syncing. In-site translation is WPMMCC ATS, menu name WPTSALL, REST namespace `wptsall/v2`.

= Can I synchronize a standalone site with a network subsite? =

Yes. The four layouts listed above are supported.

= Does this plugin call a translation API? =

No. It does not call Google, DeepL, OpenAI, or any other translation vendor, and it does not store those API keys. A translation provider configured in the optional client is used by that client for WPMMCC ATS tasks and, when a WPMMCC pairing uses sync_and_translate, for the title, content, and excerpt being synced. The provider account is the one you create in the client.

== External services ==

This plugin does not contact wpmm.cc, www.wpmm.cc, or any other vendor server. There is no phone-home, license check, telemetry, or language-pack download.

Service used: a WordPress site an administrator enters in WPMMCC Sync, or the optional WPTSALL Client an administrator installs and points at this site. There is no third-party terms-of-service URL for that peer, because it is a site or program you operate. Product documentation is at https://www.wpmm.cc/docs/content-sync.html . Privacy notes for the product are at https://www.wpmm.cc/docs/faq.html . The client guide is at https://www.wpmm.cc/docs/client-guide.html . This plugin does not call those pages.

Nothing is sent until an administrator connects a peer, runs a sync or connection test, the hourly reconciliation runs for an already paired peer, or the optional client calls this site.

= Connect a peer =

When an administrator submits the connect form, the plugin sends one JSON POST to:

`{remote site}/wp-json/wpmmcc/v1/sync/handshake`

The body contains the pairing code, this site's UUID, home URL, site name, install signature, requested direction, sync mode (`sync_only`), source language, target language, and conflict strategy. The peer answers with its UUID and a shared secret used only to sign later requests between these two parties. The shared secret is stored on this site. It is not sent to any third party.

The optional client can send that same handshake to this site, using a pairing code an administrator generated. This site then treats the client as a peer.

= Sync with a paired peer =

Later calls are JSON POSTs, signed with HMAC-SHA256. Each request includes `X-WPMMCC-Site-UUID`, `X-WPMMCC-Timestamp`, `X-WPMMCC-Nonce`, and `X-WPMMCC-Signature`.

* `/wp-json/wpmmcc/v1/sync/push` and `/wp-json/wpmmcc/v1/sync/receipt` — one content packet or a delivery receipt. A packet can include post title, content, excerpt, status fields, taxonomies, post meta, and a media manifest.
* `/wp-json/wpmmcc/v1/sync/digest` and `/wp-json/wpmmcc/v1/sync/pull` — identifiers and fingerprints used to pull packets the peer already has.
* `/wp-json/wpmmcc/v1/sync/reconcile-digest` — fingerprints compared by the hourly reconciliation job.
* Media bytes may be sent in chunks with the packet's media manifest.

The peer, or the optional client after it has paired, may call this site's matching routes. Those are inbound requests. This plugin does not open a connection to the client by itself.

= Identity request =

`GET /wp-json/wpmmcc/v1/sync/ping`

Before a client token exists, this route answers without a token and returns only plugin identity, version, and protocol numbers. After an administrator generates a client token, the caller must send that token. The authenticated response also includes this site's install signature. Site content is not included.

Private and local peer addresses are refused unless an administrator opts in. Redirects are not followed. This plugin does not send site content to WPMMCC.

== Privacy ==

Pairing records, the shared secret, the client token, the route secret, sync queue rows, and content fingerprints are stored in this site's database. Copied posts, terms, and media are stored as normal WordPress content on the destination site.

Content leaves this site only toward a peer URL an administrator saved, or toward the optional client after that client has paired. It is not sent to a translation vendor by this plugin.

Deleting the plugin removes its database tables, settings, scheduled task, and credentials stored in options. That includes the delivery tables. Copied posts and media that were written into the WordPress content tables remain until an administrator deletes them.

== Screenshots ==

1. Site identity, pairing code, and the connection pack for the optional WPTSALL Client.
2. Connect a peer. The form copies content only and does not translate.
3. Connected peers, the conflict review queue, and the sync journal.

== Changelog ==

= 2.1.10 =
* Changed: Directory listing describes the current free sync plugin, with screenshots of WPMMCC Sync and links to https://www.wpmm.cc/ and the WPTSALL Client on GitHub.

= 2.1.9 =
* Changed: Directory version is 2.1.9, which is newer than 2.0.1, so WordPress can update an existing install.
* Changed: Replacing the 2.0.1 files creates the sync tables on the next load. Tables created by 2.0.1 stay in place during the update.
* Fixed: Uninstall drops every sync table, including the delivery tables that 1.0.0 left behind, and the tables created by directory release 2.0.1. The reconciliation schedule is cleared too.

= 1.0.0 =
* Initial release of WPMMCC cross-site content synchronization.
* Four site layouts, same-install protection, and a 5-second save coalesce.
* Content adapters and an hourly fingerprint reconciliation.
* Direct pairing copies content only. The optional client is documented separately and is not required.
