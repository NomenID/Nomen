# 模板：首页

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：代码里的 `Response::view()` 调用点和 `docs-internal/docmeta/templates.json`

### home

默认首页。纯 PHP 输出，标题、副标题、按钮从站点设置读，正文区块由 `homepage.sections` 钩子决定。

- 文件：`templates/home.php`
- 变量：`home`、`brand`、`urls`、`links`

### home-page

首页指向某个 Pages 页面时的包装。把页面正文套进站点外壳，不另做排版。

- 文件：`templates/home-page.php`
- 变量：`page`、`html`

### home-custom

自定义首页模板的包装。`config/home-*.php` 返回的 HTML 直接嵌入，官方站用它挂 `home-nomenid.php`。

- 文件：`templates/home-custom.php`
- 变量：`body`

