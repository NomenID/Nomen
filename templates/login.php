<?php
/** @var string|null $error */
/** @var string|null $success */
/** @var string $redirect_to */
/** @var bool $requireCaptcha 仅当该 IP 登录尝试过多时才要求图片验证码 */
/** @var array<string,array{at:int,reason:string}> $failedMethods 本次会话里试过且没成功的方式 */
$title = t('登录');
$requireCaptcha = $requireCaptcha ?? false;
$failedMethods = $failedMethods ?? [];

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
    'privacyUrl' => (string)\Nomen\Src\Brand::get('privacy_url'),
    'termsUrl' => (string)\Nomen\Src\Brand::get('terms_url'),
    'developerGuidelinesUrl' => (string)\Nomen\Src\Brand::get('developer_guidelines_url'),
    'supportEmail' => (string)\Nomen\Src\Brand::get('support_email'),
    'supportName' => (string)\Nomen\Src\Brand::get('support_name'),
    'mailEnabled' => \Nomen\Src\Email::isConfigured(),
    'brandLogo' => (string)\Nomen\Src\Brand::get('logo'),
    'providers' => \Nomen\Src\IdentityProviders::publicList(),
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
                <h1><?= t('登录 Nomen') ?></h1>
                <p><?= t('使用您的 Nomen 账户继续') ?></p>
            </div>
            <?php if ($error): ?>
                <div class="nomen-alert nomen-alert--error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="nomen-alert nomen-alert--success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <form method="POST" action="/login" class="auth-form">
                <?= \Nomen\Src\Csrf::field() ?>
                <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($redirect_to) ?>">
                <div class="form-group">
                    <label for="noscript-username"><?= t('用户名或邮箱') ?></label>
                    <input type="text" id="noscript-username" name="username" required
                           placeholder="<?= t('请输入用户名或邮箱地址') ?>"
                           value="<?= htmlspecialchars($_GET['username'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="noscript-password"><?= t('密码') ?></label>
                    <input type="password" id="noscript-password" name="password" required
                           autocomplete="current-password" placeholder="<?= t('请输入密码') ?>">
                </div>
                <div class="form-group form-group--inline">
                    <label class="checkbox-label">
                        <input type="checkbox" name="remember_me" value="1">
                        <span class="checkmark"></span>
                        <span><?= t('记住我') ?></span>
                    </label>
                </div>
                <?php if ($requireCaptcha): ?>
                <div class="form-group">
                    <label><?= t('图片验证码') ?></label>
                    <div class="input-with-button">
                        <input type="text" name="captcha" required placeholder="<?= t('请输入验证码') ?>" maxlength="6" autocomplete="off">
                        <img src="/captcha" alt="<?= t('验证码') ?>" class="captcha-img" title="<?= t('点击刷新') ?>">
                    </div>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary btn-block"><?= t('登录') ?></button>
            </form>
            <div class="auth-footer">
                <p><a href="/register"><?= t('注册一个') ?></a> · <a href="/fast-pass"><?= t('临时密钥登录') ?></a></p>
            </div>
        </div>
    </noscript>
    <script type="application/json" id="login-props"><?= json_encode($props, $jsonFlags) ?></script>
</div>
<?= \Nomen\Src\ViteAssets::entry('src/entries/login.js') ?>
