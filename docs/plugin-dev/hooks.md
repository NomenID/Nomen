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
        "bookmarks.page.rendered": "书签页正文渲染完之后"
    }
}
```

规则：

- 钩子名必须以插件 slug 加点开头（`bookmarks.`），核心会给不合规的名字记一条日志并跳过。
- 声明只是登记，真正触发还是调用 `Hooks::run()` / `Hooks::filter()`：

```php
Hooks::run('bookmarks.saved', [$userId, $bookmarkId]);
$html = Hooks::filter('bookmarks.page.rendered', $html, $userId);
```

- 别的插件按普通钩子订阅，写在清单 `hooks` 里或 `boot` 里都行。
- `php bin/nomen plugins list` 会列出已启用插件声明了哪些钩子；它们的参数和时机由插件自己在 README 里写。

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
