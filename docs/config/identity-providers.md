# config/identity-providers.php

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：`config/identity-providers.php`

第三方登录的预设。后台「登录方式」里可以逐字段覆盖，这张表只是新建提供方时的初始值；字段名对应各家 userinfo 返回的 JSON 键。

共 56 项。

## github

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `github.logo` | — | /assets/providers/github.svg | 按钮上的图标地址，留空回退成首字母 |
| `github.name` | — | GitHub | 显示名 |
| `github.type` | — | oauth2 | 协议类型，目前只有 `oauth2` |
| `github.authorize_url` | — | https://github.com/login/oauth/authorize | 授权页地址，浏览器跳过去的地方 |
| `github.token_url` | — | https://github.com/login/oauth/access_token | 换 token 的地址 |
| `github.userinfo_url` | — | https://api.github.com/user | 取用户资料的地址 |
| `github.emails_url` | — | https://api.github.com/user/emails | 取邮箱列表的地址，留空就不查 |
| `github.scopes` | — | read:user user:email | 请求的 scope，空格分隔 |
| `github.subject_field` | — | id | 用户资料里当唯一 ID 的字段 |
| `github.email_field` | — | email | 邮箱字段 |
| `github.name_field` | — | name | 昵称字段 |
| `github.avatar_field` | — | avatar_url | 头像字段 |
| `github.email_verified_field` | — | '' | 「邮箱是否已验证」的字段；留空表示信任对方返回的邮箱 |
| `github.token_auth` | — | post | 换 token 时凭据放哪：`post` 放表单，`basic` 放 Authorization 头 |

## 其他

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `google.logo` | — | /assets/providers/google.svg | 按钮上的图标地址，留空回退成首字母 |
| `google.name` | — | Google | 显示名 |
| `google.type` | — | oidc | 协议类型，目前只有 `oauth2` |
| `google.authorize_url` | — | https://accounts.google.com/o/oauth2/v2/auth | 授权页地址，浏览器跳过去的地方 |
| `google.token_url` | — | https://oauth2.googleapis.com/token | 换 token 的地址 |
| `google.userinfo_url` | — | https://openidconnect.googleapis.com/v1/userinfo | 取用户资料的地址 |
| `google.emails_url` | — | '' | 取邮箱列表的地址，留空就不查 |
| `google.scopes` | — | openid profile email | 请求的 scope，空格分隔 |
| `google.subject_field` | — | sub | 用户资料里当唯一 ID 的字段 |
| `google.email_field` | — | email | 邮箱字段 |
| `google.name_field` | — | name | 昵称字段 |
| `google.avatar_field` | — | picture | 头像字段 |
| `google.email_verified_field` | — | email_verified | 「邮箱是否已验证」的字段；留空表示信任对方返回的邮箱 |
| `google.token_auth` | — | post | 换 token 时凭据放哪：`post` 放表单，`basic` 放 Authorization 头 |

## 其他

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `microsoft.logo` | — | /assets/providers/microsoft.svg | 按钮上的图标地址，留空回退成首字母 |
| `microsoft.name` | — | Microsoft | 显示名 |
| `microsoft.type` | — | oidc | 协议类型，目前只有 `oauth2` |
| `microsoft.authorize_url` | — | https://login.microsoftonline.com/common/oauth2/v2.0/authorize | 授权页地址，浏览器跳过去的地方 |
| `microsoft.token_url` | — | https://login.microsoftonline.com/common/oauth2/v2.0/token | 换 token 的地址 |
| `microsoft.userinfo_url` | — | https://graph.microsoft.com/oidc/userinfo | 取用户资料的地址 |
| `microsoft.emails_url` | — | '' | 取邮箱列表的地址，留空就不查 |
| `microsoft.scopes` | — | openid profile email | 请求的 scope，空格分隔 |
| `microsoft.subject_field` | — | sub | 用户资料里当唯一 ID 的字段 |
| `microsoft.email_field` | — | email | 邮箱字段 |
| `microsoft.name_field` | — | name | 昵称字段 |
| `microsoft.avatar_field` | — | picture | 头像字段 |
| `microsoft.email_verified_field` | — | '' | 「邮箱是否已验证」的字段；留空表示信任对方返回的邮箱 |
| `microsoft.token_auth` | — | post | 换 token 时凭据放哪：`post` 放表单，`basic` 放 Authorization 头 |

## 其他

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `caellabid.logo` | — | /assets/providers/caellabid.svg | 按钮上的图标地址，留空回退成首字母 |
| `caellabid.name` | — | CaelLabID | 显示名 |
| `caellabid.type` | — | oidc | 协议类型，目前只有 `oauth2` |
| `caellabid.authorize_url` | — | https://id.caellab.com/oauth/authorize | 授权页地址，浏览器跳过去的地方 |
| `caellabid.token_url` | — | https://id.caellab.com/oauth/token | 换 token 的地址 |
| `caellabid.userinfo_url` | — | https://id.caellab.com/api/userinfo | 取用户资料的地址 |
| `caellabid.emails_url` | — | '' | 取邮箱列表的地址，留空就不查 |
| `caellabid.scopes` | — | openid profile | 请求的 scope，空格分隔 |
| `caellabid.subject_field` | — | sub | 用户资料里当唯一 ID 的字段 |
| `caellabid.email_field` | — | email | 邮箱字段 |
| `caellabid.name_field` | — | display_name | 昵称字段 |
| `caellabid.avatar_field` | — | avatar | 头像字段 |
| `caellabid.email_verified_field` | — | '' | 「邮箱是否已验证」的字段；留空表示信任对方返回的邮箱 |
| `caellabid.token_auth` | — | post | 换 token 时凭据放哪：`post` 放表单，`basic` 放 Authorization 头 |

