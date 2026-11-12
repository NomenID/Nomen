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

## 配置改了什么时候生效

- `.env` 和 `config/*.php`：PHP 每次请求都会重新读，保存后刷新页面即可。开了 opcache 且关闭了
  时间戳校验的环境，需要 reload PHP-FPM。
- 后台「设置」页改的东西存在数据库里，保存后下一次请求生效。
- 改前端 `frontend/` 下的文件需要重新构建（`cd frontend && npm run build`）；从发布包部署的不用管，
  产物已经在 `public/assets/dist/` 里。

## 许可

`docs/` 下的文档与帮助文本以 [CaelLab Zero Subject License, Version 1](https://www.caellab.com/license/czs-v1)（CZS v1）授权。Nomen 软件本身按 GPL-3.0-only 授权；随包的插件和官方皮肤是独立项目，按 Apache-2.0 授权，各自的 `LICENSE` 在它们自己的仓库里。
