# config/permissions.php

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：`config/permissions.php`

共 11 项。

## *

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `groups.*.label` | — | Everyone | 所有请求者，含未登录 |

## 隐式组

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `groups.*.implicit` | — | true | 不出现在后台网格，也不可指派 |
| `groups.*.abilities` | — | 空数组 | — |

## user

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `groups.user.label` | — | Signed-in users | 已登录且状态正常的账号 |
| `groups.user.implicit` | — | true | — |
| `groups.user.abilities` | — | 空数组 | — |

## administrator

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `groups.administrator.label` | — | Administrators | 管理员组，旧版的 users.role = 'admin' |
| `groups.administrator.abilities` | — | * | 全部能力。等价于旧版 users.role = 'admin' 的一切权限。 |

## 示例

建一个只能看用户和日志的客服组（要启用就取消注释，再用后台用户详情页或 `php bin/nomen group:assign` 把人加进来）

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `groups.support.label` | — | Support | — |

## abilities

- `users.manage`
- `logs.view`

