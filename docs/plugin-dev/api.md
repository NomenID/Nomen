# 站点 API

`/nomen-api` 是给外部程序拉数据的只读接口，参数照 MediaWiki 的习惯：`action=query` 加 `meta` / `list` / `titles`。
核心只提供站点信息，页面相关的模块由 Pages 插件自己注册——别的插件同样可以在 `boot` 里挂自己的模块。

## 注册

```php
use Nomen\Src\Api;

public static function boot(): void
{
    Api::list('bookmarks', [Modules::class, 'bookmarks'], 'public', 'List bookmarks (limit / offset)');
    Api::prop('bookmark', [Modules::class, 'fields'], 'public', 'Bookmark fields (titles)');
    Api::meta('bookmarks', [Modules::class, 'summary'], 'public', 'Bookmark count for siteinfo');
    Api::action('bookmark-count', [Modules::class, 'count'], 'public', 'Total bookmarks');
}
```

四个注册方法签名一样：`(名字, 回调, 权限, 说明)`。权限只有两个值：

| 值 | 谁能读 |
|---|---|
| `public` | 匿名就能读 |
| `admin` | 管理员会话，或带管理员 OAuth 令牌的 `Authorization: Bearer` |

**写错权限名会直接 500**，不会当成 public 放出去。这是故意的：权限判断的错误必须响亮。

## 四种模块

### `action`

```php
public static function count(array $params): array
{
    return ['count' => Store::count()];
}
```

```
GET /nomen-api?action=bookmark-count&format=json
```

返回的数组直接当响应体。

### `list`

```php
public static function bookmarks(array $params): array
{
    $limit = min(50, max(1, (int)($params['limit'] ?? 20)));
    $offset = max(0, (int)($params['offset'] ?? 0));

    return [
        'bookmarks' => Store::page($limit, $offset),
        'continue'  => Store::hasMore($offset + $limit)
            ? ['bookmarkcontinue' => (string)($offset + $limit)]
            : null,
    ];
}
```

```
GET /nomen-api?action=query&list=bookmarks&limit=10
```

返回的数组合进 `query`。带 `continue` 键会被提到顶层（跟 MediaWiki 一样），客户端拿它翻页。

### `prop`

```php
public static function fields(array $titles, array $params): array
{
    $out = [];
    foreach ($titles as $title) {
        $out[$title] = Store::fields($title);   // slug => 字段
    }

    return $out;
}
```

```
GET /nomen-api?action=query&titles=a|b&prop=bookmark
```

核心按 `titles` 的顺序拼 `query.pages`，返回里没有的标 `missing`。**顺序由核心保证**，你只管给数组。

### `meta`

```php
public static function summary(array $params): array
{
    return ['bookmarks' => Store::count()];
}
```

```
GET /nomen-api?action=query&meta=bookmarks
```

并进 `query` 的同一层，适合「站点级的小统计」。

## 参数

`$params` 是解析过的查询参数：除了 `action` / `format`，`limit`、`offset`、`titles` 这些都会被拆好传进来，
`titles` 是数组（用 `|` 分隔的）。**没传的键不要假设存在**，都给默认值。

`limit` 自己夹上限。别让一次请求把整张表拉出来——API 是公开的，会被扫。

## 错误

```php
Api::error(400, 'badvalue', 'limit must be between 1 and 50');
```

输出统一：

```json
{"error":{"code":"badvalue","info":"limit must be between 1 and 50"}}
```

`error()` 会直接 `exit`，所以不用再 `return`。

## 写操作

核心只负责**读**权限。要提供写接口，自己校验：

- 会话 + CSRF：`Api::requireAdmin()` 管权限，CSRF 自己 `Csrf::verify()`
- Bearer 令牌：自己设计，别复用 `oauth_access_tokens` 里别人的令牌

写接口放在 `/nomen-api` 上要慎重：它的总开关是给「只读数据」设计的，站长关掉时你的写接口也会一起消失。
需要写操作一般另开一条普通路由更清楚。

## 总开关

后台「设置」里有 `api.nomen_api`，默认开。关掉后整个 `/nomen-api` 回 404，包括你注册的模块。

`action=help` 会列出当前装着的全部模块——**说明文字（第四个参数）写清楚点**，那是别人发现你模块的唯一途径。

## 试试

```bash
curl -s 'https://example.com/nomen-api?action=help' | python -m json.tool
curl -s 'https://example.com/nomen-api?action=query&list=bookmarks&limit=5'
```

本地没伪静态时路径是 `/index.php?page=nomen-api&action=...`（见 [无伪静态模式](../config.md)）。

## 检查清单

- [ ] 每个模块都写了说明，`action=help` 里看得懂
- [ ] `limit` 有上限
- [ ] `titles` 一次给 200 个时不会崩（分批查）
- [ ] 公开模块里没有用户隐私（邮箱、IP、内部 id 都别露）
