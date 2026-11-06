<?php
/** @var array $app */
/** @var array $redirectUris */
/** @var array $appScopes */
/** @var array $availableScopes */
/** @var array $stats */
/** @var bool $isPublic */
/** @var bool $pkceOn */
/** @var string $authorizeSample */
/** @var string $tokenSample */
/** @var string $newClientId */
/** @var string $newClientSecret */
/** @var string $success */
/** @var string $error */
$base = rtrim((string)\Nomen\Src\Brand::get('base_url'), '/');
$statusOn = (int)$app['status'] === 1;
?>
<div class="admin-header">
    <h1>
        <?= htmlspecialchars((string)$app['name']) ?>
        <span class="admin-header__id"><?= htmlspecialchars((string)$app['client_id']) ?></span>
        <?php if ($statusOn): ?>
            <span class="admin-badge admin-badge--success">启用</span>
        <?php else: ?>
            <span class="admin-badge admin-badge--danger">停用</span>
        <?php endif; ?>
    </h1>
    <p>创建于 <?= htmlspecialchars((string)$app['created_at']) ?>。回调地址和权限改完立即生效。 <a href="/admin/logs?target=<?= urlencode((string)$app['client_id']) ?>">应用日志</a></p>
</div>

<?php if ($error !== ''): ?>
    <div class="admin-card admin-card--error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success !== ''): ?>
    <div class="admin-card admin-card--ok"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<?php if ($newClientSecret !== ''): ?>
<div class="admin-card admin-card--warn">
    <div class="admin-card__head"><h2>凭证只显示这一次</h2></div>
    <p>关掉页面就看不到了。需要再拿一次只能重置密钥。</p>
    <table class="admin-kv">
        <tr><th>Client ID</th><td><code><?= htmlspecialchars($newClientId) ?></code></td></tr>
        <tr><th>Client Secret</th><td><code><?= htmlspecialchars($newClientSecret) ?></code></td></tr>
    </table>
</div>
<?php endif; ?>

<div class="admin-card">
    <div class="admin-card__head"><h2>概况</h2></div>
    <div class="admin-stat-grid">
        <div class="admin-stat">
            <div class="admin-stat__value"><?= (int)$stats['authorized_users'] ?></div>
            <div class="admin-stat__label">授权用户</div>
        </div>
        <div class="admin-stat">
            <div class="admin-stat__value"><?= (int)$stats['active_tokens'] ?></div>
            <div class="admin-stat__label">活跃 Token</div>
        </div>
        <div class="admin-stat">
            <div class="admin-stat__value"><?= $isPublic ? '公共' : '机密' ?></div>
            <div class="admin-stat__label">客户端类型</div>
        </div>
        <div class="admin-stat">
            <div class="admin-stat__value"><?= $pkceOn ? '强制' : '可选' ?></div>
            <div class="admin-stat__label">PKCE</div>
        </div>
    </div>
</div>

<div class="admin-card">
    <div class="admin-card__head"><h2>基本信息</h2></div>
    <form method="POST" action="/admin/apps/<?= htmlspecialchars((string)$app['client_id']) ?>/update"
          enctype="multipart/form-data" class="admin-form">
        <?= \Nomen\Src\Csrf::field() ?>
        <?php require __DIR__ . '/_app_fields.php'; ?>
        <div class="admin-btn-row">
            <button type="submit" class="admin-btn admin-btn--primary">保存</button>
        </div>
    </form>
</div>

<div class="admin-card">
    <div class="admin-card__head"><h2>接入端点</h2></div>
    <table class="admin-kv">
        <tr><th>授权</th><td><code><?= htmlspecialchars($base . '/oauth/authorize') ?></code></td></tr>
        <tr><th>令牌</th><td><code><?= htmlspecialchars($base . '/oauth/token') ?></code></td></tr>
        <tr><th>用户信息</th><td><code><?= htmlspecialchars($base . '/api/userinfo') ?></code></td></tr>
        <tr><th>撤销</th><td><code><?= htmlspecialchars($base . '/oauth/revoke') ?></code></td></tr>
        <tr><th>回调地址</th><td>
            <?php foreach ($redirectUris as $uri): ?>
                <div><code><?= htmlspecialchars((string)$uri) ?></code></div>
            <?php endforeach; ?>
        </td></tr>
        <tr><th>权限</th><td><code><?= htmlspecialchars(implode(' ', $appScopes)) ?></code></td></tr>
    </table>
</div>

<div class="admin-card">
    <div class="admin-card__head"><h2>接入示例</h2></div>

    <h3>1. 发起授权</h3>
    <pre class="admin-code"><?= htmlspecialchars($authorizeSample) ?></pre>

    <h3>2. 换取令牌</h3>
    <pre class="admin-code"><?= htmlspecialchars($tokenSample) ?></pre>

    <h3>3. 读取用户信息</h3>
    <pre class="admin-code">GET <?= htmlspecialchars($base . '/api/userinfo') ?>

Authorization: Bearer ACCESS_TOKEN</pre>
</div>

<div class="admin-card">
    <div class="admin-card__head"><h2>危险操作</h2></div>
    <div class="admin-btn-row">
        <form method="POST" action="/admin/apps/<?= htmlspecialchars((string)$app['client_id']) ?>/toggle">
            <?= \Nomen\Src\Csrf::field() ?>
            <button type="submit" class="admin-btn <?= $statusOn ? 'admin-btn--danger' : '' ?>">
                <?= $statusOn ? '停用应用' : '启用应用' ?>
            </button>
        </form>

        <?php if (!$isPublic): ?>
        <form method="POST" action="/admin/apps/<?= htmlspecialchars((string)$app['client_id']) ?>/reset-secret"
              onsubmit="return confirm('重置后旧密钥立即失效，所有活跃 Token 一并作废。继续？')">
            <?= \Nomen\Src\Csrf::field() ?>
            <button type="submit" class="admin-btn admin-btn--danger">重置密钥</button>
        </form>
        <?php endif; ?>

        <form method="POST" action="/admin/apps/<?= htmlspecialchars((string)$app['client_id']) ?>/delete"
              onsubmit="return confirm('删除后这个应用的所有授权和 Token 都会失效，且不可恢复。继续？')">
            <?= \Nomen\Src\Csrf::field() ?>
            <button type="submit" class="admin-btn admin-btn--danger">删除应用</button>
        </form>
    </div>
</div>
