# 配置总览

Nomen 的配置分四层，优先级从高到低：

```
真实环境变量 / .env
config/installed.php        （安装器生成，站点主配置）
config/*.php                （程序默认值，通常只改 brand.php 和开关）
数据库 site_settings        （后台「设置」页改，只覆盖少数运行期开关）
```

某一项在 `.env` 里写了，就盖过 `installed.php` 和 `config/*.php` 里的值。没有环境变量时读
`installed.php`，再没有才用 `config/*.php` 里的默认值。

## `.env`

可选文件，放项目根。格式是 `KEY=value`，`#` 开头为注释，值两边的引号会被去掉。
`config/app.php` 每次请求都会读它，所以改完刷新页面就生效。

可用的键见仓库根的 [.env.example](../.env.example)，常用的有：

| 键 | 对应配置 |
|---|---|
| `APP_URL` | 站点地址，OAuth 回调、邮件链接都基于它 |
| `BRAND_NAME` / `BRAND_LOGO` / `BRAND_COPYRIGHT` | 站点名、图标、页脚版权 |
| `BRAND_PRIVACY_URL` / `BRAND_TERMS_URL` / `BRAND_DEVELOPER_GUIDELINES_URL` | 政策页链接 |
| `BRAND_SUPPORT_NAME` | 支持团队名称 |
| `SUPPORT_EMAIL` / `ADMIN_NOTIFY_EMAIL` | 对外支持邮箱 / 管理员通知收件人 |
| `SESSION_NAME` | 会话 cookie 名，多套 Nomen 共用一个域名时必须区分 |
| `DEFAULT_SKIN` | 默认皮肤 |
| `APP_LANGUAGE` | 默认语言 |
| `FEATURE_GUARDIANS` / `FEATURE_COUNTRY` | 可选功能开关（`1` / `0`） |
| `DB_*` / `REDIS_*` / `SMTP_*` | 数据库、Redis、邮件 |
| `APP_PEPPER` / `APP_SECRET` | 密码 pepper / 预留密钥 |
| `APP_DEBUG` | 调试模式，线上必须为 `0` |

## `config/installed.php`

安装器生成，结构如下（值以实际安装为准）：

```php
return [
    'db'     => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306',
                 'name' => 'nomen', 'user' => 'nomen', 'pass' => '...',
                 'path' => 'database/nomen.sqlite'],
    'redis'  => ['host' => '127.0.0.1', 'port' => 6379, 'db' => 1, 'password' => ''],
    'smtp'   => ['host' => '...', 'port' => 587, 'username' => '...', 'password' => '...',
                 'from_email' => '...', 'from_name' => '...', 'encryption' => 'tls'],
    'pepper' => '...',
    'secret' => '...',
    'app'    => [
        'name' => '站点名', 'base_url' => 'https://...',
        'logo' => '', 'copyright' => '',
        'support_email' => '', 'admin_notify_email' => '', 'support_name' => '',
        'privacy_url' => '', 'terms_url' => '', 'developer_guidelines_url' => '',
        'language_default' => 'zh-hans', 'language_allow_switch' => true,
        'features' => ['guardians' => false, 'country' => false],
    ],
];
```

`config/` 可写时，安装器直接把文件放好；不可写时给一份内容，自己下载后传上去。
**`pepper` 一旦有用户注册就不能改**，改了所有密码永久无法验证。

## `config/brand.php`

站点对外的全部信息。每一项都有对应的 `BRAND_*` / `SUPPORT_*` 环境变量，环境变量优先。
完整的键、默认值和用法见 [品牌与外观](branding.md)。

## `config/app.php`

程序行为参数。通常不用动，需要调限流、令牌有效期、恢复流程限制时才改。

