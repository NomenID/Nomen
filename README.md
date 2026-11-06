<img src="public/assets/brand/nomen-icon.svg" width="72" alt="">

# Nomen

Nomen 是一个自托管的账号中心：一台服务器跑一份，几个站点共用一套账号。登录、注册、二步验证、找回密码和账号设置都在这里完成，各站点通过 OAuth 2.0 接入。

官方实例在 [nomen.caellab.com](https://nomen.caellab.com/)，站内文档也装在那里（`/w`，由 pages 插件提供）。同一套代码还跑在 [id.caellab.com](https://id.caellab.com)。

## 功能

- 账号：一个用户名、最多 5 个邮箱（一个首选）、头像、语言和皮肤偏好
- 登录：密码、邮箱验证码、通行密钥（WebAuthn）
- 二步验证：TOTP、恢复码、邮件验证码
- 授权：OAuth 2.0 授权码流程，PKCE 只收 S256；`/api/userinfo` 返回 `sub`、`username`、昵称和头像
- 恢复：申诉、管理员审核、一次性链接、重设凭据
- 可选：家庭与未成年监护（家长同意、家长邮箱、家长可重置密码和临时锁定，看不到其他资料）
- 可选：注册时选择国家 / 地区，按当地同意年龄判断
- 活动日志（按类型 / 执行者 / 目标筛选，字段对照、CSV 导出）、站内通知、开发者开放平台
- 界面 25 种语言，默认语言和是否允许切换由站长决定
- 站内文档、可更换的皮肤、可插拔的功能

可选功能默认关闭，安装时勾选，装完也能在 `config/installed.php` 里改（见 [INSTALL.md](INSTALL.md)）。

## 环境要求

- PHP 8.1+
- MySQL 8 / MariaDB 10.6+ / SQLite 3.35+
- Composer
- Redis（可选，用于限流和多设备会话踢出；连不上会自动降级）
- Node 18+（只有改前端才需要）

## 安装

发布包在 <https://dl.caellab.com/file/nomen/>，文件名带版本号。解压到站点目录，web root 指向 `public/`，浏览器打开站点会进入安装向导。也可以走命令行：

```bash
php bin/nomen install
```

nginx 至少要有这条伪静态，少了只有首页能开：

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
```

数据库准备、前端构建、邮件、GeoIP 和排错都在 [INSTALL.md](INSTALL.md)。

## 升级

下载包覆盖文件（保留 `config/installed.php`、`.env`、`data/` 和上传目录），或者用 git 的 `git pull`，然后：

```bash
composer install --no-dev
php bin/nomen upgrade
```

`upgrade` 跑核心迁移和插件迁移。要备份哪些、跑漏了会怎样，见 [INSTALL.md](INSTALL.md#升级)。

## 文档

- 站内文档：装好后在 `/w`，管理员可以直接在页面里编辑
- 安装与部署：[INSTALL.md](INSTALL.md)
- 插件开发：[plugins/README.md](plugins/README.md)
- 皮肤开发：[skins/README.md](skins/README.md)

## 目录

| 路径 | 是什么 |
|---|---|
| `src/` | 核心类：路由、会话、数据库、邮件、i18n、活动日志 |
| `controllers/` | 请求处理 |
| `templates/` | PHP 模板 |
| `frontend/` | Vue 岛屿与 SPA，Vite 构建到 `public/assets/dist/` |
| `public/` | web 根目录，nginx 只指向这里 |
| `bin/nomen` | 命令行：安装、迁移、插件命令 |
| `database/` | 表结构与迁移，MySQL / MariaDB 和 SQLite 各一套 |
| `config/` | 应用配置、品牌、邮件、同意年龄表 |
| `locales/` | 25 种语言的核心词条；插件和皮肤的词条在各自的 `i18n/` 目录 |
| `plugins/` | 自带插件：pages（站内文档）、open-platform、geoip |
| `skins/` | 自带皮肤，默认是 `nomen-2026` |
| `scripts/` | 后台脚本：通知邮件队列、会话索引回填 |

## 插件与皮肤

功能扩展叫插件（`plugins/<slug>/plugin.json`），整套外观叫皮肤（`skins/<slug>/skin.json`）。插件放进 `plugins/`、写进 `config/plugins.php` 就能用，不用改核心代码；皮肤可以覆盖任意模板。

安全漏洞请发邮件到 id@caellab.com，别开 issue，见 [SECURITY.md](SECURITY.md)。

## 许可证

GPL-3.0-only，全文见 [LICENSE](LICENSE)。另有 GPLv3 第 7 条 (b) 款的署名条款：拿这套代码对外，
站点首页要能看到「Powered by Nomen」，还要有链到 `/special/version` 的入口，合成一处或分开都行。
默认页脚那条可以在 `config/brand.php` 里关掉，关掉后这两条自己补；不想署名就换个站名。
全文在 [NOTICE](NOTICE)。

Copyright (C) 2025-2026 Yunyun
