# 钩子：登录与账号

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：`docs-internal/docmeta/hooks.json` 和代码里的 `Hooks::filter` / `Hooks::emit` 调用点

## 本组钩子

| 钩子 | 类型 | 参数 | 时机 |
|---|---|---|---|
| `auth.notice` | filter | html | 登录 / 注册页头部下方；返回值原样输出，PHP 与 Vue 两条渲染都生效 |
| `user.login.succeeded` | run | userId, username | 会话建立之后 |
| `user.logout` | run | userId, username | 清会话之前 |
| `user.registered` | run | userId, username, email（可能为 null） | 注册成功、邮箱记录同步之后 |

## 触发点

### auth.notice

- 类型：filter
- 参数：html
- 时机：登录 / 注册页头部下方；返回值原样输出，PHP 与 Vue 两条渲染都生效
- 触发点：
  - `templates/login.php`（filter）
  - `templates/register.php`（filter）

### user.login.succeeded

- 类型：run
- 参数：userId, username
- 时机：会话建立之后
- 触发点：
  - `controllers/AuthController.php`（run）

### user.logout

- 类型：run
- 参数：userId, username
- 时机：清会话之前
- 触发点：
  - `controllers/AuthController.php`（run）

### user.registered

- 类型：run
- 参数：userId, username, email（可能为 null）
- 时机：注册成功、邮箱记录同步之后
- 触发点：
  - `controllers/AuthController.php`（run，2 处）
  - `controllers/IdentityController.php`（run）

