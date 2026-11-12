# 数据表：账号与身份

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：`database/schema.sql` 和 `docs-internal/docmeta/tables.json`

## 账号与身份

### users

账号主表。一行一个账号，密码哈希、状态、角色、TOTP 开关都在这里。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `id` | INT | 否 | — | 自增主键 |
| `unid` | CHAR(26) | 否 | — | 对外编号（26 位 ULID，界面上只露这个） |
| `oauth_id` | VARCHAR(100) | 是 | NULL | 早期第三方登录留下的外部 ID，之后接入的走 `user_identities` |
| `username` | VARCHAR(32) | 否 | — | 登录名，唯一；界面上优先显示 `display_name` |
| `email` | VARCHAR(255) | 是 | NULL | 主邮箱（旧字段，多邮箱的实际数据在 `user_emails`） |
| `country` | CHAR(2) | 是 | NULL | 国家 / 地区代码，开启 `country` 可选功能才收集 |
| `birth_date` | DATE | 是 | NULL | 出生日期，参与同意年龄判定 |
| `gender` | VARCHAR(30) | 是 | NULL | 性别，取值在 `config/genders.php` |
| `locale` | VARCHAR(16) | 是 | NULL | 账号的语言偏好，优先级高于浏览器语言 |
| `skin` | VARCHAR(32) | 是 | NULL | 账号选的皮肤，未登录设备用站点默认 |
| `password_hash` | VARCHAR(255) | 否 | '' | 密码哈希（掺了 `config/app.php` 的 pepper） |
| `display_name` | VARCHAR(64) | 是 | NULL | 显示名，留空时界面上回退成用户名 |
| `avatar` | VARCHAR(512) | 是 | NULL | 头像地址，上传的落在 `public/assets/avatars/` |
| `bio` | TEXT | 是 | — | 个人简介 |
| `email_verified` | TINYINT(1) | 否 | 0 | 主邮箱是否已验证；未验证会拦在 `/activate` |
| `email_verify_token` | VARCHAR(64) | 是 | NULL | 注册激活令牌（一次性） |
| `email_verify_token_expires_at` | DATETIME | 是 | NULL | 激活令牌的过期时间 |
| `email_verify_last_sent_at` | DATETIME | 是 | NULL | 上次重发激活邮件的时刻，用来节流 |
| `pending_email` | VARCHAR(255) | 是 | NULL | 换邮箱时暂存的新地址，验证通过才写进 `user_emails` |
| `pending_email_token` | VARCHAR(64) | 是 | NULL | 换邮箱的确认令牌 |
| `pending_email_token_expires_at` | DATETIME | 是 | NULL | 换邮箱令牌的过期时间 |
| `status` | TINYINT(1) | 否 | 1 | 1 启用、0 禁用；禁用后登录一律拒绝 |
| `role` | ENUM('USER','ADMIN') | 否 | user | `user` 或 `admin` |
| `two_factor_policy` | ENUM('EVERY_LOGIN','NEW_DEVICE') | 否 | new_device | 二验策略：`every_login` 每次登录都要，`new_device` 只在新设备上要 |
| `last_login_at` | DATETIME | 是 | NULL | 最后登录时间，只在登录成功的入口写 |
| `last_login_ip` | VARCHAR(45) | 是 | NULL | 最后登录 IP |
| `created_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 创建时间 |
| `updated_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 最后修改时间 |
| `password_reset_code` | VARCHAR(6) | 是 | NULL | — |
| `password_reset_code_expires_at` | DATETIME | 是 | NULL | 时间 |

索引：唯一 `uk_unid`（unid）；主键 `PRIMARY`（id）；唯一 `username`（username）；唯一 `email`（email）；唯一 `uk_oauth_id`（oauth_id）；普通 `idx_email`（email）；普通 `idx_username`（username）；普通 `idx_email_verify_token`（email_verify_token）；普通 `idx_pending_email_token`（pending_email_token）

### user_emails

