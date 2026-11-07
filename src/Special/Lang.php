<?php
namespace Nomen\Src\Special;

use Nomen\Src\{I18n, Response};

/**
 * /special/lang —— 语言选择页。头部的语言选择器在无 JS 时就是指过来的普通链接
 * （?lang= 不出现在页面链接里，搜索引擎不会按语言分裂收录）；在这里选中后
 * 落 cookie（登录态还会写进账号偏好），回来源页。
 */
class Lang
{
    public static function render(): string
    {
        $select = (string)($_GET['select'] ?? '');
        if ($select !== '') {
            self::apply($select);
            return '';
        }

        $current = I18n::locale();
        $rt = self::returnTo();

        $items = '';
        foreach (I18n::registry() as $entry) {
            $active = $entry['slug'] === $current;
            $params = ['select' => $entry['slug']];
            if ($rt !== '') {
                $params['rt'] = $rt;
            }
            $items .= '<li' . ($active ? ' aria-current="true"' : '') . '>'
                . '<a class="lang-option' . ($active ? ' is-active' : '') . '" href="?' . htmlspecialchars(http_build_query($params)) . '"'
                . ' hreflang="' . htmlspecialchars($entry['code']) . '" lang="' . htmlspecialchars($entry['code']) . '">'
                . '<span class="lang-option-name" lang="' . htmlspecialchars($entry['code']) . '">' . htmlspecialchars($entry['name']) . '</span>'
                . '<span class="lang-option-meta">' . htmlspecialchars($entry['english']) . ' · ' . htmlspecialchars($entry['slug']) . '</span>'
                . ($active ? '<span class="icon icon-check lang-option-check" aria-hidden="true"></span>' : '')
                . '</a></li>';
        }

        return '<p class="form-hint">' . t('Takes effect immediately and returns you to the page you were on. The choice is stored in this browser; when signed in it also syncs to your account.') . '</p>'
            . '<ul class="lang-list lang-list--page" aria-label="' . t('Choose a language') . '">' . $items . '</ul>';
    }

    /** 选中一种语言：落 cookie / 账号偏好，回来源页 */
    private static function apply(string $slug): void
    {
        $target = self::returnTo();
        if ($target === '' || !I18n::switchingAllowed() || !I18n::isValid($slug)) {
            Response::redirect($target !== '' ? $target : '/');
            return;
        }
        I18n::applyChoice($slug);
        Response::redirect($target);
    }

    /** 来源页只收本站路径，防开放重定向 */
    private static function returnTo(): string
    {
        $rt = (string)($_GET['rt'] ?? '');
        if ($rt === '' || mb_strlen($rt) > 500
            || !str_starts_with($rt, '/')
            || str_starts_with($rt, '//')
            || str_contains($rt, '\\')
            || str_contains($rt, '://')
            || preg_match('/[\x00-\x1f\x7f]/', $rt)
        ) {
            return '';
        }
        return $rt;
    }
}
