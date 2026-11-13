# 搜索后端

搜索插件（`search`）默认直接查数据库，装了就能用。页面多了以后可以换成 Meilisearch、OpenSearch 或
Elasticsearch。

**换引擎前先确认真的需要**：数据库引擎在几万页的规模上还能用（`LIKE` 查询 + 索引），换外部引擎意味着多一个
要维护、要备份、会挂的服务。

## 配置

`config/plugins/search.json`：

```json
{
    "engine": "meilisearch",
    "per_page": 10,
    "cache_ttl": 300,
    "meilisearch": {
        "host": "http://127.0.0.1:7700",
        "api_key": "<搜索密钥>",
        "index": "nomen_pages"
    }
}
```

| 键 | 说明 |
|---|---|
| `engine` | `db` / `meilisearch` / `opensearch` / `elasticsearch` |
| `per_page` | 每页条数，1–50，默认 10 |
| `cache_ttl` | 结果缓存秒数，默认 300；`0` 关闭缓存 |
| `<engine>` | 对应引擎的连接参数 |

各引擎的参数：

| 引擎 | 键 |
|---|---|
| `meilisearch` | `host` / `api_key` / `index` |
| `opensearch` | `host` / `username` / `password` / `index` |
| `elasticsearch` | `host` / `username` / `password` / `index` |

`host` 不带结尾斜杠也行，代码会处理。`index` 默认 `nomen_pages`。

**引擎填错或连不上时会自动退回数据库**，搜索页不会报错，只是慢一点。所以「配了 Meili 但感觉没生效」
先看错误日志。

## 建索引

```bash
php bin/nomen plugins search:reindex
php bin/nomen plugins search:reindex --fresh
```

- 不带 `--fresh`：只补新文档（按 slug 覆盖写入）。
- 带 `--fresh`：**先清空同名索引里的所有文档**，再全量推。

`engine` 是 `db` 时这条命令什么都不做，直接告诉你不需要建索引。

## 全量重建的窗口期

`--fresh` 是「原地清空再填」：从清空到填完之间，搜索是空的。页面只有几百条时几秒钟，几万条时可能几十秒。

**站点大又不想中断**，就别用 `--fresh`，改成两步：

1. 在 Meilisearch 里手工建一个新索引名（比如 `nomen_pages_new`），把 `config/plugins/search.json` 的
   `index` 指过去，跑一次不带 `--fresh` 的 `search:reindex`（新索引本来就是空的）。
2. 推完把 `index` 改回正式名，用 Meilisearch 的 `POST /swap-indexes` 原子换，再删旧索引。

**磁盘放不下「旧 + 新」两份的站点，这个取舍要自己拍板**：`--fresh` 会有空窗，swap 要多一份空间。

## Meilisearch 装在哪

机器是机械盘的话**索引必须放快盘**。实测把 Meilisearch 的 LMDB 索引放到慢盘，搜索延迟从 60ms 变成 9.6 秒。
顺序读写的日志、备份放慢盘没问题，随机读密集的不行。

```bash
# 确认数据目录
grep -n "db_path\|--db-path" /etc/systemd/system/meilisearch.service
```

生产环境至少给 Meilisearch 单独一个进程和一个 `master_key`，`api_key` 用只读的搜索密钥，别把 admin key
填进插件配置——插件只需要写文档和搜。

## 索引里放什么

插件推的是**已发布的 Pages 页面**：标题、摘要、正文纯文本、slug、更新时间。`--fresh` 之后如果搜索页
一条都搜不到，按这个顺序查：

1. 页面是不是 `published`（草稿不进索引）。
2. `search:reindex` 的输出条数是否和后台页面数对得上。
3. 引擎里 `index` 名是否和配置一致（大小写、是否多了空格）。

## 搜索页

挂载点默认 `/search`，可以在后台「接入点」改。查询串 `?q=`，页码 `?page=`。

也可以走站点 API：

```
GET /nomen-api?action=query&list=search&srsearch=<关键词>
```

返回结果里带 `engine` 字段，能看出这次是哪个引擎回答的——排查时有用。

## 缓存

`cache_ttl` 默认 300 秒。搜索结果按「引擎 + 关键词 + 页码 + 每页条数」缓存，所以：

- 改了页面内容，最多 5 分钟后搜索才反映出来；要立刻生效就清缓存。
- 缓存后端是站点统一的那个（Redis / 文件 / 数据库），没配 Redis 时落文件。

## 检查清单

- [ ] `engine` 拼写对
- [ ] `host` 从本站能连通（`curl http://127.0.0.1:7700/health`）
- [ ] `api_key` 是搜索密钥，不是 master key
- [ ] `search:reindex` 跑过，条数对得上
- [ ] 大站重建前确认了磁盘余量和空窗可接受
- [ ] 缓存时间按「内容更新频率」调过
