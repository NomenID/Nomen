# 数据表：站点与内容

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：`database/schema.sql` 和 `docs-internal/docmeta/tables.json`

## 站点与内容

### site_settings

后台「设置」页可改的站点设置。键值对存储，覆盖 `config/` 里的同名默认值。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `k` | VARCHAR(64) | 否 | — | — |
| `v` | TEXT | 否 | — | — |

索引：主键 `PRIMARY`（k）

### app_meta

站点元信息。记录已安装版本和升级状态，`php bin/nomen upgrade` 靠它判断有没有迁移要跑。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `k` | VARCHAR(64) | 否 | — | — |
| `v` | TEXT | 否 | — | — |

索引：主键 `PRIMARY`（k）

### activity_logs

活动日志。登录、改密码、后台操作都记在这里，后台按 UNID 展示，插件通过 `ActivityLog::record` 写入。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `id` | BIGINT | 否 | — | 自增主键 |
| `unid` | CHAR(26) | 否 | — | 对外编号（26 位 ULID，界面上只露这个） |
| `actor_type` | ENUM('USER','ADMIN','SYSTEM') | 否 | user | — |
| `actor_id` | INT | 是 | NULL | — |
| `actor_username` | VARCHAR(64) | 是 | NULL | — |
| `action` | VARCHAR(64) | 否 | — | — |
| `category` | VARCHAR(32) | 否 | — | — |
| `target_type` | VARCHAR(32) | 是 | NULL | — |
| `target_id` | VARCHAR(64) | 是 | NULL | — |
| `target_name` | VARCHAR(255) | 是 | NULL | — |
| `summary` | VARCHAR(255) | 是 | NULL | — |
| `changes` | MEDIUMTEXT | 是 | — | — |
| `ip_address` | VARCHAR(45) | 是 | NULL | 客户端 IP |
| `user_agent` | VARCHAR(500) | 是 | NULL | 客户端 UA |
| `device_name` | VARCHAR(100) | 是 | NULL | — |
| `created_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 创建时间 |

索引：唯一 `uk_unid`（unid）；主键 `PRIMARY`（id）；普通 `idx_actor`（actor_type, actor_id, created_at）；普通 `idx_action`（action, created_at）；普通 `idx_category`（category, created_at）；普通 `idx_created`（created_at）

### notifications

站内通知。入库之后才会触发 `notification.created`，邮件是否发送由用户的分类开关决定。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `id` | BIGINT | 否 | — | 自增主键 |
| `user_id` | INT | 否 | — | 所属账号（`users.id`） |
| `category` | VARCHAR(20) | 否 | — | — |
| `title` | VARCHAR(190) | 否 | — | — |
| `body` | TEXT | 否 | — | — |
| `link` | VARCHAR(500) | 是 | NULL | — |
| `link_text` | VARCHAR(50) | 是 | NULL | — |
| `dedupe_key` | VARCHAR(64) | 是 | NULL | — |
| `email_sent_at` | DATETIME | 是 | NULL | 时间 |
| `read_at` | DATETIME | 是 | NULL | 时间 |
| `created_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 创建时间 |

索引：主键 `PRIMARY`（id）；唯一 `uk_user_dedupe`（user_id, dedupe_key）；普通 `idx_user_read`（user_id, read_at）

### user_notification_settings

通知分类的开关。没有记录表示全部走默认，用户改过哪一类才存哪一行。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `user_id` | INT | 否 | — | 所属账号（`users.id`） |
| `notify_system_important` | TINYINT | 否 | 1 | — |
| `notify_system_general` | TINYINT | 否 | 0 | — |
| `notify_system_interact` | TINYINT | 否 | 0 | — |
| `updated_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 最后修改时间 |

索引：主键 `PRIMARY`（user_id）

### cache_items

数据库缓存驱动用的表。没配 Redis 时缓存落在这里，按 key 存过期时间和序列化后的值。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `k` | VARCHAR(191) | 否 | — | — |
| `v` | MEDIUMTEXT | 否 | — | — |
| `expires_at` | INT | 否 | — | 时间 |

索引：主键 `PRIMARY`（k）；普通 `idx_expires_at`（expires_at）

### plugin_migrations

插件迁移的执行记录。`php bin/nomen plugins migrate` 按表和文件名判断哪些已经跑过。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `plugin` | VARCHAR(64) | 否 | — | — |
| `filename` | VARCHAR(191) | 否 | — | — |
| `applied_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 时间 |

索引：主键 `PRIMARY`（plugin, filename）

