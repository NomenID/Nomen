# 皮肤开发

一个皮肤是一个目录 `skins/<slug>/`，里面必须有 `skin.json`。皮肤只改外观，不改行为。

## 清单

```json
{
    "name": "My Skin",
    "version": "1.0.0",
    "description": "Describe it in one English sentence",
    "license": "GPL-3.0-only",
    "author": ["Your Name"]
}
```

`description` 是词典键，写英文原句，译文放皮肤的 `i18n/<lang>.json`。

## 启用

`config/skins.php`：

```php
return [
    'default' => 'my-skin',
    'enabled' => ['my-skin', 'nomen-2026'],
];
```

- `default` 也可以用环境变量 `DEFAULT_SKIN` 覆盖。
- `enabled` 是用户能在设置页选的列表；整个键不写表示不限制。
- 默认皮肤被禁用时，自动落到列表里第一个可用皮肤。
- 用户的选择存在 `users.skin`，非当前皮肤被禁用时回落到默认皮肤。

## 目录

| 路径 | 作用 |
|---|---|
| `layouts/` | 页面外壳：`default` / `app` / `admin-app`，提供哪个覆盖哪个 |
| `templates/` | 页面模板覆盖，和核心 `templates/` 同名就顶掉 |
| `partials/` | 皮肤自己的片段 |
| `i18n/` | 皮肤特有文案的译文 |
| `css/`、`js/` | 皮肤资源，放 `public/assets/skins/<slug>/` 也行 |

## 模板解析顺序

`Response::view('home')`：

1. 当前皮肤的 `templates/home.php`
2. 默认皮肤的 `templates/home.php`
3. 核心的 `templates/home.php`

登录页、注册页、首页都按同名文件覆盖。后台外壳是 Vue SPA，布局名 `admin-app`。

布局里能拿到 `$content`（页面正文）。公共片段用 `Skins::template('_site_sky')` 取，
这样皮肤也能覆盖它。

## 插件模板

插件页面用 `Response::view('@<slug>/<name>', ...)`。解析顺序：

1. 当前皮肤的 `templates/@<slug>/<name>.php`
2. 默认皮肤的 `templates/@<slug>/<name>.php`
3. 插件自己的 `templates/<name>.php`

插件布局 `@<slug>/<layout>` 可以在 `layouts/@<slug>/<layout>.php` 覆盖。

## 资源

- 皮肤自己的 CSS/JS 放 `public/assets/skins/<slug>/`，或直接按 `skins/<slug>/css/` 组织后由布局引用。
- 引用时带 `?v=`，值每次部署换一个随机串。**先传资源、后传引用它的模板**，否则中间的访客请求
  会把旧字节缓存到新查询串底下。
- 插件资源走 `/plugin-assets/<slug>/<path>`，皮肤不用复制。

## 品牌变量

页面上能看到的东西都从 `config/brand.php` 取，别在布局里写死：

```php
$brand = \Nomen\Src\Brand::all();
// name / base_url / logo / copyright / support_email / support_name
// legal_links / footer_links / powered_by
```

认证页的法律链接用 `Brand::legalLinks()`，页脚链接用 `Brand::footerLinks()`（会自动补 Pages 的文档入口）。
页脚整体用 `Footer::html()`，顺序是 `config/footer.php` → 皮肤 `partials/footer.php`。

## 多语言

皮肤模板里的 `t('English sentence.')` 和核心同一条链：核心已有的键直接用，皮肤特有的键放
`skins/<slug>/i18n/<lang>.json`。格式同 `locales/`，键 = 英文原句。

`skin.json` 的 `description` 渲染时也过 `t()`。

## 后台外壳

后台是 Vue SPA，皮肤提供 `layouts/admin-app.php`。侧栏菜单不是写死的，
`Admin::menuItems()` 会把核心和插件注册的项一起排出来。

## 发布

官方皮肤是独立仓库，主仓 `.gitignore` 掉 `skins/*`。打包机先把皮肤 clone 到 `skins/`，
`php code-scripts/build-dist.php` 会检查清单里的皮肤在不在。

皮肤目录里带上 `LICENSE` 和 `README.md`。
