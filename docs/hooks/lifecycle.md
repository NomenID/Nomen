# 钩子：请求与路由

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：`docs-internal/docmeta/hooks.json` 和代码里的 `Hooks::filter` / `Hooks::emit` 调用点

## 本组钩子

| 钩子 | 类型 | 参数 | 时机 |
|---|---|---|---|
| `request.before` | run | method, path | 路由匹配之前（已去掉 base path、`?page=` 模式也已归一）；返回 `false` 表示插件自己已经输出响应，不再往下匹配。适合维护页、IP 拦截、整站跳转 |
| `route.not_found` | run | method, path | 没有任何路由命中、统一 404 之前；返回 `false` 表示插件已处理（自定义固定链接、旧地址跳转这类） |

## 触发点

### request.before

- 类型：run
- 参数：method, path
- 时机：路由匹配之前（已去掉 base path、`?page=` 模式也已归一）；返回 `false` 表示插件自己已经输出响应，不再往下匹配。适合维护页、IP 拦截、整站跳转
- 触发点：
  - `src/Router.php`（run）

### route.not_found

- 类型：run
- 参数：method, path
- 时机：没有任何路由命中、统一 404 之前；返回 `false` 表示插件已处理（自定义固定链接、旧地址跳转这类）
- 触发点：
  - `src/Router.php`（run）

