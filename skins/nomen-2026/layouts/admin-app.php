<?php
/**
 * 管理后台 SPA 布局：侧栏 / 顶栏 / 页面内容都由 Vue 渲染，
 * 这里只负责文档骨架、主题初始化和资源加载。
 *
 * @var string $content admin-shell 渲染出的挂载点
 */
$brand = \Nomen\Src\Brand::all();
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($brand['name']) ?> 管理后台</title>
    <link rel="stylesheet" href="/css/style.css?v=r8n4v2kq">
    <link rel="stylesheet" href="/css/ui.css?v=woimt5tg">
    <link rel="stylesheet" href="/css/admin.css?v=p7k2m9x4">
    <?php foreach (\Nomen\Src\Plugins::styles() as $href): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($href) ?>">
    <?php endforeach; ?>
    <link rel="icon" href="<?= htmlspecialchars($brand['logo']) ?>">
    <script>
        (function(){
            var t = localStorage.getItem('theme');
            if (t) {
                document.documentElement.setAttribute('data-theme', t);
            } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
    <?= \Nomen\Src\Hooks::filter('layout.head', '', 'admin') ?>
</head>
<body class="admin-body">
    <?= \Nomen\Src\Hooks::filter('layout.body.start', '', 'admin') ?>
    <?= $content ?>
    <?= \Nomen\Src\ViteAssets::entry('src/entries/admin.js') ?>
    <?php foreach (\Nomen\Src\Plugins::scripts() as $src): ?>
        <script src="<?= htmlspecialchars($src) ?>"></script>
    <?php endforeach; ?>
    <?= \Nomen\Src\Hooks::filter('layout.body.end', '', 'admin') ?>
</body>
</html>
