<?php /*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */ ?>
<div class="container special-page<?= !empty($pageClass) ? ' ' . htmlspecialchars((string)$pageClass) : '' ?>">
    <?php if (empty($hideTitle)): ?>
    <h1 class="special-page__title"><?= htmlspecialchars($title) ?></h1>
    <?php endif; ?>
    <div class="special-page__body">
        <?= $body ?>
    </div>
</div>
