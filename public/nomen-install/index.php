<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

// 安装向导入口：只输出 Vue 挂载壳和初始数据；步骤都在 frontend/src/pages/install/ 里。

require __DIR__ . '/lib.php';

if (nomen_install_is_installed()) {
    nomen_install_render_installed();
    exit;
}

try {
    $assets = \Nomen\Src\ViteAssets::entry('src/entries/install.js');
} catch (Throwable $e) {
    nomen_install_render_missing_assets();
    exit;
}

$payload = nomen_install_payload();
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

// 当前语言的词典直接内嵌进页面：装向导期间 /i18n/ 路由不可用，也不值得多一次往返。
// en 就是母键本身，不用带词典。
$installLang = nomen_install_lang();
$installDict = [];
if ($installLang !== 'en') {
    $dictData = json_decode((string)@file_get_contents(nomen_install_root('locales/' . $installLang . '.json')), true);
    if (is_array($dictData)) {
        $installDict = $dictData;
    }
}

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, max-age=0');
?>
<!doctype html>
<html lang="<?= nomen_install_h(nomen_install_locale_code()) ?>" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="dark light">
<title><?= nomen_install_h(nomen_install_t('Install Nomen')) ?></title>
<link rel="icon" href="/assets/brand/nomen-icon.svg">
<script>
(function () {
  var theme = 'dark';
  try {
    var saved = localStorage.getItem('nomen-install-theme');
    if (saved === 'light' || saved === 'dark') {
      theme = saved;
    } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
      theme = 'light';
    }
  } catch (e) {}
  document.documentElement.dataset.theme = theme;
})();
</script>
<?php if ($installDict !== []): ?>
<script>window.__NOMEN_INSTALL_I18N__<?= json_encode($installDict, $jsonFlags) ?>;</script>
<?php endif; ?>
<?= $assets ?>
</head>
<body>
<div id="install-app">
    <noscript>
        <p class="noscript"><?= nomen_install_h(nomen_install_t('The install wizard needs JavaScript. Turn it on and refresh this page.')) ?></p>
    </noscript>
    <script type="application/json" id="install-props"><?= json_encode($payload, $jsonFlags) ?></script>
</div>
</body>
</html>
