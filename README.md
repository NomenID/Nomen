<img src="public/assets/brand/nomen-icon.svg" width="72" alt="Nomen">

# Nomen

自由的账号星系。

每个网站都可以有自己的名字、自己的内容和自己的空间，但不必各自建立一套账号体系。

Nomen 是一个自由、开放、可自托管的账号系统。 它将账号、身份认证与授权集中在一个地方，让不同的网站和应用通过统一的身份体系彼此连接。

部署一份 Nomen，让你的多个站点共享同一套账号。用户只需要一个身份，就可以在不同的服务之间自由穿行。

账号属于用户，数据由部署者管理，连接由站点决定。

这就是 Nomen 想做的事。

## 官方实例

- [nomenid.org](https://nomenid.org/)：官方站，还有站内文档（`/w`）、下载与版本信息。
- [id.caellab.com](https://id.caellab.com/)

两个实例运行同一套 Nomen 代码。

---

## 一个账号，连接多个站点

Nomen 负责账号与身份，接入的站点负责自己的业务。双方通过 OAuth 2.0 建立连接，不需要每个站点重复处理注册、登录和密码管理。标准 OIDC 客户端也可以通过发现文档直接接入。

```text
                 Nomen
              统一账号与身份
                    │
          ┌─────────┼─────────┐
          │         │         │
        站点 A     站点 B     应用 C
          │         │         │
          └─────────┼─────────┘
                    │
                同一身份
```

站点可以独立运行，也可以选择接入同一个 Nomen 实例。是否共享账号，由站点管理者决定。

## 功能

### 账号与身份

- 一个用户名，最多绑定 5 个邮箱，其中一个为首选邮箱。
- 头像、语言和皮肤偏好。
- 五种登录方式：密码、邮箱验证码、临时密钥、Passkey（WebAuthn）和第三方登录。
- 第三方登录：GitHub、Google、Microsoft、CaelLabID，也支持标准 OAuth 2.0 提供方。
- 登录状态默认保持 30 天并滑动续期，从登录算起最多 90 天；勾选「这是公共设备」则退化成浏览器会话。
- 活跃设备列表，可以单独结束某个会话。
- 注册激活、邮箱管理、密码修改与账号注销。

### 账号安全

- TOTP 动态验证码。
- 恢复码。
- 邮件验证码二步验证。
- Passkey 无密码认证。
- 登录方式可以逐项开关，也可以指定站点默认登录方式；插件能注册自己的登录方式。
- 账号恢复申诉、管理员审核与一次性恢复链接。
- 算术验证码、登录与接口限流、安全响应头。
- 凭据重设与账号安全管理。

### 统一身份

- OAuth 2.0 授权码流程，PKCE 仅接受 S256。
- OpenID Connect：`/.well-known/openid-configuration`、`/oidc/jwks.json`，id_token 带 nonce。
- `/api/userinfo` 用户信息接口，返回 `sub`、用户名、昵称和头像。
- 开发者开放平台：开发者自助创建应用、配置回调地址与 scope。
- 只读站点 API `/nomen-api`。

### 家庭与未成年人保护（可选）

- 家长同意机制与家长邮箱。
- 家长可以重置密码、临时锁定账号。
- 家长权限受到限制，不能查看孩子的其他个人资料。
- 可根据注册时选择的国家或地区，按照当地同意年龄规则进行处理。

### 内容与搜索

- 站内文档：Markdown 页面、目录与侧边栏、本页目录、上一页 / 下一页。
- 文档导入与自动 sitemap。
- 站内搜索：默认查数据库，可选 Meilisearch、OpenSearch 或 Elasticsearch。
- 站内通知。

### 管理与运维

- 活动日志：按类型、执行者和目标筛选，支持字段对照及 CSV 导出。
- Vue SPA 管理后台，包含用户、应用、接入点、恢复工单、日志和插件选项卡。
- 站点健康检查：PHP、扩展、数据库、目录权限、cron、邮件、缓存、更新、磁盘等，插件可以追加检查项。
- 概览页的活动 / 新闻卡片可以上下排序，按管理员保存。
- 定时任务：一条 cron 心跳，插件可以注册自己的任务；没配 cron 时功能照常，只是不按点运行。
- 可选的后台自动更新：默认只装补丁版，装前备份数据库和站点文件，失败自动回滚。
- 缓存：Redis、文件或数据库三种后端，Redis 不可用时自动回退。
- 首页标题、按钮和模板可以在后台修改，也可以指定一个站内页面作为首页。

### 扩展与界面

- 25 种界面语言，默认语言及语言切换权限由站长决定。
- 可替换的皮肤，包括纯 PHP 实现的 `nomen-ssr`；用户可以在设置里选择启用的皮肤。
- 可插拔的功能模块。
- 站点名、图标、版权、页脚与法律链接都可以配置。

部分功能为可选项，默认关闭。可以在安装时选择，也可以在安装后修改 `config/installed.php`。具体配置见 [INSTALL.md](INSTALL.md)。

---

## 部署

### 环境要求

| 组件 | 要求 |
|---|---|
| PHP | 8.1+ |
| MySQL | 8+ |
| MariaDB | 10.6+ |
| SQLite | 3.35+ |
| Composer | 源码部署时需要；发布包已带 `vendor/` |
| Redis | 可选，用于限流、缓存和多设备会话踢出 |
| Node.js | 18+，仅修改前端时需要 |

Redis 连接失败时会自动降级，不影响基础运行。

### 安装

从 [Nomen 发布包](https://dl.caellab.com/file/nomen/) 下载对应版本，解压到站点目录，并将 Web Root 指向 `public/`。

首次访问站点会进入安装向导。

也可以使用命令行安装：

```bash
php bin/nomen install
```

Nginx 需要配置伪静态：

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
```

如果缺少这条规则，通常只有首页可以正常访问。服务器改不了伪静态时，安装向导里可以关闭「短地址」，改用 `index.php?page=…` 形式的地址。

数据库准备、目录权限、邮件、GeoIP 和故障排查，请参阅 [INSTALL.md](INSTALL.md)。

### 定时任务

站点不依赖 cron 也能用，但 Pages 的 sitemap 预生成、插件注册的任务和自动更新检查都需要它。加一行：

```cron
* * * * * php /path/to/nomen/bin/nomen schedule run >/dev/null 2>&1
```

宝塔面板在「计划任务 → Shell 脚本」里设置，每分钟一次。没有 cron 时，sitemap 回到请求时现算，插件任务可以在后台手动运行。

### 升级

下载新版本覆盖文件，保留以下内容：

- `config/installed.php`
- `.env`
- `data/`
- 上传目录

如果使用 Git 部署，也可以通过 `git pull` 更新。

更新代码后执行：

```bash
php bin/nomen upgrade
php bin/nomen doctor
```

源码部署还需要 `composer install --no-dev`。升级命令会执行核心数据库迁移和插件迁移。备份要求及升级注意事项见 [升级文档](INSTALL.md#升级)。

开启了自动更新的站点，上面的步骤会由后台按配置完成。

---

## 运维

### 命令行

`bin/nomen` 是不需要 Web 环境的命令行入口：

| 命令 | 作用 |
|---|---|
| `install` | 交互式安装：建库、导表、建管理员、写 `.env` |
| `upgrade` | 覆盖新版本文件之后跑：核心迁移 + 插件迁移 |
| `migrate` | 应用 `database/migrations` 下还没跑过的迁移 |
| `doctor` | 自检：扩展、目录可写、配置、数据库连接、待应用迁移 |
| `backup [目录]` | 备份数据库，默认写到 `data/backups/` |
| `restore <文件>` | 从备份恢复，恢复前会自动再备份当前库 |
| `user:passwd <账号>` | 直接改密码，令牌一起撤销 |
| `user:promote` / `user:demote` | 提升或取消管理员 |
| `auth:reset` | 登录方式全关、进不去后台时恢复默认 |
| `schedule run` / `schedule list` | 跑到点的定时任务 / 查看任务与下次运行时间 |
| `update status` / `check` / `run` / `reset` | 自动更新：查状态、查新版、立即安装、清除失败记录 |
| `plugins list` / `plugins migrate` / `plugins <命令>` | 插件状态、插件迁移与插件命令 |

完整说明见 [命令行文档](docs/cli.md)。

### 备份

数据库用 `php bin/nomen backup` 导出，文件部分直接备份站点目录，注意排除缓存和临时文件。备份与恢复的完整流程见 [备份与恢复](docs/integrate/backup-restore.md)。

### 监控

站点健康检查在后台「站点健康」，也可以请求 `GET /api/admin/site-health`。日常检查项、日志位置和探针地址见 [日常运维](docs/ops/maintenance.md) 与 [日志与监控](docs/ops/logs.md)。

---

## 开发与扩展

Nomen 将功能与外观分开管理。你可以扩展系统功能，也可以更换整个界面，而不必直接修改核心代码。

### 插件

插件目录为 `plugins/<slug>/`，通过 `plugin.json` 描述插件。将插件放入 `plugins/`，并在 `config/plugins.php` 的 `enabled` 里写上名字即可。

随发布包的六个插件：

| 插件 | 默认启用 | 用途 |
|---|---|---|
| [`pages`](https://github.com/NomenID/plugin-pages) | 是 | 站内文档 |
| [`open-platform`](https://github.com/NomenID/plugin-open-platform) | 是 | 开发者开放平台 |
| [`geoip`](https://github.com/NomenID/plugin-geoip) | 是 | 按 IP 查国家与地点（需要自己装 geoip2 与 mmdb） |
| [`totp-qr`](https://github.com/NomenID/plugin-totp-qr) | 是 | 本地绘制两步验证二维码 |
| [`search`](https://github.com/NomenID/plugin-search) | 否 | 站内搜索 |
| [`api-code`](https://github.com/NomenID/plugin-api-code) | 否 | 标识符绑定 + HTTP 接口发码登录 |

另有两个不随包分发、按需安装的插件：`quick-central-login`（第一方应用免确认登录）和 `site-notice`（登录页提示条）。

插件开发：[plugins/README.md](plugins/README.md)

### 皮肤

皮肤目录为 `skins/<slug>/`，通过 `skin.json` 描述。皮肤可以覆盖任意模板，决定整个账号系统的外观。

官方皮肤独立发版，发布包自带这两个：

| 皮肤 | 说明 |
|---|---|
| [`nomen-2026`](https://github.com/NomenID/skin-nomen-2026) | 默认皮肤 |
| [`nomen-ssr`](https://github.com/NomenID/skin-nomen-ssr) | 纯 PHP 服务端渲染皮肤 |

皮肤开发：[skins/README.md](skins/README.md)

### 前端

前端源码在 `frontend/`，使用 Vue 3 和 Vite。构建产物在 `public/assets/dist/`，从发布包部署的站点不需要自己构建。

```bash
cd frontend
npm install
npm run build
```

### 测试

```bash
php tests/run.php
```

测试不需要 Composer 依赖，覆盖 TOTP、UNID、验证码和 OIDC 等核心逻辑。

---

## 项目结构

| 路径 | 内容 |
|---|---|
| `src/` | 核心类：路由、会话、数据库、邮件、i18n、缓存、活动日志 |
| `controllers/` | 请求处理 |
| `templates/` | PHP 模板 |
| `frontend/` | Vue 与 Vite 前端源码 |
| `public/` | Web 根目录 |
| `bin/nomen` | 命令行工具：安装、迁移、备份、插件命令 |
| `database/` | 数据库结构、迁移与种子数据 |
| `config/` | 应用、品牌、邮件、开放平台等配置 |
| `locales/` | 25 种语言的核心词条 |
| `plugins/` | 插件 |
| `skins/` | 皮肤 |
| `docs/` | 随包文档 |
| `tests/` | 零依赖测试 |
| `code-scripts/` | 构建与文档生成脚本（不进发布包） |
| `scripts/` | 后台脚本：通知邮件队列、会话索引回填 |

插件和皮肤的语言词条分别存放在各自的 `i18n/` 目录中。前端构建产物位于 `public/assets/dist/`。

---

## 文档

| 文档 | 内容 |
|---|---|
| [站内文档](https://nomenid.org/w) | 安装、配置、扩展开发与运维 |
| [docs/README.md](docs/README.md) | 随包文档的目录与阅读顺序 |
| [INSTALL.md](INSTALL.md) | 安装、部署与升级 |
| [命令行](docs/cli.md) | `php bin/nomen` 的全部命令 |
| [plugins/README.md](plugins/README.md) | 插件开发 |
| [skins/README.md](skins/README.md) | 皮肤开发 |
| [更新日志](https://nomenid.org/w/changelog) | 每个版本改了什么 |
| [GitHub Releases](https://github.com/NomenID/Nomen/releases) | 版本说明与下载 |
| [SECURITY.md](SECURITY.md) | 安全漏洞报告 |

## 反馈

问题、建议与文档纠错：<https://caellab.org/who-we-are/contact/>

发现安全漏洞，请发送邮件至 id@caellab.com，不要公开提交 GitHub Issue。

---

## 许可证

| 范围 | 许可证 |
|---|---|
| 代码、模板、配置与语言词条（核心仓库与发布包） | [GPL-3.0-only](LICENSE) |
| 文档与帮助文本（`docs/`、`README.md`、`INSTALL.md` 等） | [CZS v1](LICENSES/CZS-v1.txt) |
| 官方站 [nomenid.org](https://nomenid.org/) 的页面内容（另有声明者除外） | [CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/) |

软件以 GNU 通用公共许可证第 3 版授权，且仅限第 3 版（GPL-3.0-only），不适用「或任何更新版本」。完整文本见 [LICENSE](LICENSE)。

文档与帮助文本以 CaelLab Zero Subject License, Version 1（CZS v1）授权，全文见 [LICENSES/CZS-v1.txt](LICENSES/CZS-v1.txt)。官方站的站内文档（`/w`）同样按 CZS v1 授权。

代码、插件、皮肤、文档和语言词条由 NomenID Team 与其他贡献者共同完成；版权行署的是版权持有人，不是作者全名单。

另外，[NOTICE](NOTICE) 不是许可，也不附加任何条件——它只是一份建议：

- 保留版权声明、许可声明和作者署名：分发时 GPLv3 本来就要求，运行部署时只是建议。
- 保留 `/special/version` 版本页：它列出程序、PHP、数据库、依赖库、插件和皮肤的署名。插件和皮肤各自的许可证可能要求展示声明，这一条得自己核对。
- 未修改的 Nomen 可以继续叫 Nomen；改了代码的 fork 建议另起名字。名字和 logo 不在 GPLv3 授权范围内，NOTICE 里那段只是请求，不是商标许可。
- 分发时 GPLv3 全套重新生效：源码、版权/许可声明、修改标注。改名不代表分发，也不豁免 GPLv3。

页面上的「Powered by Nomen」可以在 `config/brand.php` 关掉，随你。

---

Copyright (C) 2025–2026 Yunyun (Wang Yaoze)
