=== WPMMCC ===
Contributors: wpmmcc
Tags: sync, synchronization, multisite, cross-site, backup
Requires at least: 5.8
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
* **20 Content Plugin Adapters**: Deep synchronization support for WooCommerce, Elementor, Advanced Custom Fields (ACF), bbPress, Yoast SEO, Rank Math, LMS courses, and more.
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

== Changelog ==

= 1.0.0 =
* Initial release of WPMMCC cross-site content synchronization engine.
* Support for 4 synchronization topologies and ADR-5 intra-site protection.
* 20 content plugin adapters and scheduled reconciliation engine.
