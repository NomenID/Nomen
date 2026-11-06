<?php
/** @var string $base */
/** @var array $endpoints */
/** @var array $mounts */
/** @var string $success */
/** @var string $error */
?>
<div class="admin-header">
    <h1>接入点</h1>
    <p>协议端点是固定的；插件挂载点由插件声明，路径可以在这里改。</p>
</div>

<?php if ($error !== ''): ?>
    <div class="admin-card admin-card--error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success !== ''): ?>
    <div class="admin-card admin-card--ok"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="admin-card">
    <div class="admin-card__head"><h2>协议端点</h2></div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr><th>名称</th><th>URL</th><th>说明</th></tr>
            </thead>
            <tbody>
                <?php foreach ($endpoints as [$label, $path, $description]): ?>
                <tr>
                    <td><?= htmlspecialchars($label) ?></td>
                    <td><code><?= htmlspecialchars($base . $path) ?></code></td>
                    <td class="admin-cell-sub"><?= htmlspecialchars($description) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="admin-card">
    <div class="admin-card__head"><h2>插件挂载点</h2></div>

    <?php if (!$mounts): ?>
        <p class="admin-empty">当前启用的插件没有声明挂载点。</p>
    <?php else: ?>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr><th>插件</th><th>名称</th><th>路径</th><th>访问</th><th>操作</th></tr>
            </thead>
            <tbody>
                <?php foreach ($mounts as $mount): ?>
                <tr>
                    <td><code><?= htmlspecialchars((string)$mount['plugin']) ?></code></td>
                    <td>
                        <?= htmlspecialchars((string)$mount['title']) ?>
                        <?php if (!empty($mount['description'])): ?>
                            <div class="admin-cell-sub"><?= htmlspecialchars((string)$mount['description']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><code><?= htmlspecialchars((string)$mount['path']) ?></code></td>
                    <td><?= ($mount['access'] ?? 'public') === 'admin' ? '仅管理员' : '公开' ?></td>
                    <td>
                        <?php if (!empty($mount['config'])): ?>
                            <form method="POST" action="/admin/mounts/<?= htmlspecialchars((string)$mount['plugin']) ?>" class="admin-inline-form">
                                <?= \Nomen\Src\Csrf::field() ?>
                                <input type="hidden" name="mount_id" value="<?= htmlspecialchars((string)$mount['id']) ?>">
                                <input type="hidden" name="config_key" value="<?= htmlspecialchars((string)$mount['config']) ?>">
                                <input type="text" name="path" value="<?= htmlspecialchars((string)$mount['path']) ?>" size="12" aria-label="路径">
                                <button type="submit" class="admin-btn admin-btn--sm">保存</button>
                            </form>
                        <?php else: ?>
                            <span class="admin-cell-sub">插件固定</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
