# OpenID Connect

Nomen 同时提供 OAuth 2.0 和 OpenID Connect。接入方拿到的是「这个用户是谁」，不涉及站点的其它数据：

- 只需要身份、愿意自己调 `/api/userinfo`：走 OAuth 2.0 授权码流程。
- 标准 OIDC 客户端：读发现文档，从 `id_token` 直接拿身份，不用再调一次接口。

**不需要开关**：PHP 有 openssl 扩展就一直可用；没有 openssl 时只提供普通 OAuth，不发 `id_token`。

## 先建应用

后台「应用」里新建，或者在开放平台（装了 `open-platform`）让开发者自助建。要填两样：

| 字段 | 说明 |
|---|---|
| 回调地址 | 授权码送到哪。**必须逐字节一致**，多一个斜杠都不行 |
| scope | 允许申请的 scope 勾上 |

建完拿到 `client_id` 和 `client_secret`。**secret 只显示一次**，丢了只能重置。需要 PKCE 的应用可以在应用上勾「强制 PKCE」（`oauth_clients.pkce_required`）。

CaelLabID 是其中一个实例，开放平台在 <https://id.caellab.com/open>；其它 Nomen 实例把示例里的域名换成自己的。

## 端点

| 地址 | 用途 |
|---|---|
| `/.well-known/openid-configuration` | 发现文档：端点、scope、签名算法，标准客户端一般只读这一个 |
| `/oidc/jwks.json` | id_token 的签名公钥（RS256），带 `kid` |
| `/oauth/authorize` | 授权页（OAuth 和 OIDC 共用） |
| `/oauth/token` | 授权码换 token、刷新 token |
| `/api/userinfo` | 读用户信息（Bearer） |
| `/api/token-info` | 校验令牌是否有效 |
| `/oauth/revoke` | 撤销 token |

发现文档的 `issuer` 取 `base_url`，接入方校验 `iss` 以它为准。

**nginx 必须放行 `/.well-known/`**：

```nginx
location ^~ /.well-known/ {
    try_files $uri /index.php$is_args$args;
}
```

漏了的表现是「发现文档 404，其余端点都正常」——很容易被误判成没实现 OIDC。

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

## 令牌

| 类型 | 有效期 |
|---|---|
| 授权码 | 10 分钟 |
| access_token | 1 小时 |
| refresh_token | 180 天，**刷新一次旧的立刻失效** |

令牌只存哈希，发出去之后站点自己也拿不到明文。撤销用 `POST /oauth/revoke`（`token` + 可选 `token_type_hint=access_token|refresh_token`），怎么都回 200。

刷新时新的 refresh_token 有效期重新按 180 天算。

## id_token

`id_token` 是 RS256 签名的 JWT，`kid` 对应 JWKS。

| claim | 什么时候有 | 说明 |
|---|---|---|
| `iss` / `sub` / `aud` / `iat` / `exp` | 总是 | `aud` 是 client_id；`exp` 按 `access_token_ttl` |
| `nonce` | 授权请求带了 `nonce` | 原样回写，客户端自己比对 |
| `preferred_username` / `name` / `picture` | scope 含 `profile` | `picture` 是绝对地址 |
| `email` / `email_verified` | scope 含 `email` | |

**`sub` 是应用级的**：同一个人在不同应用拿到的 `sub` 不同，和 `/api/userinfo` 返回的是同一个值。想跨应用关联必须用别的手段。

刷新令牌时也会重新发 `id_token`，但不再回写 `nonce`（规范只要求授权码那一步带）。

## 签名密钥

第一次用到时生成 RSA 2048，存在 `data/oidc-key.json`（0600，不进仓库、不进发布包）。

- **删掉这个文件等于换密钥**：缓存过 JWKS 的接入方要重新拉一次，已签发的 id_token 全部验不过。
- 多台机器共用密钥需要共享 `data/`。
- 没有 openssl 时 `jwks.json` 返回空列表、`/oauth/token` 不发 `id_token`，其余流程照常。

## 验证 id_token

```php
$jwks = json_decode(file_get_contents('https://id.example.com/oidc/jwks.json'), true);
// 用 kid 找到对应公钥，验证 RS256 签名
// 再校验 iss / aud / exp / nonce
```

**别只看 payload 不验签**：`id_token` 是 JWT，payload 是 base64，谁都能改。

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

字段按 scope 给：`openid` 给 `sub`；`profile` 给 `username` / `display_name` / `avatar`；`email` 给 `email` / `email_verified`。

- **`avatar` 可能是站内相对路径**，拼上站点地址再用。
- **`sub` 是应用级的**，别用它跨应用识别同一个人。

| | id_token | userinfo |
|---|---|---|
| 形式 | 一次性签名令牌 | 每次要带 Bearer 去调 |
| `picture` | 绝对地址 | `avatar` 是站内相对路径 |
| claim 定制 | `oidc.claims` 钩子 | `userinfo.claims` 钩子 |

两个钩子不互通：`oidc.claims` 只影响 id_token。

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

`redirect_uri` 不一致是最常见的一类。调试时把两边的地址各打印一份逐字符比，注意末尾斜杠、http/https、端口号。

## 测试

不用真账号能验一大半：

```bash
curl -s https://你的站点/.well-known/openid-configuration | python -m json.tool
curl -s https://你的站点/oidc/jwks.json | python -m json.tool
```

结构对、`kid` 和公钥对得上，签名链路就是通的。真跑一次登录仍然要用户点授权（可能还有两步验证）。

## 坑

- **反代只放行了 `/oauth/*`**：发现文档 404。
- **CF 缓存了 `/.well-known/*`**：密钥轮换后接入方还拿旧的。给这个路径短缓存。
- **`iss` 和 `base_url` 不一致**：换了域名没改 `base_url`，所有接入方的 `iss` 校验都会失败。
- **私钥文件权限**：`data/oidc-key.json` 必须是 0600 且属主是 `www`。
- **`redirect_uri` 多一个斜杠**：授权码换 token 时直接 `invalid_grant`。

## 检查清单

- [ ] 发现文档 200，`issuer` 是正确地址
- [ ] `jwks.json` 有 `kid`
- [ ] nginx 放行了 `/.well-known/`
- [ ] `data/oidc-key.json` 在备份里
- [ ] 校验了 `state`
- [ ] 验签并校验了 `iss` / `aud` / `exp` / `nonce`
- [ ] `sub` 当用户主键，不是邮箱
- [ ] 存了 `refresh_token` 并处理「旧的失效」
- [ ] 令牌缓存到过期，不是每次请求都换一次
