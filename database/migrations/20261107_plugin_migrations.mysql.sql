-- 插件迁移记录表：每个插件自己的 database/migrations 跑过哪些文件。
CREATE TABLE IF NOT EXISTS `plugin_migrations` (
  `plugin` varchar(64) NOT NULL,
  `filename` varchar(191) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`plugin`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
