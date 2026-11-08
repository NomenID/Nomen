# 缓存

缓存是加速层，不是数据存储。缓存挂了页面不该报错，只是变慢。

## 后端

后台「设置」→「缓存」：

| 值 | 说明 |
|---|---|
| `auto`（默认） | Redis 可用就用 Redis，否则文件 |
| `redis` | 强制 Redis；不可用时回退文件 |
| `file` | 文件，落 `data/cache/` |
| `db` | 数据库表 `cache_items` |

插件可以用 `Cache::registerDriver('名字', fn() => new Driver())` 注册自己的驱动，
注册后也会出现在这个列表里。

## 回退

选中的后端不可用时按 `选中的 → file` 回退，最后落到 `none`（不缓存，但功能正常）。
每次回退都会往 PHP 错误日志写一条 `[cache] ... 不可用`。

`auto` 的候选顺序是 `redis → file`。

## 现在缓存了什么

| 内容 | 键 | TTL |
|---|---|---|
| 版本页 `/special/version` | `special:version` | 60 秒 |
| 「最新正式版」检查 | `update_check.latest` | 30 分钟 |

版本页要读插件 / 皮肤的清单和许可文件，缓存 60 秒够挡掉重复扫描；更新检查 30 分钟一次，
避免每次打开后台都去请求官方站。

## 什么时候需要清缓存

- 升级程序后看到旧版本号：等 60 秒，或清掉缓存。
- 改了插件 / 皮肤清单但版本页没变：同上。
- 缓存后端从文件换成 Redis 后，旧文件缓存不再被读，可以手动删 `data/cache/`。

清 Redis 时注意只清本站在用的库（`REDIS_DB`），别 `FLUSHALL`——同一台 Redis 上可能还有别的站。

## 相关配置

| 项 | 位置 |
|---|---|
| 后端选择 | `site_settings.cache.backend`，后台「设置」 |
| Redis 连接 | `config/app.php` 的 `redis`，`.env` 的 `REDIS_*` |
| 文件缓存目录 | `data/cache/` |
