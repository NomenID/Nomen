# 性能与容量

一个请求的开销主要花在四件事上：PHP 启动与框架加载、数据库查询、模板与钩子、外部服务（Redis / 搜索 / SMTP）。
按这个顺序优化，收益从高到低。

## 先测量

```bash
# 单次请求耗时（排除网络）
curl -s -o /dev/null -w '%{time_total}\n' -H 'Host: example.com' http://127.0.0.1/login

# 数据库：哪些查询最慢
mysql -e "SELECT * FROM performance_schema.events_statements_summary_by_digest
          ORDER BY SUM_TIMER_WAIT DESC LIMIT 10\G"
```

数据库慢查询日志（MySQL）：

```sql
SET GLOBAL slow_query_log = 'ON';
SET GLOBAL long_query_time = 0.5;
```

## opcache

PHP 每次请求都要解析全部文件。opcache 让编译结果留在内存里，**这是性价比最高的一项**。

```ini
opcache.enable=1
opcache.memory_consumption=192
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
```

`validate_timestamps=1` 意味着改文件后最多 2 秒生效，**部署时不用 reload**。
关掉它（`=0`）性能略好，但每次改代码都要 reload PHP-FPM——忘了就是「改了没反应」。

```bash
php -i | grep opcache
```

## Redis

会话和缓存都能落 Redis。Redis 挂掉会回退文件，但文件缓存的并发写会互相等锁，高并发时差别明显。

```bash
redis-cli -n <REDIS_DB> info stats | grep -E 'keyspace_hits|keyspace_misses'
```

命中率长期低于 80% 说明缓存键设计有问题（TTL 太短、键太碎），加缓存没用。

共用 Redis 实例时把 `maxmemory-policy` 设成 `volatile-lru`。`noeviction` 在内存满时直接拒绝写入：
缓存写不进只是未命中，**会话写不进就是掉登录**。每个站用不同的 `REDIS_DB`，别挤在同一个库。

## 数据库

| 问题 | 表现 | 处理 |
|---|---|---|
| 缺索引 | 列表页翻到后面越来越慢 | 按 `WHERE` / `ORDER BY` 建索引 |
| `LIKE '%词%'` | 搜索慢（搜索插件默认引擎） | 页面上万换 Meilisearch |
| 大表 `COUNT(*)` | 分页总数慢 | 缓存总数，或改成「还有更多」 |
| N+1 查询 | 列表页几百次查询 | 一次 `IN (...)` 捞回来 |

检查索引有没有被用上：

```sql
EXPLAIN SELECT * FROM pages WHERE slug = 'start'\G
```

`type` 是 `ALL` 就是全表扫。

## 磁盘：随机读和顺序读写是两回事

同一台机器上的两块盘，顺序写可能都很快，**随机读能差几十倍**：

| 场景 | 适合放哪 |
|---|---|
| 日志、备份、归档 | 慢盘没关系（顺序写） |
| 数据库、搜索索引 | 必须快盘（随机读密集） |

实测数据：把 Meilisearch 的索引放到慢盘，同一个查询从 **60ms 变成 9645ms**；搬回快盘立刻回到 46ms。

测一块盘的真实速度（**`iflag=direct` 必须有**，否则读的是页缓存，两块盘都飞快）：

```bash
dd if=/dev/sdX of=/dev/null bs=1M count=200 iflag=direct
```

别信 `/sys/block/*/queue/rotational`：虚拟机上这个值经常是反的。

## 内存

**别用 `free` 的 `used` 或 `ps` 的 RSS 汇总判断**：共享库和 opcache 会被重复计算，虚高两三倍。

```bash
# 真实占用：每个进程的 Pss 累加
for f in /proc/*/smaps_rollup; do awk '/^Pss:/{s+=$2} END{print s}' "$f"; done \
  | awk '{t+=$1} END{print t/1024 " MB"}'

# 压力：看 avg300，不是看瞬时
cat /proc/pressure/memory
```

`full avg300` 超过 10 才算真紧张。swap 占用率高但 `vmstat` 的 `so` 是 0，那只是历史峰值留下的冷页，
**别用 `swapoff -a` 清它**——可用内存装不下那些页时会直接 OOM。

## 并发

PHP-FPM 的 `pm.max_children` 决定并发上限。经验值：

```
max_children ≈ 可用内存(MB) / 单进程实际占用(MB) - 余量
```

单进程实际占用按 Pss 算（不是 RSS）。设太大 → 内存耗尽；设太小 → 请求排队（502 / 504）。

## 前端产物

`public/assets/dist/` 是构建好的 JS/CSS。发布包自带，不用现场构建。

- 改了 `frontend/` 源码要 `npm run build`。
- 静态资源带 `?v=`，改了要换版本号。
- 别把 `dist/` 排除在备份之外当成「能重建所以不用管」——服务器上没装 node，重建不了。

## 容量参考

| 规模 | 建议 |
|---|---|
| 几百用户、几千页面 | 一台 2C4G + SQLite 或 MySQL，文件缓存够 |
| 上万用户、十万页面 | 4C8G + MySQL + Redis，搜索上 Meilisearch（放快盘） |
| 再往上 | 数据库和搜索分机器，前面加 CDN |

**先测量再扩容**。加内存和换快盘经常比换架构有效，而「上微服务」在这个规模上没有收益。
