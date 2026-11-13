# 缓存

缓存是加速层，不是数据存储。**缓存挂了页面不该报错，只是变慢。**

## 四个后端

后台「设置」→「缓存」：

| 值 | 说明 |
|---|---|
| `auto`（默认） | Redis 可用就用 Redis，否则文件 |
| `redis` | 强制 Redis；不可用时回退文件 |
| `file` | 文件，落 `data/cache/` |
| `db` | 数据库表 `cache_items` |

插件可以注册自己的驱动：

```php
\Nomen\Src\Cache::registerDriver('memcached', static fn() => new MyDriver());
```

驱动实现 `Nomen\Src\Cache\Driver`（`get` / `put` / `forget`，未命中返回 `null`），
注册的名字会出现在后台下拉框里。

## 回退链

```
选中的后端 → file → none
```

每一次回退都往错误日志写一条 `[cache] ... 不可用`。`none` 不是错误状态：功能全正常，只是每次都真查。

**为什么回退到文件而不是直接放弃**：文件缓存没有外部依赖，几乎不会挂。Redis 挂了站点不该跟着挂。

## 现在缓存了什么

| 内容 | 键 | TTL |
|---|---|---|
| 版本页 `/special/version` | `special:version:<locale>` | 60 秒 |
| 「最新正式版」检查 | `update_check.latest` | 30 分钟 |
| 搜索结果（装了 search 插件） | `search:<sha1>` | `cache_ttl`，默认 300 秒 |

版本页要读插件 / 皮肤的清单和许可文件，缓存 60 秒够挡掉重复扫描。**键里带 locale**：
以前漏了它，切语言时整页英文（1.3.5 修过）。

## 清缓存

```bash
# 文件缓存
rm -rf data/cache/*

# Redis：只清本站在用的库
redis-cli -n <REDIS_DB> FLUSHDB
```

**别 `FLUSHALL`**：同一台 Redis 上可能还有别的站。库编号在 `.env` 的 `REDIS_DB`。

什么时候需要清：

- 升级程序后版本页还是旧号（等 60 秒也行）。
- 改了插件 / 皮肤清单但版本页没变。
- 换了缓存后端，旧文件缓存不再被读（可以手动删 `data/cache/`）。

## 配置项

| 项 | 位置 |
|---|---|
| 后端选择 | `site_settings.cache.backend`，后台「设置」 |
| Redis 连接 | `config/app.php` 的 `redis` 段，`.env` 的 `REDIS_*` |
| 文件缓存目录 | `data/cache/` |

Redis 连接失败时会话也会回退（`[Session] Redis 不可用，回退文件存储`），这两条是独立判断的。

## 在插件里用

```php
use Nomen\Src\Cache;

$value = Cache::remember('bookmarks:top', 300, static fn() => Store::top(10));
```

三条规矩：

- **键带足够的前缀和维度**（`bookmarks:top` 而不是 `top`）。多个插件共用一个缓存空间。
- **TTL 按「多久能接受旧数据」定**，不是越长越好。
- **不要把「必须实时」的东西放缓存**：余额、权限判断、限流计数都不该走缓存。

## 坑

- **键里漏了 locale**：多语言站会出现「整页英文」，这一类 bug 很难复现（要切语言才看得到）。
- **Redis 和文件混用**：换了后端之后旧键不会被读，别指望「换回去还能命中」。
- **`data/cache/` 写满磁盘**：文件缓存不会自己限大小，异常大的站点要看一眼目录大小。
- **把缓存当存储**：清一次就没了的东西，不要当数据源。

## 检查清单

- [ ] 生产环境用 Redis 或 `auto`
- [ ] `REDIS_DB` 和同机其他程序错开
- [ ] 清缓存时只清自己的库
- [ ] 插件写的键都有前缀和 TTL
- [ ] `data/cache/` 有监控（别写满盘）
