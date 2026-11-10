<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

/** @var array $page @var string $html */
$extraCss = ['/css/home.css?v=h7m2q9xw'];
?>
<article class="home-doc">
    <header class="home-doc__head">
        <h1><?= htmlspecialchars((string)$page['title']) ?></h1>
        <?php if ((string)$page['summary'] !== ''): ?>
            <p><?= htmlspecialchars((string)$page['summary']) ?></p>
        <?php endif; ?>
    </header>
    <div class="home-doc__body">
        <?= $html ?>
    </div>
</article>
