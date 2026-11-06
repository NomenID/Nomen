<?php
/** @var array $log */

use Nomen\Src\ActivityLog;
use Nomen\Controllers\AdminController;

$actorName = (string)($log['actor_username'] ?? '');
$actorName = $actorName !== '' ? $actorName : ActivityLog::actorLabel($log);
$actorUrl = AdminController::actorUrl($log);

$targetName = (string)($log['target_name'] ?? '');
$targetId = (string)($log['target_id'] ?? '');
$targetText = $targetName !== '' ? $targetName : ($targetId !== '' ? '#' . $targetId : '');
$targetUrl = AdminController::targetUrl($log);
$targetType = (string)($log['target_type'] ?? '');

$targetFilter = $targetName !== '' ? $targetName : $targetId;
?>

<div class="admin-header admin-header--split">
    <div>
        <h1>日志 #<?= (int)$log['id'] ?></h1>
        <p><?= htmlspecialchars((string)$log['created_at']) ?> · <?= htmlspecialchars(ActivityLog::categoryLabel((string)$log['category'])) ?></p>
    </div>
    <div class="admin-btn-row">
        <a href="/admin/logs" class="admin-btn">返回日志列表</a>
    </div>
</div>

<div class="admin-card">
    <div class="admin-card__head"><h2>基本信息</h2></div>
    <table class="admin-kv">
        <tr>
            <th>操作</th>
            <td>
                <strong><?= htmlspecialchars(ActivityLog::actionLabel((string)$log['action'])) ?></strong>
                <span class="admin-cell-sub admin-mono"><?= htmlspecialchars((string)$log['action']) ?></span>
            </td>
        </tr>
        <tr>
            <th>操作者</th>
            <td>
                <?php if ($actorUrl !== null): ?>
                    <a href="<?= htmlspecialchars($actorUrl) ?>"><?= htmlspecialchars($actorName) ?></a>
                <?php else: ?>
                    <?= htmlspecialchars($actorName) ?>
                <?php endif; ?>
                <span class="admin-badge admin-badge--muted"><?= htmlspecialchars(ActivityLog::actorLabel($log)) ?></span>
                <?php if ((int)($log['actor_id'] ?? 0) > 0): ?>
                    <a href="/admin/logs?actor_id=<?= (int)$log['actor_id'] ?>" class="admin-cell-sub">该用户的日志</a>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>日志类型</th>
            <td>
                <?= htmlspecialchars(ActivityLog::categoryLabel((string)$log['category'])) ?>
                <a href="/admin/logs?category=<?= urlencode((string)$log['category']) ?>" class="admin-cell-sub">只看这一类</a>
            </td>
        </tr>
        <tr>
            <th>目标</th>
            <td>
                <?php if ($targetText !== ''): ?>
                    <?php if ($targetUrl !== null): ?>
                        <a href="<?= htmlspecialchars($targetUrl) ?>"><?= htmlspecialchars($targetText) ?></a>
                    <?php else: ?>
                        <?= htmlspecialchars($targetText) ?>
                    <?php endif; ?>
                    <?php if ($targetType !== ''): ?>
                        <span class="admin-badge admin-badge--muted"><?= htmlspecialchars(AdminController::targetTypeLabel($targetType)) ?></span>
                    <?php endif; ?>
                    <?php if ($targetId !== ''): ?>
                        <span class="admin-cell-sub admin-mono">ID <?= htmlspecialchars($targetId) ?></span>
                    <?php endif; ?>
                    <?php if ($targetFilter !== ''): ?>
                        <a href="/admin/logs?target=<?= urlencode($targetFilter) ?>" class="admin-cell-sub">该目标的日志</a>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="admin-cell-sub">没有指定目标</span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>说明</th>
            <td><?= $log['summary'] !== null && $log['summary'] !== '' ? htmlspecialchars((string)$log['summary']) : '<span class="admin-cell-sub">—</span>' ?></td>
        </tr>
        <tr>
            <th>来源</th>
            <td>
                <?php if (!empty($log['ip_address'])): ?>
                    <span class="admin-mono"><?= htmlspecialchars((string)$log['ip_address']) ?></span>
                    <a href="/admin/logs?actor=<?= urlencode((string)$log['ip_address']) ?>" class="admin-cell-sub">这个 IP 的日志</a>
                <?php else: ?>
                    <span class="admin-cell-sub">没有记录 IP</span>
                <?php endif; ?>
                <?php if (!empty($log['device_name'])): ?>
                    <div class="admin-cell-sub"><?= htmlspecialchars((string)$log['device_name']) ?></div>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>User-Agent</th>
            <td class="admin-mono"><?= !empty($log['user_agent']) ? htmlspecialchars((string)$log['user_agent']) : '<span class="admin-cell-sub">—</span>' ?></td>
        </tr>
    </table>
</div>

<div class="admin-card">
    <div class="admin-card__head"><h2>变更明细</h2></div>
    <?php if (!empty($log['changes'])): ?>
        <?= AdminController::changesHtml((string)$log['changes'], true) ?>
    <?php else: ?>
        <p class="admin-empty">这条记录没有字段变动。</p>
    <?php endif; ?>
</div>
