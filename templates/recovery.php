<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

/**
 * @var array       $ctx        待申诉的账号上下文
 * @var array       $genders    性别选项
 * @var string|null $error
 * @var array       $old
 * @var int         $attachMax       最多几张证明材料
 * @var string      $attachMaxLabel  上限的可读写法（1 MB）
 */
$title = t('Account recovery request');
$extraCss = ['/css/register.css?v=83568981', '/css/recovery.css?v=66989184'];

$old = $old ?? [];
$currentStep = (int)($old['current_step'] ?? 1);
if ($currentStep < 1 || $currentStep > 3) {
    $currentStep = 1;
}
$old['current_step'] = $currentStep;

$maskedEmail = \Nomen\Src\RecoveryHelper::maskEmail($ctx['email'] ?? '');

$props = [
    'csrf' => \Nomen\Src\Csrf::getToken(),
    'error' => $error ?: '',
    'old' => $old ?: new \stdClass(),
    'ctx' => [
        'username' => (string)($ctx['username'] ?? ''),
        'maskedEmail' => $maskedEmail,
    ],
    'genders' => array_map(static fn(string $label): string => t($label), $genders ?? []),
    'attachMax' => (int)($attachMax ?? 5),
    'attachMaxLabel' => (string)($attachMaxLabel ?? '1 MB'),
    'descriptionMin' => \Nomen\Controllers\RecoveryController::DESCRIPTION_MIN,
    'today' => date('Y-m-d'),
    'uploadUrl' => '/recovery/upload',
    'brandLogo' => (string)\Nomen\Src\Brand::get('logo'),
];

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<div id="recovery-app" class="auth-container">
    <noscript><p class="form-hint"><?= t('Account recovery requires JavaScript.') ?></p></noscript>
    <script type="application/json" id="recovery-props"><?= json_encode($props, $jsonFlags) ?></script>
</div>
<?= \Nomen\Src\ViteAssets::entry('src/entries/recovery.js') ?>
