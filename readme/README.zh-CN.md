# WPMMCC — WordPress 跨站内容同步

**在你配对的 WordPress 站点之间复制内容。这个插件不翻译。**

[English](../README.md) | **简体中文**

WPMMCC 在管理员配对的站点之间复制文章、分类、媒体和支持的插件字段。后台菜单是 WPMMCC Sync。REST 命名空间是 wpmmcc/v1。全部功能免费，不需要许可证或付费。直接配对只复制内容。可选的 WPTSALL 客户端也可以作为同步对端配对，它不会通过这个插件翻译。

## 相关链接
- 项目官网 — https://www.wpmm.cc/
- 内容同步说明 — https://www.wpmm.cc/docs/content-sync.html
- 常见问题 — https://www.wpmm.cc/docs/faq.html
- 客户端指南 — https://www.wpmm.cc/docs/client-guide.html
- 本插件的 WordPress.org 页面 — https://wordpress.org/plugins/wpmmcc/
- 翻译插件 WPMMCC ATS — https://wordpress.org/plugins/wpmmcc-ats/
- WPMMCC ATS 源码 — https://github.com/wpmmcc/wpmmcc-ats
- WPTSALL Client 源码 — https://github.com/wpmmcc/wptsall-client
- 客户端发布 — https://github.com/wpmmcc/wptsall-client/releases

## 它复制什么
- 独立站点对独立站点、网络子站点对网络子站点，以及子站点与独立站点的两个方向
- 文章、分类法、文章 meta 和媒体，包括 WooCommerce、Elementor、ACF、bbPress、Yoast SEO、Rank Math 以及其他已注册文章类型的字段
- 方向可以是双向、只推送或只拉取
- 载荷是 HMAC-SHA256 签名的 JSON。插件不会对对端数据做 PHP 反序列化
- 没有中心同步服务器，没有回传、许可证检查或遥测

## 翻译是另一个插件

翻译是 WPMMCC ATS。它的菜单是 WPTSALL，REST 命名空间是 wptsall/v2。本插件的直接配对表单会拒绝“同步并翻译”。目录页：https://wordpress.org/plugins/wpmmcc-ats/ 。

## 环境要求
- WordPress 6.2 或更高版本
- PHP 7.4 或更高版本
- 不需要账号、订阅或许可证密钥

## 安装
1. 从 WordPress.org 安装：插件 → 安装插件，搜索 WPMMCC 后启用。目录页：https://wordpress.org/plugins/wpmmcc/
2. 也可以上传用本仓库源码打包的 ZIP
3. 打开 WPMMCC Sync

## 配对两个站点
1. 在一个站点生成配对码。配对码只用一次，10 分钟内有效
2. 在另一个站点填写对端网址和配对码，然后连接
3. 管理员连接对端、执行同步，或已配对站点的每小时核对运行之前，不会向外发送内容

## 可选的 WPTSALL 客户端
两个站点直接配对不需要客户端。同一个客户端可以用 WPMMCC Sync 里的连接包配对：站点 URL、客户端令牌、路由密钥和本站 UUID。WebUI 是浏览器页面，地址是 127.0.0.1:8977。Desktop 监听 127.0.0.1:8978，只留在这台电脑上。客户端经这个插件复制的内容不会被翻译。源码：https://github.com/wpmmcc/wptsall-client 。发布：https://github.com/wpmmcc/wptsall-client/releases 。指南：https://www.wpmm.cc/docs/client-guide.html 。

## 更新
- 通过仪表盘 → 更新，或插件页面安装更新
- 替换插件文件后，下次加载会补齐缺少的同步表。已经存在的同步表会保留
- 目录包的发布说明在 readme.txt 的 Changelog

## 卸载
- 删除插件会移除它的同步表、设置、计划任务和保存的凭据
- 这包括本插件自己的 Action Scheduler 表。其他插件共用的 Action Scheduler 表会留下
- 已经写入 WordPress 的文章和媒体，要等管理员自己删除

## 开源组件
- 基于 WordPress 核心 API 的普通 PHP，不捆绑第三方 PHP 库
- 适配器读取其他插件的数据。那些插件不会被捆绑或修改

## 语言
这份 README 维护英文和简体中文。用页顶的链接切换。

## 许可证
GPL-2.0-or-later，详见 [LICENSE](../LICENSE)。

