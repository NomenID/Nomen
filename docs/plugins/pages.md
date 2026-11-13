# Pages：站内文档

在站点里写 Markdown 页面，不用另起一个文档站。默认挂在 `/w`。

## 装上

随包自带、默认启用：

```php
// config/plugins.php
'enabled' => ['open-platform', 'geoip', 'pages', 'totp-qr'],
```

## 配

`config/plugins/pages.json`：

```json
{
    "base_path": "/w"
}
```

`base_path` 是挂载点。后台「接入点」页也能改（改的是同一个文件）。插件会把 `/` 和空值兜底成 `/w`，
所以别指望用它把文档放到根路径——那会和首页撞。

## 开了哪些地址

| 地址 | 谁 | 干什么 |
|---|---|---|
| `GET /w` | 公开 | 目录首页，按目录分组列出页面 |
| `GET /w/{slug}` | 公开 | 单页，slug 支持层级（`local-docs/install`） |
| `GET /w/{slug}/edit` | 管理员 | Markdown 编辑器（旧的直出表单，后台现在是 SPA） |
| `POST /w/{slug}/edit` | 管理员 | 保存 |
| `POST /w/{slug}/delete` | 管理员 | 删除 |
| `POST /w/-/nav` | 管理员 | 保存侧边栏 |
| `GET /w/sitemap` | 公开 | 文档的 sitemap |
| `GET /w/assets/pages` | 公开 | 文档样式表 |

后台在「内容」下面：「全部 / 已发布 / 草稿」三个标签页，加一个「目录」页管侧边栏和目录记录。

## 数据

两张表，插件自己建（`CREATE TABLE IF NOT EXISTS`，不需要跑迁移）：

| 表 | 存什么 |
|---|---|
| `pages` | 页面：slug、标题、正文（Markdown）、状态、排序、摘要、更新时间 |
| `page_menus` | 目录记录：路径、标题、摘要、排序、`mode`（`auto` / `manual`） |

`page_menus` 的列是**自动补的**：老站升级到 1.2.0 之后第一次访问，插件自己 `ALTER TABLE` 补
`title` / `summary` / `sort_order` / `mode`，不用手动跑迁移。

## 侧边栏两种模式

- `manual`：后台手写一份条目列表，老站默认这个，行为没变。
- `auto`：按 slug 树现场生成。子目录成一组，**只有当前页所在的那条链展开**，其余收起；
  叶子按 `sort_order` 再标题排。组标题是指向组内第一个文档（优先 `index`）的链接 —— 没有 JS
  时直接跳转，有 JS 时点击只展开收起，Ctrl / 中键点照常开新标签页。

改成 auto 之后新加的页面会自动出现在侧边栏，不用每次手工加一条。

## 导入 Markdown

```bash
php bin/nomen plugins pages:sync                                # 导入插件自带 content/
php bin/nomen plugins pages:import docs --prefix=local-docs     # 导入某个目录
php bin/nomen plugins pages:import docs-internal/x --prefix=oauth --update
```

- `--prefix` 是导入到站内的路径前缀，决定 slug（`local-docs/install`）。
- 标题先看 frontmatter 的 `title`，没有再取正文第一个 H1（并从正文里删掉那一行），都没有才用文件名。
- `--update` 覆盖已存在的页面；不加就只补缺，站内改过的不会被盖。文件里没写 `sort:` 时保留站内排好的顺序。
- 目录是**递归**的：别把不想导入的目录放进被导入的目录里（比如把内部笔记放在 `docs/` 下面）。
- 相对 `.md` 链接会被自动改写成 `/w/<前缀>/xxx`；跨出目录的引用要写绝对地址，不然是死链。

## 模板与皮肤

正文模板（`templates/page.php`、`templates/index.php`）在插件自己的目录里，**直接 `require`，皮肤覆盖不了**。
外面那层用的是核心的 `templates/special.php`，皮肤可以覆盖它，所以页头页脚跟站点一致。

想改文档页的版式：

- 改样式表：`GET /w/assets/pages` 那支 CSS（插件自带），或者在自己的皮肤里加覆盖规则。
- 改外壳：覆盖核心的 `special` 模板，注意它会带 `hideTitle` / `pageClass` / `extraCss` 三个变量。

## 坑

- **slug 里的 `README`**：Pages 的 `/w/*` 有 nginx 白名单（`pages-mount.conf`），敏感词当 slug 不会 404；
  但别用 `..` 之类的路径穿越尝试。
- **改了 `base_path` 旧地址不跳转**：旧链接直接 404，站内链接会被改写，外部的自己处理。
- **正文只存在库里**：`/w/readme`、`/w/download` 这些页在后台改，不在仓库里；仓库里的 `docs/` 只是导入源。
- **Markdown 不支持裸 HTML**：先 `htmlspecialchars` 再渲染，写 `<div>` 会原样显示成文字。

## 检查清单

- [ ] `base_path` 没和别的路由撞
- [ ] 导入用了对的 `--prefix`
- [ ] 侧边栏模式选对了（新站建议 auto）
- [ ] 站内链接点过一遍，没有 `/w/../` 这种
