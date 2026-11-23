# 钩子：登录与账号

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：`docs-internal/docmeta/hooks.json` 和代码里的 `Hooks::filter` / `Hooks::emit` 调用点

## 本组钩子

| 钩子 | 类型 | 参数 | 时机 |
|---|---|---|---|
| `auth.human_check` | filter | check, context | 登录 / 注册页渲染和提交时的人机验证；`check` 是 `required`（要不要验证码）/ `verified`（是否已经通过），`context` 有 page（login / register）、stage（render / verify）、identifier、ip、user_agent。插件可以用 PoW 等方案替代核心算术验证码：`verified=true` 时不再要求验证码，`required=false` 时连输入框都不显示 |
| `auth.login.failed` | run | message, userId, context | 统一登录失败出口（活动日志写完之后）；`context` 带 method、identifier、ip、is_ajax，风险筛查拒绝时还有 decision |
| `auth.login.pre` | filter | screen, context | 带凭据的登录入口在验证凭据 / 建立会话之前（密码、邮箱验证码、第三方登录）。`screen` 是 `decision`（`allow` / `block` / `challenge` / `degraded`）/ `message` / `payload`，`context` 有 method、identifier、accounts（只有 id / username / email）、ip、user_agent、is_ajax。`challenge` 会让下一次尝试要求图片验证码；插件拿不到密码，也不能替用户免验证通过 |
| `auth.notice` | filter | html | 登录 / 注册页头部下方；返回值原样输出，PHP 与 Vue 两条渲染都生效 |
| `auth.page` | run | page, context | 登录 / 注册 / 临时密钥页渲染前；`page` 是 `login` / `register` / `fast-pass`，插件可以在这一步设置设备 cookie、预置挑战状态 |
| `auth.register.pre` | filter | screen, context | 核心注册页和第三方建号写库之前；`screen` 形状同 `auth.login.pre`，`context` 有 username、email、ip、user_agent、data |
| `user.avatar.changed` | run | userId, oldAvatar, newAvatar | 头像更换并写入数据库之后；两个文件名都不带 `/assets/avatars/` 前缀 |
| `user.deleted` | run | userId, username, email | 注销未验证账号、删库之前；拿到的是删前的快照 |
| `user.email.changed` | run | userId, oldEmail, newEmail | 首选邮箱切换成功之后 |
| `user.login.succeeded` | run | userId, username | 会话建立之后 |
| `user.logout` | run | userId, username | 清会话之前 |
| `user.password.changed` | run | userId, source | 密码写入新哈希之后；`source` 是 `settings`（设置页改密）/ `recovery`（恢复流程）/ `email_code`（邮箱验证码重置） |
| `user.profile.changed` | run | userId, profile, before | 个人资料（显示名 / 简介）保存之后；`profile` 是新值，`before` 是旧值 |
| `user.registered` | run | userId, username, email（可能为 null） | 注册成功、邮箱记录同步之后 |

## 触发点

### auth.human_check

- 类型：filter
- 参数：check, context
- 时机：登录 / 注册页渲染和提交时的人机验证；`check` 是 `required`（要不要验证码）/ `verified`（是否已经通过），`context` 有 page（login / register）、stage（render / verify）、identifier、ip、user_agent。插件可以用 PoW 等方案替代核心算术验证码：`verified=true` 时不再要求验证码，`required=false` 时连输入框都不显示
- 触发点：
  - `controllers/AuthController.php`（filter）

### auth.login.failed

- 类型：run
- 参数：message, userId, context
- 时机：统一登录失败出口（活动日志写完之后）；`context` 带 method、identifier、ip、is_ajax，风险筛查拒绝时还有 decision
- 触发点：
  - `controllers/AuthController.php`（run）

### auth.login.pre

- 类型：filter
- 参数：screen, context
- 时机：带凭据的登录入口在验证凭据 / 建立会话之前（密码、邮箱验证码、第三方登录）。`screen` 是 `decision`（`allow` / `block` / `challenge` / `degraded`）/ `message` / `payload`，`context` 有 method、identifier、accounts（只有 id / username / email）、ip、user_agent、is_ajax。`challenge` 会让下一次尝试要求图片验证码；插件拿不到密码，也不能替用户免验证通过
- 触发点：
  - `controllers/AuthController.php`（filter）

### auth.notice

- 类型：filter
- 参数：html
- 时机：登录 / 注册页头部下方；返回值原样输出，PHP 与 Vue 两条渲染都生效
- 触发点：
  - `templates/login.php`（filter）
  - `templates/register.php`（filter）

### auth.page

- 类型：run
- 参数：page, context
- 时机：登录 / 注册 / 临时密钥页渲染前；`page` 是 `login` / `register` / `fast-pass`，插件可以在这一步设置设备 cookie、预置挑战状态
- 触发点：
  - `controllers/AuthController.php`（run，3 处）

### auth.register.pre

- 类型：filter
- 参数：screen, context
- 时机：核心注册页和第三方建号写库之前；`screen` 形状同 `auth.login.pre`，`context` 有 username、email、ip、user_agent、data
- 触发点：
  - `controllers/AuthController.php`（filter）

### user.avatar.changed

- 类型：run
- 参数：userId, oldAvatar, newAvatar
- 时机：头像更换并写入数据库之后；两个文件名都不带 `/assets/avatars/` 前缀
- 触发点：
  - `controllers/DashboardController.php`（run）

### user.deleted

- 类型：run
- 参数：userId, username, email
- 时机：注销未验证账号、删库之前；拿到的是删前的快照
- 触发点：
  - `controllers/ActivationController.php`（run）

### user.email.changed

- 类型：run
- 参数：userId, oldEmail, newEmail
- 时机：首选邮箱切换成功之后
- 触发点：
  - `controllers/DashboardController.php`（run）

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

### user.password.changed

- 类型：run
- 参数：userId, source
- 时机：密码写入新哈希之后；`source` 是 `settings`（设置页改密）/ `recovery`（恢复流程）/ `email_code`（邮箱验证码重置）
- 触发点：
  - `controllers/DashboardController.php`（run，2 处）

### user.profile.changed

- 类型：run
- 参数：userId, profile, before
- 时机：个人资料（显示名 / 简介）保存之后；`profile` 是新值，`before` 是旧值
- 触发点：
  - `controllers/DashboardController.php`（run）

### user.registered

- 类型：run
- 参数：userId, username, email（可能为 null）
- 时机：注册成功、邮箱记录同步之后
- 触发点：
  - `controllers/AuthController.php`（run，2 处）
  - `controllers/IdentityController.php`（run）

