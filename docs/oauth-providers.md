# 第三方登录

用户可以用 GitHub、Google、Microsoft、CaelLabID 或任意兼容 OAuth2 / OIDC 的提供方登录。
提供方在后台「登录方式」页管理，配置存在数据库 `identity_providers` 表；`config/identity-providers.php`
里的预设只用于「新建时一键填表」，改预设不会影响已经建好的提供方。

## 协议

- 授权码模式，`state` 防 CSRF，PKCE S256。
- 回调地址固定：`https://你的站点/auth/<标识>/callback`。后台表单里会实时显示并带复制按钮。
- 在提供方那边登记回调地址时**必须逐字节一致**，多一个斜杠就会失败。
- 请求方 IP 限流：`/auth/<slug>/redirect` 每分钟 120 次。

## 新建提供方

后台 →「登录方式」→ 新增。表单字段：

| 字段 | 说明 |
|---|---|
| 预设 | 选 GitHub / Google / Microsoft / CaelLabID，自动填好地址和字段映射，之后仍可改 |
| 标识 `slug` | 用在回调地址里，只能小写字母、数字、连字符；建好后不要改 |
| 显示名称 | 登录按钮上的文字 |
| 图标 `logo` | 可上传，默认用预设自带的 SVG；没有图标时按钮显示名称首字母 |
| 类型 | `oauth2` 或 `oidc` |
| Client ID / Client Secret | 提供方应用设置里的凭据。公共客户端（PKCE）可以不填 secret |
| 授权地址 / 令牌地址 / 用户信息地址 | 提供方的三个端点 |
| 邮箱列表地址 `emails_url` | 可选。用户信息里没有邮箱时，再去这个接口取（GitHub 用得到） |
| Scopes | 空格分隔 |
| 字段映射 | `subject_field` / `email_field` / `name_field` / `avatar_field` / `email_verified_field`，对应提供方返回 JSON 里的字段名 |
| 额外授权参数 | 形如 `prompt=consent&access_type=offline`，原样拼到授权地址后面 |
| 启用 / 排序 | 控制登录页按钮是否显示、显示顺序 |

`email_verified_field` 留空表示不检查；填了字段名就按该字段的布尔值判断邮箱是否可信。

## 账号绑定规则

- 提供方返回**已验证**的邮箱，且站点已有同邮箱账号 → 自动绑定到该账号。
- 邮箱未验证、或提供方没给邮箱 → 创建一个新账号（用户名冲突时自动加后缀）。
- 同一提供方的一个外部账号只能绑一个本地账号；用户可以在设置页解绑。

## 预设提供方

| 标识 | 类型 | 默认 scope | 备注 |
|---|---|---|---|
| `github` | oauth2 | `read:user user:email` | 用户信息里邮箱可能为空，会再查 `/user/emails` |
| `google` | oidc | `openid profile email` | 检查 `email_verified` |
| `microsoft` | oidc | `openid profile email` | 用 Graph 的 OIDC userinfo |
| `caellabid` | oidc | `openid profile` | 字段 `sub` / `display_name` / `avatar`；邮箱 scope 未默认开启 |

预设定义在 `config/identity-providers.php`，格式是“标识 → 一组字段”，新增预设照着写即可。

## 与登录方式总开关的关系

后台「登录方式」页的「第三方登录」是总开关。总开关关掉后，所有 `/auth/*` 请求都回 404；
单个提供方的「启用」只控制按钮显不显示和能不能发起授权。

`login.method_*` 的可用性校验里，第三方登录要算「可用」，必须至少有一个已启用的提供方。
否则关掉其他四种方式后，站点会变成没有任何登录方式。

## 相关钩子

| 钩子 | 说明 |
|---|---|
| `userinfo.claims` | 往 `/api/userinfo` 的返回里加自定义 claim |
| `user.registered` | 第三方登录首次建号后触发 |
