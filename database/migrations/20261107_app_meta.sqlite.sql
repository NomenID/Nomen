-- 应用级键值表：现在只存 app_version，用来判断库结构跟不跟得上代码。
CREATE TABLE IF NOT EXISTS app_meta (
  k TEXT PRIMARY KEY,
  v TEXT NOT NULL DEFAULT ''
);
