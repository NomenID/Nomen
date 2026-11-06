<?php
/** @var array $users
 *  @var string $search
 *  @var string $filter
 *  @var array  $pagination
 *  @var array  $query
 *  @var array  $admin
 */
$filters = [
    ''           => '全部',
    'admin'      => '管理员',
    'disabled'   => '已禁用',
    'unverified' => '邮箱未验证',
];
?>

<div class="admin-header">
    <h1>用户管理</h1>
    <p>共 <?= (int)$pagination['total'] ?> 个账户</p>
</div>

<div class="admin-card">
    <div class="admin-toolbar">
        <form method="GET" action="/admin/users" class="admin-search">
            <?php if ($filter !== ''): ?>
                <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
            <?php endif; ?>
            <input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="搜索用户名 / 昵称 / 邮箱">
            <button type="submit" class="admin-btn admin-btn--primary">搜索</button>
            <?php if ($search !== ''): ?>
                <a href="/admin/users<?= $filter !== '' ? '?filter=' . urlencode($filter) : '' ?>" class="admin-btn">清空</a>
            <?php endif; ?>
        </form>
        <div class="admin-chips">
            <?php foreach ($filters as $key => $label): ?>
                <?php
                $params = [];
                if ($search !== '') $params['q'] = $search;
                if ($key !== '')     $params['filter'] = $key;
                ?>
                <a href="/admin/users<?= $params ? '?' . htmlspecialchars(http_build_query($params)) : '' ?>"
                   class="admin-chip<?= $filter === $key ? ' admin-chip--on' : '' ?>"><?= htmlspecialchars($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (!$users): ?>
        <p class="admin-empty">没有匹配的用户。</p>
    <?php else: ?>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>用户</th>
                    <th>邮箱</th>
                    <th>角色</th>
                    <th>状态</th>
                    <th>登录方式</th>
                    <th>应用</th>
                    <th>注册时间</th>
                    <th class="admin-th-actions">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                <tr>
                    <td>
                        <a href="/admin/users/<?= (int)$user['id'] ?>" class="admin-user-link">
                            <?= htmlspecialchars($user['username']) ?>
                        </a>
                        <div class="admin-cell-sub">#<?= (int)$user['id'] ?><?= $user['display_name'] && $user['display_name'] !== $user['username'] ? ' · ' . htmlspecialchars($user['display_name']) : '' ?></div>
                    </td>
                    <td>
                        <?php if (empty($user['email'])): ?>
                            <span class="admin-cell-sub">没有自己的邮箱，收信走家长邮箱</span>
                        <?php else: ?>
                            <?= htmlspecialchars((string)$user['email']) ?>
                            <?php if (empty($user['email_verified'])): ?>
                                <span class="admin-badge admin-badge--warning">未验证</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($user['role'] === 'admin'): ?>
                            <span class="admin-badge admin-badge--info">管理员</span>
                        <?php else: ?>
                            <span class="admin-badge admin-badge--muted">普通</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int)$user['status'] === 1): ?>
                            <span class="admin-badge admin-badge--success">正常</span>
                        <?php else: ?>
                            <span class="admin-badge admin-badge--danger">已禁用</span>
                        <?php endif; ?>
                    </td>
                    <td class="admin-nowrap">
                        <?php $lc = (int)$user['login_method_count']; ?>
                        <span class="admin-badge <?= $lc >= 3 ? 'admin-badge--success' : 'admin-badge--warning' ?>" title="登录方式数量，不含生物识别（<?= htmlspecialchars(\Nomen\Src\RecoveryHelper::ASSIST_LABEL) ?>）"><?= $lc ?> 种</span>
                        <?php if ((int)$user['totp_count'] > 0): ?><span class="admin-badge admin-badge--muted" title="已绑定验证器 APP">TOTP</span><?php endif; ?>
                        <?php if ((int)$user['biometric_count'] > 0): ?><span class="admin-badge admin-badge--muted" title="已绑定生物识别">生物</span><?php endif; ?>
                    </td>
                    <td><?= (int)$user['app_count'] ?></td>
                    <td class="admin-nowrap"><?= htmlspecialchars((string)$user['created_at']) ?></td>
                    <td class="admin-td-actions">
                        <button type="button" class="admin-btn admin-btn--sm"
                                data-admin-action="toggle-admin"
                                data-user-id="<?= (int)$user['id'] ?>"
                                data-username="<?= htmlspecialchars($user['username']) ?>"
                                data-role="<?= htmlspecialchars($user['role']) ?>">
                            <?= $user['role'] === 'admin' ? '撤销管理员' : '设为管理员' ?>
                        </button>
                        <button type="button" class="admin-btn admin-btn--sm <?= (int)$user['status'] === 1 ? 'admin-btn--danger' : '' ?>"
                                data-admin-action="toggle-status"
                                data-user-id="<?= (int)$user['id'] ?>"
                                data-username="<?= htmlspecialchars($user['username']) ?>"
                                data-status="<?= (int)$user['status'] ?>">
                            <?= (int)$user['status'] === 1 ? '禁用' : '启用' ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php require __DIR__ . '/pagination.php'; ?>
    <?php endif; ?>
</div>
