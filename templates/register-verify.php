<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

/** @var string $email */
/** @var string $maskedEmail */
/** @var bool $isMinor */
/** @var string|null $error */
/** @var string|null $success */
/** @var int $resendAfter */
$title = t('Verify email');
$extraCss = ['/css/activate.css?v=16054932', '/css/register-verify.css?v=16054932'];

$props = [
    'csrf' => \Nomen\Src\Csrf::getToken(),
    'email' => $email,
    'maskedEmail' => $maskedEmail,
    'isMinor' => (bool)$isMinor,
    'error' => $error ?: '',
    'success' => $success ?: '',
    'resendAfter' => (int)$resendAfter,
    'brandLogo' => (string)\Nomen\Src\Brand::get('logo'),
];

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<div id="register-verify-app" class="auth-container">
    <noscript><p class="form-hint"><?= t('Email verification requires JavaScript.') ?></p></noscript>
    <script type="application/json" id="register-verify-props"><?= json_encode($props, $jsonFlags) ?></script>
</div>
<?= \Nomen\Src\ViteAssets::entry('src/entries/register-verify.js') ?>
