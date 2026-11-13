# 邮件（SMTP）

没配 SMTP 站点也能跑，只是不发信：注册激活、找回密码、通知邮件全部静默跳过，页面上不会报错。
**所以「注册完收不到邮件」第一件事是看 SMTP 配了没。**

## 配置

`config/email.php`（或者 `.env` 的 `SMTP_*`，或者安装器写进 `config/installed.php` 的 `smtp` 段）：

```php
return [
    'host'       => getenv('SMTP_HOST') ?: ($smtp['host'] ?? ''),
    'port'       => (int)(getenv('SMTP_PORT') ?: ($smtp['port'] ?? 587)),
    'username'   => getenv('SMTP_USERNAME') ?: ($smtp['username'] ?? ''),
    'password'   => getenv('SMTP_PASSWORD') ?: ($smtp['password'] ?? ''),
    'from_email' => getenv('SMTP_FROM_EMAIL') ?: ($smtp['from_email'] ?? ''),
    'from_name'  => getenv('SMTP_FROM_NAME') ?: ($smtp['from_name'] ?? $brand['name']),
    'encryption' => getenv('SMTP_ENCRYPTION') ?: ($smtp['encryption'] ?? 'tls'),
];
```

优先级：`.env` → `installed.php` → 这里的字面量。`host` 空 = 不发信。

常见组合：

| 端口 | encryption | 说明 |
|---|---|---|
| 587 | `tls` | 标准提交端口，先明文再 STARTTLS，**首选** |
| 465 | `ssl` | 一上来就 TLS，老服务商常用 |
| 25 | 空 | 服务器之间投递，很多云厂商封了出站 25 |

`from_email` 要和 `host` 的域名对得上，否则会被对方拒。`from_name` 是显示名，留空用站点名。

## 发件域的三条 DNS

配了 SMTP 只是「能发出去」，进不进收件箱看这三样：

| 记录 | 作用 |
|---|---|
| SPF | 声明哪些服务器可以替你发信。`v=spf1 include:... ~all` |
| DKIM | 给邮件签名，证明没被改过。服务商给一段 TXT，填上即可 |
| DMARC | 告诉对方 SPF/DKIM 不通过时怎么办。`v=DMARC1; p=none; rua=mailto:...` |

先设 `p=none` 观察一段时间，收集报告，再收紧到 `quarantine` / `reject`。**跳过 SPF 直接发，
Gmail 基本直接进垃圾箱。**

## 邮箱策略

`config/email-policy.php` 拦掉一些明显是编的地址：

```php
'reserved_domains' => ['example.com', 'example.net', 'example.org', 'example.edu'],
'reserved_tlds'    => ['test', 'example', 'invalid', 'localhost', 'local', 'onion'],
'blocked_domains'  => [],
```

- 保留域和保留后缀是 RFC 2606 / 6761 / 7686 里的，DNS 上永远收不到信。
- `blocked_domains` 是给站长加自己讨厌的域名用的。
- 拦下来统一回**同一句文案**（`EmailPolicy::REJECT_MESSAGE`），不按域名分理由——分开说等于告诉对方
  「只差一个域名就过了」。

## 通知邮件的节流

管理员「新工单」提醒按 `admin_notify_window` 汇总：窗口内第一个工单进来时发一封，其余只入库，
等下一个窗口一起发。默认 86400 秒（每天最多一封）。

用户侧的通知分类开关在 `user_notification_settings` 里，没有记录表示走默认（发）。

## 测试

最快的办法是用「忘记密码」走一遍：

1. 填一个**真实能收信**的邮箱。
2. 看页面提示是否成功（不管发没发出去，页面都提示成功——防止探测邮箱是否存在）。
3. 去邮箱收；没收到就看错误日志。

日志里会有 SMTP 的连接结果和错误码。常见：

| 报错 | 原因 |
|---|---|
| `Connection refused` | host / port 错，或者出站被封 |
| `Authentication failed` | 用户名密码错，或者该用「授权码」而不是登录密码 |
| `Sender address rejected` | `from_email` 的域名不是这个 SMTP 账号允许的 |
| `Certificate verify failed` | 用 `ssl` 连了不支持的服务，或者服务器时间不对 |

命令行快速验证（不经过 Nomen）：

```bash
swaks --to you@example.com --from noreply@example.com \
      --server smtp.example.com:587 --auth LOGIN \
      --auth-user "<用户名>" --auth-password "<密码>" --tls
```

## 别做的事

- **别在 `from_email` 里填用户的邮箱**。会被当成伪造，直接进垃圾箱。
- **别频繁重发同一封**。激活邮件有节流（`email_verify_last_sent_at`），绕过去就是给自己找限流。
- **别在邮件正文里放令牌以外的隐私**。邮件会转发、会被归档。
- **别把 SMTP 密码写进仓库**。`config/email.php` 是随包分发的文件，密码放 `.env` 或 `installed.php`。

## 检查清单

- [ ] `host` 非空
- [ ] 端口和 encryption 配套（587+tls / 465+ssl）
- [ ] `from_email` 的域名和 SMTP 账号一致
- [ ] SPF / DKIM / DMARC 都配了
- [ ] 用真实邮箱收过一次
- [ ] 错误日志里没有认证失败
