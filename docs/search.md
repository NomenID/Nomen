# 搜索

搜索是随包插件（`plugins/search/`），**默认不启用**。搜的是 Pages 插件的已发布页面，草稿不出现。

## 打开

```php
// config/plugins.php
'enabled' => ['open-platform', 'geoip', 'pages', 'search', 'totp-qr'],
```

```json
// config/plugins/search.json（不写也能用，全走默认值）
{
    "base_path": "/search",
    "engine": "db",
    "per_page": 10,
    "cache_ttl": 300
}
```

`base_path` 是挂载点，后台「接入点」也能改。

## 引擎

| `engine` | 说明 |
|---|---|
| `db`（默认） | 对 `pages` 表做 `LIKE`，标题命中排前面。不用装任何东西 |
| `meilisearch` | `meilisearch.host` / `api_key` / `index` |
| `opensearch` | `opensearch.host` / `username` / `password` / `index` |
| `elasticsearch` | 同 OpenSearch，配置写在 `elasticsearch` 段 |

外部引擎连不上时**自动退回 `db`**，只往日志写一行，搜索框不会 500。

## 建索引

```bash
php bin/nomen plugins search:reindex            # 补新增
php bin/nomen plugins search:reindex --fresh    # 清空后全量
```

`db` 不需要建索引。`--fresh` 有窗口期（先清后填），大站怎么做到零中断见
[搜索后端](integrate/search-backends.md)。

## 接口

```
GET /nomen-api?action=query&list=search&q=安装&page=1&limit=10
```

返回 `query.search` 里的 `total`、`engine`、`results`（`slug` / `title` / `summary` / `excerpt` / `url`）。

## 详细说明

配置项、缓存、中文分词、重建窗口期：见 [官方插件 / Search](plugins/search.md) 和
[搜索后端](integrate/search-backends.md)。
