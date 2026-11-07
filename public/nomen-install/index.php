<?php
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

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, max-age=0');
?>
<!doctype html>
<html lang="zh-CN" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="dark light">
<title>安装 Nomen</title>
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
<?= $assets ?>
</head>
<body>
<div id="install-app">
    <noscript>
        <p class="noscript">安装向导需要 JavaScript。打开脚本后刷新这一页。</p>
    </noscript>
    <script type="application/json" id="install-props"><?= json_encode($payload, $jsonFlags) ?></script>
</div>
</body>
</html>
