<?php
/** @var array  $requests
 *  @var string $status
 *  @var string $search
 *  @var array  $pagination
 *  @var array  $query
 */
$statusFilters = [
    ''          => '全部',
    'pending'   => '待审核',
    'approved'  => '已通过待完成',
    'completed' => '已完成',
    'rejected'  => '已拒绝',
];
$statusBadges = [
    'pending'   => ['待审核', 'warning'],
    'approved'  => ['已通过待完成', 'info'],
    'rejected'  => ['已拒绝', 'danger'],
    'completed' => ['已完成', 'success'],
];
?>

<div class="admin-header">
    <h1>恢复工单</h1>
    <p>共 <?= (int)$pagination['total'] ?> 条申诉 · 待审核会排在最前面</p>
</div>

<div class="admin-card">
    <div class="admin-toolbar">
        <form method="GET" action="/admin/recovery" class="admin-search">
            <?php if ($status !== ''): ?>
                <input type="hidden" name="status" value="<?= htmlspecialchars($status) ?>">
            <?php endif; ?>
            <input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="搜索用户名 / 联系邮箱">
            <button type="submit" class="admin-btn admin-btn--primary">搜索</button>
            <?php if ($search !== ''): ?>
                <a href="/admin/recovery<?= $status !== '' ? '?status=' . urlencode($status) : '' ?>" class="admin-btn">清空</a>
            <?php endif; ?>
        </form>
        <div class="admin-chips">
            <?php foreach ($statusFilters as $key => $label): ?>
                <?php
                $params = [];
                if ($search !== '') $params['q'] = $search;
                if ($key !== '')    $params['status'] = $key;
                ?>
                <a href="/admin/recovery<?= $params ? '?' . htmlspecialchars(http_build_query($params)) : '' ?>"
                   class="admin-chip<?= $status === $key ? ' admin-chip--on' : '' ?>"><?= htmlspecialchars($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (!$requests): ?>
        <p class="admin-empty">没有匹配的工单。</p>
    <?php else: ?>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>工单</th>
                    <th>账号</th>
                    <th>状态</th>
                    <th>生日比对</th>
                    <th>性别比对</th>
                    <th>联系邮箱</th>
                    <th>提交时间</th>
                    <th class="admin-th-actions">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $req): ?>
                <?php $badge = $statusBadges[$req['status']] ?? [$req['status'], 'muted']; ?>
                <tr>
                    <td><a href="/admin/recovery/<?= (int)$req['id'] ?>">#<?= (int)$req['id'] ?></a></td>
                    <td>
                        <a href="/admin/users/<?= (int)$req['user_id'] ?>"><?= htmlspecialchars((string)$req['username']) ?></a>
                        <div class="admin-cell-sub"><?= htmlspecialchars((string)$req['account_email']) ?></div>
                    </td>
                    <td><span class="admin-badge admin-badge--<?= $badge[1] ?>"><?= htmlspecialchars($badge[0]) ?></span></td>
                    <td><?= \Nomen\Controllers\AdminController::matchBadge($req['birth_date_match'] ?? null) ?></td>
                    <td><?= \Nomen\Controllers\AdminController::matchBadge($req['gender_match'] ?? null) ?></td>
                    <td><?= htmlspecialchars((string)$req['contact_email']) ?></td>
                    <td class="admin-nowrap"><?= htmlspecialchars((string)$req['created_at']) ?></td>
                    <td class="admin-td-actions">
                        <a href="/admin/recovery/<?= (int)$req['id'] ?>" class="admin-btn admin-btn--sm">查看</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php require __DIR__ . '/pagination.php'; ?>
    <?php endif; ?>
</div>
