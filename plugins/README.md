# 插件

一个插件是一个目录 `plugins/<slug>/`，里面必须有 `plugin.json`。装插件不用改核心代码：放进 `plugins/`，再写进 `config/plugins.php` 的 `enabled` 列表。

```json
{
    "name": "Open Platform",
    "version": "0.1.0",
    "description": "开发者门户",
    "license": "GPL-3.0-only",
    "author": ["Yunyun (Wang Yaoze)"],
    "requires": {
        "Nomen": ">=0.1",
        "php": ["gd", "fileinfo"]
    },
    "autoload": {
        "Nomen\\Plugin\\OpenPlatform\\": "src/"
    },
    "boot": ["Nomen\\Plugin\\OpenPlatform\\Plugin", "boot"],
    "hooks": {
        "user.login.succeeded": [["Nomen\\Plugin\\Example\\Hooks", "onLogin"]],
        "userinfo.claims": [
            {"handler": ["Nomen\\Plugin\\Example\\Hooks", "addClaims"], "priority": 5}
        ]
    },
    "specialPages": {
        "example": {
            "title": "示例状态",
            "access": "admin",
            "handler": ["Nomen\\Plugin\\Example\\SpecialExample", "render"]
        }
    },
    "adminPages": {
        "example": {
            "title": "示例",
            "description": "插件自己的后台页面",
            "handler": ["Nomen\\Plugin\\Example\\AdminPage", "render"],
            "menu": "示例",
            "order": 35
        }
    },
    "mounts": {
        "portal": {
            "path": "/example",
            "title": "示例入口",
            "description": "公开访问的挂载点",
            "access": "public",
            "config": "base_path"
        }
    }
}
```

| 字段 | 说明 |
|---|---|
| `name` / `version` / `description` / `license` / `author` | 展示和排查用，`Special:Version` 会列出来 |
| `requires.Nomen` | 版本约束，比如 `">=0.1"`；不满足就跳过并在 `error_log` 里记一条 |
| `requires.php` | 需要的 PHP 扩展；缺一个就跳过 |
| `autoload` | PSR-4 前缀 → 目录，相对插件根 |
| `boot` | 加载时调一次的回调，用来注册路由、后台页面、命令 |
| `hooks` | 事件名 → 回调；可以写一个回调，也可以写一组，带 `priority` 的对象形式 |
| `specialPages` | `/special/<slug>` 页面；`access` 是 `public` 或 `admin` |
| `adminPages` | 后台页面；`menu` 为 `false` 时不进菜单，写字符串则用这个文字 |
| `mounts` | 公开挂载点；`config` 指向插件配置里的路径键，后台可以改 |

## 配置

插件配置放 `config/plugins/<slug>.json`（也支持 `.ini`），不要写进插件目录。升级插件时不会覆盖站长的设置。

```php
$config = \Nomen\Src\Plugins::config('pages', ['base_path' => '/w']);
```

读取时会合并到默认值上；文件不存在就用默认值。要写回（后台改挂载点这类）：

```php
\Nomen\Src\Plugins::setConfig('pages', ['base_path' => '/docs']);
```

## 路由

在 `boot` 里注册。路径支持 `{name}`（匹配一段）和 `{path:.*}`（匹配多段）；第三个参数是中间件，现有 `auth` 和 `admin`。

```php
public static function boot(): void
{
    $base = self::basePath();

    Plugins::route('GET', $base, [Controller::class, 'index']);
    Plugins::route('GET', $base . '/{slug}', [Controller::class, 'show']);
    Plugins::route('POST', $base . '/{slug}/edit', [Controller::class, 'update'], ['auth', 'admin']);
    Plugins::route('GET', $base . '/assets/{path:.*}', [Controller::class, 'asset']);
}
```

插件路由在核心路由之前注册，同名的精确路径会被核心覆盖。插件盖不掉 `/login`、`/oauth/*` 这些入口；也不要用一个很宽的通配路由去截核心的地址。

未验证邮箱和恢复模式这两个全局闸在路由之前执行，插件页面不在它们的白名单里。插件自己需要的 POST 接口，在恢复模式下仍然可达，需要的话自己检查 `RecoveryHelper::isActiveFor($userId)`。

## 后台页面

后台外壳是 Vue SPA，插件页面仍由服务端返回 HTML，核心把 HTML 放进面板里渲染。`Admin::page()` 或清单里的 `adminPages` 注册，地址是 `/admin/<slug>`。

```php
\Nomen\Src\Admin::page('content', [
    'title'       => '内容',
    'description' => '站点文档页面',
    'menu'        => '内容',   // false = 不进菜单；字符串 = 菜单文字
    'group'       => '内容',   // 侧栏分组
    'order'       => 15,
    'tabs'        => [
        ['id' => 'all', 'label' => '全部', 'handler' => static fn(): string => '...'],
    ],
    // 没有 tabs 时用 handler
    'handler'     => null,
]);
```

