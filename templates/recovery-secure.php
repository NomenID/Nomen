<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

/** @var string      $username
 *  @var array       $methods         登录方式（计入数量）
 *  @var array       $assistMethods   快速辅助验证方式（不计入数量）
 *  @var int         $methodCount
 *  @var int         $requiredCount
 *  @var bool        $resetDone
 *  @var bool        $canFinish
 *  @var string      $supportEmail
 *  @var string|null $error
 *  @var string|null $success
 */
$title = t('Regain access to your account');

$mapMethod = static fn(array $m): array => [
    'label' => t($m['label']),
    'present' => (bool)($m['present'] ?? false),
    'on' => t($m['on'] ?? 'Set up'),
    'off' => t($m['off'] ?? 'Not set'),
];

$emailMethod = $methods['email'] ?? null;
$emailMissing = $emailMethod && empty($emailMethod['present']);

$props = [
    'csrf' => \Nomen\Src\Csrf::getToken(),
    'username' => (string)$username,
    'methods' => array_values(array_map($mapMethod, $methods ?? [])),
    'assistMethods' => array_values(array_map($mapMethod, $assistMethods ?? [])),
    'methodCount' => (int)$methodCount,
    'requiredCount' => (int)$requiredCount,
    'resetDone' => (bool)$resetDone,
    'canFinish' => (bool)$canFinish,
    'supportEmail' => (string)$supportEmail,
    'emails' => array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'email' => (string)$row['email'],
        'is_primary' => !empty($row['is_primary']),
        'verified' => !empty($row['verified']),
    ], $emails ?? []),
    'maxEmails' => (int)($maxEmails ?? \Nomen\Src\EmailSet::MAX),
    'error' => $error ?: '',
    'success' => $success ?: '',
    'assistLabel' => t(\Nomen\Src\RecoveryHelper::ASSIST_LABEL),
    'emailMissing' => (bool)$emailMissing,
];

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<div id="recovery-secure-app" class="container">
    <noscript><p class="form-hint"><?= t('This secure recovery page requires JavaScript.') ?></p></noscript>
    <script type="application/json" id="recovery-secure-props"><?= json_encode($props, $jsonFlags) ?></script>
</div>
<?= \Nomen\Src\ViteAssets::entry('src/entries/recovery-secure.js') ?>
