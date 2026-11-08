<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

/** @var string|null $error */
/** @var string|null $success */
/** @var string $email */
/** @var bool $is_minor 账号不到本地区的独立使用年龄 */
/** @var bool $uses_guardian_address 收信地址是家长的（账号没有自己的邮箱） */
$title = t('Email verification');
$extraCss = '/css/activate.css?v=16054932';

$props = [
    'csrf' => \Nomen\Src\Csrf::getToken(),
    'error' => $error ?: '',
    'success' => $success ?: '',
    'email' => $email,
    'usesGuardianAddress' => (bool)($uses_guardian_address ?? false),
    'brandLogo' => (string)\Nomen\Src\Brand::get('logo'),
];

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<div id="activate-app" class="auth-container">
    <noscript><p class="form-hint"><?= t('Email verification requires JavaScript.') ?></p></noscript>
    <script type="application/json" id="activate-props"><?= json_encode($props, $jsonFlags) ?></script>
</div>
<?= \Nomen\Src\ViteAssets::entry('src/entries/activate.js') ?>
