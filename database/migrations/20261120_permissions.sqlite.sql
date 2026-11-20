-- 权限体系：组归属与组规则。'grp' 就是组名
CREATE TABLE IF NOT EXISTS user_groups (
  user_id INTEGER NOT NULL,
  grp TEXT NOT NULL,
  granted_by INTEGER NOT NULL DEFAULT 0,
  granted_at INTEGER NOT NULL DEFAULT (strftime('%s','now')),
  PRIMARY KEY (user_id, grp)
);
CREATE INDEX IF NOT EXISTS idx_user_groups_grp ON user_groups (grp);

CREATE TABLE IF NOT EXISTS group_rules (
  grp TEXT NOT NULL,
  ability TEXT NOT NULL,
  allowed INTEGER NOT NULL DEFAULT 1,
  updated_at INTEGER NOT NULL DEFAULT (strftime('%s','now')),
  PRIMARY KEY (grp, ability)
);

-- 旧版管理员（users.role = 'admin'）同步进 administrator 组。
-- role 字段保留作兼容快照，判定以组为准。
INSERT OR IGNORE INTO user_groups (user_id, grp) SELECT id, 'administrator' FROM users WHERE role = 'admin';
