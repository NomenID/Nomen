<?php
/** @var array $request
 *  @var array $user
 *  @var array $recentLogs
 *  @var int   $linkTtlDays
 */
$statusBadges = [
    'pending'   => ['待审核', 'warning'],
    'approved'  => ['已通过待完成', 'info'],
    'rejected'  => ['已拒绝', 'danger'],
    'completed' => ['已完成', 'success'],
];
$badge = $statusBadges[$request['status']] ?? [$request['status'], 'muted'];
$genderLabels = [
    'female'            => '女性',
    'male'              => '男性',
    'non-binary'        => '非二元性别',
    'prefer-not-to-say' => '我宁愿不说',
];
$tokenActive = !empty($request['recovery_token_hash'])
    && empty($request['recovery_token_used_at'])
    && strtotime((string)$request['recovery_token_expires_at']) > time();
?>

<div class="admin-header">
    <h1>工单 #<?= (int)$request['id'] ?> <span class="admin-badge admin-badge--<?= $badge[1] ?>"><?= htmlspecialchars($badge[0]) ?></span></h1>
    <p><a href="/admin/recovery"><span class="icon icon-arrow-left"></span> 返回工单列表</a></p>
</div>

<div class="admin-grid-2">
    <div class="admin-card">
        <div class="admin-card__head"><h2>申请人</h2></div>
        <table class="admin-kv">
            <tr><th>账号</th><td><a href="/admin/users/<?= (int)$request['user_id'] ?>"><?= htmlspecialchars((string)$request['username']) ?></a> <span class="admin-cell-sub">#<?= (int)$request['user_id'] ?></span></td></tr>
            <tr><th>账号邮箱</th><td><?= htmlspecialchars((string)$request['account_email']) ?>
                <?= isset($user['email_verified']) && empty($user['email_verified']) ? '<span class="admin-badge admin-badge--warning">未验证</span>' : '' ?></td></tr>
            <tr><th>联系邮箱</th><td><strong><?= htmlspecialchars((string)$request['contact_email']) ?></strong></td></tr>
            <tr><th>提交时间</th><td><?= htmlspecialchars((string)$request['created_at']) ?></td></tr>
            <tr><th>IP 地址</th><td class="admin-mono"><?= htmlspecialchars((string)$request['ip_address']) ?></td></tr>
            <tr><th>User-Agent</th><td class="admin-cell-summary admin-mono"><?= htmlspecialchars((string)$request['user_agent']) ?></td></tr>
        </table>
    </div>

    <div class="admin-card">
        <div class="admin-card__head"><h2>本人确认比对</h2></div>
        <table class="admin-kv">
            <tr>
                <th>填写的出生日期</th>
                <td>
                    <?= htmlspecialchars((string)$request['birth_date_input']) ?>
                    <?= \Nomen\Controllers\AdminController::matchBadge($request['birth_date_match'] ?? null) ?>
                </td>
            </tr>
            <tr>
                <th>填写的性别</th>
                <td>
                    <?= htmlspecialchars($genderLabels[$request['gender_input'] ?? ''] ?? (string)$request['gender_input']) ?>
                    <?= \Nomen\Controllers\AdminController::matchBadge($request['gender_match'] ?? null) ?>
                </td>
            </tr>
            <tr><th>账号记录</th><td class="admin-cell-sub">生日 <?= htmlspecialchars((string)($user['birth_date'] ?? '—')) ?> · 性别 <?= htmlspecialchars($genderLabels[$user['gender'] ?? ''] ?? '—') ?></td></tr>
        </table>
        <p class="form-hint" style="margin-top:12px;">「账号无记录」表示该用户注册时没留下这项信息，不是填错。</p>
    </div>
</div>

<div class="admin-card">
    <div class="admin-card__head"><h2>申诉描述</h2></div>
    <pre class="admin-quote"><?= htmlspecialchars((string)$request['description']) ?></pre>
</div>

<div class="admin-card">
    <div class="admin-card__head">
        <h2>证明材料</h2>
        <?php if ($attachments): ?>
            <span class="admin-cell-sub">共 <?= count($attachments) ?> 张 · 点图看原图</span>
        <?php endif; ?>
    </div>
    <?php if (!$attachments): ?>
        <p class="admin-empty">申请人没有上传证明材料。</p>
    <?php else: ?>
        <div class="admin-attachments">
            <?php foreach ($attachments as $attIndex => $attachment): ?>
            <a class="admin-attachment" href="<?= htmlspecialchars($attachment['url']) ?>" target="_blank" rel="noopener">
                <img src="<?= htmlspecialchars($attachment['url']) ?>" alt="证明材料 <?= (int)$attIndex + 1 ?>" loading="lazy">
                <span class="admin-attachment__meta">
                    <?= (int)$attIndex + 1 ?> · <?= htmlspecialchars(\Nomen\Src\RecoveryHelper::formatBytes((int)$attachment['size'])) ?>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
        <p class="form-hint" style="margin-top:14px;">
            文件名是随机串，只有这条工单里链接得到；链接可被转发，审核完如需清理请告知开发者。
        </p>
    <?php endif; ?>
</div>

