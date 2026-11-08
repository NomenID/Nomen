# 邮件与通知

验证码、激活链接、恢复链接、安全提醒、站内通知的邮件都走 SMTP。

## SMTP 配置

`config/email.php` 或 `.env`：

| 键 | 说明 |
|---|---|
| `host` | SMTP 服务器；为空时视为未配置，所有发信入口隐藏或降级 |
| `port` | 默认 587 |
| `username` / `password` | 认证凭据 |
| `from_email` | 发件地址 |
| `from_name` | 发件人名称，缺省用站点名 |
| `encryption` | `tls` / `ssl` / 空 |

`.env` 里的键是 `SMTP_HOST` / `SMTP_PORT` / `SMTP_USERNAME` / `SMTP_PASSWORD` /
`SMTP_FROM_EMAIL` / `SMTP_FROM_NAME` / `SMTP_ENCRYPTION`。

`host` 为空时 `Email::isConfigured()` 返回 false：邮箱验证码登录、邮箱二验这些入口会隐藏，
避免用户点进去却收不到信。

发件人名称默认取 `config/brand.php` 的 `name`，不是写死的 Nomen。

## 会发出的邮件

| 场景 | 收件人 |
|---|---|
| 注册激活 / 邮箱验证 | 用户邮箱 |
| 登录验证码、二验验证码 | 已验证邮箱 |
| 改密码验证码 | 已验证邮箱 |
| 第三方登录绑定提醒 | 账号邮箱 |
| 账号恢复：受理通知 | 申诉填的联系邮箱 + 账号邮箱（已验证时） |
| 账号恢复：批准 / 拒绝 / 完成 | 联系邮箱 / 账号邮箱 |
| 家庭：邀请家长、家长加入、重置孩子密码 | 对应邮箱 |
| 站内通知的邮件副本 | 按用户的通知偏好 |
| 管理员新恢复工单提醒 | `admin_notify_email` |

邮件主题统一带站点名前缀（`EmailTemplate::subjectPrefix()`），正文由 `EmailTemplate::render()` 生成。
模板里的文案都是词典键，按收件人语言渲染。

## 站内通知

三类，用户在设置页分别开关：

| 类别 | 默认 |
|---|---|
| 重要系统通知 `system_important` | 开 |
| 一般系统通知 `system_general` | 关 |
| 系统互动 `system_interact` | 关 |

站内通知入库时同时决定要不要发邮件。发信是排队的：`notifications` 表里有 `email_sent_at`，
由常驻进程消费：

```bash
php scripts/mail-drain.php
```

用 systemd 或 cron 拉起，同时只能有一个实例（`scripts/mail-drain.lock`）。

## 发信限流

同一用户：

| 窗口 | 上限 |
|---|---|
| 每分钟 | 1 封 |
| 每小时 | 3 封 |
| 每天 | 5 封 |

超过时接口返回等待秒数。管理员通知不受这个限制，但按 `admin_notify_window` 节流。

## 验证码与链接有效期

| 项 | 有效期 |
|---|---|
| 邮箱验证码 | 10 分钟 |
| 验证码可重发窗口 | 30 分钟（同一验证码上下文） |
| 邮箱验证链接 | 24 小时 |
| 恢复链接 | 7 天（`recovery_token_ttl`） |

验证码只存哈希，用过即失效。

## 拒收的邮箱域名

`config/email-policy.php`：

```php
return [
    'reserved_domains' => ['example.com', 'example.net', 'example.org', 'example.edu'],
    'reserved_tlds'    => ['test', 'example', 'invalid', 'localhost', 'local', 'onion'],
    'blocked_domains'  => [],
];
```

- `reserved_domains`：RFC 2606 保留域，永远收不到信。
- `reserved_tlds`：保留 / 特殊用途后缀。
- `blocked_domains`：想额外拦的域名往这里加。

被拦的地址一律回同一句文案，不按域名分几句报错——那等于告诉对方「只差一个域名就过了」。

## 排查

1. 后台「设置」页看「邮件已配置」是否为真。
2. 手动触发一封（注册 / 改密码验证码），看 PHP 错误日志里的 SMTP 返回。
3. 通知邮件没发出去，先确认 `mail-drain.php` 在跑、锁文件没被占用。
4. 收件方是 QQ / 163 这类信箱时，检查发件域名的 SPF / DKIM / DMARC，否则容易进垃圾箱。
