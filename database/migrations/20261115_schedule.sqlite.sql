-- 定时任务状态：name 是任务 id，其它列都是 Unix 时间戳
CREATE TABLE IF NOT EXISTS scheduled_tasks (
  name TEXT PRIMARY KEY,
  interval_seconds INTEGER NOT NULL DEFAULT 300,
  next_run_at INTEGER NOT NULL DEFAULT 0,
  running_until INTEGER NOT NULL DEFAULT 0,
  last_run_at INTEGER NOT NULL DEFAULT 0,
  last_finished_at INTEGER NOT NULL DEFAULT 0,
  last_status TEXT NOT NULL DEFAULT '',
  last_error TEXT NOT NULL DEFAULT '',
  failures INTEGER NOT NULL DEFAULT 0
);
