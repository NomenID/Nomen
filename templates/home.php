<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

/** @var array $home @var array $brand @var array $urls @var array $links */
$title = $home['title'] !== '' ? $home['title'] : (string)$brand['name'];
$extraCss = ['/css/home.css?v=h7m2q9xw'];

$primary = [
    'label' => $home['primary_label'] !== '' ? $home['primary_label'] : $urls['primary']['label'],
    'href'  => $home['primary_url'] !== '' ? $home['primary_url'] : $urls['primary']['href'],
];
$secondary = [
    'label' => $home['secondary_label'] !== '' ? $home['secondary_label'] : $urls['secondary']['label'],
    'href'  => $home['secondary_url'] !== '' ? $home['secondary_url'] : $urls['secondary']['href'],
];
?>
<section class="home-hero">
    <div class="home-hero__inner">
        <div class="home-hero__copy">
            <h1 class="home-hero__title"><?= htmlspecialchars($title) ?></h1>
            <?php if ($home['subtitle'] !== ''): ?>
                <p class="home-hero__line"><?= htmlspecialchars($home['subtitle']) ?></p>
            <?php endif; ?>
            <div class="home-hero__actions">
                <a href="<?= htmlspecialchars($primary['href']) ?>" class="btn btn-primary btn-lg"><?= htmlspecialchars($primary['label']) ?></a>
                <a href="<?= htmlspecialchars($secondary['href']) ?>" class="btn btn-ghost btn-lg"><?= htmlspecialchars($secondary['label']) ?></a>
            </div>
            <?php if ($links): ?>
                <nav class="home-hero__links" aria-label="<?= t('More') ?>">
                    <?php foreach ($links as $link): ?>
                        <a href="<?= htmlspecialchars((string)$link['href']) ?>"><?= htmlspecialchars((string)$link['label']) ?></a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>
        </div>

        <div class="home-hero__mark" aria-hidden="true">
            <svg viewBox="0 0 360 360" role="presentation" focusable="false">
                <g class="home-mark__orbits">
                    <circle cx="180" cy="180" r="88" />
                    <circle cx="180" cy="180" r="140" />
                </g>
                <g class="home-mark__links">
                    <path d="M180 180 L96 96" />
                    <path d="M180 180 L268 104" />
                    <path d="M180 180 L286 246" />
                    <path d="M180 180 L118 268" />
                    <path d="M180 180 L62 202" />
                </g>
                <g class="home-mark__nodes">
                    <circle cx="96" cy="96" r="4" />
                    <circle cx="268" cy="104" r="4" />
                    <circle cx="286" cy="246" r="4" />
                    <circle cx="118" cy="268" r="4" />
                    <circle cx="62" cy="202" r="4" />
                </g>
                <circle cx="180" cy="180" r="9" class="home-mark__core" />
            </svg>
        </div>
    </div>
</section>

<?php $sections = (array)\Nomen\Src\Hooks::filter('homepage.sections', [], $home); ?>
<?php if ($sections !== []): ?>
<section class="home-section">
    <?= implode('', array_map('strval', $sections)) ?>
</section>
<?php endif; ?>
