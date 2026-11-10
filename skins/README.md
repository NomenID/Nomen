# 皮肤

一个皮肤就是一个目录，`skins/<slug>/`，里面必须有 `skin.json`：

```json
{
    "name": "Nomen 2026",
    "version": "1.0.0",
    "description": "默认皮肤",
    "license": "GPL-3.0-only",
    "author": ["Yunyun (Wang Yaoze)"]
}
```

`slug` 就是目录名，写进 `config/skins.php` 的 `default`（或环境变量 `DEFAULT_SKIN`）就是站点默认皮肤。用户可以在设置页换自己的，登录后写进 `users.skin`。

## 官方皮肤

官方皮肤是独立仓库，源码不进核心仓库，发布包自带这两个：

- [`nomen-2026`](https://github.com/NomenID/skin-nomen-2026)：默认皮肤，通用首页、星野背景、卡片式认证页
- [`nomen-ssr`](https://github.com/NomenID/skin-nomen-ssr)：纯服务端渲染的轻量外观，没有星野背景，首页文字优先

`enabled` 默认就是这两个。站长把不想用的从 `enabled` 里删掉就行；整个 `enabled` 键不写表示不限制。默认皮肤被禁用时会自动落到列表里第一个可用皮肤。

装皮肤：把皮肤目录放进 `skins/<slug>/`（clone 或解包都行），再在 `enabled` 里带上。打包机把官方皮肤 clone 到 `skins/` 即可，`php code-scripts/build-dist.php` 会检查缺不缺。

## 目录

| 路径 | 作用 |
|---|---|
| `layouts/` | 页面外壳（`default` / `app` / `admin-app`），提供哪个就覆盖哪个 |
| `templates/` | **页面模板覆盖**，和核心 `templates/` 同名就顶掉 |
| `partials/` | 皮肤自己的片段，布局里按相对路径 require |
| `i18n/` | 皮肤特有文案的译文（`<lang>.json`，键 = 英文原句），见下面「多语言」 |
| `css/`、`js/` | 皮肤自己的静态资源（放 `public/assets/skins/<slug>/` 也行） |

## 模板覆盖

`/` 现在由核心的 `Homepage` 渲染，三种模式：

1. 默认首页 → `templates/home.php`
2. 指定 Pages 页面 → `templates/home-page.php`
3. `config/home-*.php` 自定义模板 → `templates/home-custom.php` 外壳

`Response::view('home')` 的查找顺序：

1. 当前皮肤的 `templates/home.php`
2. 默认皮肤的 `templates/home.php`
3. 核心的 `templates/home.php`

所以想改首页版式，把同名文件放进皮肤目录即可；只改标题、按钮这些文字，去后台「首页」，不用碰皮肤。登录页、注册页同样按同名文件覆盖。

布局里有 `$content`（页面正文）这个变量；取公共片段用 `Skins::template('_site_sky')`，这样皮肤也能覆盖公共片段。

后台外壳是 Vue SPA，布局名是 `admin-app`；旧的 `admin.php` 布局已不再使用。后台左边那列菜单不是写死的，`Admin::menuItems()` 会把核心和插件注册的项一起排出来（插件页由 SPA 面板渲染）。

## 插件模板

插件页面用 `Response::view('@<slug>/<name>', ...)` 渲染。解析顺序：

1. 当前皮肤的 `templates/@<slug>/<name>.php`
2. 默认皮肤的 `templates/@<slug>/<name>.php`
3. 插件自己的 `templates/<name>.php`

插件如果自带布局，名字写成 `@<slug>/<layout>`，皮肤同样可以在 `layouts/@<slug>/<layout>.php` 覆盖。这样插件能自带页面，皮肤仍然能统一外观。

## 插件资源

插件把 CSS/JS 放自己的 `assets/`，通过 `/plugin-assets/<slug>/<path>` 发出来，URL 带文件修改时间做版本。插件页面可以直接用 `Plugins::style()` / `Plugins::script()` 登记，核心布局会输出。皮肤不需要复制这些资源。

## 多语言

皮肤模板里的 `t('English sentence.')` 和核心走同一条链（键 = 英文原句，运行时按访客语言出译文）：核心已有的键直接用；皮肤特有的键放自己的 `i18n/<lang>.json`（格式同 `locales/`，每个支持的语言一份）。`skin.json` 的 `description` 渲染时也过 `t()`，照英文句子的口径写。

生成与校验用 `php docs-internal/i18n/extract.php`（`--check` 验收），规范见 `docs-internal/i18n/CONVENTIONS.md`。

## 品牌变量

页面上能看到的东西都从 `config/brand.php` 取，别在布局里写死：

| 键 | 说明 |
|---|---|
| `name` | 站点名，标题、左上角、页脚都用它（环境变量 `BRAND_NAME`） |
| `logo` | 站点图标，左上角、favicon 都用它（`BRAND_LOGO`） |
| `base_url` | 绝对地址，canonical 和示例代码用它（`APP_URL`） |
| `copyright` | 页脚版权行，留空就不显示（`BRAND_COPYRIGHT`） |
| `footer_links` | 页脚链接数组，每项 `['label' => ..., 'url' => ...]`；Pages 插件启用时会自动补一个「文档」 |
| `powered_by` | 页脚署名，`false` 关掉；关掉后首页要自己补齐署名和版本页入口（见下） |

布局里用 `\Nomen\Src\Brand::all()` / `Brand::get('name')` 取值。

## 页脚

页脚统一走 `\Nomen\Src\Footer::render()`：先找 `config/footer.php`，没有就用当前皮肤的 `partials/footer.php`。默认皮肤只有一个很素的页脚：版权 + 可选链接 + `Powered by Nomen`。

站长想改页脚，把 `config/footer.example.php` 复制成 `config/footer.php` 改就行，变量 `$brand` / `$links` / `$layout` 都能用。插件用 `footer.links` 加链接、`footer.html` 改最终 HTML。

署名那条链到 `/special/version`，是 GPLv3 第 7 条 (b) 款要求的。拆开是两条独立的要求：

- 首页上看得到 `Powered by Nomen`；
- 首页上有一个能点开的链接，落到 `/special/version`。

默认把两条放在一个元素里。合成一处、分成两处都行，位置和样式随你，两条都得在首页、都得看得见。

### 去掉它

`config/brand.php` 里把 `'powered_by'` 改成 `false`，默认那条就不渲染了，程序层面不拦。关掉之后上面两条还在，得自己在首页补；合一或分开都行，缺一条就算不合规（见 `NOTICE`）。

## 许可

皮肤目录里带一份 `LICENSE`，放协议全文；`skin.json` 的 `license` 写 SPDX 标识（如 `GPL-3.0-only`），两边保持一致。`Special:Version` 的协议名会链到 `/special/version/skin/<slug>/license`。

## 注意

- 布局和模板都是直接 `require` 的 PHP，**皮肤代码等于站点代码权限**，只装信得过的
- 布局 `</head>` 前要输出 `\Nomen\Src\Hooks::filter('layout.head', '', '<layout>')`：核心的 generator / 版本 / 皮肤 / 语言 meta 和插件的 head 内容都从这里出来，不调就没有
- 应用的路由、POST 逻辑、CSRF 在控制器里，皮肤只决定长什么样
- 改完不用清缓存，下一页就生效；改了 CSS / JS 记得换 `?v=`，值用 8 位随机字符，别带日期
