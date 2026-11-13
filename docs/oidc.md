# OpenID Connect

除了 OAuth 2.0，Nomen 也能直接当 OpenID Connect 提供方：接入方只拿一个 `id_token` 就知道「这个人是谁」，
不用再调一次 `/api/userinfo`。**不需要开关**：PHP 有 openssl 扩展就一直可用。

## 端点

| 地址 | 内容 |
|---|---|
| `/.well-known/openid-configuration` | 发现文档：端点、scope、签名算法 |
| `/oidc/jwks.json` | 签名公钥（JWKS），带 `kid` |
| `/oauth/authorize` | 授权端点（和 OAuth 共用） |
| `/oauth/token` | 令牌端点（和 OAuth 共用） |

发现文档的 `issuer` 取 `base_url`，接入方校验 `iss` 以它为准。

**nginx 必须放行 `/.well-known/`**：

```nginx
location ^~ /.well-known/ {
    try_files $uri /index.php$is_args$args;
}
```

漏了的表现是「发现文档 404，其余端点都正常」——很难查。

## id_token

`POST /oauth/token`（`grant_type=authorization_code`）的响应里多一个 `id_token`。RS256，`kid` 对应 JWKS。

| claim | 什么时候有 | 说明 |
|---|---|---|
| `iss` / `sub` / `aud` / `iat` / `exp` | 总是 | `aud` 是 client_id；`exp` 按 `access_token_ttl` |
| `nonce` | 授权请求带了 `nonce` | 原样回写，客户端自己比对 |
| `preferred_username` / `name` / `picture` | scope 含 `profile` | `picture` 是绝对地址 |
| `email` / `email_verified` | scope 含 `email` | |

**`sub` 是应用级的**：同一个人在不同应用拿到的 `sub` 不同，和 `/api/userinfo` 返回的是同一个值。
想跨应用关联必须用别的手段。

刷新令牌时不会再回写 `nonce`（规范只要求授权码那一步带）。

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

## 和 `/api/userinfo` 的差别

| | id_token | userinfo |
|---|---|---|
| 形式 | 一次性签名令牌 | 每次要带 Bearer 去调 |
| `picture` | 绝对地址 | `avatar` 是站内相对路径 |
| claim 定制 | `oidc.claims` 钩子 | `userinfo.claims` 钩子 |

两个钩子不互通：`oidc.claims` 只影响 id_token。

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

## 检查清单

- [ ] 发现文档 200，`issuer` 是正确地址
- [ ] `jwks.json` 有 `kid`
- [ ] nginx 放行了 `/.well-known/`
- [ ] `data/oidc-key.json` 在备份里
- [ ] 接入方验签 + 校验 `nonce`