| 键 | 默认值 | 说明 |
|---|---|---|
| `pepper` | `APP_PEPPER` | 参与密码哈希，上线后不可更改 |
| `secret_key` | `APP_SECRET` | 预留，当前没有代码使用 |
| `csrf_enabled` | `true` | 保持开启 |
| `session_lifetime` | `7200` | 会话有效期（秒） |
| `session_name` | `NOMEN_SESSION` | 会话 cookie 名 |
| `remember_me_ttl` | `2592000` | 「记住我」有效期（30 天） |
| `login_captcha_threshold` | `5` | 单 IP 每分钟登录尝试超过该值要求验证码 |
| `login_ip_hard_limit` | `20` | 单 IP 每分钟登录尝试硬上限 |
| `two_factor_pending_ttl` | `600` | 二验待验证会话有效期（秒） |
| `login_failed_method_ttl` | `900` | 登录页「本次已尝试」记忆时长（秒） |
| `support_email` | 品牌配置 | 对外支持邮箱 |
| `admin_notify_email` | 品牌配置 | 管理员通知收件人 |
| `recovery_ctx_ttl` | `7200` | 申诉页账号上下文有效期（秒） |
| `recovery_submit_limit` / `recovery_submit_window` | `5` / `3600` | 单 IP 申诉提交上限与窗口 |
| `recovery_token_ttl` | `604800` | 恢复登录链接有效期（7 天） |
| `recovery_attach_max_bytes` | `1048576` | 单张证明材料上限（1MB） |
| `recovery_attach_max_count` | `5` | 单次申诉最多附件数 |
| `recovery_attach_tmp_ttl` | `7200` | 未提交附件暂存时间（秒） |
| `recovery_upload_limit` / `recovery_upload_window` | `40` / `3600` | 单 IP 每小时上传次数 |
| `admin_notify_window` | `86400` | 「新恢复工单」提醒节流窗口（秒），默认每天最多一封 |
| `auth_code_ttl` | `600` | OAuth 授权码有效期（10 分钟） |
| `access_token_ttl` | `3600` | 访问令牌有效期（1 小时） |
| `refresh_token_ttl` | `15552000` | 刷新令牌有效期（180 天） |
| `token_hash_algorithm` | `sha512` | 令牌哈希算法，兼容旧记录 |
| `features` | 全 `false` | `guardians` / `country` 两个可选功能 |
| `language_default` | `zh-hans` | 默认语言，`APP_LANGUAGE` 优先 |
| `language_allow_switch` | `true` | 是否允许访客和用户切换语言 |
| `pretty_urls` | `true` | 站点能不能用 `/login` 这类短地址。`false` 时改用 `index.php?page=login`；**不写这一项按 `true`**，老站升级不受影响 |
| `db` / `redis` | 见上 | 数据库与 Redis 连接 |
| `scopes` | `openid` / `profile` / `email` | 对 OAuth 客户端开放的 scope 及说明。**改键会让现有客户端失效** |
| `webauthn_origin` / `webauthn_rp_name` | 品牌地址 / 品牌名 | 生物识别的来源与显示名 |

## 其他配置文件

| 文件 | 内容 |
|---|---|
| `config/email.php` | SMTP 连接，键为 `host` / `port` / `username` / `password` / `from_email` / `from_name` / `encryption` |
| `config/email-policy.php` | 拒绝注册的邮箱域名与后缀，见 [邮件与通知](email.md) |
| `config/consent-age.php` | 各国数字同意年龄，`default` 与 `ages` 两张表 |
| `config/consent-age-sources.php` | 上面每个年龄的法律来源，只作记录 |
| `config/identity-providers.php` | 第三方登录的预设提供方，见 [第三方登录](oauth-providers.md) |
| `config/plugins.php` | 启用的插件列表，`enabled` 数组 |
| `config/skins.php` | `default` 默认皮肤、`enabled` 可用皮肤 |
| `config/plugins/<slug>.json` | 插件挂载路径，后台「接入点」页可改 |
| `config/footer.php` | 自定义页脚，从 `footer.example.php` 复制后修改 |
| `config/home-*.php` | 自定义首页模板，从 `home.example.php` 复制后修改 |

## 后台即时设置

下面这些存在数据库 `site_settings` 表，后台改完下一次请求生效：

| 键 | 值 | 位置 |
|---|---|---|
| `security.login_2fa` | `off` / `new_device` / `every_login` | 设置 |
| `cache.backend` | `auto` / `redis` / `file` / `db` | 设置 |
| `api.nomen_api` | `1` / `0` | 设置 |
| `login.method_password` 等五个 | `1` / `0` | 登录方式 |
| `homepage.mode` | `default` / `page` / `template` | 首页 |
| `homepage.page_slug` | Pages 页面 slug | 首页 |
| `homepage.title` / `subtitle` | 首页标题、副标题 | 首页 |
| `homepage.primary_label` / `primary_url` | 主按钮 | 首页 |
| `homepage.secondary_label` / `secondary_url` | 次按钮 | 首页 |
| `homepage.template` | `config/home-*.php` 的文件名 | 首页 |
| `homepage.links_json` | 自定义链接 JSON 数组 | 首页 |
| `homepage.hidden_links` | 隐藏的默认链接 id 列表 | 首页 |

这些值不能通过 `.env` 覆盖，只能走后台或直接改库。全关到进不去后台时用
`php bin/nomen auth:reset` 恢复登录方式，见 [命令行](cli.md)。

## 可选功能

`guardians`（家庭与未成年监护）和 `country`（注册时填国家）默认关闭。开关只决定功能开不开，
不改变启用后的判定结果。打开方式：`config/installed.php` 的 `app.features`，或环境变量
`FEATURE_GUARDIANS=1` / `FEATURE_COUNTRY=1`。安装向导的「扩展」一步也能勾。

细节见 [家庭与监护](family.md)。
