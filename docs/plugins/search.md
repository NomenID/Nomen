# Search：站内搜索

按关键词搜已发布的文档页。默认查数据库，装了就能用；页面多了可以接 Meilisearch / OpenSearch / Elasticsearch。

**随包自带但默认关闭**——不是每个站都需要在首页放个搜索框。

## 装上

```php
// config/plugins.php
'enabled' => ['open-platform', 'geoip', 'pages', 'search', 'totp-qr'],
```

没有 Pages 插件时它搜不到东西（搜的就是 `pages` 表）。

## 配

`config/plugins/search.json`（不建这个文件就用数据库引擎）：

```json
{
    "engine": "db",
    "per_page": 10,
    "cache_ttl": 300
}
```

换外部引擎的完整配置见 [搜索后端](../integrate/search-backends.md)。

## 开了哪些地址

| 地址 | 干什么 |
|---|---|
| `GET /search?q=<词>&page=<页>` | 搜索页 |
| `GET /nomen-api?action=query&list=search&srsearch=<词>` | 接口形式 |

挂载点默认 `/search`，后台「接入点」能改。

## 命令

```bash
php bin/nomen plugins search:reindex            # 补新增的页面
php bin/nomen plugins search:reindex --fresh    # 清空后全量重建
```

引擎是 `db` 时这条命令什么都不做（数据库不需要建索引）。

## 它怎么搜

| 引擎 | 行为 |
|---|---|
| `db` | `LIKE '%词%'` 查 `pages` 的标题和正文，带索引和分页 |
| `meilisearch` | 推文档进去，按 Meilisearch 的相关性排序 |
| `opensearch` / `elasticsearch` | 同上，走各自的 HTTP API |

**外部引擎连不上时自动退回数据库**，搜索页不报错，只是结果和速度按数据库算。所以「配了 Meili 但感觉没生效」
先看错误日志。

## 缓存

结果按「引擎 + 关键词 + 页码 + 每页条数」缓存 `cache_ttl` 秒（默认 300）。改了页面内容，最多 5 分钟后搜索
才反映出来。设 `0` 关闭缓存。

## 坑

- **草稿不进索引**。只推 `published` 的页面，所以刚写的内容搜不到是正常的。
- **中文分词**：数据库引擎是 `LIKE`，中文按字面匹配，不需要分词；Meilisearch 对中文要选对分词器，
  不然效果反而更差。
- **`--fresh` 有空窗**：先清空再填，期间搜索是空的。大站要零中断就别用 `--fresh`，见
  [搜索后端](../integrate/search-backends.md) 的换索引办法。
- **搜索页要自己放进导航**。插件不往主导航里塞入口，用 `nav.items` 钩子加，或者后台菜单里不放也完全可以。

## 检查清单

- [ ] `enabled` 里加了
- [ ] Pages 插件也在启用状态
- [ ] `/search?q=测试` 有结果
- [ ] 外部引擎跑过 `search:reindex` 且条数对得上
- [ ] 导航里有入口（如果希望有）
