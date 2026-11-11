# 插件开发

一个插件是一个目录 `plugins/<slug>/`，里面必须有 `plugin.json`。装插件不用改核心代码：
放进 `plugins/`，再写进 `config/plugins.php` 的 `enabled` 列表。

官方的四个插件（`pages`、`open-platform`、`geoip`、`totp-qr`）是独立仓库，主仓 `plugins/` 下只有 README，
插件自己的代码和许可都在各自的仓库里（Apache-2.0，和核心的 GPL-3.0-only 无关）。
本地开发时把仓库 clone 到核心仓外面，由不进 git 的 `paths.local.php` 指过去；没有这个文件就回落 `plugins/`。
打包时 `code-scripts/build-dist.php` 只打清单里的插件，本地多 clone 的不会进包。

## 清单 `plugin.json`

```json
{
    "name": "Example",
    "version": "0.1.0",
    "description": "一句话说明",
    "license": "Apache-2.0",
    "author": ["Your Name"],
    "requires": {
        "Nomen": ">=1.0",
        "php": ["gd", "fileinfo"]
    },
    "autoload": {
        "Nomen\\Plugin\\Example\\": "src/"
    },
    "boot": ["Nomen\\Plugin\\Example\\Plugin", "boot"],
    "hooks": {
        "user.login.succeeded": [["Nomen\\Plugin\\Example\\Hooks", "onLogin"]],
        "userinfo.claims": [
            {"handler": ["Nomen\\Plugin\\Example\\Hooks", "addClaims"], "priority": 5}
        ]
    },
    "specialPages": {
        "example": {"title": "示例状态", "access": "admin", "handler": ["...", "render"]}
    },
    "adminPages": {
        "example": {"title": "示例", "handler": ["...", "render"], "menu": "示例", "order": 35}
    },
    "mounts": {
        "portal": {"path": "/example", "title": "示例入口", "access": "public", "config": "base_path"}
    }
}
```

| 字段 | 说明 |
|---|---|
| `name` / `version` / `description` / `license` / `author` | 展示用，版本页会列出来 |
| `requires.Nomen` | 版本约束，不满足就跳过并记 `error_log` |
| `requires.php` | 需要的 PHP 扩展，缺一个就跳过 |
| `autoload` | PSR-4 前缀 → 目录 |
| `boot` | 加载时调一次，用来注册路由、后台页面、命令 |
| `hooks` | 事件名 → 回调，可带 `priority` |
| `specialPages` | `/special/<slug>` 页面 |
| `adminPages` | `/admin/<slug>` 页面 |
| `mounts` | 公开挂载点，`config` 指向插件配置里的路径键 |

`plugin.json` 的 `description`、`specialPages.*.title`、`adminPages.*.title` 都是词典键，
写英文原句，译文放插件自己的 `i18n/<lang>.json`。

## 配置

放 `config/plugins/<slug>.json`（或 `.ini`），不要写进插件目录。升级插件不覆盖站长设置。

```php
$config = Plugins::config('pages', ['base_path' => '/w']);
Plugins::setConfig('pages', ['base_path' => '/docs']);
```

## 路由

在 `boot` 里注册。路径支持 `{name}` 和 `{path:.*}`，中间件现有 `auth` 和 `admin`。

```php
Plugins::route('GET',  $base, [Controller::class, 'index']);
Plugins::route('POST', $base . '/{slug}/edit', [Controller::class, 'update'], ['auth', 'admin']);
```

插件路由先于核心注册，同名的精确路径会被核心覆盖。不要用宽通配路由去截 `/login`、`/oauth/*`。

## 页面与模板

- 插件页面：`Response::view('@<slug>/<name>', $vars)`。
- 插件布局：`Response::view('@<slug>/<layout>', ...)`。
- 解析顺序：当前皮肤 → 默认皮肤 → 插件自己的 `templates/`。
- 后台页面用 `Admin::page()` 注册，返回 HTML 字符串，核心套进 SPA 面板。

## 静态资源

放插件 `assets/`，通过 `/plugin-assets/<slug>/<path>` 发出，只发白名单类型。

```php
Plugins::style('example', 'css/app.css');
Plugins::script('example', 'js/app.js');
```

URL 自动带文件修改时间做版本。nginx 需要给 `/plugin-assets/` 单独一段
`location ^~ /plugin-assets/ { try_files $uri /index.php$is_args$args; }`，
否则带 `.*\.(js|css)$` 的规则会把它抢走，表现为页面能开、样式全崩。

## 数据库迁移

放 `plugins/<slug>/database/migrations/*.sql`，跑：

```bash
php bin/nomen plugins migrate
```

记录在 `plugin_migrations` 表。插件不许 ALTER 核心表；要存自己的数据就建自己的表。

## 命令

```php
Plugins::command('example:sync', [Sync::class, 'run'], '同步示例数据');
```

```bash
php bin/nomen plugins list
php bin/nomen plugins example:sync
```

## 站点 API

在 `boot` 里注册模块，权限只有 `public` / `admin`：

