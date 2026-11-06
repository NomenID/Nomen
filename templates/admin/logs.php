<?php
/** @var array $logs
 *  @var array $filter
 *  @var array $pagination
 *  @var array $query
 */
use Nomen\Src\ActivityLog;
use Nomen\Controllers\AdminController;

$dirNewer = ($filter['dir'] ?? '') === 'newer';
$exportQuery = http_build_query($query);
$toggleDir = static function (string $dir) use ($query): string {
    $params = array_filter(array_merge($query, ['dir' => $dir]));
    return $params ? '?' . http_build_query($params) : '';
};
?>

<div class="admin-header admin-header--split">
    <div>
        <h1>活动日志</h1>
        <p>共 <?= (int)$pagination['total'] ?> 条记录 · 第 <?= (int)$pagination['page'] ?> / <?= (int)$pagination['pages'] ?> 页</p>
    </div>
    <div class="admin-btn-row">
        <a href="/admin/logs/export<?= $exportQuery !== '' ? '?' . htmlspecialchars($exportQuery) : '' ?>" class="admin-btn">导出 CSV</a>
    </div>
</div>

<div class="admin-card">
    <form method="GET" action="/admin/logs" class="admin-filter-form">
        <div class="admin-filter-grid">
            <div>
                <label for="f-category">日志类型</label>
                <select id="f-category" name="category">
                    <option value="">全部</option>
                    <?php foreach (ActivityLog::CATEGORIES as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= ($filter['category'] ?? '') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="f-actor">执行者</label>
                <input type="search" id="f-actor" name="actor" value="<?= htmlspecialchars((string)($filter['actor'] ?? '')) ?>" placeholder="用户名或 IP（精确）">
            </div>
            <div>
                <label for="f-target">目标</label>
                <input type="search" id="f-target" name="target" value="<?= htmlspecialchars((string)($filter['target'] ?? '')) ?>" placeholder="页面地址 / 用户名 / 应用 / ID">
            </div>
            <div>
                <label for="f-from">起始日期</label>
                <input type="date" id="f-from" name="from" value="<?= htmlspecialchars((string)($filter['from'] ?? '')) ?>">
            </div>
            <div>
                <label for="f-to">结束日期</label>
                <input type="date" id="f-to" name="to" value="<?= htmlspecialchars((string)($filter['to'] ?? '')) ?>">
            </div>
            <div>
                <label for="f-q">关键字</label>
                <input type="search" id="f-q" name="q" value="<?= htmlspecialchars((string)($filter['keyword'] ?? '')) ?>" placeholder="说明 / 目标名称 / 操作者">
            </div>
            <div>
                <label for="f-actor-type">操作者类型</label>
                <select id="f-actor-type" name="actor_type">
                    <option value="">全部</option>
                    <option value="user"   <?= ($filter['actor_type'] ?? '') === 'user'   ? 'selected' : '' ?>>用户</option>
                    <option value="admin"  <?= ($filter['actor_type'] ?? '') === 'admin'  ? 'selected' : '' ?>>管理员</option>
                    <option value="system" <?= ($filter['actor_type'] ?? '') === 'system' ? 'selected' : '' ?>>系统</option>
                </select>
            </div>
            <div>
                <label for="f-action">动作</label>
                <select id="f-action" name="action">
                    <option value="">全部</option>
                    <?php foreach (ActivityLog::ACTIONS as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= ($filter['action'] ?? '') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?>（<?= htmlspecialchars($key) ?>）</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="f-actor-id">用户 ID</label>
                <input type="number" id="f-actor-id" name="actor_id" min="1" value="<?= htmlspecialchars((string)($filter['actor_id'] ?? '')) ?>" placeholder="精确匹配">
            </div>
            <div>
                <label for="f-dir">时间顺序</label>
                <select id="f-dir" name="dir">
                    <option value="" <?= !$dirNewer ? 'selected' : '' ?>>最新在前</option>
                    <option value="newer" <?= $dirNewer ? 'selected' : '' ?>>最早在前</option>
                </select>
            </div>
        </div>
        <div class="admin-btn-row">
            <button type="submit" class="admin-btn admin-btn--primary">筛选</button>
            <a href="/admin/logs" class="admin-btn">重置</a>
        </div>
    </form>

    <div class="admin-log-bar">
        <span>按时间<?= $dirNewer ? '正序' : '倒序' ?>显示</span>
        <span>
            <a href="/admin/logs<?= htmlspecialchars($toggleDir('')) ?>">最新在前</a>
            ·
            <a href="/admin/logs<?= htmlspecialchars($toggleDir('newer')) ?>">最早在前</a>
        </span>
    </div>

    <?php if (!$logs): ?>
        <p class="admin-empty">没有匹配的记录。</p>
    <?php else: ?>
    <ul class="admin-log-list">
        <?php foreach ($logs as $log): ?>
        <?php
        $actorName = (string)($log['actor_username'] ?? '');
        $actorName = $actorName !== '' ? $actorName : ActivityLog::actorLabel($log);
        $actorUrl = AdminController::actorUrl($log);
        $isAdminActor = ($log['actor_type'] ?? '') === 'admin';

        $targetName = (string)($log['target_name'] ?? '');
        $targetId = (string)($log['target_id'] ?? '');
        $targetText = $targetName !== '' ? $targetName : ($targetId !== '' ? '#' . $targetId : '');
        $targetUrl = AdminController::targetUrl($log);
        $targetType = (string)($log['target_type'] ?? '');
        ?>
        <li class="admin-log" id="log-<?= (int)$log['id'] ?>">
            <div class="admin-log__time">
                <a href="/admin/logs/<?= (int)$log['id'] ?>"><?= htmlspecialchars((string)$log['created_at']) ?></a>
                <span class="admin-log__id">#<?= (int)$log['id'] ?></span>
            </div>
            <div class="admin-log__main">
                <div class="admin-log__line">
                    <span class="admin-log__actor">
                        <?php if ($actorUrl !== null): ?>
                            <a href="<?= htmlspecialchars($actorUrl) ?>"><?= htmlspecialchars($actorName) ?></a>
                        <?php else: ?>
                            <?= htmlspecialchars($actorName) ?>
                        <?php endif; ?>
                        <?php if ($isAdminActor): ?><span class="admin-badge admin-badge--info">管理员</span><?php endif; ?>
                    </span>
                    <span class="admin-log__action"><?= htmlspecialchars(ActivityLog::actionLabel((string)$log['action'])) ?></span>
                    <?php if ($targetText !== ''): ?>
                        <span class="admin-log__target">
                            <?php if ($targetUrl !== null): ?>
                                <a href="<?= htmlspecialchars($targetUrl) ?>"><?= htmlspecialchars($targetText) ?></a>
                            <?php else: ?>
                                <?= htmlspecialchars($targetText) ?>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($log['summary'])): ?>
                    <div class="admin-log__summary"><?= htmlspecialchars((string)$log['summary']) ?></div>
                <?php endif; ?>

                <div class="admin-log__meta">
                    <span><?= htmlspecialchars(ActivityLog::categoryLabel((string)$log['category'])) ?></span>
                    <?php if ($targetType !== ''): ?>
                        <span><?= htmlspecialchars(AdminController::targetTypeLabel($targetType)) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($log['ip_address'])): ?>
                        <span class="admin-mono"><?= htmlspecialchars((string)$log['ip_address']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($log['device_name'])): ?>
                        <span><?= htmlspecialchars((string)$log['device_name']) ?></span>
                    <?php endif; ?>
                    <a href="/admin/logs/<?= (int)$log['id'] ?>">详情</a>
                </div>

                <?php if (!empty($log['changes'])): ?>
                    <div class="admin-log__diff"><?= AdminController::changesHtml((string)$log['changes']) ?></div>
                <?php endif; ?>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>

    <?php require __DIR__ . '/pagination.php'; ?>
    <?php endif; ?>
</div>
