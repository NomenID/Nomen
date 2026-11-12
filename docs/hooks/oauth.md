# 钩子：OAuth 与 OIDC

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：`docs-internal/docmeta/hooks.json` 和代码里的 `Hooks::filter` / `Hooks::emit` 调用点

## 本组钩子

| 钩子 | 类型 | 参数 | 时机 |
|---|---|---|---|
| `oauth.authorize.approved` | run | userId, clientId, scope, silent | 授权码发出之后；`silent` 表示这次没经过确认页（已授权过、`prompt=none` 或插件批了快速通道） |
| `oauth.authorize.quick` | filter | null, userId, client, scopes | 授权请求判定阶段；返回非空字符串 = 该请求免确认直接发码，回调带上 `spicallogin=<返回值>`（返回 `null` 走普通确认流程）。这是产品策略钩子，跳不跳确认由插件自己判断 |
| `oauth.token.issued` | run | userId, clientId, scopes, grantType | 发出 access token 之后（`authorization_code` / `refresh_token`） |
| `oauth.token.revoked` | run | tokenTypeHint | 撤销端点处理完 |
| `oidc.claims` | filter | claims, user, scopes | id_token 的 claims 组装完；`/api/userinfo` 不走这个钩子 |
| `userinfo.claims` | filter | claims, user, scopes | `/api/userinfo` 组装完 claims 之后 |

## 触发点

### oauth.authorize.approved

- 类型：run
- 参数：userId, clientId, scope, silent
- 时机：授权码发出之后；`silent` 表示这次没经过确认页（已授权过、`prompt=none` 或插件批了快速通道）
- 触发点：
  - `controllers/OAuthController.php`（run，2 处）

### oauth.authorize.quick

- 类型：filter
- 参数：null, userId, client, scopes
- 时机：授权请求判定阶段；返回非空字符串 = 该请求免确认直接发码，回调带上 `spicallogin=<返回值>`（返回 `null` 走普通确认流程）。这是产品策略钩子，跳不跳确认由插件自己判断
- 触发点：
  - `controllers/OAuthController.php`（filter）

### oauth.token.issued

- 类型：run
- 参数：userId, clientId, scopes, grantType
- 时机：发出 access token 之后（`authorization_code` / `refresh_token`）
- 触发点：
  - `controllers/OAuthController.php`（run，2 处）

### oauth.token.revoked

- 类型：run
- 参数：tokenTypeHint
- 时机：撤销端点处理完
- 触发点：
  - `controllers/OAuthController.php`（run）

### oidc.claims

- 类型：filter
- 参数：claims, user, scopes
- 时机：id_token 的 claims 组装完；`/api/userinfo` 不走这个钩子
- 触发点：
  - `src/Oidc.php`（filter）

### userinfo.claims

- 类型：filter
- 参数：claims, user, scopes
- 时机：`/api/userinfo` 组装完 claims 之后
- 触发点：
  - `controllers/ApiController.php`（filter）

