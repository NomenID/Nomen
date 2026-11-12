# config/email.php

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：`config/email.php`

SMTP 发件配置。整份留空就不发信，注册激活、找回密码、通知邮件都会静默跳过，功能本身不受影响。

共 7 项。

## 配置项

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `host` | `SMTP_HOST` | '' | — |
| `port` | `SMTP_PORT` | 587 | — |
| `username` | `SMTP_USERNAME` | '' | — |
| `password` | `SMTP_PASSWORD` | '' | — |
| `from_email` | `SMTP_FROM_EMAIL` | '' | — |
| `from_name` | `SMTP_FROM_NAME` | — | — |
| `encryption` | `SMTP_ENCRYPTION` | tls | — |

