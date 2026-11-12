# 数据结构

表结构以 `database/schema.sql` 为准，本页由它生成。SQLite 版本在 `database/schema.sqlite.sql`，升级用 `database/migrations/` 下的增量脚本（`php bin/nomen upgrade` 执行）。

| 分组 | 表 | 说明 |
|---|---|---|
| 账号与身份 | [users](account.md) | 账号主表。一行一个账号，密码哈希、状态、角色、TOTP 开关都在这里。 |
| 账号与身份 | [user_emails](account.md) | 账号下的邮箱。一个账号可以挂多个邮箱，其中一个是主邮箱；登录、通知、找回都按这里的验证状态判断。 |
| 账号与身份 | [pending_registrations](account.md) | 待激活的注册。填完注册表单、还没点激活链接时暂存在这里，激活或过期后清掉。 |
| 账号与身份 | [user_devices](account.md) | 登录过的设备。按 `device_id` 区分，记录 IP 和 UA，`trusted_at` 非空表示站长或用户信任过这台设备。 |
| 账号与身份 | [user_biometrics](account.md) | WebAuthn / 生物识别的凭据。公钥存这里，私钥在用户设备上，登录时做一次签名验证。 |
| 账号与身份 | [user_totp_secrets](account.md) | 两步验证（TOTP）的密钥与启用状态。密钥单独一张表，删掉一行就等于关掉该账号的二验。 |
| 账号与身份 | [user_recovery_codes](account.md) | 备用恢复码。每个码只用一次，使用后标记时间，全部用完需要重新生成。 |
| 账号与身份 | [user_temp_keys](account.md) | 临时密钥。给不方便输密码的场景发的一次性凭据，过期即失效。 |
| OAuth 与开放平台 | [oauth_clients](oauth.md) | OAuth 应用。`client_id` / `client_secret`、回调白名单、允许的 scope 和所属开发者都在这张表。 |
| OAuth 与开放平台 | [oauth_authorization_codes](oauth.md) | 一次性的授权码。有效期 10 分钟，换 token 时立刻作废，一个码只能换一次。 |
| OAuth 与开放平台 | [oauth_access_tokens](oauth.md) | 已签发的访问令牌。表里只有哈希，明文只在签发那一次返回给应用。 |
| OAuth 与开放平台 | [oauth_refresh_tokens](oauth.md) | 刷新令牌。同样只存哈希；刷新一次旧的立刻失效，换发新的。 |
| OAuth 与开放平台 | [developers](oauth.md) | 应用开发者档案。开放平台里一个开发者可以拥有多个应用，应用通过 `developer_id` 指回来。 |
| OAuth 与开放平台 | [user_authorizations](oauth.md) | 用户对应用的授权记录。用来判「已授权过就不用再确认」，以及让用户在设置页撤销。 |
| OAuth 与开放平台 | [user_app_subjects](oauth.md) | 应用级 sub 的映射。OIDC 的 `sub` 按应用生成，同一个人在不同应用拿到不同的值。 |
| 第三方登录 | [identity_providers](identity.md) | 第三方登录的提供方配置。GitHub / Google / Microsoft / CaelLabID 是预设，也可以自己加 OAuth2 提供方。 |
| 第三方登录 | [user_identities](identity.md) | 用户与第三方身份的绑定关系。一个账号可以绑多个提供方；已验证邮箱的才会自动合并到同邮箱账号。 |
| 账号恢复与家庭 | [account_recovery_requests](recovery.md) | 找回账号的申诉工单。用户填的资料、上传的证明材料、管理员的审核结论和一次性恢复令牌都在这里。 |
| 账号恢复与家庭 | [family_groups](recovery.md) | 家庭组。开启 `guardians` 可选功能后才会用到，一个未成年账号属于一个家庭组。 |
| 账号恢复与家庭 | [family_members](recovery.md) | 家庭成员与监护关系。记录谁是谁的监护人、邀请是否接受、关系是否已解除。 |
| 站点与内容 | [site_settings](site.md) | 后台「设置」页可改的站点设置。键值对存储，覆盖 `config/` 里的同名默认值。 |
| 站点与内容 | [app_meta](site.md) | 站点元信息。记录已安装版本和升级状态，`php bin/nomen upgrade` 靠它判断有没有迁移要跑。 |
| 站点与内容 | [activity_logs](site.md) | 活动日志。登录、改密码、后台操作都记在这里，后台按 UNID 展示，插件通过 `ActivityLog::record` 写入。 |
| 站点与内容 | [notifications](site.md) | 站内通知。入库之后才会触发 `notification.created`，邮件是否发送由用户的分类开关决定。 |
| 站点与内容 | [user_notification_settings](site.md) | 通知分类的开关。没有记录表示全部走默认，用户改过哪一类才存哪一行。 |
| 站点与内容 | [cache_items](site.md) | 数据库缓存驱动用的表。没配 Redis 时缓存落在这里，按 key 存过期时间和序列化后的值。 |
| 站点与内容 | [plugin_migrations](site.md) | 插件迁移的执行记录。`php bin/nomen plugins migrate` 按表和文件名判断哪些已经跑过。 |
