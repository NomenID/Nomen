# 布局

布局是页面的外壳：`<html>`、head、页头、页脚、脚本。核心模板只负责正文，`Response::view()` 把正文
渲染成字符串塞进 `$content`，再 `require` 布局。

三个布局的职责不一样，写法也不一样：

| 布局 | 特点 |
|---|---|
| `default` | 完整的 PHP 输出：页头导航、页脚、移动端抽屉、脚本都在这里 |
| `app` | 只有挂载点和资源，界面外壳由前端组件渲染 |
| `admin-app` | 同上，后台用的 |

## default 布局

骨架版本（`layouts/default.php`）的骨架结构：

```php
<?php $brand = \Nomen\Src\Brand::all(); ?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\Nomen\Src\I18n::code()) ?>" dir="<?= \Nomen\Src\I18n::dir() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="canonical" href="...">
    <title>...</title>
    <link rel="stylesheet" href="/css/style.css?v=k2f9xw6t">
    <?php foreach (\Nomen\Src\Plugins::styles() as $href): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($href) ?>">
    <?php endforeach; ?>
    <?= \Nomen\Src\Hooks::filter('layout.head', '', 'default') ?>
</head>
<body>
    <?= \Nomen\Src\Hooks::filter('layout.body.start', '', 'default') ?>

    <header>... 导航 ...</header>

    <main><?= $content ?></main>

    <?php \Nomen\Src\Footer::render('default'); ?>

    <script src="<?= \Nomen\Src\I18n::catalogUrl() ?>"></script>
    <script src="/js/app.js?v=kbjnj594"></script>
    <?php foreach (\Nomen\Src\Plugins::scripts() as $src): ?>
        <script src="<?= htmlspecialchars($src) ?>"></script>
    <?php endforeach; ?>
    <?= \Nomen\Src\Hooks::filter('layout.body.end', '', 'default') ?>
    <?= \Nomen\Src\Csrf::field() ?>
</body>
</html>
```

### 不能漏的四行

```php
<?= \Nomen\Src\Hooks::filter('layout.head', '', 'default') ?>
<?= \Nomen\Src\Hooks::filter('layout.body.start', '', 'default') ?>
<?= $content ?>
<?= \Nomen\Src\Hooks::filter('layout.body.end', '', 'default') ?>
```

- `layout.head` 负责输出核心的 generator / 版本 / 皮肤 / 语言 meta，还有插件的 head 内容。漏了这些标签就没了。
- `body.start` / `body.end` 是插件加脚本、加浮层的位置。
- 第三个参数是布局名（`default` / `app` / `admin`），插件靠它区分「我这条只在 app 布局加」。

`Csrf::field()` 输出一个全局的 CSRF 隐藏字段，前端脚本靠它拿 token。**别省。**

### 有哪些变量

| 变量 | 说明 |
|---|---|
| `$content` | 页面正文，必须输出 |
| `$title` | 页面标题，可能没设 |
| `$extraCss` | 额外的样式地址数组，可能没有 |
| `$extraJs` | 额外的脚本地址，可能没有 |

`$title` 的拼法跟核心一致：

```php
<?php $pageTitle = $title ?? $brand['name']; ?>
<title><?= htmlspecialchars($pageTitle === $brand['name'] ? $pageTitle : "$pageTitle - " . $brand['name']) ?></title>
```

### 导航

导航项由 `Nav::items($userId)` 给出，核心和插件都会往里加，别在布局里写死：

```php
<?php
$navUserId = \Nomen\Src\Session::get('user_id');
$navUserId = $navUserId ? (int)$navUserId : null;
$navItems = \Nomen\Src\Nav::items($navUserId);
?>
<?php foreach ($navItems as $item): ?>
    <a href="<?= htmlspecialchars($item['href']) ?>"><?= htmlspecialchars($item['label']) ?></a>
<?php endforeach; ?>
```

每项有 `key` / `href` / `label` / `icon`。`key` 用来做特殊处理（比如 `notifications` 加未读数）。

### 页脚

```php
<?php \Nomen\Src\Footer::render('default'); ?>
```

它会先找 `config/footer.php`（站长自己改的），没有就用当前皮肤的 `partials/footer.php`。
所以**皮肤要带一个 `partials/footer.php`**，否则站长没配的时候页脚是空的。

片段里能用的变量：`$brand`、`$links`、`$layout`。

### 语言与 RTL

```php
<html lang="<?= htmlspecialchars(\Nomen\Src\I18n::code()) ?>" dir="<?= \Nomen\Src\I18n::dir() ?>">
```

RTL 语言要加载镜像样式：

```php
<?php if (\Nomen\Src\I18n::isRtl()): ?>
    <link rel="stylesheet" href="/css/rtl.css?v=31472968">
<?php endif; ?>
```

语言选择器只在允许切换时出现：

```php
<?php if (\Nomen\Src\I18n::switchingAllowed()): require \Nomen\Src\Skins::template('_lang_picker'); endif; ?>
```

### 移动端抽屉

`_mobile_menu` 是核心片段，皮肤可以覆盖。用法：

```php
<?= \Nomen\Src\Response::viewPartial('_mobile_menu', [
    'menuLinks' => $drawerLinks,     // ['href' => ..., 'label' => ...] 或 ['type' => 'divider']
    'menuUser'  => ['name' => ..., 'avatar' => ...],
]) ?>
```

不想要抽屉就整个不输出；核心没有别的地方依赖它。

### 主题切换

核心的 `app.js` 里有 `toggleTheme()`，布局里加一个按钮就能用：

```php
<button type="button" onclick="toggleTheme()" title="<?= t('Switch theme') ?>">…</button>
```

首屏别闪：在 CSS 之前用内联脚本设好 `data-theme`（骨架和官方皮肤都有这段，照抄即可）。

## app / admin-app 布局

这两个只输出文档骨架和资源，界面由前端渲染：

```php
<body>
    <?= \Nomen\Src\Hooks::filter('layout.body.start', '', 'app') ?>
    <?= $content ?>
    <script src="<?= \Nomen\Src\I18n::catalogUrl() ?>"></script>
    <?= \Nomen\Src\ViteAssets::entry('src/entries/app.js') ?>
    <?php foreach (\Nomen\Src\Plugins::scripts() as $src): ?>
        <script src="<?= htmlspecialchars($src) ?>"></script>
    <?php endforeach; ?>
    <?= \Nomen\Src\Hooks::filter('layout.body.end', '', 'app') ?>
</body>
```

- `ViteAssets::entry()` 负责从构建产物里找正确的哈希文件名，别手写 `/assets/dist/app-xxxx.js`。
- 后台那份换成 `src/entries/admin.js`，再把 `admin.css` 加进 head。
- 这两个页面**不要**输出 PHP 页头页脚，Vue 组件自己画；重复的导航会叠两层。
- 加 `<meta name="robots" content="noindex">`，登录后的页面不该被索引。

## 检查清单

- [ ] 三个布局都在
- [ ] 三个 `layout.*` 钩子都调了，第三个参数写对
- [ ] `$content` 输出了
- [ ] `Csrf::field()` 在 `</body>` 前
- [ ] `Footer::render()` 调了，`partials/footer.php` 存在
- [ ] `Plugins::styles()` / `scripts()` 都遍历输出了（插件资源靠这个加载）
- [ ] RTL 样式接上了
- [ ] 手机上导航不会挤成一团
