# OpenID Connect

Nomen 除了 OAuth 2.0，也能直接当 OpenID Connect 提供方：第三方只拿一个 `id_token` 就能确认「这个人是谁」，
不用再单独调一次 `/api/userinfo`。开关不用改配置——服务器上的 PHP 有 openssl 扩展就一直开着。

## 端点

| 地址 | 内容 |
|---|---|
| `/.well-known/openid-configuration` | 发现文档：上面几个端点、支持的 scope 和签名算法 |
| `/oidc/jwks.json` | 签名公钥（JWKS），带 `kid` |
| `/oauth/authorize` | 授权端点，OAuth 同一个 |
| `/oauth/token` | 令牌端点，OAuth 同一个 |

发现文档里的 `issuer` 取站点地址（`config/installed.php` 的 `app.base_url`），接入方的 `iss` 校验要以它为准。

## id_token

`POST /oauth/token`（`grant_type=authorization_code`）和刷新令牌的响应里，除了 `access_token` 还会多一个
`id_token`。RS256 签名，`kid` 对应 JWKS 里的那一把。

| claim | 什么时候有 | 说明 |
|---|---|---|
| `iss` / `sub` / `aud` / `iat` / `exp` | 总是 | `aud` 是应用的 client_id，`exp` 按 `access_token_ttl` |
| `nonce` | 授权请求里带了 `nonce` 才有 | 原样回写，客户端自己比对 |
| `preferred_username` / `name` / `picture` | scope 含 `profile` | `picture` 是绝对地址 |
| `email` / `email_verified` | scope 含 `email` | |

`sub` 是**应用级**的：同一个人在不同应用里拿到的 `sub` 不一样，和 `/api/userinfo` 返回的是同一个值。
想按人做关联，用自己应用里的 `sub`，不要拿用户名当主键。

刷新令牌时不会再回写 `nonce`（规范只要求授权码那一步带）。

## 签名密钥

第一次用到时生成 RSA 2048 密钥，存在 `data/oidc-key.json`（`data/` 不进仓库、也不进发布包，权限 0600）。
删掉这个文件等于换密钥：所有缓存过 JWKS 的接入方要重新拉一次。多台机器共用一个 `data/` 才会共用密钥。

没有 openssl 扩展时 `jwks.json` 返回空列表，`/oauth/token` 不发 `id_token`，其余 OAuth 流程照常。

## 接入方要注意

- 反向代理要放行 `/.well-known/` 并交给 PHP。nginx 配置样例和 `INSTALL.md` 里有这一段；
  漏了的话发现文档会 404，而其它端点正常，很难查。
- 校验签名请用 JWKS 里的公钥，不要只看 payload；`kid` 变了就重新拉 JWKS。
- `picture` 是绝对地址；`/api/userinfo` 的 `avatar` 是站内相对路径，两者不一样。

## 测试

不用真账号也能验一大半：拉一次发现文档和 JWKS，结构对、`kid` 和公钥对得上就说明签名链路是通的。
真跑一次登录仍然要用户本人点授权（可能还要过两步验证）。
