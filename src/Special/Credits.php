<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src\Special;

use Nomen\Src\{I18n, Response};

/**
 * /special/version/credits：版本页上那句「…和其他贡献者」的去处。
 * 写的是真事：谁在维护、界面有哪几种语言、站的是什么上游项目。
 */
class Credits
{
    public static function render(): void
    {
        $languages = [];
        foreach (I18n::registry() as $entry) {
            if (($entry['name'] ?? '') !== '') {
                $languages[] = (string)$entry['name'];
            }
        }

        Response::view('special', [
            'title' => t('And other contributors'),
            'body' => '<p><a href="/special/version">' . t('Back to version info') . '</a></p>'
                . '<h2>' . t('Development') . '</h2>'
                . '<p>' . t('Nomen is maintained by the NomenID Team; code, plugins, skins and documentation also come from other contributors.') . '</p>'
                . '<p><a href="https://github.com/NomenID">github.com/NomenID</a></p>'
                . '<h2>' . t('Translations') . '</h2>'
                . '<p>' . t('The interface is available in {:n} languages:', ['n' => count($languages)]) . ' '
                . htmlspecialchars(implode(' · ', $languages)) . '</p>'
                . '<p>' . t('Translation fixes and new languages are welcome on GitHub.') . '</p>'
                . '<h2>' . t('Upstream projects') . '</h2>'
                . '<p>' . t('Nomen runs on PHP and a database, and links the libraries listed on the version info page.') . '</p>',
        ]);
    }
}