账号下的邮箱。一个账号可以挂多个邮箱，其中一个是主邮箱；登录、通知、找回都按这里的验证状态判断。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `id` | INT | 否 | — | 自增主键 |
| `user_id` | INT | 否 | — | 所属账号（`users.id`） |
| `email` | VARCHAR(255) | 否 | — | — |
| `is_primary` | TINYINT(1) | 否 | 0 | — |
| `verified` | TINYINT(1) | 否 | 0 | — |
| `verify_token` | VARCHAR(64) | 是 | NULL | — |
| `verify_token_expires_at` | DATETIME | 是 | NULL | 时间 |
| `created_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 创建时间 |
| `verified_at` | DATETIME | 是 | NULL | 时间 |
| `secondary_email` | VARCHAR(255) | 是 | — | — |

索引：主键 `PRIMARY`（id）；唯一 `uniq_user_email`（user_id, email）；唯一 `uniq_secondary_email`（secondary_email）；普通 `idx_email`（email）

### pending_registrations

待激活的注册。填完注册表单、还没点激活链接时暂存在这里，激活或过期后清掉。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `id` | BIGINT | 否 | — | 自增主键 |
| `email` | VARCHAR(255) | 否 | — | — |
| `username` | VARCHAR(32) | 否 | — | — |
| `password_hash` | VARCHAR(255) | 否 | — | 密码哈希（掺了 `config/app.php` 的 pepper） |
| `country` | CHAR(2) | 是 | NULL | — |
| `birth_date` | DATE | 是 | NULL | — |
| `gender` | VARCHAR(30) | 是 | NULL | — |
| `is_under_16` | TINYINT(1) | 否 | 0 | — |
| `code_hash` | CHAR(128) | 否 | — | — |
| `attempts` | TINYINT | 否 | 0 | — |
| `last_sent_at` | DATETIME | 是 | NULL | 时间 |
| `expires_at` | DATETIME | 否 | — | 时间 |
| `ip_address` | VARCHAR(45) | 是 | NULL | 客户端 IP |
| `user_agent` | VARCHAR(500) | 是 | NULL | 客户端 UA |
| `created_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 创建时间 |
| `updated_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 最后修改时间 |

索引：主键 `PRIMARY`（id）；唯一 `uk_email`（email）；普通 `idx_expires`（expires_at）

### user_devices

登录过的设备。按 `device_id` 区分，记录 IP 和 UA，`trusted_at` 非空表示站长或用户信任过这台设备。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `id` | INT | 否 | — | 自增主键 |
| `user_id` | INT | 否 | — | 所属账号（`users.id`） |
| `device_id` | VARCHAR(64) | 否 | — | — |
| `device_name` | VARCHAR(200) | 否 | '' | — |
| `ip_address` | VARCHAR(45) | 否 | '' | 客户端 IP |
| `user_agent` | TEXT | 是 | — | 客户端 UA |
| `first_seen_at` | TIMESTAMP | 是 | CURRENT_TIMESTAMP | 时间 |
| `last_seen_at` | TIMESTAMP | 是 | NULL | 时间 |
| `trusted_at` | TIMESTAMP | 是 | NULL | 时间 |

索引：主键 `PRIMARY`（id）；唯一 `uk_device`（user_id, device_id）

### user_biometrics

WebAuthn / 生物识别的凭据。公钥存这里，私钥在用户设备上，登录时做一次签名验证。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `id` | BIGINT | 否 | — | 自增主键 |
| `user_id` | INT | 否 | — | 所属账号（`users.id`） |
| `credential_id` | TEXT | 否 | — | — |
| `public_key` | TEXT | 否 | — | — |
| `sign_count` | INT | 是 | 0 | — |
| `transports` | TEXT | 是 | — | — |
| `device_type` | VARCHAR(50) | 是 | NULL | — |
| `revoked` | TINYINT | 是 | 0 | — |
| `created_at` | DATETIME | 是 | CURRENT_TIMESTAMP | 创建时间 |

索引：主键 `PRIMARY`（id）；普通 `idx_user_id`（user_id）

### user_totp_secrets

两步验证（TOTP）的密钥与启用状态。密钥单独一张表，删掉一行就等于关掉该账号的二验。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `id` | INT | 否 | — | 自增主键 |
| `user_id` | INT | 否 | — | 所属账号（`users.id`） |
| `secret` | VARCHAR(64) | 否 | — | — |
| `verified` | TINYINT(1) | 否 | 0 | — |
| `created_at` | TIMESTAMP | 是 | CURRENT_TIMESTAMP | 创建时间 |
| `updated_at` | TIMESTAMP | 是 | CURRENT_TIMESTAMP | 最后修改时间 |

索引：主键 `PRIMARY`（id）；唯一 `uk_user`（user_id）

### user_recovery_codes

备用恢复码。每个码只用一次，使用后标记时间，全部用完需要重新生成。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `id` | INT | 否 | — | 自增主键 |
| `user_id` | INT | 否 | — | 所属账号（`users.id`） |
| `code_hash` | VARCHAR(255) | 否 | — | — |
| `batch_id` | VARCHAR(32) | 否 | — | — |
| `used_at` | TIMESTAMP | 是 | NULL | 时间 |
| `created_at` | TIMESTAMP | 是 | CURRENT_TIMESTAMP | 创建时间 |

索引：主键 `PRIMARY`（id）；普通 `idx_user`（user_id）；普通 `idx_user_used`（user_id, used_at）；普通 `idx_batch`（batch_id）

### user_temp_keys

临时密钥。给不方便输密码的场景发的一次性凭据，过期即失效。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `id` | INT | 否 | — | 自增主键 |
| `user_id` | INT | 否 | — | 所属账号（`users.id`） |
| `key_hash` | VARCHAR(255) | 否 | — | — |
| `label` | VARCHAR(50) | 是 | NULL | — |
| `expires_at` | DATETIME | 否 | — | 时间 |
| `last_used_at` | DATETIME | 是 | NULL | 时间 |
| `created_at` | DATETIME | 是 | CURRENT_TIMESTAMP | 创建时间 |

索引：主键 `PRIMARY`（id）；普通 `idx_user_temp_keys_user`（user_id）；普通 `idx_user_temp_keys_expires`（expires_at）