<div class="admin-card">
    <div class="admin-card__head"><h2>审核要点</h2></div>
    <ul class="admin-checklist">
        <li>申请人写的注册邮箱，是否与账号记录的邮箱一致</li>
        <li>他说的常用登录地点 / 网络，是否与「IP 地址」或下方日志里的历史 IP 吻合</li>
        <li>他提到的已授权应用，是否真的挂在该账号名下（到用户详情页的「已授权应用」核对）</li>
        <li>出生日期与性别两项的静默比对结果（见上方那张卡）</li>
        <li>下方最近活动里的登录 / 改密 / 改邮箱 / 设备记录，是否与他的说法对得上</li>
        <li>证明材料里的内容，是否与账号记录、申诉描述一致（截图里出现的时间 / 账号名 / 设备等）</li>
    </ul>
    <p class="form-hint" style="margin-top:14px;">
        以上都对不上或无从印证时，不要签发恢复链接 —— 先通过联系邮箱回信要求补充材料。
    </p>
</div>

<?php if (!empty($request['admin_note']) || !empty($request['reviewed_at'])): ?>
<div class="admin-card">
    <div class="admin-card__head"><h2>审核记录</h2></div>
    <table class="admin-kv">
        <tr><th>审核时间</th><td><?= htmlspecialchars((string)$request['reviewed_at']) ?></td></tr>
        <tr><th>审核人</th><td>
            <?php if (!empty($request['reviewed_by'])): ?>
                <a href="/admin/users/<?= (int)$request['reviewed_by'] ?>">#<?= (int)$request['reviewed_by'] ?></a>
            <?php else: ?>—<?php endif; ?>
        </td></tr>
        <tr><th>内部备注</th><td><pre class="admin-quote"><?= htmlspecialchars((string)$request['admin_note']) ?></pre></td></tr>
    </table>
</div>
<?php endif; ?>

<div class="admin-card">
    <div class="admin-card__head"><h2>恢复链接</h2></div>
    <table class="admin-kv">
        <tr><th>状态</th><td>
            <?php if ($tokenActive): ?>
                <span class="admin-badge admin-badge--success">有效</span>
                有效期至 <?= htmlspecialchars((string)$request['recovery_token_expires_at']) ?>
            <?php elseif (!empty($request['recovery_token_used_at'])): ?>
                <span class="admin-badge admin-badge--muted">已使用</span>
                <?= htmlspecialchars((string)$request['recovery_token_used_at']) ?>
            <?php else: ?>
                <span class="admin-badge admin-badge--muted">未签发 / 已失效</span>
            <?php endif; ?>
        </td></tr>
        <?php if (!empty($request['completed_at'])): ?>
        <tr><th>完成修复</th><td><?= htmlspecialchars((string)$request['completed_at']) ?></td></tr>
        <?php endif; ?>
        <tr><th>说明</th><td class="admin-cell-sub">链接一次性使用、有效期 <?= (int)$linkTtlDays ?> 天；重新签发会让旧链接立刻失效。</td></tr>
    </table>

    <?php if ($request['status'] !== 'completed'): ?>
    <form method="POST" action="/admin/recovery/approve" class="admin-review-form">
        <?= \Nomen\Src\Csrf::field() ?>
        <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
        <div class="form-group">
            <label for="approve-note">内部备注（不会发给用户）</label>
            <input type="text" id="approve-note" name="admin_note" maxlength="500" placeholder="例如：生日与性别均匹配，IP 归属地一致">
        </div>
        <div class="admin-btn-row">
            <button type="submit" class="admin-btn admin-btn--primary">
                <?= $request['status'] === 'approved' ? '重新签发恢复链接' : '通过申诉并发送恢复链接' ?>
            </button>
        </div>
    </form>

    <?php if (!empty($request['recovery_token_hash'])): ?>
    <form method="POST" action="/admin/recovery/resend" class="admin-review-form">
        <?= \Nomen\Src\Csrf::field() ?>
        <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
        <div class="admin-btn-row">
            <button type="submit" class="admin-btn">仅重新发送链接（不改备注）</button>
        </div>
    </form>
    <?php endif; ?>

    <form method="POST" action="/admin/recovery/reject" class="admin-review-form">
        <?= \Nomen\Src\Csrf::field() ?>
        <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
        <div class="form-group">
            <label for="reject-note">拒绝原因（记录在工单内，不会发给用户）</label>
            <input type="text" id="reject-note" name="admin_note" maxlength="500" required placeholder="必填，便于日后复核">
        </div>
        <div class="admin-btn-row">
            <button type="submit" class="admin-btn admin-btn--danger">拒绝申诉</button>
        </div>
    </form>
    <?php endif; ?>
</div>

<div class="admin-card">
    <div class="admin-card__head">
        <h2>该账号的最近活动</h2>
        <a href="/admin/logs?actor_id=<?= (int)$request['user_id'] ?>" class="admin-card__more">查看全部<span class="icon icon-arrow-right"></span></a>
    </div>
    <?php if (!$recentLogs): ?>
        <p class="admin-empty">还没有记录。</p>
    <?php else: ?>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>时间</th><th>分类</th><th>动作</th><th>说明</th><th>IP</th></tr></thead>
            <tbody>
                <?php foreach ($recentLogs as $log): ?>
                <tr>
                    <td class="admin-nowrap"><?= htmlspecialchars((string)$log['created_at']) ?></td>
                    <td><?= htmlspecialchars(\Nomen\Src\ActivityLog::categoryLabel((string)$log['category'])) ?></td>
                    <td><?= htmlspecialchars(\Nomen\Src\ActivityLog::actionLabel((string)$log['action'])) ?></td>
                    <td class="admin-cell-summary"><?= htmlspecialchars((string)$log['summary']) ?></td>
                    <td class="admin-mono"><?= htmlspecialchars((string)$log['ip_address']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
