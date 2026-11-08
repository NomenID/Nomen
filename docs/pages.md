# 站内页面（Pages 插件）

`pages` 插件在站内提供一套文档页面：用 Markdown 写，后台编辑，不用另起一个文档站。
默认启用，挂载路径 `/w`。

页面存在数据库 `pages` 表，不写文件。升级程序不会覆盖站内改过的内容。

## 路径

| 地址 | 作用 |
|---|---|
| `/w` | 页面列表 |
| `/w/<slug>` | 单页 |
| `/w/<slug>/edit` | 编辑（管理员） |
| `/w/new` | 新建（管理员） |
| `/w/sitemap` | 页面 sitemap（XML） |

挂载路径在 `config/plugins/pages.json` 的 `base_path`，也可以去后台「接入点」页改。
改完列表、页内链接、首页的「文档」入口、`/sitemap.xml` 都跟着变。

站点的 `/sitemap.xml` 是一个索引，指向 `<挂载路径>/sitemap`。

## 页面字段

| 字段 | 说明 |
|---|---|
| `slug` | 地址里的名字，必填、唯一；建议小写字母、数字、连字符 |
| 标题 | 页面标题 |
| 摘要 | 列表和 SEO 描述用 |
| 正文 | Markdown |
| 状态 | 发布 / 草稿 |
| 排序 | 列表顺序，小的在前 |

Markdown 支持标题、列表、表格、代码块、链接、图片、引用这些常用语法。正文里的 HTML 会被转义，
不会当标签执行。

## 后台

后台「内容」页有三个选项卡：全部 / 已发布 / 草稿。列表里能看标题、slug、状态、更新时间，
可以新建、编辑、删除。删除是硬删除，没有回收站。

插件还提供 `pages:sync` 命令，把插件 `content/` 目录里新增的默认页面补进库：

```bash
php bin/nomen plugins pages:sync
```

**只补缺，不覆盖**已经存在或被站内改过的页面。

## 首页用页面

后台「首页」把模式切成 `page`，填页面 slug，首页就渲染这个页面。外层仍走当前皮肤。

## 站点 API

插件通过核心的 `/nomen-api` 暴露三个只读模块：

```
GET /nomen-api?action=query&list=allpages
GET /nomen-api?action=query&prop=info&titles=welcome
GET /nomen-api?action=query&prop=content&titles=welcome
```

`allpages` 支持 `limit` / `offset` / `drafts`；`info` 和 `content` 接受 `titles`（`|` 分隔）
和 `drafts`。草稿只有管理员能读。详见 [站点 API](api.md)。

## 配置

| 项 | 位置 | 默认 |
|---|---|---|
| 挂载路径 | `config/plugins/pages.json` 的 `base_path` | `/w` |
| 是否启用 | `config/plugins.php` 的 `enabled` | 启用 |
| 随包默认页面 | 插件 `content/` 目录 | 欢迎、沙盒两页 |

停用插件后 `/w` 消失，但库里的页面还在。重新启用就恢复。
