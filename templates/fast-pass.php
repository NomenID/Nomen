<?php
/** @var string|null $error */
/** @var string|null $success */
/** @var string $redirect_to */
$title = t('临时密钥登录');
?>

<div class="auth-container">
    <div class="auth-card" style="max-width:480px;">
        <div class="auth-header">
            <div class="auth-logo">
                <img src="/assets/brand/nomen-icon.svg" alt="" width="48" height="48">
            </div>
            <h1><?= t('临时密钥登录') ?></h1>
            <p><?= t('使用临时密钥快速登录，跳过新设备验证') ?></p>
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
                <label for="tempkey-username"><?= t('用户名或邮箱') ?></label>
                <input type="text" id="tempkey-username" name="username" required autofocus
                       placeholder="<?= t('请输入用户名或邮箱地址') ?>">
            </div>

            <div class="form-group">
                <label for="tempkey-key"><?= t('临时密钥') ?></label>
                <input type="password" id="tempkey-key" name="temp_key" required
                       placeholder="<?= t('请输入临时密钥') ?>">
            </div>

            <p style="font-size:12px;color:var(--color-text-subtle);margin-bottom:16px;line-height:1.6;">
                <?= t('临时密钥仅用于跳过新设备验证（如在学校电脑上登录），不影响密码安全。') ?><br>
                <?= t('可在账户设置中创建和管理临时密钥。') ?>
            </p>

            <div class="form-group">
                <label><?= t('验证码') ?></label>
                <div class="input-with-button">
                    <input type="text" name="captcha" required placeholder="<?= t('请输入验证码') ?>" maxlength="6" autocomplete="off" style="flex:1;">
                    <img src="/captcha" alt="<?= t('验证码') ?>" onclick="this.src='/captcha?t='+Date.now()" style="height:38px;max-width:120px;cursor:pointer;border-radius:6px;" title="<?= t('点击刷新') ?>">
                </div>
            </div>

            <?php
            $privacyUrl = (string)\Nomen\Src\Brand::get('privacy_url');
            $termsUrl = (string)\Nomen\Src\Brand::get('terms_url');
            ?>
            <?php if ($privacyUrl !== '' || $termsUrl !== ''): ?>
                <p style="font-size:12px;color:var(--color-text-subtle);margin-bottom:12px;">
                    <?= t('登录即代表同意') ?>
                    <?php if ($privacyUrl !== ''): ?>
                        <a href="<?= htmlspecialchars($privacyUrl) ?>" target="_blank"><?= t('隐私政策') ?></a>
                    <?php endif; ?>
                    <?php if ($privacyUrl !== '' && $termsUrl !== ''): ?><?= t('和') ?><?php endif; ?>
                    <?php if ($termsUrl !== ''): ?>
                        <a href="<?= htmlspecialchars($termsUrl) ?>" target="_blank"><?= t('服务协议') ?></a>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary btn-block"><?= t('登录') ?></button>
        </form>

        <div class="auth-footer">
            <p><a href="/login"><?= t('返回密码登录') ?></a></p>
        </div>
    </div>
</div>
