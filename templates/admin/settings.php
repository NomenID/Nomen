<?php
/** @var string $policy */
/** @var array<string, string> $login2faOptions */
/** @var string $backend */
/** @var array<string, string> $cacheOptions */
/** @var string $effectiveBackend */
/** @var bool $mailConfigured */
/** @var bool $settingsReady */
/** @var string $success */
/** @var string $error */
$effectiveLabel = $effectiveBackend === 'none'
    ? '不可用'
    : ($cacheOptions[$effectiveBackend] ?? $effectiveBackend);
?>
<div class="admin-header">
    <h1>设置</h1>
    <p>登录安全和缓存的站点级开关，保存后全站生效。</p>
</div>

<?php if ($error !== ''): ?>
    <div class="admin-card admin-card--error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success !== ''): ?>
    <div class="admin-card admin-card--ok"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<?php if (!$settingsReady): ?>
    <div class="admin-card admin-card--warn">
        <p style="margin:0">数据库还没升级，设置暂时存不进去。先在项目根目录跑 <code>php bin/nomen upgrade</code>。</p>
    </div>
<?php endif; ?>

<form method="POST" action="/admin/settings" class="admin-form">
    <?= \Nomen\Src\Csrf::field() ?>

    <div class="admin-card">
        <div class="admin-card__head"><h2>登录二次验证</h2></div>
        <p class="admin-cell-sub" style="margin:0 0 12px">
            关闭时登录只验密码。账号自己绑定的验证器也只在站点开启后才要求。
        </p>
        <div class="admin-check-list">
            <?php foreach ($login2faOptions as $value => $label): ?>
            <label class="admin-check">
                <input type="radio" name="login_2fa" value="<?= htmlspecialchars($value) ?>"<?= $policy === $value ? ' checked' : '' ?>>
                <span><?= htmlspecialchars($label) ?></span>
            </label>
            <?php endforeach; ?>
        </div>
        <?php if (!$mailConfigured): ?>
            <p class="admin-cell-sub" style="margin:12px 0 0">
                本站还没配置 SMTP：邮箱验证码登录和「邮箱验证码」这个第二因素都不会出现。
                要开二次验证，账号得先绑定验证器 APP 或生成恢复码。
            </p>
        <?php endif; ?>
    </div>

    <div class="admin-card">
        <div class="admin-card__head"><h2>缓存</h2></div>
        <div class="form-group">
            <label for="cache_backend">后端</label>
            <select id="cache_backend" name="cache_backend">
                <?php foreach ($cacheOptions as $value => $label): ?>
                <option value="<?= htmlspecialchars($value) ?>"<?= $backend === $value ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <p class="admin-cell-sub" style="margin:0">
            当前生效：<code><?= htmlspecialchars($effectiveLabel) ?></code>。
            版本页缓存 60 秒；站点设置保存时立即失效。
        </p>
        <?php if ($backend !== 'auto' && $backend !== $effectiveBackend): ?>
            <p class="admin-cell-sub" style="margin:8px 0 0">
                选中的后端当前不可用，已临时回退到 <code><?= htmlspecialchars($effectiveLabel) ?></code>，具体原因看日志。
            </p>
        <?php endif; ?>
    </div>

    <div class="admin-btn-row">
        <button type="submit" class="admin-btn admin-btn--primary"<?= $settingsReady ? '' : ' disabled' ?>>保存设置</button>
    </div>
</form>
