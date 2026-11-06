<?php
/**
 * 邮箱列表 + 添加表单。设置页和恢复修复页共用一份。
 *
 * @var array $emails       该账号的全部邮箱（EmailSet::all 的返回）
 * @var int   $maxEmails    一个账号最多几个
 * @var bool  $recoveryMode 恢复流程中（当前密码可以留空）
 */
$emails       = $emails ?? [];
$maxEmails    = $maxEmails ?? \Nomen\Src\EmailSet::MAX;
$recoveryMode = $recoveryMode ?? false;
?>
<div class="email-list" data-recovery-mode="<?= $recoveryMode ? '1' : '0' ?>">
    <?php foreach ($emails as $row): ?>
    <div class="email-item" data-email-id="<?= (int)$row['id'] ?>">
        <div class="email-item__main">
            <span class="email-item__addr"><?= htmlspecialchars((string)$row['email']) ?></span>
            <?php if (!empty($row['is_primary'])): ?>
                <span class="badge badge--info"><?= t('首选') ?></span>
            <?php endif; ?>
            <?php if (!empty($row['verified'])): ?>
                <span class="badge badge--success"><?= t('已验证') ?></span>
            <?php else: ?>
                <span class="badge badge--warning"><?= t('待验证') ?></span>
            <?php endif; ?>
        </div>
        <div class="email-item__actions">
            <?php if (empty($row['verified'])): ?>
                <button type="button" class="btn btn-sm btn-secondary" data-email-action="resend"><?= t('重新发送验证邮件') ?></button>
            <?php elseif (empty($row['is_primary'])): ?>
                <button type="button" class="btn btn-sm btn-secondary" data-email-action="primary"><?= t('设为首选') ?></button>
            <?php endif; ?>
            <?php if (count($emails) > 1): ?>
                <button type="button" class="btn btn-sm btn-danger-outline" data-email-action="remove"><?= t('移除') ?></button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (!$emails): ?>
        <p class="text-muted" style="padding:8px 0;"><?= t('还没有绑定邮箱，现在收信走的是家长的邮箱。') ?></p>
    <?php endif; ?>
</div>

<?php if (count($emails) < $maxEmails): ?>
<form id="email-add-form" method="POST" action="/settings/emails/add" class="settings-form" style="margin-top:16px;">
    <?= \Nomen\Src\Csrf::field() ?>
    <div class="form-group">
        <label for="new_email"><?= t('添加邮箱') ?></label>
        <input type="email" id="new_email" name="new_email" required placeholder="<?= t('请输入要添加的邮箱地址') ?>">
    </div>
    <div class="form-group">
        <label for="email_add_password"><?= t('当前密码') ?></label>
        <input type="password" id="email_add_password" name="password" <?= $recoveryMode ? '' : 'required' ?>
               placeholder="<?= $recoveryMode ? t('恢复流程中可留空') : t('输入当前密码以确认身份') ?>">
        <?php if ($recoveryMode): ?>
            <p class="form-hint"><?= t('恢复流程中无需填写当前密码。') ?></p>
        <?php endif; ?>
    </div>
    <button type="submit" id="email-add-btn" class="btn btn-primary"><?= t('发送验证邮件') ?></button>
    <small class="form-hint"><?= t('验证邮件会发到新邮箱，点里面的链接确认后才会生效') ?></small>
</form>
<?php else: ?>
    <p class="form-hint" style="margin-top:16px;"><?= t('已经绑满 {:max} 个邮箱，要加新的请先移除一个。', ['max' => (int)$maxEmails]) ?></p>
<?php endif; ?>
