<?php
/** @var array $user
 *  @var array $methods         登录方式（计入数量）
 *  @var array $assistMethods   快速辅助验证方式（不计入数量）
 *  @var array $authorizations
 *  @var array $recoveryHistory
 *  @var array $recentLogs
 *  @var array|null $membership          本人作为孩子的成员行
 *  @var array $familyGuardians          该组成员行里的监护人
 *  @var array $ownedGroups              本人作为监护人的组
 *  @var array $ownedGroupsChildren      上面每组的孩子
 *  @var string|null $guardianEmail      本人（未成年）的监护人邮箱
 */
$methodState = static fn(array $m): string => $m['present'] ? ($m['on'] ?? '已拥有') : ($m['off'] ?? '未设置');
$genderLabels = [
    'female'            => '女性',
    'male'              => '男性',
    'non-binary'        => '非二元性别',
    'prefer-not-to-say' => '我宁愿不说',
];
$statusLabels = [
    'pending'   => ['待审核', 'warning'],
    'approved'  => ['已通过待完成', 'info'],
    'rejected'  => ['已拒绝', 'danger'],
    'completed' => ['已完成', 'success'],
];
?>

<div class="admin-header">
    <h1><?= htmlspecialchars($user['username']) ?> <span class="admin-header__id">#<?= (int)$user['id'] ?></span></h1>
    <p><a href="/admin/users"><span class="icon icon-arrow-left"></span> 返回用户列表</a></p>
</div>

