# Nomen 文档

这里写给部署和维护 Nomen 的人看：怎么装、每一项配置放在哪、各个功能怎么用。

文档随发布包分发，放在部署根目录的 `docs/`，不在 `public/` 里，所以不会通过网址对外提供。

## 从哪看起

刚拿到代码：先看 [安装与升级](install.md)，装完再按 [配置总览](config.md) 改站点信息。

已经在跑、要找某个功能：下表按功能列。

## 目录

| 文档 | 内容 |
|---|---|
| [安装与升级](install.md) | 环境要求、网页向导、命令行安装、目录权限、升级步骤 |
| [配置总览](config.md) | `.env`、`config/*.php`、`installed.php`、后台即时设置的优先级和全部配置项 |
| [品牌与外观](branding.md) | 站点名、图标、版权、法律链接、页脚、首页 |
| [账号与登录](accounts.md) | 注册、五种登录方式、会话、设备管理、邮箱管理 |
| [两步验证](two-factor.md) | 站点策略、用户策略、TOTP、恢复码 |
| [账号恢复](recovery.md) | 申诉流程、管理员审核、一次性恢复链接、强制安全修复 |
| [家庭与监护](family.md) | 未成年账号、家长同意、家长邮箱、家庭页 |
| [第三方登录](oauth-providers.md) | GitHub / Google / Microsoft / CaelLabID 与自定义 OAuth2 提供方 |
| [开放平台](open-platform.md) | 开发者注册、自助创建 OAuth 应用、回调地址与 scope |
| [OpenID Connect](oidc.md) | 发现文档、JWKS、id_token 与接入方要注意的事 |
| [站内页面](pages.md) | Pages 插件：在站内写文档、Markdown 编辑器、挂载路径 |
| [搜索](search.md) | 搜索插件：默认查数据库，也可接 Meilisearch / OpenSearch / Elasticsearch |
| [站点 API](api.md) | `/nomen-api` 的只读接口与权限 |
| [插件开发](plugins.md) | 插件清单、钩子、路由、后台页面、资源、迁移、命令 |
| [皮肤开发](skins.md) | 皮肤结构、模板覆盖顺序、静态资源、品牌变量 |
| [多语言](i18n.md) | 语言判定顺序、切换入口、新增语言、词典规则 |
| [邮件与通知](email.md) | SMTP、邮件模板、站内通知分类、发信队列、拒收域名 |
| [安全策略](security.md) | pepper、调试开关、验证码与限流、OAuth 令牌、响应头 |
| [缓存](cache.md) | Redis / 文件 / 数据库三种后端、回退顺序、插件自定义驱动 |
| [管理后台](admin.md) | 后台每个页面的作用与操作 |
| [GeoIP](geoip.md) | 按 IP 查国家与地点，注册页预填、活动日志显示 |
| [命令行](cli.md) | `php bin/nomen` 的全部命令与常见用法 |

## 参考手册

下面四组是按代码生成的，比上面的功能文档更细：配置每一项、钩子每一个、模板变量、表字段。改完代码跑
`php code-scripts/docgen.php` 重新生成；`--check` 会在 CI 里核对，手改会被覆盖。

| 手册 | 内容 |
|---|---|
| [配置参考](config/index.md) | 每个 `config/*.php` 一页：键、环境变量、默认值、说明 |
| [钩子参考](hooks/index.md) | 按域分组，每个钩子的类型、参数、时机和触发文件 |
| [模板参考](templates/index.md) | 核心模板的变量表，按登录 / 恢复 / 外壳 / 首页 / 片段分组 |
| [数据结构](database/index.md) | 27 张表按域分组，字段、类型、默认值、索引 |

## 扩展开发

写插件和皮肤从这里开始，两边都有可 clone 的骨架。

| 文档 | 内容 |
|---|---|
| [写一个插件](plugin-dev/index.md) | 从骨架起步：目录、清单、第一次改动、调试 |
| [清单字段](plugin-dev/manifest.md) | `plugin.json` 每个字段，含 hooks / 特殊页 / 后台页 / 挂载点 |
| [钩子](plugin-dev/hooks.md) | `run` 与 `filter` 的区别、优先级、返回值限制、常用示例 |
| [页面与路由](plugin-dev/pages-and-routes.md) | 特殊页、挂载点、路由参数、中间件 |
| [后台页面](plugin-dev/admin-pages.md) | 后台页注册、标签页、表单提交、`a-*` 样式 |
| [模板与资源](plugin-dev/templates-and-assets.md) | `@<slug>` 模板、皮肤覆盖、`/plugin-assets/` |
| [迁移与命令](plugin-dev/migrations.md) | 插件建表、迁移文件命名、CLI 命令 |
| [站点 API](plugin-dev/api.md) | 给 `/nomen-api` 加 `action` / `list` / `prop` / `meta` 模块 |
| [多语言](plugin-dev/i18n.md) | 英文母键、`i18n/` 词典、`extract.php --check` 的口径 |
| [发布插件](plugin-dev/release.md) | 版本号、README 要求、随包分发 |
| [写一个皮肤](skin-dev/index.md) | 从骨架起步：三个布局、第一次改动、验证清单 |
| [布局](skin-dev/layouts.md) | `default` / `app` / `admin-app` 的职责与必备调用 |
| [模板覆盖](skin-dev/templates.md) | 覆盖顺序、变量来源、首页三种模式 |
| [静态资源](skin-dev/assets.md) | CSS 变量表、深浅色、RTL、`?v=` 规则 |
| [发布皮肤](skin-dev/release.md) | 启用规则、发版前清单、核心升级后要复查什么 |