面板里的约定：

- 表单加 `data-admin-ajax`，提交由外层接管（POST + 自动刷新面板）；再加 `data-admin-confirm="..."` 会先弹确认。
- 指向 `/admin/...` 的链接会被 SPA 接管；外部链接加 `target="_blank"`。
- 表单里带 `_return=admin`，动作完成后可以跳回后台页面（插件自己的控制器里判断）。
- 样式直接用 `a-*` 类（`a-card` / `a-table` / `a-btn` / `a-input` / `a-field` / `a-tabs` ...），不用另写一套。
- 需要额外 CSS / JS 用 `Admin::style()` / `Admin::script()`。

后台菜单由注册表统一渲染，插件不用改皮肤里的布局。`Admin::menu()` 只登记菜单项，不注册页面，给核心页面用；插件一般用 `Admin::page()`。

## 挂载点

挂载点是公开入口的声明，不是路由本身。`mounts` 里的 `path` 是默认值，`config` 指向插件配置里的键；后台「接入点」页会列出来，能改路径的直接写回 `config/plugins/<slug>.json`。

```php
$base = \Nomen\Src\Plugins::mountUrl('pages'); // '/w'，没装返回 null
```

真正的路由还是用 `Plugins::route()` 注册。路径变了以后，路由要用 `Plugins::config()` 重新算，别写死。

## 模板与静态资源

插件模板放 `plugins/<slug>/templates/`，用 `@<slug>/<name>` 渲染：

```php
Response::view('@open-platform/landing', $data, 'default');
```

皮肤可以在 `skins/<skin>/templates/@<slug>/<name>.php` 覆盖插件模板。插件也可以自带宽布局：`layouts/<name>.php`，渲染时第三参数写 `'@<slug>/<name>'`；皮肤同样能在 `skins/<skin>/layouts/@<slug>/<name>.php` 覆盖。

静态资源放 `plugins/<slug>/assets/`：

```php
Plugins::style('open-platform', 'portal.css');
Plugins::script('open-platform', 'portal.js');
```

实际地址由 `Plugins::assetUrl()` 生成，带文件修改时间做缓存版本；核心布局会把它们输出到页面里。也可以只在某个页面前登记，避免全站加载。`/plugin-assets/<slug>/<path>` 只发白名单类型（css、js、图片、字体、json），路径不能跳出 `assets/`。

设置页的 TOTP 二维码交给插件补：核心调 `window.NomenQr.render(uri)` 拿 `{ size, path }` 画 `<svg>`，没有这个全局就只显示密钥、让用户手动输入。实现参考 `plugins/totp-qr/assets/qr.js`。

## 迁移

插件自己的表放 `plugins/<slug>/database/migrations/`：

```
plugins/example/database/migrations/20261107_create_example.mysql.sql
plugins/example/database/migrations/20261107_create_example.sqlite.sql
```

跑：

```bash
php bin/nomen plugins migrate
php bin/nomen plugins migrate example
```

跑过的文件记在 `plugin_migrations` 表。文件名排序执行；只往前加，已经发布的文件不要改。插件**不要 ALTER 核心表**，要加字段就用自己的表，或者用钩子把数据写到核心已有的服务里。

## CLI 命令

插件可以注册后台命令：

```php
Plugins::command('example:sync', [Sync::class, 'run'], '把示例数据补进库');
```

```bash
php bin/nomen plugins list          # 看插件和命令
php bin/nomen plugins example:sync  # 跑命令
```

命令处理函数返回字符串会打印出来，返回整数会打印「完成（N）」。

## 缓存

核心缓存有 `redis`、`file`、`db` 三种内置驱动，站长在后台「设置」里选；选中的驱动不可用时会自动回退到文件。插件可以注册自己的驱动：

```php
use Nomen\Src\Cache;

Cache::registerDriver('memcached', static fn() => new MyMemcachedDriver());
```

驱动实现 `Nomen\Src\Cache\Driver`（`get` / `put` / `forget`，未命中返回 `null`）。注册的名字会出现在后台的下拉框里。

## 钩子

```php
use Nomen\Src\Hooks;

Hooks::add('user.login.succeeded', [Hooks::class, 'onLogin'], 10); // 数字越小越先跑
Hooks::run('user.login.succeeded', [$userId, $username]);

$claims = Hooks::filter('userinfo.claims', $claims, $user, $scopes);
```

- `run`：按优先级调用；回调返回 `false` 会停掉后面的回调。`abortable: false` 的事件里返回 `false` 会抛异常。
- `filter`：回调拿到当前值，返回非 `null` 就替换它。
- 钩子是在动作之后通知用的，不要把密码、二步验证、恢复审核这些安全判断做成钩子结果。

