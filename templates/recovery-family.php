<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

/**
 * 已经在家庭里、且家长真的加入了的孩子走这一页，而不是自助申诉：
 * 家长在「家庭」页面就能直接给 TA 重置密码，比凑够 3 种登录方式快得多。
 *
 * @var string $username
 * @var string $maskedGuardian
 * @var string|null $error
 */
$title = t('Managed by a parent');
?>

<div class="auth-container">
    <div class="auth-card" style="max-width:520px;">
        <div class="auth-header">
            <div class="auth-logo">
                <img src="<?= htmlspecialchars((string)\Nomen\Src\Brand::get('logo')) ?>" alt="" width="48" height="48">
            </div>
            <h1><?= t('Managed by a parent') ?></h1>
            <p><?= htmlspecialchars(t('{:name} is already in the family', ['name' => $username])) ?></p>
        </div>

        <div class="nomen-alert nomen-alert--info">
            <?= t('A parent can reset your password for you — much faster than a recovery request.') ?>
        </div>

        <?php if ($error): ?>
        <div class="nomen-alert nomen-alert--error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <p style="font-size:14px;line-height:1.9;color:var(--color-text-muted);">
            <?= t('Ask a parent to sign in to {:sitename} and reset your password on the Family page.') ?>
            <?= t('The parent\'s email is') ?> <strong style="color:var(--color-text);"><?= htmlspecialchars($maskedGuardian) ?></strong><?= t('.') ?>
        </p>

        <p style="font-size:14px;line-height:1.9;color:var(--color-text-muted);">
            <?= t('If the parent can\'t do that either, send an email from that address to') ?>
            <a href="mailto:id&#64;caellab.com">id&#64;caellab.com</a> <?= t('Explain the situation and we\'ll handle it manually.') ?>
        </p>

        <div class="auth-footer" style="margin-top:24px;">
            <p><a href="/login"><?= t('Back to sign-in') ?></a></p>
        </div>
    </div>
</div>
