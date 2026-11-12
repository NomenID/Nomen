# 模板：账号恢复页

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：代码里的 `Response::view()` 调用点和 `docs-internal/docmeta/templates.json`

### recovery

账号恢复的申诉入口。填账号信息、联系方式、证明材料，提交后生成工单。

- 文件：`templates/recovery.php`
- 变量：`title`、`ctx`、`genders`、`error`、`old`、`attachMax`、`attachMaxLabel`

### recovery-family

家庭组相关的恢复页。走监护人这条路径时用它，和普通申诉分开。

- 文件：`templates/recovery-family.php`
- 变量：`username`、`maskedGuardian`、`error`

### recovery-secure

恢复凭证的设置与展示页。恢复码、新的二验绑定在这里完成，`TotpSection` 的二维码由 totp-qr 插件本地绘制。

- 文件：`templates/recovery-secure.php`
- 变量：`title`、`username`、`methods`、`assistMethods`、`methodCount`、`requiredCount`、`resetDone`、`canFinish`、`supportEmail`、`emails`、`maxEmails`、`error`、`success`

### recovery-sent

申诉提交成功的提示页。不透露工单是否存在，避免被拿来探测账号。

- 文件：`templates/recovery-sent.php`
- 变量：`title`、`contact`

