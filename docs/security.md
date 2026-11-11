# 安全策略

这一页列站点侧的安全相关配置和默认行为。改之前先理解每项在挡什么。

## 密钥

| 项 | 位置 | 注意 |
|---|---|---|
| `APP_PEPPER` | `.env` / `installed.php` 的 `pepper` | 参与全部密码哈希。**上线后有用户就不能改**，改了所有密码永久无法验证 |
| `APP_SECRET` | `.env` / `installed.php` 的 `secret` | 预留，当前代码没有使用 |
| `APP_DEBUG` | `.env` | 线上必须 `0`。开着会显示异常类型、消息和调用栈 |

pepper 还在用默认值时，PHP 错误日志会记一条 `[SECURITY]` 警告。

## 密码与令牌

- 密码、临时密钥、恢复码用 **Argon2id** 哈希。
- OAuth 的 client_secret / access_token / refresh_token 用 `token_hash_algorithm`（默认 SHA-512）哈希后入库。
- 授权码 10 分钟、access_token 1 小时、refresh_token 180 天；刷新时旧 refresh_token 立刻失效。
- 恢复链接、邮箱验证链接都只存哈希，一次性。

## 会话与请求

| 项 | 默认 |
|---|---|
| 未登录访客的会话 | 2 小时 |
| 登录保持 | 30 天滑动续期，最长 90 天；勾「这是公共设备」则为浏览器会话 |
| 会话 cookie 名 | `NOMEN_SESSION` |
| CSRF | 所有 POST 校验 `_csrf`，`csrf_enabled` 保持 `true` |
| 响应头 | `X-Content-Type-Options: nosniff`、`X-Frame-Options: DENY`、`Referrer-Policy: strict-origin-when-cross-origin`、CSP `frame-ancestors 'none'` |

HSTS 由 nginx 统一设置，程序不重复发。

多套 Nomen 跑在同一个域名下时，`SESSION_NAME` 必须各不相同，否则会话会串。

## 登录防护

| 项 | 默认 | 说明 |
|---|---|---|
| `login_captcha_threshold` | 5 次/分钟 | 单 IP 登录尝试超过这个数才要求图片验证码 |
| `login_ip_hard_limit` | 20 次/分钟 | 单 IP 硬上限，超过直接拒绝 |
| 账号级限制 | 5 次/分钟 | 针对同一个账号 |
| 注册 | 3 次/小时/IP | |
| 回收未验证账号邮箱 | 20 次/小时/IP | `/register/verify` |

图片验证码需要 GD 扩展；没装时这一步整体跳过，日志里会有提示。

## 两步验证

站点策略默认 `off`。可选 `new_device` / `every_login`，见 [两步验证](two-factor.md)。
二验待验证会话 10 分钟有效。账号一个可用的第二因素都没有时直接放行，避免锁死。

## 账号恢复

| 项 | 默认 |
|---|---|
| 单 IP 申诉提交 | 5 次/小时 |
| 单 IP 附件上传 | 40 次/小时 |
| 附件大小 / 数量 | 1MB / 5 张 |
| 恢复链接 | 7 天、一次性 |
| 新工单提醒 | 每天最多一封汇总 |

恢复完成后强制撤销其他设备会话、全部 OAuth 令牌、全部第三方应用授权。

## 限流依赖 Redis

**限流只在配了 Redis 且连接正常时生效。** Redis 不可用时 `RateLimiter::check()` 直接放行——
这是有意的取舍：限流是加固，不该在 Redis 抖动时把所有人挡在门外。代价是那段时间限流形同不存在，
后台概览的 Redis 状态卡片会提示。

要高可用就配 Redis；不配的话，至少靠 nginx 层做一层 IP 限流。

## 邮件相关

发信限流（每用户 1/分钟、3/小时、5/天）挡的是验证码轰炸。拒收域名在 `config/email-policy.php`，
见 [邮件与通知](email.md)。

## 开放平台与第三方登录

- 授权页带 `state` 与 PKCE S256；回调地址必须和登记值完全一致。
- `sub` 是应用级 ID，不同应用之间不可关联。
- 第三方登录只信任提供方返回的**已验证**邮箱做自动绑定；未验证邮箱会新建账号。
- 公共客户端强制 PKCE，机密客户端可以额外要求。

## 部署侧

- web root 只指向 `public/`，不要把 `config/`、`data/`、`vendor/` 暴露出去。
- `config/installed.php`、`.env` 不进仓库、不给 web 服务器之外的人读。
- 传输走 HTTPS；反代 / CDN 场景下确认程序拿到的 `REMOTE_ADDR` 是真实访客地址，
  否则限流会按反代 IP 统计。
- 定期备份数据库、`data/`、`public/assets/avatars/`、`public/assets/recovery/`、`public/assets/develops/`。
