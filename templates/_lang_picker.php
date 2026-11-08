<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

/**
 * 语言选择器（头部）。无 JS 时就是指向 /special/lang 的普通链接；
 * 有 JS 时 app.js 把它升级成下拉面板，选中项走 ?lang=（服务端落 cookie / 账号偏好）。
 * 选项的 href 由 JS 在面板打开时现场生成，页面源码里不含 ?lang=，
 * 搜索引擎不会顺着链接把同一页按语言收录成多个地址。
 *
 * @var string $langPickerId  同页多个选择器时的面板 id 后缀
 */
$current = \Nomen\Src\I18n::locale();
$entries = \Nomen\Src\I18n::registry();
$panelId = 'langPanel' . ($langPickerId ?? '');
$langUrl = '/special/lang';
$returnTo = (string)($_SERVER['REQUEST_URI'] ?? '');
if ($returnTo !== '' && $returnTo !== '/' && !str_starts_with($returnTo, '/special/lang')) {
    $langUrl .= '?rt=' . rawurlencode($returnTo);
}
?>
<div class="lang-picker" data-lang-picker data-current="<?= htmlspecialchars($current) ?>">
    <a class="lang-trigger" href="<?= htmlspecialchars($langUrl) ?>" aria-expanded="false" aria-haspopup="true" aria-controls="<?= $panelId ?>" aria-label="<?= t('Language') ?>">
        <span class="icon icon-globe" aria-hidden="true"></span>
        <span class="lang-trigger-name"><?= htmlspecialchars(\Nomen\Src\I18n::entry($current)['name']) ?></span>
        <svg class="lang-chevron" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
    </a>
    <div class="lang-panel" id="<?= $panelId ?>" hidden>
        <div class="lang-search">
            <input type="search" data-lang-search placeholder="<?= t('Search languages') ?>" autocomplete="off" aria-label="<?= t('Search languages') ?>">
        </div>
        <ul class="lang-list" aria-label="<?= t('Choose a language') ?>">
            <?php foreach ($entries as $entry): ?>
                <li<?php if ($entry['slug'] === $current): ?> aria-current="true"<?php endif; ?>>
                    <a class="lang-option<?= $entry['slug'] === $current ? ' is-active' : '' ?>"
                       data-lang-slug="<?= htmlspecialchars($entry['slug']) ?>"
                       hreflang="<?= htmlspecialchars($entry['code']) ?>"
                       lang="<?= htmlspecialchars($entry['code']) ?>"
                       data-search="<?= htmlspecialchars(mb_strtolower($entry['name'] . ' ' . $entry['english'] . ' ' . $entry['slug'] . ' ' . $entry['code'], 'UTF-8')) ?>">
                        <span class="lang-option-name" lang="<?= htmlspecialchars($entry['code']) ?>"><?= htmlspecialchars($entry['name']) ?></span>
                        <span class="lang-option-meta"><?= htmlspecialchars($entry['english']) ?> · <?= htmlspecialchars($entry['slug']) ?></span>
                        <?php if ($entry['slug'] === $current): ?><span class="icon icon-check lang-option-check" aria-hidden="true"></span><?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="lang-empty" hidden><?= t('No language found') ?></p>
    </div>
</div>
