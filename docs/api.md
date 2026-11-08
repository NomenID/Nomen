# 站点 API

`/nomen-api` 是给脚本和外部工具读数据的只读接口。
格式固定 JSON，不支持 XML。

## 开关

后台「设置」→「站点 API」，默认开启。关掉后整个入口返回 404。

存在 `site_settings.api.nomen_api`（`1` / `0`）。

## 限流

每个 IP 每分钟 120 次，超了返回 429 和 `Retry-After`。

## 请求

```
GET /nomen-api?action=query&meta=siteinfo&format=json
```

- `format` 只接受 `json`，其它值返回 400。
- 返回形状是 `formatversion=2`：布尔值是真布尔，不是 `""`。
- 数组参数（`?x[]=1`）会被丢掉，所有参数按字符串处理。
- 不带 `action` 时等同 `action=help`。

## 模块

### action=help

列出全部模块、权限和说明。接入方可以靠它自检。

### action=query&meta=...

| 模块 | 参数 | 返回 |
|---|---|---|
| `siteinfo` | `siprop=general\|extensions\|skins\|rights`，`|` 分隔，默认 `general` | 站点名、地址、logo、版本、语言、已启用插件 / 皮肤、权限组 |
| `version` | 无 | 软件名、版本号、项目地址 |

### action=query&list=...

| 模块 | 参数 | 返回 |
|---|---|---|
| `allpages` | `limit`、`offset`、`drafts` | Pages 插件的页面列表 |

### action=query&prop=...

| 模块 | 参数 | 返回 |
|---|---|---|
| `info` | `titles`（`|` 分隔）、`drafts` | 页面元信息 |
| `content` | 同上 | 页面 Markdown 原文 |

`titles` 不存在时按「缺失」返回，不报错。

## 权限

只有两级：

- `public`：匿名可读。
- `admin`：需要管理员会话 cookie，或管理员的 OAuth Bearer token。

权限不够返回 403 `permissiondenied`。模块在注册时写错权限名会直接 500，不会放行。

## 错误格式

```json
{
    "error": {
        "code": "badvalue",
        "info": "Unknown action: foo"
    }
}
```

常见 code：`api-disabled`（404）、`nosuchaction`（400）、`badvalue`（400）、
`permissiondenied`（403）、`ratelimited`（429）。

## 和 OAuth 接口的区别

| 接口 | 用途 |
|---|---|
| `/nomen-api` | 读站点内容与元信息，管理员模块也能读管理数据 |
| `/api/userinfo`、`/api/token-info` | OAuth 接入方读当前登录用户的身份 |
| `/api/version` | 公开的版本号，更新检查用 |

## 插件扩展

插件在 `boot()` 里注册自己的模块：

```php
Api::action('echo', fn(array $p): array => ['echo' => $p], 'public', '原样返回参数');
Api::meta('foo', [Meta::class, 'build'], 'public', '站点信息扩展');
Api::list('things', [ListModule::class, 'all'], 'public', '列出 things');
Api::prop('thing', [PropModule::class, 'fields'], 'public', 'things 的字段');
```

写法见 [插件开发](plugins.md)。
