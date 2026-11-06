-- users.locale：账号级界面语言偏好（取值 = locales/languages.php 的 slug，NULL = 未设置）
-- 老规矩：先跑这份 DDL，再上新代码。回滚：ALTER TABLE users DROP COLUMN locale;
ALTER TABLE users ADD COLUMN locale VARCHAR(16) NULL DEFAULT NULL AFTER gender;

-- 存量用户回填简体中文：站群此前只有中文，回填后他们的邮件和界面不受多语言上线影响
UPDATE users SET locale = 'zh-hans' WHERE locale IS NULL;
