<?php
/**
 * 语言选择器（头部下拉）。挂在 default.php 的导航里，
 * 选项是带 ?lang= 的链接：服务端 I18n::init() 收到后落 cookie（登录态还会写账号偏好）。
 *
 * @var string $langPickerId  同页多个选择器时的面板 id 后缀
 */
$current = \Nomen\Src\I18n::locale();
$entries = \Nomen\Src\I18n::registry();
$basePath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$langQuery = $_GET;
$langHref = function (string $slug) use ($basePath, &$langQuery): string {
    $langQuery['lang'] = $slug;
    $qs = http_build_query($langQuery);
    return $basePath . ($qs !== '' ? '?' . $qs : '');
};
$panelId = 'langPanel' . ($langPickerId ?? '');
?>
<div class="lang-picker" data-lang-picker data-current="<?= htmlspecialchars($current) ?>">
    <button class="lang-trigger" type="button" aria-expanded="false" aria-haspopup="true" aria-controls="<?= $panelId ?>" aria-label="<?= t('语言') ?>">
        <span class="icon icon-globe" aria-hidden="true"></span>
        <span class="lang-trigger-name"><?= htmlspecialchars(\Nomen\Src\I18n::entry($current)['name']) ?></span>
        <svg class="lang-chevron" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
    </button>
    <div class="lang-panel" id="<?= $panelId ?>" hidden>
        <div class="lang-search">
            <input type="search" data-lang-search placeholder="<?= t('搜索语言') ?>" autocomplete="off" aria-label="<?= t('搜索语言') ?>">
        </div>
        <ul class="lang-list" aria-label="<?= t('选择语言') ?>">
            <?php foreach ($entries as $entry): ?>
                <li<?php if ($entry['slug'] === $current): ?> aria-current="true"<?php endif; ?>>
                    <a class="lang-option<?= $entry['slug'] === $current ? ' is-active' : '' ?>"
                       href="<?= htmlspecialchars($langHref($entry['slug'])) ?>"
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
        <p class="lang-empty" hidden><?= t('没有找到语言') ?></p>
    </div>
</div>
