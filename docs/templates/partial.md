# 模板：公共片段

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：代码里的 `Response::view()` 调用点和 `docs-internal/docmeta/templates.json`

### _email_list

账号邮箱列表片段。后台用户详情里渲染，显示每个邮箱的验证状态和主邮箱标记。

- 文件：`templates/_email_list.php`
- 变量：`emails`、`maxEmails`、`recoveryMode`
- 注意：用 `Response::viewPartial` 渲染成字符串塞进 JSON，不单独出页面。

### _lang_picker

语言选择器。无 JS 时是一组指向 `/special/lang` 的普通链接，有 JS 才由脚本升级成下拉。

- 文件：`templates/_lang_picker.php`
- 变量：无（由 `include` 直接引入，或渲染方用 `$props` 之类整体传入）
- 注意：由皮肤布局 `require` 引入，变量来自布局作用域。

### _mobile_menu

移动端抽屉菜单。语言入口只有 `/special/lang` 一种走法，首页不带 `rt` 参数。

- 文件：`templates/_mobile_menu.php`
- 变量：无（由 `include` 直接引入，或渲染方用 `$props` 之类整体传入）
- 注意：由皮肤布局用 `viewPartial` 渲染。

### _site_sky

星空背景。默认皮肤用它铺在页面底层，无星空的皮肤不引入这个文件。

- 文件：`templates/_site_sky.php`
- 变量：无（由 `include` 直接引入，或渲染方用 `$props` 之类整体传入）
- 注意：由皮肤布局 `require` 引入，本身不接收变量。

