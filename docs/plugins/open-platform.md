# Open Platform：开发者门户

给第三方应用一个自助入口：注册开发者、建 OAuth 应用、看自己的应用、改回调地址和 scope。
不开这个插件，管理员仍然可以在后台「应用」里建应用——核心的 `OAuthClients` 不依赖插件。

## 装上

随包自带、默认启用。不想要就把它从 `enabled` 里删掉，`/open` 立刻消失，已有应用照常能授权。

## 配

`config/plugins/open-platform.json`：

```json
{
    "base_path": "/open"
}
```

后台「接入点」页能改。

## 开了哪些地址

| 地址 | 谁 | 干什么 |
|---|---|---|
| `GET /open` | 公开 | 门户首页，介绍能做什么 |
| `GET /open/dashboard` | 登录 | 我的应用列表 |
| `GET /open/settings` | 登录 | 开发者资料 |
| `POST /open/register` | 登录 | 注册成开发者 |
| `POST /open/profile` | 登录 | 改开发者资料 |
| `GET/POST /open/apps/create` | 登录 | 新建应用 |
| `GET /open/apps/{clientId}` | 登录 | 应用详情 |
| `POST /open/apps/{clientId}/update` | 登录 | 改回调地址、scope、名称 |
| `POST /open/apps/{clientId}/reset-secret` | 登录 | 重置 secret（旧的立刻失效） |
| `POST /open/apps/{clientId}/delete` | 登录 | 删应用 |

后台还有「开发者」页（`/admin/developers`），管理员能看全部开发者、启停某个账号。

## 数据

| 表 | 存什么 |
|---|---|
| `developers` | 开发者档案：所属账号、名称、图标、API 令牌哈希、状态 |
| `oauth_clients` | 应用本身（核心表），`developer_id` 指回开发者 |

开发者只能看到 `developer_id` 是自己的应用。**这条归属判断在插件里**，核心不管。

## 和核心的分工

| 事 | 谁做 |
|---|---|
| 建 / 改 / 删应用 | 插件（后台也能做） |
| 授权页、发码、换 token | 核心 `OAuthController` |
| 用户的授权记录 | 核心 `user_authorizations` |
| 应用列表的「开发者」列 | 插件通过 `admin.apps.list` 钩子补 |

所以停用插件之后，应用还在、还能授权，只是开发者自己看不到管理界面了。

## 坑

- **secret 只显示一次**。重置之后旧 secret 立刻失效，接入方会掉线——重置前提醒用户。
- **回调地址逐字节一致**。门户里改完要通知接入方同步改，否则 `invalid_grant`。
- **开发者 ≠ 管理员**。开发者能建应用，但进不了后台；管理员能给任何应用改配置。
- **删除应用不撤销已发出的令牌**。删了应用，用户手里已经换到的 access_token 还能用到过期为止。

## 检查清单

- [ ] `base_path` 没和别的路由撞
- [ ] 门户首页能开
- [ ] 用一个普通账号走完「注册开发者 → 建应用 → 拿 secret」
- [ ] 新应用的授权页能正常确认
