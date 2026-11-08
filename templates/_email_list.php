<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

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
                <span class="badge badge--info"><?= t('Primary') ?></span>
            <?php endif; ?>
            <?php if (!empty($row['verified'])): ?>
                <span class="badge badge--success"><?= t('Verified') ?></span>
            <?php else: ?>
                <span class="badge badge--warning"><?= t('Pending') ?></span>
            <?php endif; ?>
        </div>
        <div class="email-item__actions">
            <?php if (empty($row['verified'])): ?>
                <button type="button" class="btn btn-sm btn-secondary" data-email-action="resend"><?= t('Resend verification email') ?></button>
            <?php elseif (empty($row['is_primary'])): ?>
                <button type="button" class="btn btn-sm btn-secondary" data-email-action="primary"><?= t('Set as primary') ?></button>
            <?php endif; ?>
            <?php if (count($emails) > 1): ?>
                <button type="button" class="btn btn-sm btn-danger-outline" data-email-action="remove"><?= t('Remove') ?></button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (!$emails): ?>
        <p class="text-muted" style="padding:8px 0;"><?= t('No email bound yet — notifications currently go to a parent\'s address.') ?></p>
    <?php endif; ?>
</div>

<?php if (count($emails) < $maxEmails): ?>
<form id="email-add-form" method="POST" action="/settings/emails/add" class="settings-form" style="margin-top:16px;">
    <?= \Nomen\Src\Csrf::field() ?>
    <div class="form-group">
        <label for="new_email"><?= t('Add email') ?></label>
        <input type="email" id="new_email" name="new_email" required placeholder="<?= t('Enter the address to add') ?>">
    </div>
    <div class="form-group">
        <label for="email_add_password"><?= t('Current password') ?></label>
        <input type="password" id="email_add_password" name="password" <?= $recoveryMode ? '' : 'required' ?>
               placeholder="<?= $recoveryMode ? t('Can be left empty in recovery mode') : t('Enter the current password to confirm it\'s you') ?>">
        <?php if ($recoveryMode): ?>
            <p class="form-hint"><?= t('No current password needed in recovery mode.') ?></p>
        <?php endif; ?>
    </div>
    <button type="submit" id="email-add-btn" class="btn btn-primary"><?= t('Send verification email') ?></button>
    <small class="form-hint"><?= t('The verification mail goes to the new address; the change applies after you click its link') ?></small>
</form>
<?php else: ?>
    <p class="form-hint" style="margin-top:16px;"><?= t('You already have {:max} addresses. Remove one before adding another.', ['max' => (int)$maxEmails]) ?></p>
<?php endif; ?>
