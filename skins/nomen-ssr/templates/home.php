<?php
/** @var array $home @var array $brand @var array $urls @var array $links */
$title = $home['title'] !== '' ? $home['title'] : (string)$brand['name'];
$extraCss = ['/css/home-ssr.css?v=s4r8n2kq'];

$primary = [
    'label' => $home['primary_label'] !== '' ? $home['primary_label'] : $urls['primary']['label'],
    'href'  => $home['primary_url'] !== '' ? $home['primary_url'] : $urls['primary']['href'],
];
$secondary = [
    'label' => $home['secondary_label'] !== '' ? $home['secondary_label'] : $urls['secondary']['label'],
    'href'  => $home['secondary_url'] !== '' ? $home['secondary_url'] : $urls['secondary']['href'],
];
?>
<article class="ssr-home">
    <header class="ssr-home__head">
        <h1><?= htmlspecialchars($title) ?></h1>
        <?php if ($home['subtitle'] !== ''): ?>
            <p class="ssr-home__line"><?= htmlspecialchars($home['subtitle']) ?></p>
        <?php endif; ?>
        <p class="ssr-home__actions">
            <a href="<?= htmlspecialchars($primary['href']) ?>" class="btn btn-primary"><?= htmlspecialchars($primary['label']) ?></a>
            <a href="<?= htmlspecialchars($secondary['href']) ?>" class="btn btn-ghost"><?= htmlspecialchars($secondary['label']) ?></a>
        </p>
        <?php if ($links): ?>
            <nav class="ssr-home__links" aria-label="<?= t('More') ?>">
                <?php foreach ($links as $link): ?>
                    <a href="<?= htmlspecialchars((string)$link['href']) ?>"><?= htmlspecialchars((string)$link['label']) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
    </header>

    <?php $sections = (array)\Nomen\Src\Hooks::filter('homepage.sections', [], $home); ?>
    <?php if ($sections !== []): ?>
        <div class="ssr-home__sections"><?= implode('', array_map('strval', $sections)) ?></div>
    <?php endif; ?>
</article>
