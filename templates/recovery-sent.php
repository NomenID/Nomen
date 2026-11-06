<?php
/** @var string|null $contact */
$title = t('申诉已提交');

// 和申诉页第 3 步一样打码：用户那里可能选的就是「用账号邮箱」，
// 这页若把完整地址打出来，等于把上一步的打码白做了。
$maskedContact = \Nomen\Src\RecoveryHelper::maskEmail($contact);
?>

<div class="auth-container">
    <div class="auth-card" style="max-width:520px;">
        <div class="auth-header">
            <div class="auth-logo">
                <img src="/assets/brand/nomen-icon.svg" alt="" width="48" height="48">
            </div>
            <h1><?= t('申诉已提交') ?></h1>
            <p><?= t('站点管理员会尽快审核您的申诉') ?></p>
        </div>

        <div class="nomen-alert nomen-alert--success">
            <?= t('已收到您的账号恢复申诉。') ?>
        </div>

        <?php if ($maskedContact !== ''): ?>
        <p style="font-size:14px;line-height:1.8;color:var(--color-text-muted);">
            <?= t('审核结果与恢复链接都会发送至') ?> <strong style="color:var(--color-text);"><?= htmlspecialchars($maskedContact) ?></strong><?= t('，请留意查收（也请看看垃圾邮件箱）。') ?>
        </p>
        <?php endif; ?>

        <p style="font-size:14px;line-height:1.8;color:var(--color-text-muted);">
            <?= t('大部分请求的审核用时为 2 个自然日，但某些请求可能需要更长的时间。感谢您的耐心等待。') ?>
        </p>

        <div class="auth-footer" style="margin-top:24px;">
            <p><a href="/login"><?= t('返回登录') ?></a></p>
        </div>
    </div>
</div>
