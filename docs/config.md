# 配置总览

配置分三层，优先级从高到低：

```
.env  →  config/installed.php  →  config/*.php 里的字面量
```

`config/app.php` 这类文件先读 `.env`，没有再读 `installed.php`，都没有才用文件里写的默认值。
后台「设置」页改的东西进数据库 `site_settings`，优先级**高于**前三层（它是运行时覆盖）。

## 三处分别管什么

| 位置 | 谁改 | 进不进 git |
|---|---|---|
| `.env` | 运维，手工 | 不进（`.gitignore` 挡着） |
| `config/installed.php` | 安装器生成，之后手工改 | 不进（部署时排除） |
| `config/*.php` | 开发者 / 站长改默认值 | 进 |
| 后台「设置」 | 站长 | 进数据库 |

**升级时排除 `config/installed.php` 和 `.env`**，否则等于重新装一遍。

可选配置文件（`genders.php`、`countries.php`、`consent-age*.php`、`email-policy.php`、
`identity-providers.php`、`plugins.php`、`skins.php`、`brand.php`）**不在时用内置默认值**，
不会报错；`config/app.php`（含数据库连接）和 `installed.php` 仍是必需的。

## .env

```env
APP_URL=https://id.example.com
APP_PEPPER=<64 位随机>
APP_DEBUG=0
APP_LANGUAGE=zh-hans

DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=nomen
DB_USER=nomen
DB_PASS=<密码>
# SQLite 用这一行，driver 写 sqlite
# DB_PATH=database/nomen.sqlite

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_DB=0
REDIS_PASSWORD=

BRAND_NAME=Example
BRAND_LOGO=/assets/brand/icon.svg
BRAND_COPYRIGHT=© 2026 Example
BRAND_PRIVACY_URL=https://example.com/privacy
BRAND_SUPPORT_NAME=Support
BRAND_SUPPORT_EMAIL=support@example.com

SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USERNAME=noreply@example.com
SMTP_PASSWORD=<密码>
SMTP_FROM_EMAIL=noreply@example.com
SMTP_FROM_NAME=Example
SMTP_ENCRYPTION=tls

DEFAULT_SKIN=nomen-2026
FEATURE_GUARDIANS=0
FEATURE_COUNTRY=0
```

**`.env` 是 CRLF 也没关系**（解析时会 trim），但值里不要带引号以外的空格。

## installed.php

安装器写的，长这样：

```php
<?php
return [
    'db'    => ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
                'name' => 'nomen', 'user' => 'nomen', 'pass' => '...'],
    'redis' => ['host' => '127.0.0.1', 'port' => 6379, 'db' => 0, 'password' => ''],
    'app'   => [
        'name'             => 'Example',
        'base_url'         => 'https://id.example.com',
        'language_default' => 'zh-hans',
        'language_allow_switch' => true,
        'pretty_urls'      => true,
        'logo'             => '/assets/brand/icon.svg',
        'copyright'        => '© 2026 Example',
        'features'         => ['guardians' => false, 'country' => false],
    ],
    'pepper' => '<64 位随机>',
    'secret' => '<随机>',
    'smtp'   => ['host' => '', 'port' => 587, 'username' => '', 'password' => '',
                 'from_email' => '', 'from_name' => '', 'encryption' => 'tls'],
];
```

几个容易忽略的键：

| 键 | 说明 |
|---|---|
| `app.base_url` | canonical、OAuth `redirect_uri`、邮件链接全用它，**改域名必须改这里** |
| `app.pretty_urls` | `false` 走 `index.php?page=`，没配伪静态就设它 |
| `app.features` | `guardians` / `country` 两个可选功能，默认关 |
| `pepper` | 参与密码哈希，**有用户后绝不能改** |
| `redis.db` | 同一个 Redis 上跑多个程序时，每个站分配不同的库 |

## config/*.php

每个文件一页的逐项说明在 [配置参考](config/index.md)。这里只说怎么改：

```php
// config/brand.php：改默认值
'name' => getenv('BRAND_NAME') ?: ($app['name'] ?? 'Nomen'),
```

改这里的字面量只影响「前两层都没有」的情况。已经在后台设置过的值不会回头覆盖。

## 后台设置

后台 →「设置」页，写进 `site_settings`：

| 键 | 说明 |
|---|---|
| `security.login_2fa` | 站点范围的两步验证策略 |
| `cache.backend` | 缓存后端 |
| `api.nomen_api` | 站点 API 总开关 |
| `homepage.*` | 首页模式、标题、按钮、链接 |
| `login.method_*` | 五种登录方式的开关 |

后台改动**立刻生效**（下一次请求就读库），不用 reload PHP。

## 改完什么时候生效

| 改了什么 | 生效方式 |
|---|---|
| `.env` / `config/*.php` | 下一次请求。开了 opcache 且关时间戳校验的要 reload PHP-FPM |
| 后台设置 | 下次请求 |
| 前端 `frontend/` | 重新构建（`npm run build`）；从发布包部署的不用管 |
| 皮肤 / 插件 | 下次请求 |
| nginx | `nginx -t` 过了再 `reload` |

## 验证配置生效

```bash
# 站点地址和版本
curl -s https://你的站点/api/version

# 实际在跑的版本（含快速构建标记）
curl -s 'https://你的站点/api/version?action=thissite'

# 版本页会显示站点名、皮肤、语言
curl -s https://你的站点/special/version | grep -o '<title>[^<]*'
```

配错了导致站点起不来时，把 `APP_DEBUG=1` 临时打开（**验完立刻关**），能看到具体哪个文件哪一行。

## 坑

- **改了 `config/app.php` 却没生效**：`.env` 或 `installed.php` 里有同名键，优先级更高。
- **换域名只改了 nginx**：`base_url` 没改，登录回调、邮件链接全是旧域名。
- **用 `config('app.pepper')` 取不到**：配置文件是整份 `require` 进来的，取键用 `$config['pepper']`。
- **`installed.php` 被升级包覆盖**：等于重新装一遍，库连不上、pepper 也变了。

## 检查清单

- [ ] `.env` 和 `installed.php` 都在备份里
- [ ] `base_url` 和实际地址一致（含 https）
- [ ] `APP_PEPPER` 记在安全的地方
- [ ] `APP_DEBUG` 线上是关的
- [ ] 改完配置用 `/api/version?action=thissite` 验过
