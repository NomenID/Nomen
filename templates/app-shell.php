<?php
/**
 * 应用区（真 SPA）布局：页面骨架只剩挂载点 + props JSON，
 * 头部 / 导航 / 抽屉 / 页脚全部由 Vue（AppLayout）渲染。
 *
 * @var array $appProps  viewer + csrf + page（首屏数据，避免多一次请求）
 */
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<div id="app-root" class="app-root-host">
    <noscript><p class="form-hint"><?= t('This page requires JavaScript.') ?></p></noscript>
    <script type="application/json" id="app-props"><?= json_encode($appProps, $jsonFlags) ?></script>
</div>
