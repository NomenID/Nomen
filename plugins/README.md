# 插件

一个插件是一个目录 `plugins/<slug>/`，里面必须有 `plugin.json`。装插件不用改核心代码：放进 `plugins/`，再写进 `config/plugins.php` 的 `enabled` 列表。

```json
{
    "name": "Open Platform",
    "version": "0.1.0",
    "description": "开发者门户",
    "license": "GPL-3.0-only",
    "author": ["Yunyun"],
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

`Admin::page()` 或清单里的 `adminPages` 注册，地址是 `/admin/<slug>`。处理函数返回 HTML 字符串，核心用后台布局套起来。

```php
$html = '<div class="admin-card">...</div>';
return $html;
```

可以给后台页面加样式和脚本：

```php
\Nomen\Src\Admin::style(\Nomen\Src\Plugins::assetUrl('example', 'admin.css'));
\Nomen\Src\Admin::script(\Nomen\Src\Plugins::assetUrl('example', 'admin.js'));
```

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
| `oauth.authorize.approved` | run | `userId`, `clientId`, `scope`, `quickLogin` | 用户同意授权、写入授权记录之后 |
| `oauth.token.issued` | run | `userId`, `clientId`, `scopes`, `grantType` | 发出 access token 之后（`authorization_code` / `refresh_token`） |
| `oauth.token.revoked` | run | `tokenTypeHint` | 撤销端点处理完 |
| `admin.user.status_changed` | run | `userId`, `status`, `adminId` | 管理员启用/禁用账户之后 |
| `admin.user.role_changed` | run | `userId`, `role`, `adminId` | 管理员授予/撤销管理员之后 |
| `email.sending` | filter | `payload`（`to` / `subject` / `body`）, `embedded` | 真正投递 SMTP 之前；返回数组可替换 |
| `notification.created` | run | `userId`, `category`, `title`, `body`, `notificationId` | 站内通知入库之后、发邮件之前 |
| `viewer.props` | filter | `viewer`, `userId`, `user` | SPA 的 viewer 数据组装完 |

## 多语言

插件界面文案用全局 `t('中文原文')`，键就是简体中文原文。核心已有的键直接用，运行时按「核心优先、插件补缺」合并；只有核心没有的键才放进插件自己的 `i18n/`：

```
plugins/<slug>/
├── plugin.json
├── i18n/
│   ├── zh-hans.json
│   ├── en.json
│   └── ...
└── src/
```

清单里的 `description`、`specialPages.title`、`adminPages.title` 渲染时也过 `t()`。生成和校验用：

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
