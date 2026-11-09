<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

/** @var string|null $error */
/** @var string|null $success */
/** @var string $redirect_to */
/** @var bool $requireCaptcha 仅当该 IP 登录尝试过多时才要求图片验证码 */
/** @var array<string,array{at:int,reason:string}> $failedMethods 本次会话里试过且没成功的方式 */
/** @var array<string,bool> $methods 登录方式总开关（后台「登录方式」页） */
$title = t('Sign in');
$requireCaptcha = $requireCaptcha ?? false;
$failedMethods = $failedMethods ?? [];
$methods = array_merge([
    'password' => true, 'email_code' => true, 'biometric' => true, 'temp_key' => true, 'oauth' => true,
], (array)($methods ?? []));

$props = [
    'csrf' => \Nomen\Src\Csrf::getToken(),
    'error' => $error ?: '',
    'success' => $success ?: '',
    'redirectTo' => $redirect_to,
    'requireCaptcha' => $requireCaptcha,
    'failedMethods' => array_keys($failedMethods),
    'triedLabels' => array_map('t', \Nomen\Controllers\AuthController::LOGIN_METHOD_LABELS),
    'initialUsername' => $_GET['username'] ?? '',
    'initialEmail' => $_GET['email'] ?? '',
    'legalLinks' => \Nomen\Src\Brand::legalLinks(),
    'supportEmail' => (string)\Nomen\Src\Brand::get('support_email'),
    'supportName' => (string)\Nomen\Src\Brand::get('support_name'),
    'mailEnabled' => \Nomen\Src\Email::isConfigured(),
    'brandLogo' => (string)\Nomen\Src\Brand::get('logo'),
    'providers' => \Nomen\Src\IdentityProviders::publicList(),
    'methods' => $methods,
    'urls' => [
        'sendCode' => '/login/send-code',
        'biometricOptions' => '/biometric/auth/options',
        'biometricVerify' => '/biometric/auth/verify',
    ],
];

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<div id="login-app" class="auth-container">
    <noscript>
        <div class="auth-card">
            <div class="auth-header">
                <div class="auth-logo">
                    <img src="<?= htmlspecialchars((string)\Nomen\Src\Brand::get('logo')) ?>" alt="" width="48" height="48">
                </div>
                <h1><?= t('Sign in to {:sitename}') ?></h1>
                <p><?= t('Continue with your {:sitename} account') ?></p>
            </div>
            <?php if ($error): ?>
                <div class="nomen-alert nomen-alert--error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="nomen-alert nomen-alert--success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if (!$methods['password']): ?>
                <p class="form-hint"><?= t('Password sign-in is turned off on this site.') ?></p>
            <?php else: ?>
            <form method="POST" action="/login" class="auth-form">
                <?= \Nomen\Src\Csrf::field() ?>
                <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($redirect_to) ?>">
                <div class="form-group">
                    <label for="noscript-username"><?= t('Username or email') ?></label>
                    <input type="text" id="noscript-username" name="username" required
                           placeholder="<?= t('Enter a username or email address') ?>"
                           value="<?= htmlspecialchars($_GET['username'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="noscript-password"><?= t('Password') ?></label>
                    <input type="password" id="noscript-password" name="password" required
                           autocomplete="current-password" placeholder="<?= t('Enter the password') ?>">
                </div>
                <div class="form-group form-group--inline">
                    <label class="checkbox-label">
                        <input type="checkbox" name="remember_me" value="1">
                        <span class="checkmark"></span>
                        <span><?= t('Remember me') ?></span>
                    </label>
                </div>
                <?php if ($requireCaptcha): ?>
                <div class="form-group">
                    <label><?= t('CAPTCHA') ?></label>
                    <div class="input-with-button">
                        <input type="text" name="captcha" required placeholder="<?= t('Enter the code') ?>" maxlength="6" autocomplete="off">
                        <img src="/captcha" alt="<?= t('Code') ?>" class="captcha-img" title="<?= t('Click to refresh') ?>">
                    </div>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary btn-block"><?= t('Sign in') ?></button>
            </form>
            <?php endif; ?>
            <div class="auth-footer">
                <p><a href="/register"><?= t('Create one') ?></a><?php if ($methods['temp_key']): ?> · <a href="/fast-pass"><?= t('Temp key sign-in') ?></a><?php endif; ?></p>
            </div>
        </div>
    </noscript>
    <script type="application/json" id="login-props"><?= json_encode($props, $jsonFlags) ?></script>
</div>
<?= \Nomen\Src\ViteAssets::entry('src/entries/login.js') ?>
