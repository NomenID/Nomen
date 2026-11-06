<?php
/**
 * 已经在家庭里、且家长真的加入了的孩子走这一页，而不是自助申诉：
 * 家长在「家庭」页面就能直接给 TA 重置密码，比凑够 3 种登录方式快得多。
 *
 * @var string $username
 * @var string $maskedGuardian
 * @var string|null $error
 */
$title = t('账号由家长管理');
?>

<div class="auth-container">
    <div class="auth-card" style="max-width:520px;">
        <div class="auth-header">
            <div class="auth-logo">
                <img src="<?= htmlspecialchars((string)\Nomen\Src\Brand::get('logo')) ?>" alt="" width="48" height="48">
            </div>
            <h1><?= t('账号由家长管理') ?></h1>
            <p><?= htmlspecialchars(t('{:name} 已经在家庭里', ['name' => $username])) ?></p>
        </div>

        <div class="nomen-alert nomen-alert--info">
            <?= t('让家长帮你重置密码就行，比走申诉快得多。') ?>
        </div>

        <?php if ($error): ?>
        <div class="nomen-alert nomen-alert--error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <p style="font-size:14px;line-height:1.9;color:var(--color-text-muted);">
            <?= t('请让家长登录 Nomen，在「家庭」页面里把你的密码重置掉。') ?>
            <?= t('家长用的邮箱是') ?> <strong style="color:var(--color-text);"><?= htmlspecialchars($maskedGuardian) ?></strong><?= t('。') ?>
        </p>

        <p style="font-size:14px;line-height:1.9;color:var(--color-text-muted);">
            <?= t('家长也不方便操作的话，用那个邮箱发一封邮件到') ?>
            <a href="mailto:id&#64;caellab.com">id&#64;caellab.com</a> <?= t('说明情况，我们会人工处理。') ?>
        </p>

        <div class="auth-footer" style="margin-top:24px;">
            <p><a href="/login"><?= t('返回登录') ?></a></p>
        </div>
    </div>
</div>
