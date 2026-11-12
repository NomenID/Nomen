# 模板参考

核心模板在 `templates/`，皮肤可以覆盖同名文件（`skins/<slug>/templates/`）。变量列只统计**核心代码**里的 `Response::view('<模板>', [...])` 调用点：插件调用同一个模板时会传它自己的变量，那些不算在内。列表里少一个键，页面上就会变成 undefined。

| 分组 | 模板 |
|---|---|
| 登录与注册页 | [login](auth.md) |
| 登录与注册页 | [register](auth.md) |
| 登录与注册页 | [register-verify](auth.md) |
| 登录与注册页 | [activate](auth.md) |
| 登录与注册页 | [2fa-verify](auth.md) |
| 登录与注册页 | [authorize](auth.md) |
| 登录与注册页 | [fast-pass](auth.md) |
| 账号恢复页 | [recovery](recovery.md) |
| 账号恢复页 | [recovery-family](recovery.md) |
| 账号恢复页 | [recovery-secure](recovery.md) |
| 账号恢复页 | [recovery-sent](recovery.md) |
| 页面外壳 | [app-shell](shell.md) |
| 页面外壳 | [admin-shell](shell.md) |
| 页面外壳 | [special](shell.md) |
| 首页 | [home](home.md) |
| 首页 | [home-page](home.md) |
| 首页 | [home-custom](home.md) |
| 公共片段 | [_email_list](partial.md) |
| 公共片段 | [_lang_picker](partial.md) |
| 公共片段 | [_mobile_menu](partial.md) |
| 公共片段 | [_site_sky](partial.md) |
