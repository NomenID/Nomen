# 钩子：首页

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：`docs-internal/docmeta/hooks.json` 和代码里的 `Hooks::filter` / `Hooks::emit` 调用点

## 本组钩子

| 钩子 | 类型 | 参数 | 时机 |
|---|---|---|---|
| `homepage.links` | filter | links | 首页底部链接组；追加条目带 `by`（插件显示名）的话，后台「首页」编辑器会标「由 X 托管」 |
| `homepage.sections` | filter | sections, home | 默认首页正文区块；插件返回 HTML 片段数组 |

## 触发点

### homepage.links

- 类型：filter
- 参数：links
- 时机：首页底部链接组；追加条目带 `by`（插件显示名）的话，后台「首页」编辑器会标「由 X 托管」
- 触发点：
  - `src/Homepage.php`（filter）

### homepage.sections

- 类型：filter
- 参数：sections, home
- 时机：默认首页正文区块；插件返回 HTML 片段数组
- 触发点：
  - `templates/home.php`（filter）

