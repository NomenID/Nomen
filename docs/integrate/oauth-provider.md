# 接入外部身份提供方

除了自带的 GitHub / Google / Microsoft / CaelLabID，还能接任何标准 OAuth2 提供方。配置在后台
「登录方式」里改，不写进代码。

## 在后台建

后台 →「登录方式」→ 新建提供方。字段：

| 字段 | 说明 |
|---|---|
| slug | 地址里用，`/auth/<slug>/redirect` |
| 名称 | 按钮上的文字，写英文原句（会过 `t()`） |
| 类型 | 目前只有 `oauth2` |
| 授权地址 | 浏览器跳过去的地方 |
| 令牌地址 | 换 token 的地方 |
| 用户信息地址 | 取资料的地方 |
| 邮箱列表地址 | 可选，取多个邮箱时用 |
| scopes | 空格分隔，比如 `read:user user:email` |
| 字段映射 | 对方的 JSON 哪个键是唯一 ID / 邮箱 / 昵称 / 头像 |
| token 认证方式 | `post`（默认）或 `basic` |

**回调地址是站内固定格式**，在管理页上会实时显示，带复制按钮：

```
https://你的站点/auth/<slug>/callback
```

把它填进对方应用的后台。**逐字节一致**，包括 http/https 和末尾斜杠。

## 预设

`config/identity-providers.php` 里预置了四家的地址和字段映射，后台新建时会带出来。改这份文件只影响
**新建时**的初始值，已经在后台建过的以数据库为准。

| 预设 | 字段映射要点 |
|---|---|
| GitHub | `subject_field: id`，邮箱要单独查 `emails_url` |
| Google | 有 `email_verified` |
| Microsoft | 用 `sub` 当 ID，不是 `id` |
| CaelLabID | `avatar` 是站内相对路径，要补前缀 |

## 字段映射的实际含义

对方返回的 JSON 长这样（举例）：

```json
{
  "id": 12345,
  "login": "someone",
  "name": "Someone",
  "email": "someone@example.com",
  "avatar_url": "https://..."
}
```

映射就填：

```json
{
  "subject_field": "id",
  "email_field": "email",
  "name_field": "name",
  "avatar_field": "avatar_url",
  "email_verified_field": ""
}
```

- `subject_field` 是**唯一 ID**，必须稳定不变。用邮箱当 ID 是错的——邮箱会改。
- `email_verified_field` 留空表示**信任对方返回的邮箱**。对方有验证标记（比如 `email_verified: true`）就填上，
  否则未验证邮箱也能自动合并到已有账号，等于给了冒名入口。
- 字段是**点号路径**时按嵌套取（`user.email` 这种），具体看管理页的提示。

## 已验证邮箱才合并

接入方的邮箱**已验证**且和站内某个账号的已验证邮箱相同时，会合并到那个账号；否则新建一个。

这条规则决定了「能不能用邮箱当作跨站身份」：

- 已验证 → 是同一个人，合并。
- 未验证 → 可能是别人登记的，单独建号，不合并。

## 解绑与冲突

用户在「设置」里能解绑第三方身份。解绑后账号还在，只是不能再从那个入口登录。

一个账号可以绑多个提供方。如果两个身份指向同一个邮箱、但其中一个没验证过，会各建各的——这是预期行为。

## 自定义提供方的坑

- **PKCE**：如果对方要求 PKCE（`S256`），后台建的时候要勾上；Nomen 会自己生成和校验 verifier。
- **token 认证方式**：GitHub 用 `post`，有些服务只认 `Authorization: Basic`。填错的表现是 `invalid_client`。
- **scope 分隔符**：绝大多数是空格。少数服务要逗号，那种得改成自定义实现。
- **用户信息地址要 token**：Nomen 会自动带上 `Authorization: Bearer <token>`。
- **返回的不是 JSON**：有些服务返回表单格式或需要特定 `Accept` 头，这种要单独写适配。

## 排查

登录链路上卡住时，先看浏览器停在哪个域名：

| 停在哪 | 说明 |
|---|---|
| 对方的域名 | 对方那边的事：账号没登录、应用没授权、回调地址没登记 |
| 本站 `/auth/<slug>/callback` 但 500 | 字段映射或 token 交换出错，看错误日志 |
| 本站 `/login` 又回来了，没登录成功 | 回调里 `state` 不匹配，或者创建用户的逻辑拒了（邮箱被策略拦下） |

日志里会有对方返回的状态码和错误体，**不包含令牌**。

## 检查清单

- [ ] 回调地址逐字节填进对方后台
- [ ] `subject_field` 用的是稳定不变的字段
- [ ] `email_verified_field` 填了（如果对方提供）
- [ ] scopes 申请了邮箱（不然拿不到 email）
- [ ] 这个提供方在后台是启用状态
- [ ] 用一个新号从对方登录一次，确认能建号也能登录
