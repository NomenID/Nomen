<?php
/** @var array $stats
 *  @var array $recentLogs
 *  @var array $admin
 *  @var array $redis
 */
$cards = [
    ['label' => '注册用户', 'value' => (int)$stats['total_users'], 'hint' => '今日新增 ' . (int)$stats['new_users_today']],
    ['label' => '已验证邮箱', 'value' => (int)$stats['verified_users'], 'hint' => '未验证 ' . ((int)$stats['total_users'] - (int)$stats['verified_users'])],
    ['label' => '待处理恢复工单', 'value' => (int)$stats['pending_recovery'], 'hint' => $stats['pending_recovery'] > 0 ? '有申诉等待审核' : '暂无待处理', 'alert' => $stats['pending_recovery'] > 0],
    ['label' => '已通过待完成', 'value' => (int)$stats['approved_recovery'], 'hint' => '已发链接、用户尚未完成修复'],
    ['label' => '管理员', 'value' => (int)$stats['admins'], 'hint' => '含你自己'],
    ['label' => '已禁用账户', 'value' => (int)$stats['disabled_users'], 'hint' => '已停用'],
    ['label' => '30 天活跃设备', 'value' => (int)$stats['active_devices'], 'hint' => '最近 30 天有活动'],
    ['label' => '在架应用', 'value' => (int)$stats['active_apps'], 'hint' => '累计授权 ' . (int)$stats['total_authorizations'] . ' 次'],
];

if (\Nomen\Src\Features::guardians()) {
    $cards[] = ['label' => '邀请中的家庭', 'value' => (int)$stats['unclaimed_families'], 'hint' => $stats['unclaimed_families'] > 0 ? '等家长用账号加入' : '都处理完了', 'alert' => $stats['unclaimed_families'] > 0];
    $cards[] = ['label' => '家庭里的孩子', 'value' => (int)$stats['managed_children'], 'hint' => '有家长一起用的账号'];
}
?>

<div class="admin-header">
    <h1>概览</h1>
    <p>站点状态、待处理事项和最近活动。</p>
</div>

<?php if (!empty($installerPresent)): ?>
<div class="admin-card" style="border-color:#9e6a03">
    <div class="admin-card__head"><h2>安装向导还没删</h2></div>
    <p style="margin:0">
        <code>public/nomen-install/</code> 还在服务器上。装完应删除这个目录，否则任何人都能重新打开安装页。
    </p>
</div>
<?php endif; ?>

<div class="admin-card">
    <div class="admin-card__head"><h2>关键数据</h2></div>
    <div class="admin-stat-grid">
        <?php foreach ($cards as $card): ?>
        <div class="admin-stat<?= !empty($card['alert']) ? ' admin-stat--alert' : '' ?>">
            <div class="admin-stat__value"><?= $card['value'] ?></div>
            <div class="admin-stat__label"><?= htmlspecialchars($card['label']) ?></div>
            <div class="admin-stat__hint"><?= htmlspecialchars($card['hint']) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="admin-card">
    <div class="admin-card__head">
        <h2>插件</h2>
        <a href="/special/plugins">详情</a>
    </div>
    <?php
    $loadedPlugins = \Nomen\Src\Plugins::loaded();
    $bundledPlugins = \Nomen\Src\Plugins::all();
    $missingExt = array_values(array_filter(
        ['gd', 'fileinfo', 'redis'],
        static fn(string $ext): bool => !extension_loaded($ext)
    ));
    ?>
    <p>已启用 <?= count($loadedPlugins) ?> 个；随包带了 <?= count($bundledPlugins) ?> 个：<?= htmlspecialchars(implode('、', array_column($bundledPlugins, 'name'))) ?></p>
    <?php if ($missingExt !== []): ?>
        <p>缺 PHP 扩展 <?= htmlspecialchars(implode('、', $missingExt)) ?>，相关功能会降级，见 <a href="/special/plugins">插件页</a></p>
    <?php endif; ?>
</div>

<div class="admin-card">
    <div class="admin-card__head">
        <h2>Redis</h2>
        <?php if (!$redis['ok']): ?>
            <span class="admin-badge admin-badge--danger">不可用</span>
        <?php elseif ($redis['warning']): ?>
            <span class="admin-badge admin-badge--warning">内存偏紧</span>
        <?php else: ?>
            <span class="admin-badge admin-badge--success">正常</span>
        <?php endif; ?>
    </div>
    <table class="admin-kv">
        <?php if (!$redis['ok']): ?>
            <tr><th>错误</th><td><?= htmlspecialchars($redis['error']) ?></td></tr>
            <tr><th>影响</th><td>限流会静默放行、登录状态可能丢失。先看 Redis 进程和整机内存。</td></tr>
        <?php else: ?>
            <tr><th>版本</th><td><?= htmlspecialchars($redis['version']) ?></td></tr>
            <tr><th>内存</th><td><?= htmlspecialchars($redis['used']) ?> / <?= htmlspecialchars($redis['max']) ?><?= $redis['percent'] === null ? '' : '（' . (int)$redis['percent'] . '%）' ?></td></tr>
            <tr><th>淘汰策略</th><td><?= htmlspecialchars($redis['policy']) ?><?= $redis['policy'] === 'noeviction' ? '（不做淘汰，写满即报错）' : '' ?></td></tr>
            <tr><th>当前库键数</th><td><?= (int)$redis['keys'] ?></td></tr>
            <tr><th>会话索引</th><td><?= $redis['index'] ? '已回填' : '未回填，踢其他设备时会扫全库' ?></td></tr>
        <?php endif; ?>
    </table>
</div>

<div class="admin-card">
    <div class="admin-card__head">
        <h2>最近活动</h2>
        <a href="/admin/logs" class="admin-card__more">查看全部<span class="icon icon-arrow-right"></span></a>
    </div>
    <?php if (!$recentLogs): ?>
        <p class="admin-empty">还没有任何记录。</p>
    <?php else: ?>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr><th>时间</th><th>操作者</th><th>分类</th><th>动作</th><th>说明</th></tr>
            </thead>
            <tbody>
                <?php foreach ($recentLogs as $log): ?>
                <tr>
                    <td class="admin-nowrap"><a href="/admin/logs/<?= (int)$log['id'] ?>"><?= htmlspecialchars($log['created_at']) ?></a></td>
                    <td>
                        <?php if ($log['actor_id']): ?>
                            <a href="/admin/users/<?= (int)$log['actor_id'] ?>"><?= htmlspecialchars((string)$log['actor_username']) ?></a>
                        <?php else: ?>
                            <?= htmlspecialchars((string)$log['actor_username']) ?>
                        <?php endif; ?>
                        <span class="admin-badge admin-badge--muted"><?= htmlspecialchars(\Nomen\Src\ActivityLog::actorLabel($log)) ?></span>
                    </td>
                    <td><?= htmlspecialchars(\Nomen\Src\ActivityLog::categoryLabel((string)$log['category'])) ?></td>
                    <td><?= htmlspecialchars(\Nomen\Src\ActivityLog::actionLabel((string)$log['action'])) ?></td>
                    <td class="admin-cell-summary"><?= htmlspecialchars((string)$log['summary']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
