# 清单字段

`plugin.json` 是一个严格 JSON（不能写 `//` 注释，写一行整个插件就加载不了）。最少要有 `name`、`version`、
`autoload` 和 `boot` 里的至少一项，其余按需要加。

骨架里的清单：

```json
{
    "name": "Skeleton",
    "version": "0.1.0",
    "description": "A starting point for a Nomen plugin: one special page, one hook, one stylesheet.",
    "license": "Apache-2.0",
    "author": ["Your Name"],
    "requires": {
        "Nomen": ">=1.2.0"
    },
    "autoload": {
        "Nomen\\Plugin\\Skeleton\\": "src/"
    },
    "boot": ["Nomen\\Plugin\\Skeleton\\Plugin", "boot"],
    "hooks": {
        "auth.notice": ["Nomen\\Plugin\\Skeleton\\Plugin", "authNotice"]
    },
    "specialPages": {
        "skeleton": {
            "title": "Skeleton",
            "access": "public",
            "handler": ["Nomen\\Plugin\\Skeleton\\Plugin", "renderStatus"]
        }
    }
}
```

## 字段一览

| 字段 | 类型 | 说明 |
|---|---|---|
| `name` | 字符串 | 显示名，`/special/version` 里列出来 |
| `version` | 字符串 | 插件自己的版本号，跟核心版本无关 |
| `description` | 字符串 | **写成英文原句**，渲染时会过 `t()`；译文放 `i18n/<语言>.json` |
| `license` | 字符串 | SPDX 标识，比如 `Apache-2.0`、`GPL-3.0-only` |
| `author` | 数组 | 作者名，一个或多个 |
| `homepage` | 字符串 | 插件介绍页地址，`/special/version` 里链过去 |
| `requires.Nomen` | 字符串 | 版本约束，`>=1.2.0`、`>=1.5` 这类；不满足就整个插件跳过 |
| `requires.php` | 数组 | 需要的 PHP 扩展，缺一个就跳过，比如 `["curl", "fileinfo"]` |
| `autoload` | 对象 | PSR-4：命名空间前缀 → 目录，相对插件根 |
| `boot` | 数组 | `[类, 方法]`，所有注册做完之后调一次 |
| `hooks` | 对象 | 事件名 → 回调，见 [钩子](hooks.md) |
| `specialPages` | 对象 | `/special/<slug>` 页面 |
| `adminPages` | 对象 | `/admin/<slug>` 页面 |
| `mounts` | 对象 | 公开挂载点声明，后台「接入点」页能看能改路径 |

## requires 的两种

```json
"requires": {
    "Nomen": ">=1.5.0",
    "php": ["curl", "fileinfo", "gd"]
}
```

版本比较用 PHP 的 `version_compare`，`>=`、`<`、`^` 这些写法都认。快速构建版本号（`1.5.0+qb.20261112`）比较时
会先去掉 `+` 后面的部分，所以对着 qb 版本开发不用改约束。

**不满足要求时插件不会报错页面**，只在 `error_log` 里写一行，`php bin/nomen plugins list` 里显示
`未启用` 或 `启用但加载失败`。排查插件「为什么没反应」先看这里。

## autoload

```json
"autoload": {
    "Nomen\\Plugin\\Example\\": "src/"
}
```

前缀必须以 `\\` 结尾（JSON 里转义过的反斜杠）。类 `Nomen\Plugin\Example\Hooks` 对应 `src/Hooks.php`。
文件名和类名必须完全一致，大小写也一样——Linux 上大小写敏感，Windows 上的小写测试会骗人。

一个插件可以注册多个前缀，也可以只写一个不需要 `src/` 目录的插件（纯钩子函数写在 `boot` 里）。

## boot

```json
"boot": ["Nomen\\Plugin\\Example\\Plugin", "boot"]
```

注册顺序是：`autoload` → `hooks` → `specialPages` → `adminPages` → `mounts` → **`boot`**。
所以 `boot` 里能直接用已经注册好的东西，但钩子里的代码不能假设 `boot` 已经跑过（`boot` 最后才跑）。

`boot` 适合做：注册路由（`Plugins::route()`）、注册后台页面（`Admin::page()`）、注册命令（`Plugins::command()`）、
注册缓存驱动（`Cache::registerDriver()`）。

不适合做：查库、发邮件、写日志。每个请求都会执行 `boot`。

## specialPages

```json
"specialPages": {
    "health": {
        "title": "Plugin health",
        "access": "admin",
        "handler": ["Nomen\\Plugin\\Example\\Health", "render"]
    }
}
```

- 地址是 `/special/<slug>`，slug 就是这里的键。
- `title` 写成英文原句，渲染时过 `t()`。
- `access` 只有 `public` 和 `admin`。`admin` 要求已登录且是管理员，否则 302 到登录页或 403。
- `handler` 返回 HTML 字符串，核心把它放进特殊页外壳（`templates/special.php`）。

细节见 [页面与路由](pages-and-routes.md)。

## adminPages

```json
"adminPages": {
    "example": {
        "title": "Example",
        "description": "What this page is for",
        "handler": ["Nomen\\Plugin\\Example\\AdminPage", "render"],
        "menu": "Example",
        "group": "Site",
        "order": 35
    }
}
```

- `menu` 写字符串就是菜单文字，写 `false` 就不进菜单（只能直接访问 `/admin/<slug>`）。
- `group` 是侧栏分组名，不写就跟核心页面平铺。
- `order` 越小越靠前。

细节见 [后台页面](admin-pages.md)。

## mounts

```json
"mounts": {
    "portal": {
        "path": "/example",
        "title": "Example portal",
        "description": "Public entry point",
        "access": "public",
        "config": "base_path"
    }
}
```

挂载点是**公开入口的声明**，不是路由。它的作用是：后台「接入点」页能列出插件占了哪个地址、站长能改路径；
改完写回 `config/plugins/<slug>.json`，插件用 `Plugins::config()` 重新读。

```php
$base = \Nomen\Src\Plugins::mountUrl('example');   // '/example'，没装返回 null
```

真正的路由还是得在 `boot` 里用 `Plugins::route()` 注册，路径从 `mountUrl()` 取，别写死。

## 常见错误

| 现象 | 原因 |
|---|---|
| 插件列表里显示 `启用但加载失败`，日志说「不可调用」 | `hooks` 里的类名或方法名拼错，或者 `autoload` 前缀和 `namespace` 不一致 |
| 改了东西没生效 | 文件在，但 `enabled` 里没加；或者 opcache 没刷新 |
| `plugin.json` 明明写了 `specialPages` 却 404 | 键名大小写没问题的话，看 `handler` 是否可调用；特殊页 slug 是小写匹配的 |
| 描述在中文站还是英文 | `i18n/zh-hans.json` 缺这一条；`description` 的键就是英文原句本身 |

读配置 / 环境变量时，0 是合法值的键（开关上限之类）**别用 `?:` 兜底**——PHP 里 `"0"` 是 falsy，
用户设了 0 会被静默跳回默认值，报错都看不到。用 `??` 读数组，环境变量用显式判空：

```php
// 错：设 0 会被吞掉，永远拿到 64
$max = (int)(getenv('EXAMPLE_MAX') ?: ($config['max'] ?? 64));

// 对：0 就是 0
$env = getenv('EXAMPLE_MAX');
$max = ($env !== false && $env !== '') ? (int)$env : (int)($config['max'] ?? 64);
```
