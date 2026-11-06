<?php
/** @var array|null $app */
/** @var array $appScopes */
/** @var array $availableScopes */
/** @var array $redirectUris */
/** @var string $error */
?>
<div class="admin-header">
    <h1>新建应用</h1>
    <p>创建后 Client ID 和 Secret 只显示一次，记得先存好。</p>
</div>

<?php if ($error !== ''): ?>
    <div class="admin-card admin-card--error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="admin-card">
    <form method="POST" action="/admin/apps/create" enctype="multipart/form-data" class="admin-form">
        <?= \Nomen\Src\Csrf::field() ?>
        <?php require __DIR__ . '/_app_fields.php'; ?>
        <div class="admin-btn-row">
            <button type="submit" class="admin-btn admin-btn--primary">创建应用</button>
            <a href="/admin/apps" class="admin-btn">取消</a>
        </div>
    </form>
</div>
