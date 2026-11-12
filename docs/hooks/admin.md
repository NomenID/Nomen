# 钩子：后台

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：`docs-internal/docmeta/hooks.json` 和代码里的 `Hooks::filter` / `Hooks::emit` 调用点

## 本组钩子

| 钩子 | 类型 | 参数 | 时机 |
|---|---|---|---|
| `admin.apps.detail` | filter | app, clientId | 应用详情返回前 |
| `admin.apps.list` | filter | apps | 应用列表返回前 |
| `admin.dashboard.cards` | filter | cards, stats | 概览页统计卡 |
| `admin.dashboard.site` | filter | site | 概览页站点信息块 |
| `admin.logs.rows` | filter | rows | 活动日志列表返回前 |
| `admin.menu.items` | filter | items | 后台侧栏菜单组装完 |
| `admin.mounts.list` | filter | mounts | 挂载点列表返回前 |
| `admin.plugin.page.body` | filter | html, slug, page, tab | 插件后台页面输出前 |
| `admin.recovery.detail` | filter | payload, request, user | 恢复工单详情返回前 |
| `admin.settings.data` | filter | payload | 站点设置页数据 |
| `admin.settings.saved` | run | policy, backend, adminId, apiEnabled | 站点设置保存后 |
| `admin.shell.props` | filter | props, adminId | 后台 SPA 首屏数据 |
| `admin.user.detail` | filter | payload, userId, user, admin | 用户详情返回前 |
| `admin.user.role_changed` | run | userId, role, adminId | 管理员授予/撤销管理员之后 |
| `admin.user.status_changed` | run | userId, status, adminId | 管理员启用/禁用账户之后 |
| `admin.users.rows` | filter | rows | 用户列表返回前 |

## 触发点

### admin.apps.detail

- 类型：filter
- 参数：app, clientId
- 时机：应用详情返回前
- 触发点：
  - `controllers/AdminAppsController.php`（filter）

### admin.apps.list

- 类型：filter
- 参数：apps
- 时机：应用列表返回前
- 触发点：
  - `controllers/AdminAppsController.php`（filter）

### admin.dashboard.cards

- 类型：filter
- 参数：cards, stats
- 时机：概览页统计卡
- 触发点：
  - `controllers/AdminController.php`（filter）

### admin.dashboard.site

- 类型：filter
- 参数：site
- 时机：概览页站点信息块
- 触发点：
  - `controllers/AdminController.php`（filter）

### admin.logs.rows

- 类型：filter
- 参数：rows
- 时机：活动日志列表返回前
- 触发点：
  - `controllers/AdminController.php`（filter）

### admin.menu.items

- 类型：filter
- 参数：items
- 时机：后台侧栏菜单组装完
- 触发点：
  - `src/Admin.php`（filter）

### admin.mounts.list

- 类型：filter
- 参数：mounts
- 时机：挂载点列表返回前
- 触发点：
  - `controllers/AdminMountsController.php`（filter）

### admin.plugin.page.body

- 类型：filter
- 参数：html, slug, page, tab
- 时机：插件后台页面输出前
- 触发点：
  - `src/Admin.php`（filter）

### admin.recovery.detail

- 类型：filter
- 参数：payload, request, user
- 时机：恢复工单详情返回前
- 触发点：
  - `controllers/AdminController.php`（filter）

### admin.settings.data

- 类型：filter
- 参数：payload
- 时机：站点设置页数据
- 触发点：
  - `controllers/AdminSettingsController.php`（filter）

### admin.settings.saved

- 类型：run
- 参数：policy, backend, adminId, apiEnabled
- 时机：站点设置保存后
- 触发点：
  - `controllers/AdminSettingsController.php`（run）

### admin.shell.props

- 类型：filter
- 参数：props, adminId
- 时机：后台 SPA 首屏数据
- 触发点：
  - `src/AdminShell.php`（filter）

### admin.user.detail

- 类型：filter
- 参数：payload, userId, user, admin
- 时机：用户详情返回前
- 触发点：
  - `controllers/AdminController.php`（filter）

### admin.user.role_changed

- 类型：run
- 参数：userId, role, adminId
- 时机：管理员授予/撤销管理员之后
- 触发点：
  - `controllers/AdminController.php`（run）

### admin.user.status_changed

- 类型：run
- 参数：userId, status, adminId
- 时机：管理员启用/禁用账户之后
- 触发点：
  - `controllers/AdminController.php`（run）

### admin.users.rows

- 类型：filter
- 参数：rows
- 时机：用户列表返回前
- 触发点：
  - `controllers/AdminController.php`（filter）

