# 数据表：账号恢复与家庭

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：`database/schema.sql` 和 `docs-internal/docmeta/tables.json`

## 账号恢复与家庭

### account_recovery_requests

找回账号的申诉工单。用户填的资料、上传的证明材料、管理员的审核结论和一次性恢复令牌都在这里。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `id` | INT | 否 | — | 自增主键 |
| `unid` | CHAR(26) | 否 | — | 对外编号（26 位 ULID，界面上只露这个） |
| `user_id` | INT | 否 | — | 所属账号（`users.id`） |
| `username` | VARCHAR(64) | 否 | — | — |
| `account_email` | VARCHAR(255) | 是 | NULL | — |
| `birth_date_input` | DATE | 是 | NULL | — |
| `gender_input` | VARCHAR(32) | 是 | NULL | — |
| `birth_date_match` | TINYINT(1) | 是 | NULL | — |
| `gender_match` | TINYINT(1) | 是 | NULL | — |
| `description` | TEXT | 是 | — | — |
| `contact_email` | VARCHAR(255) | 否 | — | — |
| `status` | ENUM('PENDING','APPROVED','REJECTED','COMPLETED') | 否 | pending | 状态标记 |
| `admin_note` | TEXT | 是 | — | — |
| `reviewed_by` | INT | 是 | NULL | — |
| `reviewed_at` | DATETIME | 是 | NULL | 时间 |
| `recovery_token_hash` | CHAR(128) | 是 | NULL | — |
| `recovery_token_expires_at` | DATETIME | 是 | NULL | 时间 |
| `recovery_token_used_at` | DATETIME | 是 | NULL | 时间 |
| `completed_at` | DATETIME | 是 | NULL | 时间 |
| `admin_email_notify_at` | DATETIME | 是 | NULL | 时间 |
| `ip_address` | VARCHAR(45) | 是 | NULL | 客户端 IP |
| `user_agent` | VARCHAR(500) | 是 | NULL | 客户端 UA |
| `created_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 创建时间 |

索引：唯一 `uk_unid`（unid）；主键 `PRIMARY`（id）；普通 `idx_user`（user_id）；普通 `idx_status_created`（status, created_at）；普通 `idx_token`（recovery_token_hash）

### family_groups

家庭组。开启 `guardians` 可选功能后才会用到，一个未成年账号属于一个家庭组。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `id` | INT | 否 | — | 自增主键 |
| `unid` | CHAR(26) | 否 | — | 对外编号（26 位 ULID，界面上只露这个） |
| `status` | VARCHAR(16) | 否 | unclaimed | 状态标记 |
| `created_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 创建时间 |
| `claimed_at` | DATETIME | 是 | NULL | 时间 |
| `updated_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 最后修改时间 |

索引：唯一 `uk_unid`（unid）；主键 `PRIMARY`（id）

### family_members

家庭成员与监护关系。记录谁是谁的监护人、邀请是否接受、关系是否已解除。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `id` | INT | 否 | — | 自增主键 |
| `group_id` | INT | 否 | — | — |
| `kind` | VARCHAR(8) | 否 | — | — |
| `user_id` | INT | 是 | NULL | 所属账号（`users.id`） |
| `email` | VARCHAR(255) | 是 | NULL | — |
| `relationship` | VARCHAR(16) | 是 | NULL | — |
| `status` | VARCHAR(16) | 否 | — | 状态标记 |
| `consent_at` | DATETIME | 是 | NULL | 时间 |
| `release_requested_at` | DATETIME | 是 | NULL | 时间 |
| `independent_at` | DATETIME | 是 | NULL | 时间 |
| `independent_reason` | VARCHAR(16) | 是 | NULL | — |
| `left_at` | DATETIME | 是 | NULL | 时间 |
| `created_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 创建时间 |
| `updated_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 最后修改时间 |

索引：主键 `PRIMARY`（id）；普通 `idx_group`（group_id）；普通 `idx_user_kind`（user_id, kind）；普通 `idx_guardian_email`（email）

