-- users.skin：账号级外观偏好。NULL = 跟随站点默认皮肤。
SET @has_skin := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'skin'
);
SET @ddl := IF(
    @has_skin = 0,
    'ALTER TABLE `users` ADD COLUMN `skin` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `locale`',
    'DO 0'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
