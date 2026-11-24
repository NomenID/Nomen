# 钩子

钩子是核心主动开的口子，分两种：`run` 是「事情发生了，通知一下」，`filter` 是「这个值你还能改」。

**密码校验、两步验证、恢复审核这些「能不能过」仍由核心决定**，插件不能替用户免验证通过。
需要按 IP、设备、风险分决定「这次登录要不要先拦一下」时，用 `auth.login.pre` / `auth.register.pre`：
它们只能放行、拒绝或要求补一次人机验证，拿不到密码，也不能绕过第二因素。

## 两种语义

| | 回调能拿到 | 回调返回 | 谁受影响 |
|---|---|---|---|
| `run` | 事件参数 | `null` / `true` 继续，`false` 中断 | 只影响后面的回调 |
| `filter` | 当前值 + 上下文参数 | `null` 不改，其它值替换 | 核心拿到的结果 |

`run` 的回调**只能返回 `null`、`true` 或 `false`**。返回数组、字符串这类别的值会抛
`UnexpectedValueException`，整个请求 500——这是最容易踩的坑，`return $this->log(...)` 这种写法就是错的。

`run` 的事件默认可以中断（第一个返回 `false` 的回调停掉后面所有回调）。声明成 `abortable: false` 的事件里
返回 `false` 会直接抛 `RuntimeException`。

## 在清单里登记

三种写法，优先级默认 10，数字越小越先跑：

```json
"hooks": {
    "auth.notice": ["Nomen\\Plugin\\Example\\Hooks", "notice"],

    "user.registered": [
        ["Nomen\\Plugin\\Example\\Hooks", "sendWelcome"],
        ["Nomen\\Plugin\\Example\\Hooks", "syncProfile"]
    ],

    "userinfo.claims": [
        {"handler": ["Nomen\\Plugin\\Example\\Hooks", "addClaims"], "priority": 5}
    ]
}
```

也可以写在代码里（`boot` 里）：

```php
use Nomen\Src\Hooks;

Hooks::add('user.login.succeeded', [Hooks::class, 'onLogin'], 10);
```

清单里能表达的就不用写代码：代码里的注册更难发现，排查时得翻两处。

## filter 的写法

第一个参数是当前值，后面的参数是上下文：

```php
final class Hooks
{
    /** @param array<string,mixed> $claims */
    public static function addClaims(array $claims, array $user, array $scopes): array
    {
        if (in_array('profile', $scopes, true)) {
            $claims['team'] = 'docs';
        }

        return $claims;
    }
}
```

返回 `null` 表示不改。想「让别人先改、我最后收尾」就把 `priority` 写大一点。

**`filter` 的返回值会被原样使用**，所以往 `meta.tags`、`layout.head` 这类钩子里塞字符串时，里面的用户输入
必须自己转义：`htmlspecialchars()`。

## 常用的几个

### `auth.notice` / `homepage.sections`：往页面里塞东西

```php
public static function notice(string $html): string
{
    if ($html !== '') {
        return $html;                       // 别人已经写了，不覆盖
    }

    return '<p class="notice">' . htmlspecialchars(t('Scheduled maintenance on Sunday.')) . '</p>';
}
```

`auth.notice` 在登录页和注册页头部下方，PHP 和 Vue 两条渲染都生效，是「挂一条公告」最省事的位置。
`homepage.sections` 是数组，返回 HTML 片段数组，会追加到默认首页正文。

### `nav.items`：往导航里加链接

```php
/** @param array<int,array<string,string>> $items */
public static function nav(array $items, ?int $userId): array
{
    if ($userId === null) {
        return $items;
    }

    $items[] = [
        'key'   => 'bookmarks',
        'href'  => '/bookmarks',
        'label' => t('Bookmarks'),
        'icon'  => 'file',
    ];

    return $items;
}
```

PHP 布局和 Vue 外壳共用这份结果，加一次两边都有。`href` 必须是站内地址或者完整的绝对地址。

### `user.registered` / `user.login.succeeded`：记自己的一份

```php
public static function registered(int $userId, string $username, ?string $email): void
{
    error_log("[example] 新用户 {$userId}");
}
```

`run` 的回调**没有返回值**就是返回 `null`，别写 `return true;` 以外的表达式。

### `meta.tags`：加 meta 标签

```php
/** @param array<string,string> $tags */
public static function tags(array $tags, string $layout): array
{
    $tags['og:image'] = 'https://example.com/card.png';
    $tags['robots'] = 'index,follow';

    return $tags;
}
```

`og:` 开头的按 `property` 输出，其余按 `name`。值会被转义一次。

### `oauth.authorize.quick`：跳过确认页

这是产品策略钩子，拿到的第一个参数是 `null`：

