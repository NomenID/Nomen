<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

/** @var string|null $contact */
$title = t('Request submitted');

// 和申诉页第 3 步一样打码：用户那里可能选的就是「用账号邮箱」，
// 这页若把完整地址打出来，等于把上一步的打码白做了。
$maskedContact = \Nomen\Src\RecoveryHelper::maskEmail($contact);
?>

<div class="auth-container">
    <div class="auth-card" style="max-width:520px;">
        <div class="auth-header">
            <div class="auth-logo">
                <img src="<?= htmlspecialchars((string)\Nomen\Src\Brand::get('logo')) ?>" alt="" width="48" height="48">
            </div>
            <h1><?= t('Request submitted') ?></h1>
            <p><?= t('A site admin will review your request shortly.') ?></p>
        </div>

        <div class="nomen-alert nomen-alert--success">
            <?= t('We received your account recovery request.') ?>
        </div>

        <?php if ($maskedContact !== ''): ?>
        <p style="font-size:14px;line-height:1.8;color:var(--color-text-muted);">
            <?= t('The outcome and the recovery link will both be sent to') ?> <strong style="color:var(--color-text);"><?= htmlspecialchars($maskedContact) ?></strong><?= t(' — keep an eye out (and check the spam folder).') ?>
        </p>
        <?php endif; ?>

        <p style="font-size:14px;line-height:1.8;color:var(--color-text-muted);">
            <?= t('Most requests are reviewed within 2 business days; some take longer. Thank you for your patience.') ?>
        </p>

        <div class="auth-footer" style="margin-top:24px;">
            <p><a href="/login"><?= t('Back to sign-in') ?></a></p>
        </div>
    </div>
</div>
