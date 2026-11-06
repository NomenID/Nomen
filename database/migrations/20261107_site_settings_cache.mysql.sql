-- 站点级设置（登录策略、缓存后端等），键值都按字符串存
CREATE TABLE IF NOT EXISTS `site_settings` (
  `k` varchar(64) NOT NULL,
  `v` text NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 数据库缓存驱动用；过期行由 Cache 读写时顺手清理
CREATE TABLE IF NOT EXISTS `cache_items` (
  `k` varchar(191) NOT NULL,
  `v` mediumtext NOT NULL,
  `expires_at` int unsigned NOT NULL,
  PRIMARY KEY (`k`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
