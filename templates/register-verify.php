<?php
/** @var string $email */
/** @var string $maskedEmail */
/** @var bool $isMinor */
/** @var string|null $error */
/** @var string|null $success */
/** @var int $resendAfter */
$title = t('验证邮箱');
$extraCss = ['/css/activate.css?v=16054932', '/css/register-verify.css?v=16054932'];

$props = [
    'csrf' => \Nomen\Src\Csrf::getToken(),
    'email' => $email,
    'maskedEmail' => $maskedEmail,
    'isMinor' => (bool)$isMinor,
    'error' => $error ?: '',
    'success' => $success ?: '',
    'resendAfter' => (int)$resendAfter,
];

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<div id="register-verify-app" class="auth-container">
    <noscript><p class="form-hint"><?= t('邮箱验证需要开启 JavaScript。') ?></p></noscript>
    <script type="application/json" id="register-verify-props"><?= json_encode($props, $jsonFlags) ?></script>
</div>
<?= \Nomen\Src\ViteAssets::entry('src/entries/register-verify.js') ?>
