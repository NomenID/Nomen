<div class="container special-page<?= !empty($pageClass) ? ' ' . htmlspecialchars((string)$pageClass) : '' ?>">
    <?php if (empty($hideTitle)): ?>
    <h1 class="special-page__title"><?= htmlspecialchars($title) ?></h1>
    <?php endif; ?>
    <div class="special-page__body">
        <?= $body ?>
    </div>
</div>
