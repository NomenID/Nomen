<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

/**
 * 移动端抽屉菜单，必须放在 <header> 之外（页面末尾）。
 * 放在 header 里会被 .header 的 backdrop-filter 变成包含块，fixed 定位直接失效。
 *
 * @var array $menuLinks  链接项 href / label / icon / badge / danger / onclick / target，
 *                        或 ['type' => 'divider'] 表示分隔线
 * @var array|null $menuUser  ['name' => 用户名, 'avatar' => 头像 URL 或 null]，为空即未登录
 * @var bool $menuAuthButtons  未登录时是否显示登录 / 注册按钮
 */
$menuLinks = $menuLinks ?? [];
$menuUser = $menuUser ?? null;
$menuAuthButtons = $menuAuthButtons ?? true;

$renderLinkInner = function (array $item): string {
    $html = '';
    if (!empty($item['icon'])) {
        $html .= '<span class="icon icon-' . htmlspecialchars($item['icon']) . '" aria-hidden="true"></span>';
    }
    $html .= '<span>' . htmlspecialchars($item['label']) . '</span>';
    if (!empty($item['badge'])) {
        $count = (int)$item['badge'];
        $html .= '<span class="mobile-menu-badge">' . ($count > 99 ? '99+' : $count) . '</span>';
    }
    return $html;
};
?>
<div class="mobile-menu-overlay" id="mobile-menu-overlay"></div>
<div class="mobile-menu-drawer" id="mobile-menu-drawer">
    <div class="mobile-menu-header">
        <?php if ($menuUser): ?>
            <div class="mobile-menu-user">
                <?php if (!empty($menuUser['avatar'])): ?>
                    <img src="<?= htmlspecialchars($menuUser['avatar']) ?>" alt="" class="mobile-menu-avatar">
                <?php else: ?>
                    <span class="mobile-menu-avatar"><?= htmlspecialchars(strtoupper(mb_substr($menuUser['name'], 0, 1, 'UTF-8'))) ?></span>
                <?php endif; ?>
                <span class="mobile-menu-name"><?= htmlspecialchars($menuUser['name']) ?></span>
            </div>
        <?php elseif ($menuAuthButtons): ?>
            <div class="mobile-menu-auth">
                <a href="/login" class="btn btn-secondary"><?= t('Sign in') ?></a>
                <a href="/register" class="btn btn-primary"><?= t('Sign up') ?></a>
            </div>
        <?php endif; ?>
    </div>
    <nav class="mobile-menu-nav">
        <?php foreach ($menuLinks as $item): ?>
            <?php if (($item['type'] ?? '') === 'divider'): ?>
                <div class="mobile-menu-divider"></div>
            <?php elseif (!empty($item['onclick'])): ?>
                <button type="button" class="mobile-menu-link<?= !empty($item['danger']) ? ' mobile-menu-danger' : '' ?>" onclick="<?= htmlspecialchars($item['onclick']) ?>"><?= $renderLinkInner($item) ?></button>
            <?php else: ?>
                <a href="<?= htmlspecialchars($item['href']) ?>" class="mobile-menu-link<?= !empty($item['danger']) ? ' mobile-menu-danger' : '' ?>"<?= !empty($item['target']) ? ' target="' . htmlspecialchars($item['target']) . '" rel="noopener"' : '' ?>><?= $renderLinkInner($item) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>

        <div class="mobile-menu-divider"></div>
        <div class="mobile-theme-section">
            <span class="mobile-theme-label"><?= t('Theme') ?></span>
            <div class="mobile-theme-options">
                <button type="button" class="mobile-theme-btn" data-theme-value="light" title="<?= t('Light') ?>" aria-label="<?= t('Light theme') ?>"><span class="icon icon-sun"></span></button>
                <button type="button" class="mobile-theme-btn" data-theme-value="dark" title="<?= t('Dark') ?>" aria-label="<?= t('Dark theme') ?>"><span class="icon icon-moon"></span></button>
            </div>
        </div>

        <div class="mobile-menu-divider"></div>
        <div class="mobile-lang-section">
            <span class="mobile-theme-label"><?= t('Language') ?></span>
            <div class="mobile-lang-options">
                <?php
                // 和头部选择器同一条路：只给 /special/lang 的入口，?lang= 不出现在页面源码里
                $currentLang = \Nomen\Src\I18n::locale();
                $mobileLangUrl = '/special/lang';
                $mobileReturnTo = (string)($_SERVER['REQUEST_URI'] ?? '');
                if ($mobileReturnTo !== '' && $mobileReturnTo !== '/' && !str_starts_with($mobileReturnTo, '/special/lang')) {
                    $mobileLangUrl .= '?rt=' . rawurlencode($mobileReturnTo);
                }
                ?>
                <a href="<?= htmlspecialchars($mobileLangUrl) ?>"
                   class="mobile-lang-btn is-active"
                   aria-label="<?= t('Choose a language') ?>"
                   lang="<?= htmlspecialchars(\Nomen\Src\I18n::code($currentLang)) ?>"><?= htmlspecialchars(\Nomen\Src\I18n::entry($currentLang)['name']) ?> ›</a>
            </div>
        </div>
    </nav>
</div>
