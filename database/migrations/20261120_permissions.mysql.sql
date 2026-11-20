-- 权限体系：组归属与组规则。'grp' 就是组名（group 在 MySQL 8 是保留字，列名用 grp）
CREATE TABLE IF NOT EXISTS `user_groups` (
  `user_id` int unsigned NOT NULL,
  `grp` varchar(64) NOT NULL,
  `granted_by` int unsigned NOT NULL DEFAULT 0,
  `granted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `grp`),
  KEY `idx_user_groups_grp` (`grp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `group_rules` (
  `grp` varchar(64) NOT NULL,
  `ability` varchar(128) NOT NULL,
  `allowed` tinyint NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`grp`, `ability`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 旧版管理员（users.role = 'admin'）同步进 administrator 组。
-- role 字段保留作兼容快照，判定以组为准。
INSERT IGNORE INTO `user_groups` (`user_id`, `grp`) SELECT `id`, 'administrator' FROM `users` WHERE `role` = 'admin';
