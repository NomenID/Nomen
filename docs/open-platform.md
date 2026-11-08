# 开放平台

让第三方开发者自己注册、自己创建 OAuth 应用，不用站长每个都手动建。
插件 `open-platform` 默认启用，挂载路径默认 `/open`。

停用插件后前台入口消失，但后台「应用」页仍能建应用——核心的 OAuth 客户端管理不依赖这个插件。

## 开发者流程

1. 用站点账号登录，打开 `/open`，填开发者名称（2–64 字符）入驻。
   每个账号每小时最多提交一次。
2. 在 `/open/dashboard` 看自己的应用，`/open/apps/create` 新建。
   每个开发者最多 10 个应用，创建接口每分钟 1 次。
3. 应用详情页能改名称、描述、主页、回调地址、scope、客户端类型，能重置密钥、删除应用。

开发者状态由管理员控制：后台「开发者」页可以停用某个开发者，停用后他不能再建应用，
已有应用也按后台的处理逻辑走。

## 应用字段

| 字段 | 说明 |
|---|---|
| 名称 / 描述 / 主页 | 展示用 |
| 图标 | 上传，落 `public/assets/develops/` |
| 回调地址 | 每行一个，必须和请求里的 `redirect_uri` 完全一致 |
| Scopes | 从 `openid` / `profile` / `email` 里选 |
| 客户端类型 | `confidential`（有 secret）或 `public`（无 secret，强制 PKCE） |
| PKCE | 机密客户端也可以强制要求 |

创建成功后 `client_id` 和 `client_secret` **只显示一次**，secret 之后只存哈希。
丢了只能重置，重置后旧 secret 立即失效。

## 站点提供的 OAuth 端点

| 端点 | 方法 | 说明 |
|---|---|---|
| `/oauth/authorize` | GET / POST | 授权页；`response_type=code`、`client_id`、`redirect_uri`、`scope`、`state` |
| `/oauth/token` | POST | `grant_type=authorization_code` 或 `refresh_token`；支持 form 参数或 Basic 认证 |
| `/api/userinfo` | GET | `Authorization: Bearer <token>`；返回 `sub`、`username`、`display_name`、`avatar`、`email` |
| `/api/token-info` | GET | 校验令牌是否有效 |
| `/oauth/revoke` | POST | 撤销 access_token 或 refresh_token，总是返回 200 |

`sub` 是**应用级**的：同一个人在不同应用里拿到不同的 `sub`，应用之间无法据此关联用户。
需要邮箱时申请 `email` scope，返回里还会带 `email_verified`。

## 令牌有效期

| 令牌 | 默认 |
|---|---|
| 授权码 | 10 分钟 |
| access_token | 1 小时 |
| refresh_token | 180 天 |

刷新时旧 refresh_token 立刻失效（轮换）。数值在 `config/app.php` 的 `auth_code_ttl` /
`access_token_ttl` / `refresh_token_ttl`。

## 管理员侧

| 页面 | 作用 |
|---|---|
| 后台「应用」 | 核心的 OAuth 应用管理，不依赖插件；能建应用、改配置、重置密钥、启停、删除 |
| 后台「开发者」 | 插件页；看开放平台注册的开发者及其名下应用，停用 / 启用 |
| 后台「接入点」 | 列出所有协议端点和插件挂载点；Pages 的 `/w`、开放平台的 `/open` 都能在这里改路径 |

改挂载路径会写 `config/plugins/<slug>.json`。改完页面上的链接和 `sitemap` 会自动跟着变。

## 接入方核对清单

1. 回调地址在应用里登记的和代码里请求的完全一致。
2. 授权请求带 `state`，回调时校验。
3. 用 `code` 换 token 时带上同一个 `redirect_uri`。
4. 只信任 `userinfo` 返回的 `sub` 作为用户标识，不要用用户名或邮箱做唯一键。
5. 需要邮箱时申请 `email` scope，并检查 `email_verified`。
