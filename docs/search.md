# 搜索

搜索是一个随包的插件（`plugins/search/`），**默认不启用**——用不上就不开，开了也不影响别的功能。

## 打开

1. 把 `search` 加进 `config/plugins.php` 的 `enabled`；
2. 需要的话写一份 `config/plugins/search.json`（不写也能用，全部走默认值）。

```json
{
    "base_path": "/search",
    "engine": "db",
    "per_page": 10,
    "cache_ttl": 300
}
```

搜索页在 `base_path`，后台「接入点」里能改路径。它搜的是 Pages 插件的**已发布**页面，草稿不会出现。

## 引擎

| `engine` | 说明 |
|---|---|
| `db`（默认） | 直接对 `pages` 表做 `LIKE`，标题命中的排前面。不用装任何东西，小站够用 |
| `meilisearch` | 需要 Meilisearch 实例和 `meilisearch.host` / `api_key` / `index` |
| `opensearch` | 需要 OpenSearch 和 `opensearch.host` / `username` / `password` / `index` |
| `elasticsearch` | 同 OpenSearch（7.x 起接口一致），配置写在 `elasticsearch` 段 |

外部引擎连不上时自动退回 `db`，只往错误日志写一行，搜索框不会 500。所以引擎挂了站点也不会跟着挂。

## 建索引

`db` 不需要建索引。换成外部引擎之后推一次：

```bash
php bin/nomen plugins search:reindex
php bin/nomen plugins search:reindex --fresh
```

`--fresh` 会先清掉旧文档再推。之后改了页面要再跑一次，或者挂个定时任务（页面不多的话，一天一次足够）。

## 接口

`/nomen-api?action=query&list=search&q=安装&page=1&limit=10`

返回 `query.search` 里的 `total`、`engine` 和 `results`（`slug` / `title` / `summary` / `excerpt` / `url`）。
翻页用 `page`，超过一页时会带 `continue.page`。

## 缓存

查询结果走核心缓存（见 [缓存](cache.md)），键里带引擎、关键词和页码，默认 300 秒。`cache_ttl` 设成 `0`
关掉缓存——改完页面想立刻看到效果时有用，但每次搜索都会真查一遍引擎。

## 许可

插件按 Apache-2.0 授权，源码在 <https://github.com/NomenID/plugin-search>。
