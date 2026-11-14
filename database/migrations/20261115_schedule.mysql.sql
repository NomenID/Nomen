-- 定时任务状态：name 是任务 id，其它列都是 Unix 时间戳
CREATE TABLE IF NOT EXISTS `scheduled_tasks` (
  `name` varchar(64) NOT NULL,
  `interval_seconds` int unsigned NOT NULL DEFAULT 300,
  `next_run_at` bigint NOT NULL DEFAULT 0,
  `running_until` bigint NOT NULL DEFAULT 0,
  `last_run_at` bigint NOT NULL DEFAULT 0,
  `last_finished_at` bigint NOT NULL DEFAULT 0,
  `last_status` varchar(16) NOT NULL DEFAULT '',
  `last_error` varchar(1000) NOT NULL DEFAULT '',
  `failures` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