```php
Api::action('echo', fn(array $p): array => ['echo' => $p], 'public', '原样返回参数');
Api::meta('foo', [Meta::class, 'build'], 'public', '站点信息扩展');
Api::list('things', [ListModule::class, 'all'], 'public', '列出 things');
Api::prop('thing', [PropModule::class, 'fields'], 'public', 'things 的字段');
```

## 缓存驱动

```php
Cache::registerDriver('example', fn() => new ExampleDriver());
```

注册后后台「设置」页的缓存选项里会出现。驱动要实现 `Nomen\Src\Cache\Driver`。

## 钩子

### 用户与认证

| 钩子 | 类型 | 参数 | 时机 |
|---|---|---|---|
| `user.registered` | run | `userId`, `username`, `email` | 注册成功、邮箱记录同步之后 |
| `user.login.succeeded` | run | `userId`, `username` | 会话建立之后 |
| `user.logout` | run | `userId`, `username` | 清会话之前 |
| `userinfo.claims` | filter | `claims`, `user`, `scopes` | `/api/userinfo` 组装完 claims |
| `oauth.authorize.quick` | filter | `null`, `userId`, `client`, `scopes` | 授权请求判定阶段；返回非空字符串 = 免确认直接发码，回调带 `spicallogin=<返回值>` |
| `oauth.authorize.approved` | run | `userId`, `clientId`, `scope`, `silent` | 授权码发出之后；`silent` 表示没经过确认页 |
| `oauth.token.issued` | run | `userId`, `clientId`, `scopes`, `grantType` | 发出 access token 之后 |
| `oauth.token.revoked` | run | `tokenTypeHint` | 撤销端点处理完 |
| `email.sending` | filter | `payload`（`to` / `subject` / `body`）, `embedded` | 投递 SMTP 之前 |
| `notification.created` | run | `userId`, `category`, `title`, `body`, `notificationId` | 站内通知入库后、发邮件前 |

### 布局与导航

| 钩子 | 类型 | 参数 | 时机 |
|---|---|---|---|
| `viewer.props` | filter | `viewer`, `userId`, `user` | SPA 的 viewer 数据组装完 |
| `nav.items` | filter | `items`, `userId` | 站点导航组装完 |
| `layout.head` | filter | `html`, `layout` | `</head>` 前 |
| `layout.body.start` | filter | `html`, `layout` | `<body>` 后 |
| `layout.body.end` | filter | `html`, `layout` | `</body>` 前 |
| `homepage.links` | filter | `links` | 首页底部链接组 |
| `homepage.sections` | filter | `sections`, `home` | 默认首页正文区块 |
| `footer.links` | filter | `links`, `brand`, `layout` | 页脚链接数组 |
| `footer.html` | filter | `html`, `layout`, `brand`, `links` | 页脚最终 HTML |

### 管理后台

| 钩子 | 类型 | 参数 | 时机 |
|---|---|---|---|
| `admin.menu.items` | filter | `items` | 后台侧栏菜单 |
| `admin.shell.props` | filter | `props`, `adminId` | 后台 SPA 首屏数据 |
| `admin.dashboard.cards` | filter | `cards`, `stats` | 概览页统计卡 |
| `admin.dashboard.site` | filter | `site` | 概览页站点信息块 |
| `admin.users.rows` | filter | `rows` | 用户列表返回前 |
| `admin.user.detail` | filter | `payload`, `userId`, `user`, `admin` | 用户详情返回前 |
| `admin.user.status_changed` | run | `userId`, `status`, `adminId` | 启用/禁用账户之后 |
| `admin.user.role_changed` | run | `userId`, `role`, `adminId` | 授予/撤销管理员之后 |
| `admin.apps.list` / `admin.apps.detail` | filter | `apps` / `app`, `clientId` | 应用列表 / 详情 |
| `admin.mounts.list` | filter | `mounts` | 挂载点列表 |
| `admin.logs.rows` | filter | `rows` | 活动日志列表 |
| `admin.recovery.detail` | filter | `payload`, `request`, `user` | 恢复工单详情 |
| `admin.settings.data` | filter | `payload` | 站点设置页数据 |
| `admin.settings.saved` | run | `policy`, `backend`, `adminId`, `apiEnabled` | 站点设置保存后 |
| `admin.plugin.page.body` | filter | `html`, `slug`, `page`, `tab` | 插件后台页输出前 |

### 内容（Pages 插件）

| 钩子 | 类型 | 参数 | 时机 |
|---|---|---|---|
| `pages.content.before_save` | filter | `data`, `existing`, `isNew` | 写库前，可改字段 |
| `pages.content.saved` | run | `pageId`, `data`, `isNew`, `userId` | 保存后 |
| `pages.content.deleted` | run | `pageId`, `page`, `userId` | 删除前 |
| `api.siteinfo` | filter | `info`, `siprops`, `params` | siteinfo 组装完 |

钩子的注册点和参数以 `plugins/README.md` 与站内 `/w/hooks` 为准，那边会跟着核心一起更新。
