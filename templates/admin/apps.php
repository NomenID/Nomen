<?php
/** @var array $apps */
/** @var string $success */
/** @var string $error */
?>
<div class="admin-header">
    <h1>应用</h1>
    <p>共 <?= count($apps) ?> 个 OAuth 客户端。管理员建的应用不依赖开放平台插件。</p>
</div>

<?php if ($error !== ''): ?>
    <div class="admin-card admin-card--error"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success !== ''): ?>
    <div class="admin-card admin-card--ok"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="admin-card">
    <div class="admin-card__head">
        <h2>全部应用</h2>
        <a href="/admin/apps/create" class="admin-btn admin-btn--primary">新建应用</a>
    </div>

    <?php if (!$apps): ?>
        <p class="admin-empty">还没有应用。新建一个，把回调地址和权限配好就能接入。</p>
    <?php else: ?>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>应用</th>
                    <th>Client ID</th>
                    <th>类型</th>
                    <th>归属</th>
                    <th>状态</th>
                    <th>创建时间</th>
                    <th class="admin-th-actions">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($apps as $app): ?>
                <tr>
                    <td>
                        <a href="/admin/apps/<?= htmlspecialchars((string)$app['client_id']) ?>" class="admin-user-link">
                            <?= htmlspecialchars((string)$app['name']) ?>
                        </a>
                        <?php if (!empty($app['description'])): ?>
                            <div class="admin-cell-sub"><?= htmlspecialchars((string)$app['description']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><code><?= htmlspecialchars((string)$app['client_id']) ?></code></td>
                    <td><?= ($app['client_type'] ?? 'confidential') === 'public' ? '公共' : '机密' ?></td>
                    <td>
                        <?php if (!empty($app['developer_name'])): ?>
                            开发者 · <?= htmlspecialchars((string)$app['developer_name']) ?>
                        <?php elseif (!empty($app['owner_username'])): ?>
                            管理员 · <?= htmlspecialchars((string)$app['owner_username']) ?>
                        <?php else: ?>
                            <span class="admin-cell-sub">无归属</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int)$app['status'] === 1): ?>
                            <span class="admin-badge admin-badge--success">启用</span>
                        <?php else: ?>
                            <span class="admin-badge admin-badge--danger">停用</span>
                        <?php endif; ?>
                    </td>
                    <td class="admin-nowrap"><?= htmlspecialchars((string)$app['created_at']) ?></td>
                    <td class="admin-td-actions">
                        <a href="/admin/apps/<?= htmlspecialchars((string)$app['client_id']) ?>" class="admin-btn admin-btn--sm">详情</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
