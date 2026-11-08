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
/** @var string $redirect_to */
$title = t('Temp key sign-in');
?>

<div class="auth-container">
    <div class="auth-card" style="max-width:480px;">
        <div class="auth-header">
            <div class="auth-logo">
                <img src="<?= htmlspecialchars((string)\Nomen\Src\Brand::get('logo')) ?>" alt="" width="48" height="48">
            </div>
            <h1><?= t('Temp key sign-in') ?></h1>
            <p><?= t('Use a temp key to sign in quickly and skip device verification') ?></p>
        </div>

        <?php if ($error): ?>
            <div class="nomen-alert nomen-alert--error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="nomen-alert nomen-alert--success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="POST" action="/login/temp-key" class="auth-form">
            <?= \Nomen\Src\Csrf::field() ?>
            <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($redirect_to) ?>">

            <div class="form-group">
                <label for="tempkey-username"><?= t('Username or email') ?></label>
                <input type="text" id="tempkey-username" name="username" required autofocus
                       placeholder="<?= t('Enter a username or email address') ?>">
            </div>

            <div class="form-group">
                <label for="tempkey-key"><?= t('Temp key') ?></label>
                <input type="password" id="tempkey-key" name="temp_key" required
                       placeholder="<?= t('Enter the temp key') ?>">
            </div>

            <p style="font-size:12px;color:var(--color-text-subtle);margin-bottom:16px;line-height:1.6;">
                <?= t('Temp keys only skip new-device verification (e.g. on a school computer) and never affect your password.') ?><br>
                <?= t('Create and manage temp keys in account settings.') ?>
            </p>

            <div class="form-group">
                <label><?= t('Code') ?></label>
                <div class="input-with-button">
                    <input type="text" name="captcha" required placeholder="<?= t('Enter the code') ?>" maxlength="6" autocomplete="off" style="flex:1;">
                    <img src="/captcha" alt="<?= t('Code') ?>" onclick="this.src='/captcha?t='+Date.now()" style="height:38px;max-width:120px;cursor:pointer;border-radius:6px;" title="<?= t('Click to refresh') ?>">
                </div>
            </div>

            <?php $legalLinks = \Nomen\Src\Brand::legalLinks(); ?>
            <?php if ($legalLinks !== []): ?>
                <p style="font-size:12px;color:var(--color-text-subtle);margin-bottom:12px;">
                    <?= t('By signing in, you agree to the') ?>
                    <?php foreach ($legalLinks as $i => $link): ?>
                        <?php if ($i > 0): ?> · <?php endif; ?><a href="<?= htmlspecialchars($link['url']) ?>" target="_blank"><?= t($link['label']) ?></a>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary btn-block"><?= t('Sign in') ?></button>
        </form>

        <div class="auth-footer">
            <p><a href="/login"><?= t('Back to password sign-in') ?></a></p>
        </div>
    </div>
</div>
