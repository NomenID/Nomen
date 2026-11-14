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

Redis 的库号走 `config/app.php` 的 `redis.db`，`.env` 的 `REDIS_DB` 可覆盖。同一个 Redis
上跑多个程序时，**给每个站分配不同的库**：库号相同的话，键名再带前缀也只能避免误删，避免不了
`SCAN`、`DBSIZE` 这些操作互相看见对方的键。

插件可以注册自己的驱动：

```php
\Nomen\Src\Cache::registerDriver('memcached', static fn() => new MyDriver());
```

驱动实现 `Nomen\Src\Cache\Driver`（`get` / `put` / `forget`，未命中返回 `null`）。想支持后台一键清空，
再实现 `Nomen\Src\Cache\Flushable` 的 `flush()`，**只删自己的键**。

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
| Pages 页面列表 | `pages:v<代次>:all:<public\|drafts>` | 10 分钟 |
| Pages 正文 HTML + 目录 | `pages:md:v<版本>:<正文哈希>` | 7 天 |
| 搜索结果（装了 search 插件） | `search:<sha1>` | `cache_ttl`，默认 300 秒 |

版本页要读插件 / 皮肤的清单和许可文件，缓存 60 秒够挡掉重复扫描。**键里带 locale**：
以前漏了它，切语言时整页英文（1.3.5 修过）。

Pages 的正文缓存按**正文哈希**做键：改一个字就是新键，旧条目到期自己走。

## 改内容时怎么失效

Pages 的新建、编辑、删除、导入、改目录菜单都会 `Cache::bump('pages')`：代次号 +1，
所有 `pages:v<代次>:*` 的旧键立刻不再命中，不用逐个找。TTL 只是兜底。

插件要缓存「一改就全失效」的一组数据，也照这个做：

```php
$key = 'bookmarks:v' . Cache::generation('bookmarks') . ':' . $id;
$items = Cache::remember($key, 600, static fn(): array => Store::items($id));

// 数据改动后
Cache::bump('bookmarks');
```

## 清缓存

后台「设置」→「缓存」→「清空缓存」。只清**本站前缀**下的键：

- 文件：删 `data/cache/*.cache`
- 数据库：清空 `cache_items`
- Redis：`SCAN` 出 `nomen:cache:<站点哈希>:*` 再删，**不是 `FLUSHDB`**——同一个库里可能还有别的程序

插件驱动的后端如果没实现 `Flushable`，后台会提示不支持，不会假装清过。

命令行等价操作：

```bash
# 文件缓存
rm -rf data/cache/*

# Redis：只清本站前缀（库编号在 .env 的 REDIS_DB）
redis-cli -n <REDIS_DB> --scan --pattern 'nomen:cache:<站点哈希>:*' | xargs -r redis-cli -n <REDIS_DB> unlink
```

什么时候需要清：

- 升级程序后版本页还是旧号（等 60 秒也行）。
- 改了插件 / 皮肤清单但版本页没变。
- 换了缓存后端，旧文件缓存不再被读（可以手动删 `data/cache/`）。

## 容量与回收

缓存不该因为「写满了」把站点拖垮。三层各自有上限：

| 后端 | 回收方式 |
|---|---|
| `file` | 每次写入有 1/50 概率整理：先删过期文件，超过 `file_max_mb` 再按最旧优先删到 80% |
| `db` | 写入时清过期行；单条超过 8 MB 直接不缓存 |
| `redis` | 每条都有 TTL，由 Redis 自己过期；单条超过 8 MB 直接不缓存 |

**Redis 的 `maxmemory-policy` 是实例级参数，不看前缀也不看库。** 如果实例设成 `noeviction`，
内存满时所有写入都会失败（缓存写不进是未命中，会话写不进就是掉登录）。前缀能保证「我们清的时候
只删自己的」，但保证不了「满了只淘汰自己的」。共用实例时把策略设成 `volatile-lru`
（只淘汰带 TTL 的键），或者给这个站单独起一个 Redis 实例。

## 配置项

| 项 | 位置 |
|---|---|
| 后端选择 | `site_settings.cache.backend`，后台「设置」 |
| 文件缓存上限 | `config/app.php` 的 `cache.file_max_mb`（默认 64），或环境变量 `CACHE_FILE_MAX_MB` |
| Redis 连接 | `config/app.php` 的 `redis` 段，`.env` 的 `REDIS_*` |
| 文件缓存目录 | `data/cache/` |

Redis 连接失败时会话也会回退（`[Session] Redis 不可用，回退文件存储`），这两条是独立判断的。

## 在插件里用

```php
use Nomen\Src\Cache;

$value = Cache::remember('bookmarks:top', 300, static fn() => Store::top(10));
```

四条规矩：

- **键带足够的前缀和维度**（`bookmarks:top` 而不是 `top`）。多个插件共用一个缓存空间。
- **TTL 按「多久能接受旧数据」定**，不是越长越好。
- **不要把「必须实时」的东西放缓存**：余额、权限判断、限流计数都不该走缓存。
- **一组数据一起失效时用 `generation` / `bump`**，别在保存时逐个 `forget`——漏一个就是长期脏数据。

## 坑

- **键里漏了 locale**：多语言站会出现「整页英文」，这一类 bug 很难复现（要切语言才看得到）。
- **Redis 和文件混用**：换了后端之后旧键不会被读，别指望「换回去还能命中」。
- **把缓存当存储**：清一次就没了的东西，不要当数据源。
- **两套缓存各写一份**：同一份数据别在核心和插件里各缓存一次，失效逻辑迟早对不上。

## 检查清单

- [ ] 生产环境用 Redis 或 `auto`
- [ ] 同一个 Redis 上每个程序用不同的库
- [ ] 共用 Redis 实例时策略不是 `noeviction`
- [ ] 清缓存时只清本站前缀
- [ ] 插件写的键都有前缀和 TTL
- [ ] 缓存目录有监控（文件缓存有上限，但磁盘本身也要看）
