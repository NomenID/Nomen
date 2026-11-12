# 模板：登录与注册页

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：代码里的 `Response::view()` 调用点和 `docs-internal/docmeta/templates.json`

### login

登录页。密码、邮箱验证码、临时密钥、生物识别、第三方登录五种方式在一页里切换，具体显示哪些由站长的「登录方式」开关决定。

- 文件：`templates/login.php`
- 变量：`error`、`success`、`redirect_to`、`requireCaptcha`、`failedMethods`、`methods`
- 注意：有 JS 时挂 `LoginApp` 岛屿接管整页；无 JS 时表单走服务端 POST，`noscript` 那段就是纯 HTML 版本。

### register

注册页。密码注册关闭时只剩第三方登录按钮，这个判断在 `RegisterApp` 的 `passwordRegistration` 属性里。

- 文件：`templates/register.php`
- 变量：`error`、`old`、`detectedCountry`、`passwordRegistration`

### register-verify

注册完成后的「去邮箱点激活链接」提示页。

- 文件：`templates/register-verify.php`
- 变量：`email`、`maskedEmail`、`isMinor`、`error`、`success`、`resendAfter`

### activate

激活链接的落地页。成功、失败、已激活三种结果都用这一页，靠变量区分。

- 文件：`templates/activate.php`
- 变量：`error`、`success`、`email`、`is_minor`、`uses_guardian_address`

### 2fa-verify

两步验证的输入页。登录判定通过后停在这里，输对 TOTP 或恢复码才真正建会话。

- 文件：`templates/2fa-verify.php`
- 变量：`pending`、`factors`、`firstFactor`、`emailChoices`、`error`

### authorize

OAuth 授权确认页。列出应用名称、要的 scope 和当前登录账号；`prompt=none` 或已授权时不会渲染这一页。

- 文件：`templates/authorize.php`
- 变量：`client`、`scope`、`state`、`user`、`scopeDescriptions`、`requestedScopeList`、`redirectUri`、`clientId`、`responseType`、`isFirstParty`

### fast-pass

临时密钥（快速通行）页。生成一次性凭据给不方便输密码的场景用。

- 文件：`templates/fast-pass.php`
- 变量：`error`、`success`、`redirect_to`

