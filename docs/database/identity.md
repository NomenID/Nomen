# 数据表：第三方登录

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：`database/schema.sql` 和 `docs-internal/docmeta/tables.json`

## 第三方登录

### identity_providers

第三方登录的提供方配置。GitHub / Google / Microsoft / CaelLabID 是预设，也可以自己加 OAuth2 提供方。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `id` | INT | 否 | — | 自增主键 |
| `slug` | VARCHAR(32) | 否 | — | — |
| `name` | VARCHAR(64) | 否 | — | — |
| `logo` | VARCHAR(255) | 否 | '' | — |
| `type` | VARCHAR(16) | 否 | oauth2 | — |
| `client_id` | VARCHAR(255) | 否 | '' | 所属应用（`oauth_clients.client_id`） |
| `client_secret` | VARCHAR(255) | 否 | '' | — |
| `authorize_url` | VARCHAR(500) | 否 | '' | — |
| `token_url` | VARCHAR(500) | 否 | '' | — |
| `userinfo_url` | VARCHAR(500) | 否 | '' | — |
| `emails_url` | VARCHAR(500) | 否 | '' | — |
| `scopes` | VARCHAR(500) | 否 | '' | — |
| `subject_field` | VARCHAR(64) | 否 | sub | — |
| `email_field` | VARCHAR(64) | 否 | email | — |
| `name_field` | VARCHAR(64) | 否 | name | — |
| `avatar_field` | VARCHAR(64) | 否 | picture | — |
| `email_verified_field` | VARCHAR(64) | 否 | '' | — |
| `token_auth` | VARCHAR(8) | 否 | post | — |
| `extra_authorize_params` | VARCHAR(500) | 否 | '' | — |
| `enabled` | TINYINT(1) | 否 | 1 | — |
| `sort_order` | INT | 否 | 0 | — |
| `created_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 创建时间 |
| `updated_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 最后修改时间 |

索引：主键 `PRIMARY`（id）；唯一 `uk_identity_providers_slug`（slug）

### user_identities

用户与第三方身份的绑定关系。一个账号可以绑多个提供方；已验证邮箱的才会自动合并到同邮箱账号。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `id` | BIGINT | 否 | — | 自增主键 |
| `user_id` | INT | 否 | — | 所属账号（`users.id`） |
| `provider_id` | INT | 否 | — | — |
| `subject` | VARCHAR(191) | 否 | — | — |
| `email` | VARCHAR(255) | 是 | NULL | — |
| `display_name` | VARCHAR(191) | 是 | NULL | — |
| `avatar_url` | VARCHAR(500) | 是 | NULL | — |
| `raw` | MEDIUMTEXT | 是 | — | — |
| `created_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 创建时间 |
| `updated_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 最后修改时间 |

索引：主键 `PRIMARY`（id）；唯一 `uk_user_identities_provider_subject`（provider_id, subject）；唯一 `uk_user_identities_user_provider`（user_id, provider_id）；普通 `idx_user_identities_user`（user_id）