```php
public static function quick(?string $decision, int $userId, array $client, array $scopes): ?string
{
    if ((int)$client['developer_id'] !== 1) {
        return null;                       // 不是第一方应用，走普通确认流程
    }

    return 'example-quick-login';          // 非空 = 免确认，回调地址会带 spicallogin=<这个值>
}
```

返回的字符串会被当成 `spicallogin` 参数发给接入方，所以别在里面放用户信息。判定逻辑必须自己写清楚，
默认返回 `null` 是最安全的。

已经授权过的应用会直接重定向（用户之前点过同意）；还没授权过的会先显示中间页（打勾动画）再自动提交，
中间页不会带 `spicallogin`，免得接入方再弹一次提示。

### `oauth.authorize.quick.settings`：中间页的设置入口

中间页上有一句「可以随时在设置里关掉快速登录」，这个钩子决定链接指向哪里：

```php
public static function quickSettings(mixed $url, int $userId, array $client): string
{
    return '/special/quick-login';         // 只接受站内路径；空串 = 不显示链接
}
```

只有 `oauth.authorize.quick` 批了快速登录、并且要渲染中间页时才会触发。核心里不写死任何插件的设置页地址。

## 登录 / 注册风控：`auth.login.pre` / `auth.register.pre`

这两个是「拦一下」的钩子，返回一个数组：

| 键 | 说明 |
|---|---|
| `decision` | `allow`（放行）、`block`（拒绝）、`challenge`（要求人机验证）、`degraded`（依赖的服务不可用） |
| `message` | 显示给用户的成品文案；留空时核心用内置的兜底文案 |
| `payload` | 只并入 AJAX 失败响应，给前端自己处理（比如触发工作量证明） |

```php
public static function screenLogin(array $screen, array $context): array
{
    if ($context['method'] !== 'password') {
        return $screen;                    // 只管密码登录，其它入口放行
    }

    if (risk_ok($context['ip'])) {
        return $screen;                    // decision 仍是 allow
    }

    return [
        'decision' => 'block',
        'message'  => '登录尝试被拒绝，请稍后再试',
        'payload'  => ['reason' => 'risk'],
    ];
}
```

`auth.login.pre` 覆盖密码、邮箱验证码和第三方登录；`auth.register.pre` 覆盖核心注册页和第三方首次建号。
`context` 里给的是 `identifier`、`accounts`（只有 `id` / `username` / `email`）、`ip`、`user_agent`，
**没有密码**。返回 `challenge` 会让这台客户端下一次尝试要求图片验证码。

风控服务自己不可用时要明确选一种策略：返回 `allow`（放行）或 `degraded`（拒绝并提示服务不可用）。
别让异常冒到钩子外面——那会把所有人的登录打成 500。

要记录失败次数、报告风控结果，用 `auth.login.failed`（`message, userId, context`）。登录成功用
`user.login.succeeded`，注册成功用 `user.registered`。

`auth.page`（`page, context`）在登录 / 注册 / 临时密钥页渲染前触发，适合放设备 cookie、预置挑战状态。
它只在这些页面被真正渲染时跑，不是每个请求都跑。

### 人机验证：`auth.human_check`

如果站点想用 PoW、第三方挑战这类方案替代核心的算术验证码，用这个 filter：

```php
public static function humanCheck(array $check, array $context): array
{
    if ($context['page'] === 'register' && $context['stage'] === 'render' && pow_available()) {
        return ['required' => false, 'verified' => false]; // 不显示验证码
    }

    if ($context['stage'] === 'verify' && pow_passed()) {
        return ['required' => true, 'verified' => true];   // 已经通过，跳过验证码
    }

    return $check;
}
```

- `required`：这次要不要验证码；`false` 时页面不显示输入框。
- `verified`：插件已经完成人机验证；`true` 时核心不再校验验证码。
- `context.page` 是 `login` / `register`，`context.stage` 是 `render`（渲染页面）/ `verify`（提交时）。
- 默认值是核心原来的行为：登录按 IP 频率决定，注册始终要求。返回非数组或异常都按默认值处理。

## SEO 与响应头

### `meta.robots`：控制 noindex

返回指令数组。后台布局默认已经是 `noindex, nofollow`；其它页面可按路径决定：

```php
/** @param string[] $directives */
public static function robots(array $directives, string $layout, string $path): array
{
    if (str_starts_with($path, '/preview/')) {
        return ['noindex', 'nofollow'];
    }

    return $directives;
}
```

指令会拼成 `<meta name="robots" content="noindex, nofollow">`，空数组表示不输出这一条。
`meta.tags` 也能改 `robots`，但 `meta.robots` 更明确，按路径判断时优先用它。

### `http.headers`：CSP、HSTS 这类响应头

```php
public static function headers(array $headers, string $kind): array
{
    if ($kind === 'view') {
        $headers['Content-Security-Policy'] = "default-src 'self'";
    }

    return $headers;
}
```

