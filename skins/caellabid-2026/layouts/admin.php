<?php $brand = \Nomen\Src\Brand::all(); ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php $t = $pageTitle ?? t('管理后台'); echo htmlspecialchars($t . ' - ' . $brand['name'] . ' ' . t('管理后台')); ?></title>
    <link rel="stylesheet" href="/css/style.css?v=4btk9ktk">
    <link rel="stylesheet" href="/css/ui.css?v=woimt5tg">
    <link rel="stylesheet" href="/css/admin.css?v=8f3k2m7q">
    <?php foreach (\Nomen\Src\Admin::styles() as $href): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($href) ?>">
    <?php endforeach; ?>
    <link rel="icon" href="<?= htmlspecialchars($brand['logo']) ?>" type="image/svg+xml">
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
</head>
<body class="admin-body">
    <header class="admin-topbar">
        <a href="/admin" class="admin-topbar__brand">
            <img src="<?= htmlspecialchars($brand['logo']) ?>" alt="<?= htmlspecialchars($brand['name']) ?>" width="28" height="28">
            <span><?= htmlspecialchars($brand['name']) ?> <?= t('管理后台') ?></span>
        </a>
        <div class="admin-topbar__right">
            <span class="admin-topbar__user"><?= htmlspecialchars(\Nomen\Src\Session::get('username', '')) ?></span>
            <a href="/dashboard" class="admin-topbar__link">返回账户</a>
            <button type="button" class="admin-topbar__link admin-topbar__link--danger" onclick="handleLogout()">退出</button>
            <button class="theme-toggle" onclick="toggleTheme()" title="切换主题" aria-label="切换主题">
                <span class="icon icon-sun"></span>
                <span class="icon icon-moon"></span>
            </button>
        </div>
    </header>

    <div class="admin-shell">
        <aside class="admin-sidebar">
            <?php foreach (\Nomen\Src\Admin::menuItems() as $key => $item): ?>
            <a href="<?= htmlspecialchars($item['href']) ?>" class="admin-nav<?= ($activeMenu ?? '') === $key ? ' admin-nav--active' : '' ?>"><?= htmlspecialchars($item['label']) ?></a>
            <?php endforeach; ?>
        </aside>

        <main class="admin-main">
            <?= $content ?>
        </main>
    </div>

    <script src="/js/app.js?v=h7t2qk9m"></script>
    <script src="/js/admin.js?v=91370385"></script>
    <?php foreach (\Nomen\Src\Admin::scripts() as $src): ?>
        <script src="<?= htmlspecialchars($src) ?>"></script>
    <?php endforeach; ?>
    <?= \Nomen\Src\Csrf::field() ?>
</body>
</html>