## 架构与集成

想知道「请求进来之后发生了什么」「接 Redis / Meili / SMTP / 反代怎么配」看这组。

| 文档 | 内容 |
|---|---|
| [一次请求走过的路](internals/lifecycle.md) | 十四个阶段、两个全局闸、路由顺序、渲染链 |
| [数据模型](internals/data-model.md) | 27 张表的关系、UNID 的取舍、哪些必须备份、两种数据库的差异 |
| [权限模型](internals/permissions.md) | 角色、中间件、scope、插件必须自己判什么 |
| [错误处理](internals/error-handling.md) | 报错页、500 编号、`APP_DEBUG`、日志前缀 |
| [兼容与升级](internals/compatibility.md) | 版本号两档、升级流程、什么算破坏性改动、无伪静态模式 |
| [把 Nomen 当身份提供方接入](integrate/oidc-client.md) | OIDC / OAuth 端点、PKCE、令牌、`sub`、错误码 |
| [接入外部身份提供方](integrate/oauth-provider.md) | 后台配置、字段映射、已验证邮箱才合并 |
| [邮件（SMTP）](integrate/smtp.md) | 配置、SPF/DKIM/DMARC、常见报错 |
| [搜索后端](integrate/search-backends.md) | Meilisearch / OpenSearch / ES 配置、建索引、重建窗口期 |
| [反向代理与 CDN](integrate/reverse-proxy.md) | nginx 必需片段、真实 IP、缓存、子路径 |
| [备份与恢复](integrate/backup-restore.md) | 库和文件分别备什么、恢复顺序、演练方法 |

## 官方扩展

| 文档 | 内容 |
|---|---|
| [官方插件](plugins/index.md) | 七个插件各干什么、哪些随包、默认是否启用 |
| [Pages](plugins/pages.md) | 站内文档：目录、侧边栏、Markdown 导入 |
| [Open Platform](plugins/open-platform.md) | 开发者门户、应用自助管理 |
| [GeoIP](plugins/geoip.md) | 依赖要自己装、mmdb 放哪、CDN 下的真实 IP |
| [TOTP QR](plugins/totp-qr.md) | 本地画二维码、降级行为 |
| [Search](plugins/search.md) | 站内搜索、引擎切换 |
| [Quick Central Login](plugins/quick-central-login.md) | 第一方免确认、`spicallogin` |
| [Site Notice](plugins/site-notice.md) | 登录页提示条（不随包） |
| [官方皮肤](skins/index.md) | 两个皮肤的定位和启用规则 |
| [nomen-2026](skins/nomen-2026.md) | 默认皮肤：星野、卡片式认证页 |
| [nomen-ssr](skins/nomen-ssr.md) | 轻量皮肤：无星图、文字优先 |

## 运维

| 文档 | 内容 |
|---|---|
| [故障排查](ops/troubleshooting.md) | 按现象查：打不开、500、404、插件样式、登录、邮件、慢 |
| [性能与容量](ops/performance.md) | opcache、Redis、索引、磁盘随机读、内存怎么看、规模参考 |
| [日常运维](ops/maintenance.md) | 每天 / 每周 / 每月 / 每季各查什么 |
| [日志与监控](ops/logs.md) | 日志在哪、前缀约定、怎么 grep、探针打哪个地址 |
| [升级与发布检查清单](ops/upgrade-checklist.md) | 发布前、上传前、上传顺序、部署后验收、回滚 |

## 配置改了什么时候生效

- `.env` 和 `config/*.php`：PHP 每次请求都会重新读，保存后刷新页面即可。开了 opcache 且关闭了
  时间戳校验的环境，需要 reload PHP-FPM。
- 后台「设置」页改的东西存在数据库里，保存后下一次请求生效。
- 改前端 `frontend/` 下的文件需要重新构建（`cd frontend && npm run build`）；从发布包部署的不用管，
  产物已经在 `public/assets/dist/` 里。

## 许可

`docs/` 下的文档与帮助文本以 [CaelLab Zero Subject License, Version 1](https://www.caellab.com/license/czs-v1)（CZS v1）授权。Nomen 软件本身按 GPL-3.0-only 授权；随包的插件和官方皮肤是独立项目，按 Apache-2.0 授权，各自的 `LICENSE` 在它们自己的仓库里。
