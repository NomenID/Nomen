# 钩子：页面渲染与导航

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：`docs-internal/docmeta/hooks.json` 和代码里的 `Hooks::filter` / `Hooks::emit` 调用点

## 本组钩子

| 钩子 | 类型 | 参数 | 时机 |
|---|---|---|---|
| `footer.html` | filter | html, layout, brand, links | 页脚最终 HTML（config/footer.php 或皮肤 partial 渲染完） |
| `footer.links` | filter | links, brand, layout | 页脚链接数组 |
| `layout.body.end` | filter | html, layout | 布局 `</body>` 前 |
| `layout.body.start` | filter | html, layout | 布局 `<body>` 后 |
| `layout.head` | filter | html, layout | 布局 `</head>` 前；返回值原样输出（核心先在这里给出 generator / 版本 / 皮肤 / 语言 meta） |
| `meta.tags` | filter | tags, layout | 页面 head 的 meta 组装完；以 `og:` 开头的按 `property` 输出，其余按 `name` |
| `nav.items` | filter | items, userId | 站点导航组装完（PHP 布局和 Vue 外壳共用） |
| `viewer.props` | filter | viewer, userId, user | SPA 的 viewer 数据组装完 |

## 触发点

### footer.html

- 类型：filter
- 参数：html, layout, brand, links
- 时机：页脚最终 HTML（config/footer.php 或皮肤 partial 渲染完）
- 触发点：
  - `src/Footer.php`（filter）

### footer.links

- 类型：filter
- 参数：links, brand, layout
- 时机：页脚链接数组
- 触发点：
  - `src/Footer.php`（filter）

### layout.body.end

- 类型：filter
- 参数：html, layout
- 时机：布局 `</body>` 前
- 触发点：
  - `Skins/nomen-2026/layouts/admin-app.php`（filter）
  - `Skins/nomen-2026/layouts/app.php`（filter）
  - `Skins/nomen-2026/layouts/default.php`（filter）
  - `Skins/nomen-ssr/layouts/admin-app.php`（filter）
  - `Skins/nomen-ssr/layouts/app.php`（filter）
  - `Skins/nomen-ssr/layouts/default.php`（filter）

### layout.body.start

- 类型：filter
- 参数：html, layout
- 时机：布局 `<body>` 后
- 触发点：
  - `Skins/nomen-2026/layouts/admin-app.php`（filter）
  - `Skins/nomen-2026/layouts/app.php`（filter）
  - `Skins/nomen-2026/layouts/default.php`（filter）
  - `Skins/nomen-ssr/layouts/admin-app.php`（filter）
  - `Skins/nomen-ssr/layouts/app.php`（filter）
  - `Skins/nomen-ssr/layouts/default.php`（filter）

### layout.head

- 类型：filter
- 参数：html, layout
- 时机：布局 `</head>` 前；返回值原样输出（核心先在这里给出 generator / 版本 / 皮肤 / 语言 meta）
- 触发点：
  - `Skins/nomen-2026/layouts/admin-app.php`（filter）
  - `Skins/nomen-2026/layouts/app.php`（filter）
  - `Skins/nomen-2026/layouts/default.php`（filter）
  - `Skins/nomen-ssr/layouts/admin-app.php`（filter）
  - `Skins/nomen-ssr/layouts/app.php`（filter）
  - `Skins/nomen-ssr/layouts/default.php`（filter）

### meta.tags

- 类型：filter
- 参数：tags, layout
- 时机：页面 head 的 meta 组装完；以 `og:` 开头的按 `property` 输出，其余按 `name`
- 触发点：
  - `src/Meta.php`（filter）

### nav.items

- 类型：filter
- 参数：items, userId
- 时机：站点导航组装完（PHP 布局和 Vue 外壳共用）
- 触发点：
  - `src/Nav.php`（filter）

### viewer.props

- 类型：filter
- 参数：viewer, userId, user
- 时机：SPA 的 viewer 数据组装完
- 触发点：
  - `src/AppData.php`（filter）

