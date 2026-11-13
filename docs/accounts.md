# 账号与登录

一个账号从注册到注销，中间涉及的所有入口和数据结构。运维要知道的都在这里。

## 注册流程

```
填表 → 存 pending_registrations → 发激活邮件 → 点链接 → 建 users 行 → 自动登录
```

- 表单先过邮箱策略（`config/email-policy.php`），保留域和保留 TLD 直接拒。
- 同意年龄按 `config/consent-age.php` 判定，低于门槛的账号需要监护人。
- 账号在 `pending_registrations` 里最多留 `pending_registrations` 的过期时间，过期自动清。
- **激活链接里是一次性令牌**，验证通过才建账号。

激活页是 `/activate`，重发是 `POST /activate/send`。

## 五种登录方式

后台「登录方式」页可以逐项开关，存在 `site_settings` 的 `login.method_*`：

| 方式 | 入口 | 依赖 |
|---|---|---|
| 密码 | `POST /login` | 无 |
| 邮箱验证码 | `POST /login/send-code` → `/login/code` | SMTP + 已验证邮箱 |
| 临时密钥 | `GET /fast-pass`、`POST /login/temp-key` | 用户先在自己的设置页生成 |
| 生物识别 | `/biometric/auth/options` → `verify` | 浏览器支持 WebAuthn，用户注册过凭据 |
| 第三方 | `/auth/<slug>/redirect` | 后台建了提供方 |

**至少留一种可用方式**：关到没有可用方式时，保存会 422。第三方登录要算「可用」，
必须至少有一个已启用的提供方。

被关掉的方式：页面上不显示，接口直接拒绝，不会绕道。

## 会话

| 场景 | 有效期 |
|---|---|
| 未登录访客 | 2 小时（`session_lifetime`） |
| 正常登录 | 30 天滑动，每次访问续期（`login_ttl`） |
| 从登录算起 | 最多 90 天（`login_max_ttl`） |
| 勾了「这是公共设备」 | 浏览器会话 cookie，关浏览器失效 |

**没有「记住我」这个选项**（1.4.1 起）。默认就是长期会话，公共设备才降级——这样五种登录方式行为一致。

`last_login_at` 只在登录成功的入口写（`AuthController::touchLogin()`），后台显示的「从未登录」就是没写过。

## 设置页

`/settings` 下面这一堆，都是登录后可达：

| 功能 | 地址 |
|---|---|
| 资料（显示名、简介） | `POST /settings/profile` |
| 头像 | `POST /settings/avatar` |
| 密码 | `POST /settings/password`（可发验证码代替旧密码） |
| 邮箱管理 | `/settings/emails/{add,primary,remove,resend,verify}` |
| 设备 | `POST /settings/devices/revoke`、`revoke-all` |
| 生物识别 | `/settings/biometrics`、`/delete` |
| 临时密钥 | `/settings/temp-keys`、`/generate`、`/delete` |
| 两步验证 | `/settings/two-factor/policy`、`/2fa/*` |
| 通知开关 | `POST /settings/notifications` |
| 语言 | `POST /settings/locale` |
| 皮肤 | `POST /settings/skin` |
| 第三方绑定 | `POST /settings/identities/unlink` |

## 多邮箱

一个账号可以挂多个邮箱，其中一个是主邮箱：

- 主邮箱决定登录、通知的默认地址。
- `users.email` 是**旧字段**，真实数据在 `user_emails`。
- 换主邮箱要验证新地址，验证通过才切。
- 改邮箱分两步：`pending_email` 暂存新地址，验证通过后写进 `user_emails`。

**判断「有没有验证过的邮箱」两边都要看**：`users.email_verified` 只管主邮箱，孩子账号可能一个都没有。

## 设备

登录过的设备记在 `user_devices`：`device_id`、名称、IP、UA、首次/最后出现时间、是否被信任。

- 用户可以在设置页撤销单台或全部设备。
- `trusted_at` 非空表示信任过——两步验证策略是 `new_device` 时，可信设备不要求第二因素。

## 注销账号

`POST /activate/delete-account`：删账号、清会话。**关联数据按表分别处理**：

- 邮箱、设备、二验、恢复码、临时密钥：一起删。
- 活动日志、通知：保留（审计需要），但不再关联具体人。
- 第三方绑定：删。
- 插件自己的表：插件自己处理（核心不知道）。

要「彻底清」的站点，删完还要清插件表里的 `user_id` 引用。

## 排查

| 现象 | 原因 |
|---|---|
| 登录后立刻被弹到 `/activate` | 没有任何已验证的邮箱 |
| 密码对但登录失败 | pepper 变了（库和 `installed.php` 不是一套） |
| 收不到验证码 | SMTP 没配，或邮箱被策略拦了 |
| 设备列表里同一个浏览器出现多次 | `device_id` 存在 cookie 里，清过 cookie 就是新设备 |
| 后台显示「从未登录」 | 该账号从没走过 `touchLogin()`（注册后直接登录、点激活链接的路径也会写） |

## 检查清单

- [ ] 至少一种登录方式开着
- [ ] SMTP 可用（邮箱验证码和激活都靠它）
- [ ] 未验证邮箱的账号会被拦到激活页（不是直接可用）
- [ ] 公共设备的会话不会长期留着
- [ ] 注销流程清掉了二验和恢复码