`kind` 是 `view` / `json` / `redirect`。值的数组会发成多条同名头；头名和值里的换行会被清掉。

### `response.html` / `robots.txt` / `sitemap.xml`

- `response.html`（`html, layout`）：整页 HTML 输出前，适合注入分析脚本、统一改链接。
- `robots.txt`（`body`）：返回完整正文，可以追加 `Disallow`。
- `sitemap.xml`（`xml`）：返回完整 XML。

## 请求生命周期

这几个钩子不管具体功能，给的是**请求本身**的接缝：

| 钩子 | 类型 | 参数 | 用法 |
|---|---|---|---|
| `request.before` | run | `method, path` | 路由匹配之前；返回 `false` 表示你已经输出响应，不再匹配 |
| `route.not_found` | run | `method, path` | 没有路由命中、统一 404 之前；返回 `false` 表示你处理了 |
| `api.request` | filter | `params` | `/nomen-api` 参数合并之后、校验之前；可改参数 |
| `api.response` | filter | `data` | `/nomen-api` 正常响应编码之前 |

`request.before` 适合维护页、IP 拦截、整站跳转；`route.not_found` 适合自定义固定链接、旧地址跳转。
两者返回 `false` 时**必须自己把响应发出去**（`Response::redirect()` / `Response::view()` / `echo` 都行），
否则访客会看到一个空白 200。

## 账号变更通知

`user.password.changed`（`userId, source`）、`user.email.changed`（`userId, oldEmail, newEmail`）、
`user.profile.changed`（`userId, profile, before`）、`user.avatar.changed`（`userId, oldAvatar, newAvatar`）、
`user.deleted`（`userId, username, email`）在对应动作成功后触发。`source` 是 `settings` / `recovery` /
`email_code`，需要区分改密来源时用它。头像钩子给的是文件名，不带 `/assets/avatars/` 前缀。
这些钩子跑在数据库写入之后，删号的在删之前。

## 插件自己的钩子

插件也可以开自己的钩子，让别的插件接。先在 `plugin.json` 里声明：

```json
{
    "provides": {
        "bookmarks.saved": "书签保存之后",
        "bookmarks.page.rendered": {
            "description": "书签页正文渲染完之后",
            "type": "filter",
            "args": "html, userId"
        }
    }
}
```

值写字符串就是一句说明；写对象可以带上 `type`（`run` / `filter`）、`args`、`since`。

规则：

- 钩子名必须以插件 slug 加点开头（`bookmarks.`），核心会给不合规的名字记一条日志并跳过。
- 声明只是登记，真正触发还是调用 `Hooks::run()` / `Hooks::filter()`：

```php
Hooks::run('bookmarks.saved', [$userId, $bookmarkId]);
$html = Hooks::filter('bookmarks.page.rendered', $html, $userId);
```

- 也可以在 `boot` 里用代码登记，效果一样：

```php
Hooks::define('bookmarks.saved', [
    'owner'       => 'bookmarks',
    'type'        => 'run',
    'args'        => 'userId, bookmarkId',
    'description' => '书签保存之后',
]);
```

- 别的插件按普通钩子订阅，写在清单 `hooks` 里或 `boot` 里都行。
- `php bin/nomen hooks` 会列出核心钩子目录（类型、参数）、**运行时**的监听者、插件声明了哪些钩子、
  哪些已弃用；`php bin/nomen hooks bookmarks.saved` 看单个钩子的类型、参数和监听者。
  核心目录由 `src/HookCatalog.php` 提供，docgen 从 `hooks.json` 生成，CI 会检查它是否过期。

### 改名与弃用：`Hooks::alias()`

钩子名要改时，别直接把旧名字删掉——老插件会静默失效。加一条别名：

```php
Hooks::alias('bookmarks.saved', 'bookmarks.saved.v2', '2.0.0');
```

两个方向都生效：触发新名字时旧监听者照跑，触发旧名字时新监听者也照跑，第一次用到旧名字会往
`error_log` 写一条弃用提示。**参数必须保持兼容**，别名只换名字，不换参数顺序和含义。

## 全部钩子

按域分组的完整清单（类型、参数、时机、触发文件）在 [钩子参考](../hooks/index.md)。那份是生成的，
代码里删了钩子而文档没跟上时 CI 会报错。

## 调试

先确认插件加载了：

```bash
php bin/nomen plugins list
```

再看钩子有没有登记上。最快的办法是临时在自己回调的第一行写：

```php
error_log('[example] hook fired: ' . __FUNCTION__);
```

`error_log` 写进服务器错误日志，不会出现在页面上。调试完记得删掉。

如果钩子在 Vue 渲染的页面上没生效，先分清是哪条渲染路径：`auth.notice` 两条路径都走，
`admin.*` 那批只影响 `/api/admin/*` 返回的数据，页面上的缓存或前端状态可能让你看成没生效。
