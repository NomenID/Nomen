<?php
/** @var array $pagination ['page' => int, 'pages' => int, 'total' => int] */
/** @var array $query      需要保留在翻页链接里的筛选参数 */
$pgPages = (int)($pagination['pages'] ?? 1);
$pgPage  = (int)($pagination['page'] ?? 1);
$pgTotal = (int)($pagination['total'] ?? 0);

$pgLink = static fn(int $p): string => '?' . http_build_query(array_merge($query ?? [], ['page' => $p]));

$pgStart = max(1, $pgPage - 2);
$pgEnd   = min($pgPages, $pgStart + 4);
$pgStart = max(1, $pgEnd - 4);
?>
<div class="admin-pagination">
    <span class="admin-pagination__info">共 <?= $pgTotal ?> 条 · 第 <?= $pgPage ?> / <?= $pgPages ?> 页</span>
    <?php if ($pgPages > 1): ?>
    <div class="admin-pagination__links">
        <?php if ($pgPage > 1): ?><a href="<?= htmlspecialchars($pgLink($pgPage - 1)) ?>">上一页</a><?php endif; ?>
        <?php for ($pgI = $pgStart; $pgI <= $pgEnd; $pgI++): ?>
            <?php if ($pgI === $pgPage): ?>
                <span class="admin-pagination__current"><?= $pgI ?></span>
            <?php else: ?>
                <a href="<?= htmlspecialchars($pgLink($pgI)) ?>"><?= $pgI ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($pgPage < $pgPages): ?><a href="<?= htmlspecialchars($pgLink($pgPage + 1)) ?>">下一页</a><?php endif; ?>
    </div>
    <?php endif; ?>
</div>
