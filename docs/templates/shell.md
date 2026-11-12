# 模板：页面外壳

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：代码里的 `Response::view()` 调用点和 `docs-internal/docmeta/templates.json`

### app-shell

前台 Vue 应用的外壳。只输出挂载点和初始数据，页面内容由前端路由接管。

- 文件：`templates/app-shell.php`
- 变量：`appProps`、`title`

### admin-shell

后台 Vue 应用的外壳。和前台一样是挂载点 + 初始数据，布局由皮肤的 `admin-app.php` 提供。

- 文件：`templates/admin-shell.php`
- 变量：`appProps`

### special

特殊页的外壳。版本页、许可页、插件页这些 `/special/<slug>` 都套这一层。

- 文件：`templates/special.php`
- 变量：`title`、`body`、`n`、`name`、`hideTitle`、`pageClass`、`extraCss`

