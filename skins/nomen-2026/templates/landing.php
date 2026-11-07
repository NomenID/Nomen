<?php
$brand = \Nomen\Src\Brand::all();
$loggedIn = \Nomen\Src\Session::get('user_id');
$title = \Nomen\Src\Brand::get('name', 'Nomen');
$extraCss = '/css/landing-nomen.css?v=5iclylew';

$heroProps = [
    'brand' => (string)($brand['name'] ?? 'Nomen'),
    'loggedIn' => (bool)$loggedIn,
    // 文档维护在官方站，本地页面只有欢迎和沙盒
    'docsBase' => rtrim(\Nomen\Src\Special\Version::PROJECT_URL, '/') . '/w',
    'urls' => [
        'register' => '/register',
        'login' => '/login',
        'dashboard' => '/dashboard',
        'settings' => '/settings',
    ],
];
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<div id="landing-app">
    <noscript>
        <section class="np-hero">
            <div class="np-hero__inner">
                <h1 class="np-hero__title"><?= htmlspecialchars((string)($brand['name'] ?? 'Nomen')) ?></h1>
                <p class="np-hero__line"><?= t('A galaxy of free accounts') ?></p>
                <div class="np-hero__actions">
                    <?php if ($loggedIn): ?>
                        <a href="/dashboard" class="btn btn-primary btn-lg"><?= t('Open console') ?></a>
                        <a href="/settings" class="btn btn-ghost btn-lg"><?= t('Account settings') ?></a>
                    <?php else: ?>
                        <a href="/register" class="btn btn-primary btn-lg"><?= t('Create account') ?></a>
                        <a href="/login" class="btn btn-ghost btn-lg"><?= t('Sign in') ?></a>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </noscript>
    <script type="application/json" id="landing-props"><?= json_encode($heroProps, $jsonFlags) ?></script>
</div>
<?= \Nomen\Src\ViteAssets::entry('src/entries/landing-nomen.js') ?>
