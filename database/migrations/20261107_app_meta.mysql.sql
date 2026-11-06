-- 应用级键值表：现在只存 app_version，用来判断库结构跟不跟得上代码。
CREATE TABLE IF NOT EXISTS `app_meta` (
  `k` varchar(64) NOT NULL,
  `v` text NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
