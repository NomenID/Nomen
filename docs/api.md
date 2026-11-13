# 站点 API

`/nomen-api` 是给程序读数据的只读接口，参数照 MediaWiki 的习惯：`action=query` 加 `meta` / `list` / `titles`。
返回的永远是 JSON，形状固定成 `{...}`，不跟请求头协商。

## 基本用法

```bash
# 列出所有模块
curl -s 'https://你的站点/nomen-api?action=help'

# 站点信息
curl -s 'https://你的站点/nomen-api?action=query&meta=siteinfo'

# 列出页面
curl -s 'https://你的站点/nomen-api?action=query&list=allpages&limit=20'

# 取页面内容
curl -s 'https://你的站点/nomen-api?action=query&titles=start&prop=content'
```

| 参数 | 说明 |
|---|---|
| `action` | `query` / `help` / 插件注册的 action；不传等于 `help` |
| `format` | 只支持 `json`（别的值 400） |
| `meta` | 站点级信息模块 |
| `list` | 列表模块，支持 `limit` / `offset` |
| `titles` / `prop` | 按标题取字段，多个用 `|` 分隔 |

**数组参数会被丢掉**（`?x[]=1` 这种），所有值按字符串处理。

## 核心模块

| 模块 | 形状 | 内容 |
|---|---|---|
| `meta=siteinfo` | query | 站点名、地址、语言、版本、皮肤、插件清单 |
| `meta=version` | query | 程序版本、PHP 版本、数据库类型 |
| `list=allpages` | query | 已发布的 Pages 页面（需要 Pages 插件） |
| `prop=info` | titles | 页面元信息 |
| `prop=content` | titles | 页面正文（Markdown） |

`allpages` / `info` / `content` 是 Pages 插件注册的，没装 Pages 就没有这三个。

## 限流与权限

- **全站 120 次 / 分钟 / IP**，超了 429 + `Retry-After`。
- 模块两级权限：`public`（匿名）和 `admin`（管理员会话，或管理员 OAuth Bearer 令牌）。
- 写错权限名的插件模块会**直接 500**，不会默认放行。

## 总开关

后台「设置」里的 `api.nomen_api`，默认开。关掉后整个 `/nomen-api` 回 404——包括插件注册的模块。

如果站点在用外部程序对接这个接口，关之前先确认。

## 错误

```json
{"error": {"code": "nosuchaction", "info": "Unknown action: foo"}}
```

| code | 含义 |
|---|---|
| `badvalue` | 参数不合法（比如 `format=xml`） |
| `nosuchaction` | 没有这个 action |
| `ratelimited` | 触发限流 |
| `api-disabled` | 总开关关着 |
| `permissiondenied` | 需要管理员 |

## 自己加模块

见 [插件开发 / 站点 API](plugin-dev/api.md)。

## 在无伪静态的站点上

地址变成：

```
/index.php?page=nomen-api&action=query&list=allpages
```

`page=nomen-api` 这一段是路由，后面的参数和正常模式一样。

## 坑

- **`formatversion`**：返回形状固定是 `formatversion=2` 的风格（对象数组），不认 `formatversion` 参数。
- **`continue`**：列表放不下时会带 `continue`，客户端拿它翻页，别自己算 offset。
- **`titles` 一次给太多**：几十个没问题，上千个会超时——分批。
- **`admin` 模块用 OAuth 令牌**：令牌必须是管理员账号签发的，普通用户的令牌一样 403。

## 检查清单

- [ ] `action=help` 能列出模块
- [ ] 外部程序用的是 `/nomen-api` 而不是直接读库
- [ ] 限流够不够用（120 次/分钟/IP）
- [ ] 关掉总开关之前沟通过
- [ ] 私有数据的模块都标了 `admin`
