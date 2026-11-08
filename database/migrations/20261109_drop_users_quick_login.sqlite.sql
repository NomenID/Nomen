-- 快速中央登录已移出核心（改由插件实现），users.quick_login 列一并删除。
ALTER TABLE users DROP COLUMN quick_login;
