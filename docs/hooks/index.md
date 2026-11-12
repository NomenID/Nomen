# 钩子参考

钩子的说明写在 `docs-internal/docmeta/hooks.json`，本页由它和代码里的触发点一起生成。`--check` 会双向核对：代码里查不到的钩子、以及代码里有但没登记钩子的，都会报错。

| 钩子 | 类型 | 触发点 |
|---|---|---|
| [auth.notice](auth.md) | filter | 2 处 |
| [user.login.succeeded](auth.md) | run | 1 处 |
| [user.logout](auth.md) | run | 1 处 |
| [user.registered](auth.md) | run | 3 处 |
| [oauth.authorize.approved](oauth.md) | run | 2 处 |
| [oauth.authorize.quick](oauth.md) | filter | 1 处 |
| [oauth.token.issued](oauth.md) | run | 2 处 |
| [oauth.token.revoked](oauth.md) | run | 1 处 |
| [oidc.claims](oauth.md) | filter | 1 处 |
| [userinfo.claims](oauth.md) | filter | 1 处 |
| [footer.html](render.md) | filter | 1 处 |
| [footer.links](render.md) | filter | 1 处 |
| [layout.body.end](render.md) | filter | 扩展侧 |
| [layout.body.start](render.md) | filter | 扩展侧 |
| [layout.head](render.md) | filter | 扩展侧 |
| [meta.tags](render.md) | filter | 1 处 |
| [nav.items](render.md) | filter | 1 处 |
| [viewer.props](render.md) | filter | 1 处 |
| [homepage.links](home.md) | filter | 1 处 |
| [homepage.sections](home.md) | filter | 1 处 |
| [admin.apps.detail](admin.md) | filter | 1 处 |
| [admin.apps.list](admin.md) | filter | 1 处 |
| [admin.dashboard.cards](admin.md) | filter | 1 处 |
| [admin.dashboard.site](admin.md) | filter | 1 处 |
| [admin.logs.rows](admin.md) | filter | 1 处 |
| [admin.menu.items](admin.md) | filter | 1 处 |
| [admin.mounts.list](admin.md) | filter | 1 处 |
| [admin.plugin.page.body](admin.md) | filter | 1 处 |
| [admin.recovery.detail](admin.md) | filter | 1 处 |
| [admin.settings.data](admin.md) | filter | 1 处 |
| [admin.settings.saved](admin.md) | run | 1 处 |
| [admin.shell.props](admin.md) | filter | 1 处 |
| [admin.user.detail](admin.md) | filter | 1 处 |
| [admin.user.role_changed](admin.md) | run | 1 处 |
| [admin.user.status_changed](admin.md) | run | 1 处 |
| [admin.users.rows](admin.md) | filter | 1 处 |
| [api.siteinfo](content.md) | filter | 1 处 |
| [pages.admin.editor.extras](content.md) | filter | 扩展侧 |
| [pages.content.before_save](content.md) | filter | 扩展侧 |
| [pages.content.deleted](content.md) | run | 扩展侧 |
| [pages.content.saved](content.md) | run | 扩展侧 |
| [email.sending](notify.md) | filter | 1 处 |
| [notification.created](notify.md) | run | 1 处 |
