# 钩子：邮件与通知

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：`docs-internal/docmeta/hooks.json` 和代码里的 `Hooks::filter` / `Hooks::emit` 调用点

## 本组钩子

| 钩子 | 类型 | 参数 | 时机 |
|---|---|---|---|
| `email.sending` | filter | payload（to / subject / body）, embedded | 真正投递 SMTP 之前；返回数组可替换 |
| `email.sent` | run | to, subject, ok, error | SMTP 投递结束（成功或失败都触发）；SMTP 未配置 / vendor 缺失也算 `ok=false` |
| `notification.created` | run | userId, category, title, body, notificationId | 站内通知入库之后、发邮件之前 |

## 触发点

### email.sending

- 类型：filter
- 参数：payload（to / subject / body）, embedded
- 时机：真正投递 SMTP 之前；返回数组可替换
- 触发点：
  - `src/Email.php`（filter）

### email.sent

- 类型：run
- 参数：to, subject, ok, error
- 时机：SMTP 投递结束（成功或失败都触发）；SMTP 未配置 / vendor 缺失也算 `ok=false`
- 触发点：
  - `src/Email.php`（run）

### notification.created

- 类型：run
- 参数：userId, category, title, body, notificationId
- 时机：站内通知入库之后、发邮件之前
- 触发点：
  - `src/NotificationHelper.php`（run）

