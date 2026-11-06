-- Nomen: 主邮箱位与备选位各自唯一，互不干扰（2026-10-15 定的口径）。
--
--   * 主邮箱位：一个地址最多是一个账号的 users.email —— 那个唯一索引本来就是它。
--   * 备选位：一个地址最多是一个账号的备选 —— 就是下面这个索引。
--
-- 于是同一个地址最多落在 2 个账号上（一个当主、一个当备），这个组合是**允许**的；
-- 登录 / 找回时输入它可能命中 2 个账号，由 EmailSet::candidatesForEmail() 交给用户自己挑。
--
-- MySQL 没有「部分唯一索引」，用一个「不是可用备选时取 NULL」的生成列来做：
-- 唯一索引允许多个 NULL，所以主邮箱行和还没验证的行都不占位，
-- 只有**已验证的备选行**之间才互相排他 —— 和 EmailSet::secondaryTakenByOther() 同一口径。
-- （未验证不占位是有意的：否则任何人把别人的邮箱塞进自己的待验证行就能卡住对方。）

ALTER TABLE `user_emails`
  ADD COLUMN `secondary_email` VARCHAR(255) COLLATE utf8mb4_unicode_ci
    GENERATED ALWAYS AS (IF(`is_primary` = 1 OR `verified` = 0, NULL, `email`)) STORED,
  ADD UNIQUE KEY `uniq_secondary_email` (`secondary_email`);

-- Rollback:
--   ALTER TABLE `user_emails` DROP INDEX `uniq_secondary_email`, DROP COLUMN `secondary_email`;
