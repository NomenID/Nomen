# 第三方登录

用户可以用 GitHub、Google、Microsoft、CaelLabID 或任意兼容 OAuth2 / OIDC 的提供方登录。
提供方存在数据库 `identity_providers`，`config/identity-providers.php` 只是「新建时的预设」。

## 新建一个

后台 →「登录方式」→ 新增：

```json
{
  "slug": "github",
  "name": "GitHub",
  "type": "oauth2",
  "authorize_url": "https://github.com/login/oauth/authorize",
  "token_url": "https://github.com/login/oauth/access_token",
  "userinfo_url": "https://api.github.com/user",
  "emails_url": "https://api.github.com/user/emails",
  "scopes": "read:user user:email",
  "subject_field": "id",
  "email_field": "email",
  "name_field": "name",
  "avatar_field": "avatar_url",
  "email_verified_field": "",
  "token_auth": "post"
}
```

字段含义见 [接入外部身份提供方](integrate/oauth-provider.md)。

**回调地址是固定的**：`https://你的站点/auth/<slug>/callback`，后台表单里实时显示并带复制按钮。
填进对方后台时必须逐字节一致。

## 协议细节

- 授权码模式，`state` 防 CSRF，**PKCE S256**（对方支持就启用）。
- 请求方 IP 限流：`/auth/<slug>/redirect` 每分钟 120 次。
- `type` 取 `oauth2` 或 `oidc`；`oidc` 会走发现文档拿端点（如果对方有）。
- `token_auth` 取 `post` 或 `basic`，决定换 token 时凭据放哪。

## 账号绑定规则

| 情况 | 行为 |
|---|---|
| 对方返回**已验证**邮箱，站内已有同邮箱账号 | 自动绑定到该账号 |
| 邮箱未验证 / 没给邮箱 | 创建一个新账号（用户名冲突自动加后缀） |
| 同一个外部账号重复登录 | 走已有绑定 |

**`email_verified_field` 留空表示信任对方返回的邮箱**。这是个安全取舍：留空时未验证邮箱也能合并到已有账号，
可能被冒名。对方给验证标记就一定要填。

## 预设

| 标识 | 类型 | 默认 scope | 备注 |
|---|---|---|---|
| `github` | oauth2 | `read:user user:email` | 用户信息里邮箱可能为空，会再查 `/user/emails` |
| `google` | oidc | `openid profile email` | 检查 `email_verified` |
| `microsoft` | oidc | `openid profile email` | 用 `sub` 当 ID，不是 `id` |
| `caellabid` | oidc | `openid profile` | `avatar` 是站内相对路径；邮箱 scope 默认没开 |

## 和登录方式总开关的关系

- 后台「登录方式」页的「第三方登录」是**总开关**，关掉后所有 `/auth/*` 回 404。
- 单个提供方的「启用」只控制按钮显不显示、能不能发起授权。
- 总开关关掉时，可用性校验不把第三方算作可用方式。

## 排查

| 现象 | 原因 |
|---|---|
| 400 `invalid_client` | client_id / secret 错，或 `token_auth` 填错 |
| 回调页 500 | 字段映射错、token 交换失败，看日志 |
| 登录后没合并到已有账号 | 对方邮箱未验证 |
| 按钮不显示 | 提供方没启用，或总开关关着 |
| 一直跳回登录页 | `state` 不匹配（cookie 被清？跨域？） |

## 检查清单

- [ ] 回调地址逐字节填进对方后台
- [ ] `subject_field` 是稳定字段
- [ ] `email_verified_field` 填了（对方提供的话）
- [ ] scope 里申请了邮箱
- [ ] 用一个新号完整登录过一次
