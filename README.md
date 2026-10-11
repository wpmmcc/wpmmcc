# WPMMCC — Cross-site content sync for WordPress

**Copy content between WordPress sites you pair. This plugin does not translate.**

Version 1.0.0. WordPress 6.2 or newer. PHP 7.4 or newer. License GPL-2.0-or-later.

## What it does

WPMMCC copies posts, terms, media, and supported plugin fields between sites an administrator pairs. The wp-admin menu is named **WPMMCC Sync**. The REST namespace is `wpmmcc/v1`.

Four topologies are supported:

- Standalone site to standalone site
- Network subsite to network subsite
- Network subsite to standalone site
- Standalone site to network subsite

Content adapters cover WooCommerce, Elementor, Advanced Custom Fields (ACF), bbPress, Yoast SEO, Rank Math, LMS courses, galleries, podcasts, downloads, and other registered post types.

## What it does not do

- It does not translate. Translation is the separate plugin [WPMMCC ATS](https://github.com/wpmmcc/wpmmcc-ats), whose wp-admin menu is named **WPTSALL**.
- It does not use the WPTSALL Client. That client talks only to WPMMCC ATS.
- It does not contact wpmm.cc, www.wpmm.cc, or any vendor server. There is no phone-home, license check, telemetry, or language-pack download.
- It does not use a central sync server. Paired sites call each other directly.
- It refuses private and local peer addresses unless an administrator opts in, and it does not follow redirects.
- Payloads are JSON signed with HMAC-SHA256. The plugin does not PHP-unserialize data from the wire.

## Installation

1. Upload the `wpmmcc` directory to `/wp-content/plugins/`, or upload the plugin ZIP from Plugins → Add New → Upload Plugin.
2. Activate the plugin through the Plugins menu.
3. Open the **WPMMCC Sync** menu and generate a pairing code.

Minimum requirements: WordPress 6.2 or newer, PHP 7.4 or newer.

## How pairing works

Nothing is sent until an administrator connects a peer, runs a sync or connection test, or the hourly reconciliation job runs for a peer that is already paired.

Connecting a peer sends one JSON POST to:

`{remote site}/wp-json/wpmmcc/v1/sync/handshake`

Later calls are JSON POSTs to that same site, signed with HMAC-SHA256. Each request includes `X-WPMMCC-Site-UUID`, `X-WPMMCC-Timestamp`, `X-WPMMCC-Nonce`, and `X-WPMMCC-Signature`.

- `/wp-json/wpmmcc/v1/sync/push` and `/wp-json/wpmmcc/v1/sync/receipt` — content changes and delivery receipts
- `/wp-json/wpmmcc/v1/sync/digest` and `/wp-json/wpmmcc/v1/sync/pull` — identifiers used to pull content the peer already has
- `/wp-json/wpmmcc/v1/sync/reconcile-digest` — fingerprints compared by the hourly reconciliation job

The shared secret stays on the two paired sites. It is not sent to a third party.

## Links

- Documentation — https://www.wpmm.cc/docs/content-sync.html
- Project website — https://www.wpmm.cc/
- Source — https://github.com/wpmmcc/wpmmcc
- Translation plugin, WPMMCC ATS — https://wordpress.org/plugins/wpmmcc-ats/
- Directory page for this plugin is `readme.txt` in this repository

## 简体中文

WPMMCC 在你配对的 WordPress 站点之间复制内容。它不翻译。

版本 1.0.0。需要 WordPress 6.2 或更高版本，PHP 7.4 或更高版本。许可证是 GPL-2.0-or-later。

后台菜单名是 **WPMMCC Sync**。REST 命名空间是 `wpmmcc/v1`。支持四种拓扑：独立站点对独立站点、网络子站点对网络子站点、网络子站点对独立站点、独立站点对网络子站点。

内容适配器覆盖 WooCommerce、Elementor、Advanced Custom Fields (ACF)、bbPress、Yoast SEO、Rank Math、LMS 课程、相册、播客、下载，以及其他已注册的文章类型。

它不调用翻译客户端。翻译是另一个插件 [WPMMCC ATS](https://github.com/wpmmcc/wpmmcc-ats)，后台菜单名是 **WPTSALL**。客户端只连接 WPMMCC ATS：WebUI 默认是浏览器地址 `127.0.0.1:8977`，Desktop 监听 `127.0.0.1:8978`，只留在这台电脑上。

没有中心同步服务器，也不会向 wpmm.cc 回传数据。管理员在 WPMMCC Sync 里配对对端之后，站点之间才直接通信。私网和本机地址默认拒绝，除非管理员明确允许。不跟随重定向。线上数据是 HMAC-SHA256 签名的 JSON，不做 PHP 反序列化。

安装后打开 **WPMMCC Sync**，生成配对码即可。使用说明：https://www.wpmm.cc/docs/content-sync.html
