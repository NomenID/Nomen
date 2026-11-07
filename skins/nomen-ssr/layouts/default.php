<?php $brand = \Nomen\Src\Brand::all(); ?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(\Nomen\Src\I18n::code()) ?>" dir="<?= \Nomen\Src\I18n::dir() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="canonical" href="<?= htmlspecialchars($brand['base_url']) ?><?= rtrim(strtolower(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), '/') ?: '/' ?>">
    <title><?php $t = $title ?? $brand['name']; echo htmlspecialchars($t === $brand['name'] ? $t : "$t - " . $brand['name']); ?></title>
    <link rel="stylesheet" href="/css/style.css?v=8l9hchod">
    <link rel="stylesheet" href="/css/ui.css?v=woimt5tg">
    <?php if (\Nomen\Src\I18n::isRtl()): ?>
        <link rel="stylesheet" href="/css/rtl.css?v=31472968">
    <?php endif; ?>
    <?php foreach ((array)($extraCss ?? []) as $extraCssItem): ?>
        <?php if ((string)$extraCssItem !== ''): ?>
            <link rel="stylesheet" href="<?= htmlspecialchars((string)$extraCssItem) ?>">
        <?php endif; ?>
    <?php endforeach; ?>
    <?php foreach (\Nomen\Src\Plugins::styles() as $href): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($href) ?>">
    <?php endforeach; ?>
    <link rel="icon" href="<?= htmlspecialchars($brand['logo']) ?>">
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
    <?= \Nomen\Src\Hooks::filter('layout.head', '', 'default') ?>
</head>
<body class="skin-ssr">
    <?= \Nomen\Src\Hooks::filter('layout.body.start', '', 'default') ?>
    <header class="header">
        <div class="header-inner">
            <a href="/" class="logo">
                <img src="<?= htmlspecialchars($brand['logo']) ?>" alt="<?= htmlspecialchars($brand['name']) ?>" class="logo-icon" width="32" height="32">
                <span class="logo-text"><?= htmlspecialchars($brand['name']) ?></span>
            </a>
            <button class="mobile-menu-btn" id="mobile-menu-btn" aria-label="<?= t('Menu') ?>" aria-expanded="false" aria-controls="mobile-menu-drawer">
                <span></span><span></span><span></span>
            </button>
            <nav class="nav" id="mainNav">
                <?php
                $navUserId = \Nomen\Src\Session::get('user_id');
                $navUserId = $navUserId ? (int)$navUserId : null;
                $navItems = \Nomen\Src\Nav::items($navUserId);
                $navAvatar = $navUserId ? \Nomen\Controllers\DashboardController::avatarUrlForUser($navUserId) : null;
                $notifUnread = $navUserId ? \Nomen\Src\NotificationHelper::unreadCount($navUserId) : 0;
                $notifLatest = $notifUnread > 0 ? \Nomen\Src\NotificationHelper::latestUnread($navUserId) : null;
                ?>
                <?php if ($navUserId): ?>
                    <?php foreach ($navItems as $item): ?>
                        <?php if ($item['key'] === 'notifications'): ?>
                    <a href="<?= htmlspecialchars($item['href']) ?>" class="nav-notif" title="<?= htmlspecialchars($item['label']) ?>" aria-label="<?= htmlspecialchars($item['label']) ?>" data-notif-unread="<?= $notifUnread ?>"
                       <?php if ($notifLatest): ?>
                       data-notif-latest-id="<?= (int)$notifLatest['id'] ?>"
                       data-notif-latest-title="<?= htmlspecialchars($notifLatest['title']) ?>"
                       data-notif-latest-link="<?= htmlspecialchars($notifLatest['link'] ?: ('/notifications/' . (int)$notifLatest['id'])) ?>"
                       <?php endif; ?>><span class="icon icon-bell"></span><?php if ($notifUnread > 0): ?><span class="nav-notif__count"><?= $notifUnread > 99 ? '99+' : $notifUnread ?></span><?php endif; ?></a>
                        <?php else: ?>
                    <a href="<?= htmlspecialchars($item['href']) ?>" class="nav-link"><?= htmlspecialchars($item['label']) ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <span class="nav-user"><?php if ($navAvatar): ?><img src="<?= htmlspecialchars($navAvatar) ?>" alt="" class="nav-avatar"><?php endif; ?><?= htmlspecialchars(\Nomen\Src\Session::get('username', '')) ?></span>
                    <button class="nav-link nav-link--danger" onclick="handleLogout()"><?= t('Sign out') ?></button>
                <?php else: ?>
                    <a href="/login" class="nav-link"><?= t('Sign in') ?></a>
                    <a href="/register" class="btn btn-primary btn-sm"><?= t('Sign up') ?></a>
                <?php endif; ?>
                <?php if (\Nomen\Src\I18n::switchingAllowed()): require \Nomen\Src\Skins::template('_lang_picker'); endif; ?>
                <button class="theme-toggle" onclick="toggleTheme()" title="<?= t('Switch theme') ?>" aria-label="<?= t('Toggle light / dark theme') ?>">
                    <span class="icon icon-sun"></span>
                    <span class="icon icon-moon"></span>
                </button>
            </nav>
        </div>
    </header>

    <main class="main main--landing">
        <?= $content ?>
    </main>

    <?php \Nomen\Src\Footer::render('default'); ?>

    <?php
    $drawerUserId = \Nomen\Src\Session::get('user_id');
    $drawerLinks = [];
    if ($drawerUserId) {
        foreach ($navItems as $item) {
            $drawerLinks[] = [
                'href'  => $item['href'],
                'label' => $item['label'],
                'icon'  => $item['icon'] !== '' ? $item['icon'] : 'file',
                'badge' => $item['key'] === 'notifications' ? ($notifUnread ?? 0) : null,
            ];
        }
        $drawerLinks[] = ['type' => 'divider'];
        $drawerLinks[] = ['label' => t('Sign out'), 'icon' => 'logout', 'danger' => true, 'onclick' => 'handleLogout()'];
    }
    echo \Nomen\Src\Response::viewPartial('_mobile_menu', [
        'menuLinks' => $drawerLinks,
        'menuUser' => $drawerUserId ? ['name' => (string)\Nomen\Src\Session::get('username', ''), 'avatar' => $navAvatar] : null,
    ]);
    ?>

    <script src="<?= \Nomen\Src\I18n::catalogUrl() ?>"></script>
    <script src="/js/app.js?v=kbjnj594"></script>
    <?php foreach (\Nomen\Src\Plugins::scripts() as $src): ?>
        <script src="<?= htmlspecialchars($src) ?>"></script>
    <?php endforeach; ?>
    <?php if (!empty($extraJs)): ?>
        <script src="<?= htmlspecialchars($extraJs) ?>"></script>
    <?php endif; ?>
    <?= \Nomen\Src\Hooks::filter('layout.body.end', '', 'default') ?>
    <?= \Nomen\Src\Csrf::field() ?>
</body>
</html>
