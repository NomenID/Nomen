# 把 Nomen 当身份提供方接入

Nomen 同时说 OAuth 2.0 和 OpenID Connect。接入方拿到的是「这个用户是谁」，不涉及站点的其它数据。

## 先建应用

后台「应用」里新建，或者在开放平台（装了 `open-platform`）让开发者自助建。要填两样：

| 字段 | 说明 |
|---|---|
| 回调地址 | 授权码送到哪。**必须逐字节一致**，多一个斜杠都不行 |
| scope | 允许申请的 scope 勾上 |

建完拿到 `client_id` 和 `client_secret`。**secret 只显示一次**，丢了只能重置。

需要 PKCE 的应用可以在应用上勾「强制 PKCE」（`oauth_clients.pkce_required`）。

## 端点

| 端点 | 用途 |
|---|---|
| `GET /.well-known/openid-configuration` | 发现文档，接入方一般只读这一个 |
| `GET /oidc/jwks.json` | id_token 的签名公钥（RS256） |
| `GET /oauth/authorize` | 授权页 |
| `POST /oauth/token` | code 换 token、刷新 token |
| `GET /api/userinfo` | 取用户信息（Bearer） |
| `GET /api/token-info` | 校验令牌 |
| `POST /oauth/revoke` | 撤销令牌 |

**发现文档要 nginx 放行**，否则接入方第一步就 404：

```nginx
location ^~ /.well-known/ {
    try_files $uri /index.php$is_args$args;
}
```

现象是「发现文档 404、其余端点都正常」，很容易被误判成没实现 OIDC。

## 授权码流程

```
GET /oauth/authorize
    ?response_type=code
    &client_id=<client_id>
    &redirect_uri=https%3A%2F%2Fapp.example.com%2Fcallback
    &scope=openid%20profile%20email
    &state=<随机串，回来要校验>
    &code_challenge=<base64url(sha256(verifier))>
    &code_challenge_method=S256
    &nonce=<随机串，只有 OIDC 用>
```

用户确认后跳回：

```
https://app.example.com/callback?code=<码>&state=<原样带回>&spicallogin=<可选>
```

- **`state` 必须校验**，不然是 CSRF。
- **`spicallogin` 只是「快速登录」标记**，值别比对（站点之间不一样），看到就当提示用。
- `code` 有效期 **10 分钟**，用一次立刻作废。

换 token：

```bash
curl -s -X POST https://id.example.com/oauth/token \
  -u "<client_id>:<client_secret>" \
  -d grant_type=authorization_code \
  -d code="<码>" \
  -d redirect_uri="https://app.example.com/callback" \
  -d code_verifier="<原始 verifier>"
```

也可以把 `client_id` / `client_secret` 放表单里，两种都支持。返回：

```json
{
  "access_token": "...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "...",
  "scope": "openid profile email",
  "id_token": "eyJhbGciOiJSUzI1NiIs..."
}
```

`id_token` 只在开了 OIDC 且申请了 `openid` 时出现。验签用 `/oidc/jwks.json` 里的公钥，
顺便校验 `iss`（站点地址）、`aud`（你的 client_id）、`nonce`。

## 令牌

| | 有效期 |
|---|---|
| 授权码 | 10 分钟 |
| access_token | 1 小时 |
| refresh_token | 180 天，**刷新一次旧的立刻失效** |

令牌只存哈希，发出去之后站点自己也拿不到明文。撤销用 `POST /oauth/revoke`（`token` + 可选
`token_type_hint=access_token|refresh_token`），怎么都回 200。

## 用户信息

```bash
curl -s https://id.example.com/api/userinfo -H "Authorization: Bearer <access_token>"
```

```json
{
  "sub": "01J8ZK...",
  "username": "yunyun",
  "display_name": "云云",
  "avatar": "/assets/avatars/xxxx.webp",
  "email": "user@example.com",
  "email_verified": true
}
```

两个坑：

1. **`sub` 是应用级的**。同一个用户在你的应用和别人的应用拿到不同的 `sub`，别指望用它跨应用识别同一个人。
2. **`avatar` 可能是站内相对路径**。拼成绝对地址再用：`https://id.example.com` + 路径。

字段按 scope 给：`openid` 给 `sub`；`profile` 给 `username` / `display_name` / `avatar`；
`email` 给 `email` / `email_verified`。

## 限流

| 端点 | 限制（按请求方 IP） |
|---|---|
| `/oauth/token` | 30 次 / 分钟 |
| `/api/userinfo` | 60 次 / 分钟 |
| `/api/token-info` | 30 次 / 分钟 |

超了回 429，响应头带 `Retry-After`。**服务端之间调用不要按每个用户去换 token**，缓存 access_token 到过期。

## 错误

统一格式：

```json
{"error": "invalid_grant", "error_description": "Authorization code is invalid or expired"}
```

| error | 常见原因 |
|---|---|
| `invalid_client` | client_id / secret 错，或认证方式不对 |
| `invalid_grant` | 码过期、用过、redirect_uri 不一致 |
| `invalid_request` | 缺参数、`response_type` 不对 |
| `invalid_scope` | 申请了没勾选的 scope |
| `rate_limited` | 触发限流 |

`redirect_uri 不一致` 是最常见的一类。调试时把两边的地址各打印一份逐字符比，注意末尾斜杠、http/https、
端口号。

## 接入方检查清单

- [ ] 校验了 `state`
- [ ] 校验了 `id_token` 的签名、`iss`、`aud`、`nonce`（用 OIDC 时）
- [ ] 存了 `refresh_token` 并且处理「旧的失效」
- [ ] `sub` 当用户主键，不是邮箱（邮箱会换）
- [ ] 头像地址补了绝对路径前缀
- [ ] 令牌缓存到过期，不是每次请求都换一次
