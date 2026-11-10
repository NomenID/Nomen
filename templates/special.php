<?php /*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */ ?>
<div class="container special-page<?= !empty($pageClass) ? ' ' . htmlspecialchars((string)$pageClass) : '' ?>">
    <?php if (empty($hideTitle)): ?>
    <h1 class="special-page__title"><?= htmlspecialchars($title) ?></h1>
    <?php endif; ?>
    <div class="special-page__body">
        <?= $body ?>
    </div>
</div>