核心现在会发的事件：

| 事件 | 类型 | 参数 | 时机 |
|---|---|---|---|
| `user.registered` | run | `userId`, `username`, `email`（可能为 null） | 注册成功、邮箱记录同步之后 |
| `user.login.succeeded` | run | `userId`, `username` | 会话建立之后 |
| `user.logout` | run | `userId`, `username` | 清会话之前 |
| `userinfo.claims` | filter | `claims`, `user`, `scopes` | `/api/userinfo` 组装完 claims 之后 |
| `oauth.authorize.quick` | filter | `null`, `userId`, `client`, `scopes` | 授权请求判定阶段；返回非空字符串 = 该请求免确认直接发码，回调带上 `spicallogin=<返回值>`（返回 `null` 走普通确认流程）。这是产品策略钩子，跳不跳确认由插件自己判断 |
| `oauth.authorize.approved` | run | `userId`, `clientId`, `scope`, `silent` | 授权码发出之后；`silent` 表示这次没经过确认页（已授权过、`prompt=none` 或插件批了快速通道） |
| `oauth.token.issued` | run | `userId`, `clientId`, `scopes`, `grantType` | 发出 access token 之后（`authorization_code` / `refresh_token`） |
| `oauth.token.revoked` | run | `tokenTypeHint` | 撤销端点处理完 |
| `admin.user.status_changed` | run | `userId`, `status`, `adminId` | 管理员启用/禁用账户之后 |
| `admin.user.role_changed` | run | `userId`, `role`, `adminId` | 管理员授予/撤销管理员之后 |
| `email.sending` | filter | `payload`（`to` / `subject` / `body`）, `embedded` | 真正投递 SMTP 之前；返回数组可替换 |
| `notification.created` | run | `userId`, `category`, `title`, `body`, `notificationId` | 站内通知入库之后、发邮件之前 |
| `viewer.props` | filter | `viewer`, `userId`, `user` | SPA 的 viewer 数据组装完 |
| `nav.items` | filter | `items`, `userId` | 站点导航组装完（PHP 布局和 Vue 外壳共用） |
| `layout.head` | filter | `html`, `layout` | 布局 `</head>` 前；返回值原样输出（核心先在这里给出 generator / 版本 / 皮肤 / 语言 meta） |
| `layout.body.start` | filter | `html`, `layout` | 布局 `<body>` 后 |
| `layout.body.end` | filter | `html`, `layout` | 布局 `</body>` 前 |
| `homepage.links` | filter | `links` | 首页底部链接组；追加条目带 `by`（插件显示名）的话，后台「首页」编辑器会标「由 X 托管」 |
| `homepage.sections` | filter | `sections`, `home` | 默认首页正文区块；插件返回 HTML 片段数组 |
| `footer.links` | filter | `links`, `brand`, `layout` | 页脚链接数组 |
| `footer.html` | filter | `html`, `layout`, `brand`, `links` | 页脚最终 HTML（config/footer.php 或皮肤 partial 渲染完） |
| `admin.menu.items` | filter | `items` | 后台侧栏菜单组装完 |
| `admin.shell.props` | filter | `props`, `adminId` | 后台 SPA 首屏数据 |
| `admin.dashboard.cards` | filter | `cards`, `stats` | 概览页统计卡 |
| `admin.dashboard.site` | filter | `site` | 概览页站点信息块 |
| `admin.users.rows` | filter | `rows` | 用户列表返回前 |
| `admin.user.detail` | filter | `payload`, `userId`, `user`, `admin` | 用户详情返回前 |
| `admin.apps.list` | filter | `apps` | 应用列表返回前 |
| `admin.apps.detail` | filter | `app`, `clientId` | 应用详情返回前 |
| `admin.mounts.list` | filter | `mounts` | 挂载点列表返回前 |
| `admin.logs.rows` | filter | `rows` | 活动日志列表返回前 |
| `admin.recovery.detail` | filter | `payload`, `request`, `user` | 恢复工单详情返回前 |
| `admin.settings.data` | filter | `payload` | 站点设置页数据 |
| `admin.settings.saved` | run | `policy`, `backend`, `adminId`, `apiEnabled` | 站点设置保存后 |
| `admin.plugin.page.body` | filter | `html`, `slug`, `page`, `tab` | 插件后台页面输出前 |
| `pages.content.before_save` | filter | `data`, `existing`, `isNew` | Pages 插件写库前，可改字段 |
| `pages.content.saved` | run | `pageId`, `data`, `isNew`, `userId` | Pages 插件保存后 |
| `pages.content.deleted` | run | `pageId`, `page`, `userId` | Pages 插件删除前 |
| `api.siteinfo` | filter | `info`, `siprops`, `params` | `/nomen-api` 的 siteinfo 组装完 |

## 站点 API

