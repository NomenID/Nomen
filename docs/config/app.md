# config/app.php

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：`config/app.php`

框架主配置：版本号、安全参数、会话有效期、限流阈值、可选功能开关、数据库和 Redis 连接。大部分键可以由 `.env` 或安装器生成的 `config/installed.php` 覆盖，表里的「默认值」是这两者都没写时用的兜底。

共 50 项。

## 基本信息

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `name` | — | ← brand.name | 取自 `config/brand.php` 的 `name` |
| `version` | — | 1.7.0 | 版本号，整个仓库只有这一处；发版改这里，打包脚本从它取包名 |
| `base_url` | — | ← brand.base_url | 取自 `config/brand.php` 的 `base_url` |

## 安全

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `pepper` | `APP_PEPPER` | change-me-to-another-random-string | ⚠️ pepper 已参与全部密码哈希，一旦上线【绝不可更改】更改后所有用户密码将永久无法验证，且无法回滚 |
| `secret_key` | `APP_SECRET` | change-me-to-a-random-string | 暂未被任何代码使用，保留待用 |
| `csrf_enabled` | — | true | 是否校验 CSRF token。正常别关，关了所有表单都没有跨站防护 |

## Session

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `session_lifetime` | — | 7200 | 未登录访客的 cookie 有效期：2小时 |
| `session_name` | — | ← brand.session_name | 取自 `config/brand.php` 的 `session_name` |

## 登录后的默认有效期

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `login_ttl` | — | 2592000 | 30 天内没再来就重新登录，每次访问自动续期 |
| `login_max_ttl` | — | 7776000 | 从登录那一刻起最长保留 90 天，到点必须重新登录 |

## 登录防护

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `login_captcha_threshold` | — | 5 | 单 IP 每分钟登录尝试超过该值才要求图片验证码 |
| `login_ip_hard_limit` | — | 20 | 单 IP 每分钟登录尝试硬上限，超过直接拒绝 |
| `two_factor_pending_ttl` | — | 600 | 二验待验证会话有效期（秒） |
| `login_failed_method_ttl` | — | 900 | 登录页「本次已尝试」的记忆时长（秒）：超出这个时间就不再提醒，免得用户隔天回来还看到「你刚才密码没登进去」 |
| `support_email` | — | ← brand.support_email | 对外联系方式（页面上给用户看的支持邮箱，别写成管理员私人信箱） |

## 管理员「新工单」提醒的收件人

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `admin_notify_email` | — | ← brand.admin_notify_email | 管理员自己的信箱，与对外的 support_email 分开。提醒本身按下面的 admin_notify_window 节流汇总，不会每单都发。 |

## 账号恢复

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `recovery_ctx_ttl` | — | 7200 | 申诉页账号上下文有效期（秒） |
| `recovery_submit_limit` | — | 5 | 单 IP 申诉提交次数上限 |
| `recovery_submit_window` | — | 3600 | 上面那条的统计窗口（秒） |
| `recovery_token_ttl` | — | 604800 | 恢复登录链接有效期（秒） |
| `recovery_attach_max_bytes` | — | 1048576 | 单张证明材料上限（1MB，超了后端直接拒收） |
| `recovery_attach_max_count` | — | 5 | 单次申诉最多几张 |
| `recovery_attach_tmp_ttl` | — | 7200 | 暂存区里没提交的图多久清掉（秒） |
| `recovery_upload_limit` | — | 40 | 单 IP 每小时上传次数上限 |
| `recovery_upload_window` | — | 3600 | 上面那条上传次数上限的统计窗口（秒） |
| `admin_notify_window` | — | 86400 | 管理员「新工单」提醒的节流窗口（秒）。全站共用一个窗口：窗口内第一个工单进来时立刻发一封汇总，之后窗口内的工单只入库不发信，等下一个窗口开启时连带一起发过去。 86400 = 每天最多一封（用户要的档位）；想更及时就改小。 |

## OAuth

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `auth_code_ttl` | — | 600 | 授权码有效期 10分钟 |
| `access_token_ttl` | — | 3600 | 访问令牌有效期 1小时 |
| `refresh_token_ttl` | — | 15552000 | 刷新令牌有效期 180天 |
| `token_hash_algorithm` | — | sha512 | Token 哈希算法（兼容旧的 SHA-256 记录） |

## 可选功能

默认全关，不需要的站长不用被迫接受。用 `FEATURE_GUARDIANS` / `FEATURE_COUNTRY` 环境变量，或 `config/installed.php` 的 `features` 段打开；安装向导的「扩展」一步也能勾。

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `features.guardians` | — | 关（用 FEATURE_* 或 `installed.php` 打开） | 家庭与未成年监护：家长同意、家长邮箱、家庭页 |
| `features.country` | — | 关（用 FEATURE_* 或 `installed.php` 打开） | 注册时填写国家 / 地区（含按 IP 预填） |

## 多语言

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `language_default` | `APP_LANGUAGE` | zh-hans | 没登录、浏览器语言也匹配不上时用的语言；安装向导的「站点」一步可以选 |

## 关掉后全站固定默认语言

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `language_allow_switch` | — | true | 语言选择器、?lang=、账号语言偏好全部停用 |

## 数据库

driver 取 mysql / mariadb / sqlite SQLite 用 path，相对路径按项目根算；其余字段忽略

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `db.driver` | `DB_DRIVER` | mysql | `mysql` / `mariadb` / `sqlite`；写 `sqlite` 时只看 `path` |
| `db.host` | `DB_HOST` | 127.0.0.1 | MySQL 主机名或 IP |
| `db.port` | `DB_PORT` | 3306 | MySQL 端口 |
| `db.name` | `DB_NAME` | nomen | 库名 |
| `db.user` | `DB_USER` | nomen | 库账号。每个库用自己的专用账号，别用 root |
| `db.pass` | `DB_PASS` | '' | 库密码 |
| `db.path` | `DB_PATH` | database/nomen.sqlite | SQLite 文件路径，相对路径按项目根算 |

## 其他

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `redis.host` | `REDIS_HOST` | 127.0.0.1 | Redis 主机名或 IP |
| `redis.port` | `REDIS_PORT` | 6379 | Redis 端口 |
| `redis.db` | `REDIS_DB` | 1 | Redis 库编号，和别的程序共用实例时错开 |
| `redis.password` | `REDIS_PASSWORD` | '' | Redis 密码，没设密码就留空 |

## 其他

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `scopes.openid` | — | Basic profile | 只给 `sub`，身份标识 |
| `scopes.profile` | — | username, display name, avatar | 昵称和头像 |
| `scopes.email` | — | Verified email | 已验证的邮箱 |

## 其他

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `webauthn_origin` | — | ← brand.base_url | WebAuthn 的 RP ID，必须和浏览器地址栏的站点一致 |
| `webauthn_rp_name` | — | ← brand.name | WebAuthn 弹窗里显示的站点名 |

