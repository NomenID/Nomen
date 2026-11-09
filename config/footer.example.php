<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

/**
 * Custom footer example.
 *
 * Copy to config/footer.php and edit. Available variables:
 *   $brand  site info (name, logo, copyright, ...)
 *   $links  footer links from config/brand.php, plugins and the footer.links hook
 *   $layout layout name (default / app / admin)
 *
 * Return the full <footer> markup. Plugins can still adjust the result with
 * the footer.html hook.
 */
?>
<footer class="footer">
    <div class="footer-inner">
        <span>&copy; <?= date('Y') ?> <?= htmlspecialchars((string)$brand['name']) ?></span>
        <nav class="footer-links">
            <?php foreach ($links as $link): ?>
                <a href="<?= htmlspecialchars((string)($link['url'] ?? '#')) ?>"><?= htmlspecialchars(t((string)($link['label'] ?? ''))) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
</footer>
