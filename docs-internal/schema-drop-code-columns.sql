-- 删掉 users 表上四个「验证码」列，它们已经搬去 Redis（src/VerifyCode.php）：
--   login_code / login_code_expires_at            第一因素登录码   -> 键 login_code:{userId}
--   two_factor_code / two_factor_code_expires_at  第二因素验证码   -> 键 two_factor_code:{userId}
-- password_reset_code / password_reset_code_expires_at 是重置密码那套，不在这里面。
--
-- 顺序很重要：先把不再读写这四列的代码传上去，再跑下面的 ALTER。
-- 反过来的话，还在跑的旧代码会在「发送验证码」那一步报 1054 Unknown column。
-- 删之前的数据在 /www/backup/db-schema/users_code_columns_<日期>.tsv，
-- 表结构在 /www/backup/db-schema/users_before_drop_<日期>.sql。

ALTER TABLE `users`
    DROP COLUMN `login_code`,
    DROP COLUMN `login_code_expires_at`,
    DROP COLUMN `two_factor_code`,
    DROP COLUMN `two_factor_code_expires_at`,
    ALGORITHM = INSTANT;
-- 注意：ALGORITHM=INSTANT 不能和 LOCK=NONE 一起写（MySQL 报 1221），INSTANT 本身就不加锁。

-- 回滚（把列加回来）。类型与可空性和原件一致，列顺序会落到表末尾；
-- 要逐字节还原位置就看上面那个 users_before_drop_*.sql 里的定义。
-- ALTER TABLE `users`
--     ADD COLUMN `login_code` VARCHAR(6) DEFAULT NULL,
--     ADD COLUMN `login_code_expires_at` DATETIME DEFAULT NULL,
--     ADD COLUMN `two_factor_code` VARCHAR(6) DEFAULT NULL,
--     ADD COLUMN `two_factor_code_expires_at` DATETIME DEFAULT NULL;
