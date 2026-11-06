<?php
/** @var array $page */
/** @var string $body */
?>
<div class="admin-header">
    <h1><?= htmlspecialchars((string)($page['title'] ?? '')) ?></h1>
    <?php if (!empty($page['description'])): ?>
        <p><?= htmlspecialchars((string)$page['description']) ?></p>
    <?php endif; ?>
</div>

<div class="admin-plugin-page">
    <?= $body ?>
</div>
