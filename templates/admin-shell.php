<?php
/**
 * 管理后台 SPA 的挂载点。界面全部由 Vue（AdminRoot）渲染，
 * 这里只保留 props JSON，避免首屏多一次请求。
 *
 * @var array $appProps viewer + brand + csrf + menu
 */
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<div id="admin-root" class="admin-root-host">
    <noscript><p class="admin-noscript"><?= t('The admin area requires JavaScript.') ?></p></noscript>
    <script type="application/json" id="admin-props"><?= json_encode($appProps, $jsonFlags) ?></script>
</div>
