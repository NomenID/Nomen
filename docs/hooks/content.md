# 钩子：内容与站点 API

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：`docs-internal/docmeta/hooks.json` 和代码里的 `Hooks::filter` / `Hooks::emit` 调用点

## 本组钩子

| 钩子 | 类型 | 参数 | 时机 |
|---|---|---|---|
| `api.siteinfo` | filter | info, siprops, params | `/nomen-api` 的 siteinfo 组装完 |
| `pages.admin.editor.extras` | filter | html, page | Pages 插件后台的 Markdown 编辑器下方，返回值原样输出，用来给编辑器加额外表单片段 |
| `pages.content.before_save` | filter | data, existing, isNew | Pages 插件写库前，可改字段 |
| `pages.content.deleted` | run | pageId, page, userId | Pages 插件删除前 |
| `pages.content.saved` | run | pageId, data, isNew, userId | Pages 插件保存后 |

## 触发点

### api.siteinfo

- 类型：filter
- 参数：info, siprops, params
- 时机：`/nomen-api` 的 siteinfo 组装完
- 触发点：
  - `src/Api/Core.php`（filter）

### pages.admin.editor.extras

- 类型：filter
- 参数：html, page
- 时机：Pages 插件后台的 Markdown 编辑器下方，返回值原样输出，用来给编辑器加额外表单片段
- 注意：Pages 插件自己的钩子，核心不触发；没启用 Pages 就没有这个钩子。
- 触发点：
  - `Plugins/pages/src/Admin.php`（filter）

### pages.content.before_save

- 类型：filter
- 参数：data, existing, isNew
- 时机：Pages 插件写库前，可改字段
- 触发点：
  - `Plugins/pages/src/Controller.php`（filter，2 处）

### pages.content.deleted

- 类型：run
- 参数：pageId, page, userId
- 时机：Pages 插件删除前
- 触发点：
  - `Plugins/pages/src/Controller.php`（run）

### pages.content.saved

- 类型：run
- 参数：pageId, data, isNew, userId
- 时机：Pages 插件保存后
- 触发点：
  - `Plugins/pages/src/Controller.php`（run，2 处）

