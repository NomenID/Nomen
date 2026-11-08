-- 快速中央登录已移出核心（改由插件实现），users.quick_login 列一并删除。
-- 注意：升级后用户之前的开关状态会丢失，插件启用后默认重新开启。
ALTER TABLE `users` DROP COLUMN `quick_login`;
