<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src;

/**
 * 页面 head 里由程序本身给出的 meta：谁生成的页面、哪个版本、什么皮肤和语言。
 * 皮肤不用各自写，经 layout.head 钩子统一取。
 */
final class Meta
{
    public static function head(): string
    {
        $version = self::version();

        $tags = [
            'generator'     => 'Nomen' . ($version !== '' ? ' ' . $version : ''),
            'nomen_version' => $version,
            'nomen_skin'    => Skins::active(),
            'nomen_locale'  => I18n::locale(),
        ];

        $out = [];
        foreach ($tags as $name => $content) {
            $content = (string)$content;
            if ($content === '') {
                continue;
            }
            $out[] = '<meta name="' . htmlspecialchars($name) . '" content="' . htmlspecialchars($content) . '">';
        }

        return implode("\n    ", $out);
    }

    private static function version(): string
    {
        static $version = null;

        if ($version === null) {
            $config = require dirname(__DIR__) . '/config/app.php';
            $version = (string)($config['version'] ?? '');
        }

        return $version;
    }
}
