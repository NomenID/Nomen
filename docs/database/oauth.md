# 数据表：OAuth 与开放平台

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：`database/schema.sql` 和 `docs-internal/docmeta/tables.json`

## OAuth 与开放平台

### oauth_clients

OAuth 应用。`client_id` / `client_secret`、回调白名单、允许的 scope 和所属开发者都在这张表。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `id` | INT | 否 | — | 自增主键 |
| `client_id` | VARCHAR(64) | 否 | — | 公开的应用标识，出现在授权链接里 |
| `client_secret` | VARCHAR(128) | 否 | — | 应用密钥，只存哈希；创建时给站长看一次 |
| `client_type` | VARCHAR(20) | 否 | confidential | — |
| `pkce_required` | TINYINT(1) | 否 | 0 | — |
| `name` | VARCHAR(128) | 否 | — | — |
| `icon` | VARCHAR(255) | 是 | NULL | — |
| `description` | TEXT | 是 | — | — |
| `homepage_url` | VARCHAR(512) | 是 | NULL | — |
| `logo_url` | VARCHAR(512) | 是 | NULL | — |
| `redirect_uris` | TEXT | 否 | — | 回调地址白名单，多个用换行分隔，必须逐字节一致 |
| `scopes` | VARCHAR(512) | 否 | openid profile email | — |
| `owner_id` | INT | 是 | NULL | — |
| `developer_id` | INT | 是 | NULL | 所属开发者（`developers.id`） |
| `status` | TINYINT(1) | 否 | 1 | 状态标记 |
| `created_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 创建时间 |
| `updated_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 最后修改时间 |

索引：主键 `PRIMARY`（id）；唯一 `client_id`（client_id）；普通 `idx_client_id`（client_id）；普通 `owner_id`（owner_id）；普通 `idx_developer_id`（developer_id）

### oauth_authorization_codes

一次性的授权码。有效期 10 分钟，换 token 时立刻作废，一个码只能换一次。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `id` | INT | 否 | — | 自增主键 |
| `code` | VARCHAR(128) | 否 | — | — |
| `client_id` | VARCHAR(64) | 否 | — | 所属应用（`oauth_clients.client_id`） |
| `user_id` | INT | 否 | — | 所属账号（`users.id`） |
| `redirect_uri` | VARCHAR(512) | 否 | — | — |
| `scopes` | VARCHAR(512) | 否 | — | — |
| `code_challenge` | VARCHAR(256) | 是 | NULL | — |
| `code_challenge_method` | VARCHAR(10) | 是 | NULL | — |
| `nonce` | VARCHAR(255) | 是 | NULL | — |
| `expires_at` | DATETIME | 否 | — | 时间 |
| `created_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 创建时间 |

索引：主键 `PRIMARY`（id）；唯一 `code`（code）；普通 `idx_code`（code）；普通 `idx_expires`（expires_at）；普通 `client_id`（client_id）；普通 `user_id`（user_id）

### oauth_access_tokens

已签发的访问令牌。表里只有哈希，明文只在签发那一次返回给应用。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `id` | INT | 否 | — | 自增主键 |
| `token_hash` | VARCHAR(128) | 否 | — | — |
| `client_id` | VARCHAR(64) | 否 | — | 所属应用（`oauth_clients.client_id`） |
| `user_id` | INT | 否 | — | 所属账号（`users.id`） |
| `scopes` | VARCHAR(512) | 否 | — | — |
| `expires_at` | DATETIME | 否 | — | 时间 |
| `revoked` | TINYINT(1) | 否 | 0 | — |
| `created_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 创建时间 |

索引：主键 `PRIMARY`（id）；唯一 `token_hash`（token_hash）；普通 `idx_token_hash`（token_hash）；普通 `idx_expires`（expires_at）；普通 `client_id`（client_id）；普通 `user_id`（user_id）

### oauth_refresh_tokens

刷新令牌。同样只存哈希；刷新一次旧的立刻失效，换发新的。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `id` | INT | 否 | — | 自增主键 |
| `token_hash` | VARCHAR(128) | 否 | — | — |
| `access_token_id` | INT | 否 | — | — |
| `client_id` | VARCHAR(64) | 否 | — | 所属应用（`oauth_clients.client_id`） |
| `user_id` | INT | 否 | — | 所属账号（`users.id`） |
| `scopes` | VARCHAR(512) | 否 | — | — |
| `expires_at` | DATETIME | 否 | — | 时间 |
| `revoked` | TINYINT(1) | 否 | 0 | — |
| `created_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 创建时间 |

索引：主键 `PRIMARY`（id）；唯一 `token_hash`（token_hash）；普通 `idx_token_hash`（token_hash）；普通 `access_token_id`（access_token_id）；普通 `client_id`（client_id）；普通 `user_id`（user_id）

### developers

应用开发者档案。开放平台里一个开发者可以拥有多个应用，应用通过 `developer_id` 指回来。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `id` | INT | 否 | — | 自增主键 |
| `user_id` | INT | 否 | — | 所属账号（`users.id`） |
| `name` | VARCHAR(128) | 否 | — | — |
| `icon` | VARCHAR(255) | 是 | NULL | — |
| `api_token_hash` | VARCHAR(128) | 是 | NULL | — |
| `status` | TINYINT(1) | 否 | 1 | 状态标记 |
| `created_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 创建时间 |
| `updated_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 最后修改时间 |

索引：主键 `PRIMARY`（id）；唯一 `uk_user`（user_id）

### user_authorizations

用户对应用的授权记录。用来判「已授权过就不用再确认」，以及让用户在设置页撤销。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `id` | INT | 否 | — | 自增主键 |
| `user_id` | INT | 否 | — | 所属账号（`users.id`） |
| `client_id` | VARCHAR(64) | 否 | — | 所属应用（`oauth_clients.client_id`） |
| `scopes` | VARCHAR(512) | 否 | — | — |
| `created_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 创建时间 |
| `updated_at` | DATETIME | 否 | CURRENT_TIMESTAMP | 最后修改时间 |

索引：主键 `PRIMARY`（id）；唯一 `uk_user_client`（user_id, client_id）；普通 `client_id`（client_id）

### user_app_subjects

应用级 sub 的映射。OIDC 的 `sub` 按应用生成，同一个人在不同应用拿到不同的值。

| 字段 | 类型 | 可空 | 默认 | 说明 |
|---|---|---|---|---|
| `id` | INT | 否 | — | 自增主键 |
| `user_id` | INT | 否 | — | 所属账号（`users.id`） |
| `client_id` | VARCHAR(64) | 否 | — | 所属应用（`oauth_clients.client_id`） |
| `subject_id` | VARCHAR(64) | 否 | — | 该应用看到的用户编号，全局唯一、不随账号改名变化 |
| `created_at` | TIMESTAMP | 是 | CURRENT_TIMESTAMP | 创建时间 |

索引：主键 `PRIMARY`（id）；唯一 `uq_user_client`（user_id, client_id）；唯一 `uq_subject`（subject_id）；普通 `client_id`（client_id）

