<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

/**
 * @var array       $pending     待验证会话（含 nonce）
 * @var array       $factors     可用第二因素（已排除第一因素）
 * @var string|null $firstFactor 已通过的第一因素
 * @var string|null $error
 * @var array       $emailChoices
 */
$title = t('Two-step verification');

$firstFactorLabels = [
    'password'   => 'Password',
    'email_code' => 'Email code',
    'biometric'  => 'Biometric',
];
$firstLabel = t($firstFactorLabels[$firstFactor ?? ''] ?? 'First step verification');

$props = [
    'csrf' => \Nomen\Src\Csrf::getToken(),
    'nonce' => $pending['nonce'] ?? '',
    'factors' => $factors,
    'firstLabel' => $firstLabel,
    'error' => $error ?: '',
    'emailChoices' => array_map(static fn(array $choice): array => [
        'email' => (string)$choice['email'],
        'is_primary' => !empty($choice['is_primary']),
    ], $emailChoices ?? []),
    'brandLogo' => (string)\Nomen\Src\Brand::get('logo'),
    'urls' => [
        'sendCode' => '/2fa/verify/send-code',
        'verify' => '/2fa/verify',
    ],
];

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<div id="twofa-app" class="auth-container">
    <noscript><p class="form-hint"><?= t('Two-step verification requires JavaScript.') ?></p></noscript>
    <script type="application/json" id="twofa-props"><?= json_encode($props, $jsonFlags) ?></script>
</div>
<?= \Nomen\Src\ViteAssets::entry('src/entries/twofa.js') ?>
