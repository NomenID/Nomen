# 数据模型

27 张表，中心是 `users`。表之间**没有外键约束**，靠应用层维护引用关系——这样删用户、搬库、灌测试数据都不会被
约束拦住，代价是孤儿行得自己清。

## 账号

```
users ─┬─ user_emails            一个账号多个邮箱，主邮箱标在这里
       ├─ user_devices           登录过的设备（IP / UA / 是否信任）
       ├─ user_biometrics        WebAuthn 凭据（公钥在库，私钥在用户设备）
       ├─ user_totp_secrets      两步验证密钥
       ├─ user_recovery_codes    一次性恢复码
       ├─ user_temp_keys         临时密钥
       ├─ pending_registrations  没点激活链接的注册（临时数据）
       └─ user_identities ─────── identity_providers   第三方身份绑定
```

几条容易踩的：

- `users.email` 是**旧字段**，多邮箱的真实数据在 `user_emails`。判断「有没有验证过的邮箱」要两边一起看。
- `users.unid` 是 26 位 ULID，**对外只露这个**；`users.id` 仍然是自增主键，插件和钩子看到的都是 int。
- `email_verified` 只管主邮箱。孩子账号没有自己邮箱时，走家长确认（`FamilyHelper::guardianConfirmed()`）。
- 设备是「见过就记」，不是白名单：`trusted_at` 只影响二次验证的策略判断。

## 第三方身份与 sub

```
identity_providers    提供方配置（GitHub / Google / Microsoft / CaelLabID / 自定义）
user_identities       谁绑了哪个提供方的哪个外部 ID
user_app_subjects     某个账号在某个 OAuth 应用里的 sub
```

`user_app_subjects` 是 OIDC 的关键：**`sub` 按应用生成**，同一个用户在不同应用拿到的 sub 不同，应用之间没法
拿 sub 串号。别把 `users.id` 发给接入方。

已验证邮箱的第三方身份会自动合并到同邮箱账号；未验证或没给邮箱就建新号。

## 开放平台

```
developers             开发者档案（一个开发者可以有多个应用）
oauth_clients          应用：client_id / secret 哈希 / 回调白名单 / scopes
user_authorizations    用户对应用的历史授权（判「已授权就不用再确认」）
oauth_authorization_codes   一次性授权码（10 分钟）
oauth_access_tokens         访问令牌（只存哈希，默认 1 小时）
oauth_refresh_tokens        刷新令牌（只存哈希，180 天，刷新一次旧的失效）
```

令牌表里**没有明文**。明文只在签发那一次返回给应用，之后丢了就只能重新授权。

## 家庭与恢复

```
family_groups                  家庭组
family_members                 成员与监护关系（谁是谁的监护人、邀请状态、解除时间）
account_recovery_requests      找回账号的申诉工单
```

`account_recovery_requests` 里有一次性的恢复令牌（哈希）和证明材料路径。工单审核通过后发一条
7 天有效的一次性链接，用掉即作废。

## 站点运行

```
site_settings                 后台「设置」里改的东西（键值对，覆盖 config/ 默认值）
app_meta                      已安装版本、升级状态
activity_logs                 登录与后台操作日志
notifications                 站内通知
user_notification_settings    通知分类开关（没有记录表示走默认）
cache_items                   数据库缓存驱动的存储
plugin_migrations             插件迁移执行记录
```

`cache_items` 和 `plugin_migrations` 是**可以丢的**：清缓存不影响业务，迁移记录丢了下一次
`plugins migrate` 会重跑（所以迁移脚本要能重复执行，或者确认自己没跑过）。

## 哪些必须备份

| 表 | 丢了会怎样 |
|---|---|
| `users` / `user_emails` / `user_identities` | 账号全没 |
| `user_biometrics` / `user_totp_secrets` / `user_recovery_codes` | 用户的二验和生物识别全失效，只能走恢复流程 |
| `oauth_clients` | 所有接入方立刻失效 |
| `site_settings` | 后台设置回到 `config/` 默认值 |
| `activity_logs` / `notifications` | 历史记录丢失，业务不受影响 |

`cache_items` / `plugin_migrations` 丢了基本无感。

## UNID 的取舍

主键仍然是自增 int：改主键要动所有关联表、插件契约、钩子参数，破坏性太大。所以另加一列 `unid`：

- 新装站的 `schema.sql` 里是 `NOT NULL`，由插入代码显式生成（`Unid::generate()`）。
- 老站升级走迁移加**可空**列，`php bin/nomen upgrade` 里 `Unid::backfill()` 分批补（每批 500 行）。
- 后台的 URL、日志、CSV 用 UNID；插件拿到的仍然是 int。

哪些表有 `unid`：`users`、`activity_logs`、`account_recovery_requests`、`family_groups`。
其它表不对外露号，不加。

## 两种数据库

`database/schema.sql`（MySQL/MariaDB）和 `database/schema.sqlite.sql`（SQLite）是**并列维护**的，
迁移脚本也是成对的（`*.mysql.sql` / `*.sqlite.sql`）。

写 SQL 时注意两边的差异：

| MySQL | SQLite |
|---|---|
| `AUTO_INCREMENT` | `INTEGER PRIMARY KEY AUTOINCREMENT` |
| `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4` | 没有 |
| `datetime DEFAULT CURRENT_TIMESTAMP` | `TEXT NOT NULL DEFAULT (datetime('now','localtime'))` |
| `enum('a','b')` | `TEXT` + 应用层校验 |

改表结构时**两份都要改**，本地两种都跑过一遍再交付。CI 只跑 SQLite（`tests/run.php`），MySQL 的语法错误
要自己本地验。

## 查询

插件查自己的表用 `Database::getInstance()` 拿 PDO，跟着站点的驱动走：

```php
$db = \Nomen\Src\Database::getInstance();
$stmt = $db->prepare('SELECT * FROM bookmarks WHERE user_id = :id ORDER BY id DESC LIMIT 20');
$stmt->execute([':id' => $userId]);
```

三条规矩：

- **一律预编译**，别把用户输入拼进 SQL。
- 别在循环里查库（一次没查完就再来一次的那种），要么一次 `IN (...)` 捞回来，要么缓存。
- 大表分页用 `WHERE id < :last ORDER BY id DESC LIMIT n`，别用 `LIMIT offset, n` —— 翻到后面会越来越慢。
