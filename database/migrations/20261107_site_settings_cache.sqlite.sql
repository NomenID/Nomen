-- 站点级设置（登录策略、缓存后端等），键值都按字符串存
CREATE TABLE IF NOT EXISTS site_settings (
  k TEXT PRIMARY KEY,
  v TEXT NOT NULL DEFAULT ''
);

-- 数据库缓存驱动用；过期行由 Cache 读写时顺手清理
CREATE TABLE IF NOT EXISTS cache_items (
  k TEXT PRIMARY KEY,
  v TEXT NOT NULL,
  expires_at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_cache_items_expires_at ON cache_items (expires_at);
