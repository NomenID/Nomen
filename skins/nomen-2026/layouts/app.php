<?php
/**
 * 应用区（真 SPA）布局。区别于 default.php：没有 PHP 头部/页脚/抽屉，
 * 只输出星野背景 + SPA 挂载点，界面外壳由前端 AppLayout 组件渲染。
 *
 * @var string $content  app-shell 渲染出的挂载点
 */
$brand = \Nomen\Src\Brand::all();
?><!DOCTYPE html>
<html lang="<?= htmlspecialchars(\Nomen\Src\I18n::code()) ?>" dir="<?= \Nomen\Src\I18n::dir() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title><?php $t = $title ?? $brand['name']; echo htmlspecialchars($t === $brand['name'] ? $t : "$t - " . $brand['name']); ?></title>
    <link rel="stylesheet" href="/css/style.css?v=4btk9ktk">
    <link rel="stylesheet" href="/css/ui.css?v=woimt5tg">
    <?php if (\Nomen\Src\I18n::isRtl()): ?>
        <link rel="stylesheet" href="/css/rtl.css?v=31472968">
    <?php endif; ?>
    <?php foreach (\Nomen\Src\Plugins::styles() as $href): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($href) ?>">
    <?php endforeach; ?>
    <link rel="icon" href="<?= htmlspecialchars($brand['logo']) ?>" type="image/svg+xml">
    <script>
        // 阻止主题切换闪烁：在 CSS 加载前就设置好主题
        (function(){
            var t = localStorage.getItem('theme');
            if (t) {
                document.documentElement.setAttribute('data-theme', t);
            } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
</head>
<body>
    <?php require \Nomen\Src\Skins::template('_site_sky'); ?>
    <?= $content ?>
    <script src="<?= \Nomen\Src\I18n::catalogUrl() ?>"></script>
    <?= \Nomen\Src\ViteAssets::entry('src/entries/app.js') ?>
    <?php foreach (\Nomen\Src\Plugins::scripts() as $src): ?>
        <script src="<?= htmlspecialchars($src) ?>"></script>
    <?php endforeach; ?>
</body>
</html>