`/nomen-api` 是给开发者拉数据的只读接口，参数照 MediaWiki 的习惯：

```
GET /nomen-api?action=query&meta=siteinfo&format=json
GET /nomen-api?action=query&list=allpages&limit=20
GET /nomen-api?action=query&titles=start&prop=content
```

总开关在后台「设置」里（`site_settings.api.nomen_api`，默认开），关掉后整个入口回 404。`action=help` 会列出当前装着的全部模块。

插件在 `boot()` 里注册模块：

```php
use Nomen\Src\Api;

Api::action('echo', fn(array $params): array => ['echo' => $params], 'public', '原样返回参数');
Api::meta('foo', [Meta::class, 'build'], 'public', '站点信息扩展');
Api::list('things', [ListModule::class, 'all'], 'public', '列出 things（limit / offset）');
Api::prop('thing', [PropModule::class, 'fields'], 'public', 'things 的字段（titles）');
```

- `access` 只有 `public`（匿名可读）和 `admin`（管理员会话或管理员 OAuth Bearer token）两种。写错名字直接 500，不会当成 public 放出去。
- `meta` / `list` 回调签名 `fn(array $params): array`，返回的数组并进 `query`；带 `continue` 键会被提到顶层。
- `prop` 回调签名 `fn(array $titles, array $params): array`，返回 `slug => 字段`。核心按 `titles` 的顺序拼 `query.pages`，没找到的标 `missing`。
- 错误用 `Api::error(400, 'badvalue', '...')`，输出统一是 `{"error":{"code":...,"info":...}}`。
- 写操作的模块要自己校验 CSRF 或令牌，核心只负责读权限。

页面相关的模块见 `plugins/pages/src/Api.php`（`allpages` / `info` / `content`）。

## 多语言

插件界面文案用全局 `t('English sentence.')`——键是英文原句，运行时按当前访客语言出译文，核心词典（`locales/`）没有的键才落到插件自己的 `i18n/<语言>.json`：

```
plugins/<slug>/
├── plugin.json
├── i18n/
│   ├── en.json          键 = 值（英文原句）
│   ├── zh-hans.json     英文键 → 中文译文
│   └── ...              每个核心支持的语言一份
└── src/
```

- 插件能直接读到站点当前语言：模板和 PHP 里用 `t()`（全局助手），拿语言标记用 `I18n::locale()`；
  前端词典（`/i18n/<语言>.js`）会把插件 `i18n/` 的词条一并提供，Vue / JS 里照常 `t()`。
- 清单里的 `description`、`specialPages.title`、`adminPages.title` 渲染时也过 `t()`，照英文句子的口径写。
- 新增键当场把语言文件补齐（`--check` 对缺键会红）；既有键的措辞别顺手改，改一个键等于全部语言跟着动。
- 安装向导类的独立入口（核心的 `public/nomen-install/`）用 `nomen_install_t()`，键同样是英文原句。

生成和校验用：

```bash
php docs-internal/i18n/extract.php
php docs-internal/i18n/extract.php --check
```

## 许可

每个插件目录带一份 `LICENSE`。最上面是作者自己的通知块，空一行后接协议全文；`plugin.json` 的 `license` 写 SPDX 标识。`README.md` 是作者自己的说明页。`/special/version/plugin/<slug>/license` 和 `/readme` 会链到它们。

## 注意

- 插件跑的是全权限 PHP，只装信得过的。
- 插件不要 ALTER 核心表；核心表结构不是插件 API，升级时可能变。
- 依赖用 Composer 装到项目根，别塞一份 `vendor/` 进插件目录。
- 不要把密钥、Token、密码写进 `ActivityLog` 或通知正文。
- 插件自己的路由、后台页面、挂载点、模板、资源、迁移、命令都要写进 README，别让下一个维护的人猜。

## 自带插件

发布包里带三个插件，但它们是独立项目、独立仓库，不属于 Nomen 本体：

| 插件 | 仓库 | 做什么 |
|---|---|---|
| `pages` | <https://github.com/NomenID/plugin-pages> | 站内文档页面 |
| `open-platform` | <https://github.com/NomenID/plugin-open-platform> | 开发者门户 |
| `geoip` | <https://github.com/NomenID/plugin-geoip> | IP 归属地 |

另有不在发布包里的插件，按需自行安装：

| 插件 | 仓库 | 做什么 |
|---|---|---|
| `quick-central-login` | <https://github.com/NomenID/plugin-quick-central-login> | 第一方应用免确认的快速中央登录（回调带 `spicallogin` 标记），需要核心 ≥1.3.2 |

本仓库的 `plugins/` 只有这份说明；插件源码在各自仓库里。打包时先把三个自带目录 clone 到 `plugins/`，再跑 `php code-scripts/build-dist.php`，zip 里会带上它们。
