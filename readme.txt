=== WPMMCC ===
Contributors: wpmmcc
Tags: sync, synchronization, multisite, cross-site, backup
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cross-site content synchronization engine for standalone WordPress sites and multisite subsite networks with zero wire unserialize security.

== Description ==

WPMMCC is a high-performance, enterprise-grade content synchronization engine designed for WordPress. It enables point-to-point bidirectional content synchronization between independent standalone WordPress installations, between multisite networks, and between standalone sites and multisite subsites.

All features are free and fully functional. No license key or payment is required.

= Key Features =

* **4 Synchronization Topologies**: Standalone to Standalone, Subsite to Subsite, Subsite to Standalone, and Standalone to Subsite.
* **Intra-Site Isolation Protection (ADR-5)**: Prevents accidental self-pairing and infinite loops within the same underlying WordPress installation.
* **Incremental CDC & Debounce**: Captures real-time changes using Change Data Capture hooks with an automatic 5-second debounce coalesce window.
* **Scheduled Keyset Reconciliation**: Hourly cron background self-healing that compares entity SHA-256 fingerprints to fix silent data drift.
* **Content plugin adapters**: WooCommerce, Elementor, Advanced Custom Fields (ACF), bbPress, Yoast SEO, Rank Math, LMS courses, galleries, podcasts, downloads, and other registered post types.
* **Zero Wire Unserialize Security**: Strict JSON-only payloads signed by HMAC-SHA256 with replay prevention, completely eliminating PHP object injection vulnerabilities.
* **Multimodal Asset Synchronization**: Handles images, video, audio, and documents with CAS deduplication and safe chunked streaming.

== Installation ==

1. Upload the `wpmmcc` directory to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to the 'WPMMCC Sync' menu in the admin dashboard to generate pairing codes and configure peers.

== Frequently Asked Questions ==

= Does WPMMCC require a central server? =
No. WPMMCC uses direct, peer-to-peer communication between authorized WordPress sites authenticated via HMAC-SHA256 signatures.

= Can I synchronize between a standalone site and a multisite subsite? =
Yes. WPMMCC fully supports hybrid synchronization between standalone sites and multisite subsites.

= Does WPMMCC translate content? =
No. WPMMCC copies content between sites an administrator pairs. It does not translate, and it does not use the translation client. Translation is a separate plugin, WPMMCC ATS, whose wp-admin menu is named WPTSALL.

== External services ==

This plugin does not contact wpmm.cc, www.wpmm.cc, or any other vendor server. There is no phone-home, license check, telemetry, or language-pack download.

The only outbound HTTP is to a WordPress site that an administrator pairs in WPMMCC Sync. The address is the remote site URL entered in that screen. Nothing is sent until an administrator connects a peer, runs a sync or connection test, or the hourly reconciliation cron runs for an already paired peer.

= Connect a peer =

When an administrator submits the connect form, the plugin sends one JSON POST to:

`{remote site}/wp-json/wpmmcc/v1/sync/handshake`

The body contains the pairing code, this site's UUID, home URL, site name, install signature, requested direction, sync mode, source language, target language, and conflict strategy. The peer answers with its UUID and a shared secret used only to sign later requests between these two sites. The shared secret is stored on this site. It is not sent to any third party.

= Sync with a paired peer =

Later calls are JSON POSTs to the paired site, signed with HMAC-SHA256. Each request includes the headers `X-WPMMCC-Site-UUID`, `X-WPMMCC-Timestamp`, `X-WPMMCC-Nonce`, and `X-WPMMCC-Signature`. The body is the sync payload for that route:

* `/wp-json/wpmmcc/v1/sync/push` and `/wp-json/wpmmcc/v1/sync/receipt` — content changes and delivery receipts the administrator or the sync queue sends to that peer.
* `/wp-json/wpmmcc/v1/sync/digest` and `/wp-json/wpmmcc/v1/sync/pull` — identifiers used to pull content that peer already has.
* `/wp-json/wpmmcc/v1/sync/reconcile-digest` — content fingerprints compared by the hourly reconciliation job.

The peer may call this site's matching REST routes. Those are inbound requests to your site, not calls this plugin makes to a vendor.

Private and local peer addresses are refused unless an administrator opts in. Redirects are not followed. This plugin does not send site content to WPMMCC.

== Changelog ==

= 1.0.0 =
* Initial release of WPMMCC cross-site content synchronization engine.
* Support for 4 synchronization topologies and ADR-5 intra-site protection.
* Content plugin adapters and a scheduled reconciliation engine.