<div class="admin-grid-2">
    <div class="admin-card">
        <div class="admin-card__head"><h2>基本信息</h2></div>
        <table class="admin-kv">
            <tr><th>用户名</th><td><?= htmlspecialchars($user['username']) ?></td></tr>
            <tr><th>昵称</th><td><?= htmlspecialchars((string)$user['display_name']) ?></td></tr>
            <tr><th>邮箱</th><td>
                <?php if (($user['email'] ?? '') === null || $user['email'] === ''): ?>
                    <span class="admin-cell-sub">— 没有自己的邮箱（收信走家长邮箱<?= $guardianEmail ? '：' . htmlspecialchars((string)$guardianEmail) : '' ?>）</span>
                <?php else: ?>
                    <?= htmlspecialchars((string)$user['email']) ?>
                    <?= empty($user['email_verified']) ? '<span class="admin-badge admin-badge--warning">未验证</span>' : '<span class="admin-badge admin-badge--success">已验证</span>' ?>
                <?php endif; ?>
            </td></tr>
            <tr><th>角色</th><td><?= $user['role'] === 'admin' ? '<span class="admin-badge admin-badge--info">管理员</span>' : '普通用户' ?></td></tr>
            <tr><th>状态</th><td><?= (int)$user['status'] === 1 ? '<span class="admin-badge admin-badge--success">正常</span>' : '<span class="admin-badge admin-badge--danger">已禁用</span>' ?></td></tr>
            <?php if (\Nomen\Src\Features::country()): ?>
            <tr><th>国家 / 地区</th><td><?= htmlspecialchars((string)$user['country']) ?></td></tr>
            <?php endif; ?>
            <tr><th>出生日期</th><td><?= htmlspecialchars((string)$user['birth_date']) ?></td></tr>
            <tr><th>性别</th><td><?= htmlspecialchars($genderLabels[$user['gender'] ?? ''] ?? (string)$user['gender']) ?></td></tr>
            <tr><th>两步验证策略</th><td><?= ($user['two_factor_policy'] ?? '') === 'every_login' ? '每次登录都验证' : '仅新设备验证' ?></td></tr>
            <tr><th>快速登录</th><td><?= !empty($user['quick_login']) ? '已开启' : '已关闭' ?></td></tr>
            <tr><th>注册时间</th><td><?= htmlspecialchars((string)$user['created_at']) ?></td></tr>
            <tr><th>最近登录</th><td><?= htmlspecialchars((string)$user['last_login_at']) ?> <span class="admin-cell-sub"><?= htmlspecialchars((string)$user['last_login_ip']) ?></span></td></tr>
        </table>
    </div>

    <div class="admin-card">
        <div class="admin-card__head"><h2>登录方式</h2></div>
        <div class="recovery-methods">
            <?php foreach ($methods as $method): ?>
            <span class="recovery-method<?= $method['present'] ? ' recovery-method--on' : '' ?>">
                <span class="recovery-method__name"><?= htmlspecialchars($method['label']) ?></span>
                <span class="recovery-method__state"><?= htmlspecialchars($methodState($method)) ?></span>
            </span>
            <?php endforeach; ?>
        </div>
        <p class="admin-cell-sub" style="margin-top:16px;"><?= htmlspecialchars(\Nomen\Src\RecoveryHelper::ASSIST_LABEL) ?>（不计入上面的数量）</p>
        <div class="recovery-methods" style="margin-top:8px;">
            <?php foreach ($assistMethods as $method): ?>
            <span class="recovery-method<?= $method['present'] ? ' recovery-method--on' : '' ?>">
                <span class="recovery-method__name"><?= htmlspecialchars($method['label']) ?></span>
                <span class="recovery-method__state"><?= htmlspecialchars($methodState($method)) ?></span>
            </span>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="admin-card">
    <div class="admin-card__head"><h2>已授权应用</h2></div>
    <?php if (!$authorizations): ?>
        <p class="admin-empty">这个用户还没有授权过任何应用。</p>
    <?php else: ?>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>应用</th><th>Client ID</th><th>Scopes</th><th>授权时间</th></tr></thead>
            <tbody>
                <?php foreach ($authorizations as $auth): ?>
                <tr>
                    <td><?= htmlspecialchars((string)($auth['client_name'] ?: '（应用已删除）')) ?></td>
                    <td class="admin-mono"><?= htmlspecialchars((string)$auth['client_id']) ?></td>
                    <td class="admin-mono"><?= htmlspecialchars((string)$auth['scopes']) ?></td>
                    <td class="admin-nowrap"><?= htmlspecialchars((string)$auth['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="admin-card">
    <div class="admin-card__head"><h2>恢复申诉历史</h2></div>
    <?php if (!$recoveryHistory): ?>
        <p class="admin-empty">这个用户没有提交过恢复申诉。</p>
    <?php else: ?>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>工单</th><th>状态</th><th>生日比对</th><th>性别比对</th><th>联系邮箱</th><th>提交时间</th></tr></thead>
            <tbody>
                <?php foreach ($recoveryHistory as $req): ?>
                <?php $st = $statusLabels[$req['status']] ?? [$req['status'], 'muted']; ?>
                <tr>
                    <td><a href="/admin/recovery/<?= (int)$req['id'] ?>">#<?= (int)$req['id'] ?></a></td>
                    <td><span class="admin-badge admin-badge--<?= $st[1] ?>"><?= htmlspecialchars($st[0]) ?></span></td>
                    <td><?= \Nomen\Controllers\AdminController::matchBadge($req['birth_date_match'] ?? null) ?></td>
                    <td><?= \Nomen\Controllers\AdminController::matchBadge($req['gender_match'] ?? null) ?></td>
                    <td><?= htmlspecialchars((string)$req['contact_email']) ?></td>
                    <td class="admin-nowrap"><?= htmlspecialchars((string)$req['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if (\Nomen\Src\Features::guardians()): ?>
<div class="admin-card">
    <div class="admin-card__head">
        <h2>家庭</h2>
    </div>

    <?php
    $memberStatusLabels = [
        'pending'     => '邀请中（家长还没加入）',
        'active'      => '已加入',
        'independent' => '已停止监护',
        'removed'     => '已退出',
    ];
    $groupStatusLabels = ['unclaimed' => '邀请中', 'active' => '已建立', 'released' => '已结束'];
    ?>

    <?php if ($membership): ?>
    <table class="admin-kv">
        <tr><th>家庭</th><td>#<?= (int)$membership['group_id'] ?>（<?= htmlspecialchars($groupStatusLabels[$membership['group_status']] ?? (string)$membership['group_status']) ?>）</td></tr>
        <tr><th>本人状态</th><td><?= htmlspecialchars($memberStatusLabels[$membership['status']] ?? (string)$membership['status']) ?><?= $membership['status'] === 'independent' && !empty($membership['independent_at']) ? '（' . htmlspecialchars((string)($membership['independent_reason'] ?? '')) . ' 于 ' . htmlspecialchars((string)$membership['independent_at']) . '）' : '' ?></td></tr>
        <tr><th>本人邮箱</th><td><?= htmlspecialchars((string)($user['email'] ?? '') ?: '—（收信走家长邮箱：' . htmlspecialchars((string)$guardianEmail) . '）') ?></td></tr>
        <tr><th>家长</th><td>
            <?php if (!$familyGuardians): ?>
                <span class="admin-cell-sub">还没有邀请家长</span>
            <?php else: ?>
                <?php foreach ($familyGuardians as $guardian): ?>
                    <div><?= htmlspecialchars((string)($guardian['username'] ?: $guardian['email'])) ?>
                        <span class="admin-cell-sub">· <?= htmlspecialchars((string)($guardian['relationship'] ?? '')) ?> · <?= $guardian['user_id'] ? '已加入' : '邀请中' ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </td></tr>
    </table>
    <?php elseif (!$ownedGroups): ?>
        <p class="admin-empty">不在任何家庭里，也不是家长。</p>
    <?php endif; ?>

    <?php foreach ($ownedGroups as $group): ?>
    <table class="admin-kv">
        <tr><th>作为家长</th><td>#<?= (int)$group['id'] ?>（<?= htmlspecialchars($groupStatusLabels[$group['status']] ?? (string)$group['status']) ?>）· 邮箱 <?= htmlspecialchars((string)($group['guardian_email'] ?? '')) ?></td></tr>
        <tr><th>孩子</th><td>
            <?php if (empty($ownedGroupsChildren[(int)$group['id']])): ?>
                <span class="admin-cell-sub">还没有孩子账号</span>
            <?php else: ?>
                <?php foreach ($ownedGroupsChildren[(int)$group['id']] as $child): ?>
                    <div>
                        <a href="/admin/users/<?= (int)$child['user_id'] ?>"><?= htmlspecialchars((string)$child['username']) ?></a>
                        <span class="admin-cell-sub">· <?= htmlspecialchars($memberStatusLabels[$child['status']] ?? (string)$child['status']) ?> · <?= !empty($child['own_email']) ? htmlspecialchars((string)$child['own_email']) : '没有自己的邮箱' ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </td></tr>
    </table>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="admin-card">
    <div class="admin-card__head">
        <h2>最近活动</h2>
        <a href="/admin/logs?actor_id=<?= (int)$user['id'] ?>" class="admin-card__more">查看全部<span class="icon icon-arrow-right"></span></a>
    </div>
    <?php if (!$recentLogs): ?>
        <p class="admin-empty">还没有记录。</p>
    <?php else: ?>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>时间</th><th>分类</th><th>动作</th><th>说明</th><th>变动</th><th>IP</th></tr></thead>
            <tbody>
                <?php foreach ($recentLogs as $log): ?>
                <tr>
                    <td class="admin-nowrap"><a href="/admin/logs/<?= (int)$log['id'] ?>"><?= htmlspecialchars((string)$log['created_at']) ?></a></td>
                    <td><?= htmlspecialchars(\Nomen\Src\ActivityLog::categoryLabel((string)$log['category'])) ?></td>
                    <td><?= htmlspecialchars(\Nomen\Src\ActivityLog::actionLabel((string)$log['action'])) ?></td>
                    <td class="admin-cell-summary"><?= htmlspecialchars((string)$log['summary']) ?></td>
                    <td class="admin-cell-changes"><?= \Nomen\Controllers\AdminController::changesHtml($log['changes'] ?? null) ?></td>
                    <td class="admin-mono"><?= htmlspecialchars((string)$log['ip_address']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
