-- 插件迁移记录表：每个插件自己的 database/migrations 跑过哪些文件。
CREATE TABLE IF NOT EXISTS plugin_migrations (
  plugin TEXT NOT NULL,
  filename TEXT NOT NULL,
  applied_at TEXT NOT NULL DEFAULT '',
  PRIMARY KEY (plugin, filename)
);
