<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src;

/**
 * 站点页脚。优先级：
 *   1. config/footer.php（站长自己的模板）
 *   2. 当前皮肤的 partials/footer.php
 * 默认模板拿到的变量：$brand、$links、$layout。
 * 插件用 footer.links 改链接、footer.html 改最终 HTML。
 */
final class Footer
{
    public static function render(string $layout = 'default'): void
    {
        echo self::html($layout);
    }

    public static function html(string $layout = 'default'): string
    {
        $brand = Brand::all();
        $links = Hooks::filter('footer.links', Brand::footerLinks(), $brand, $layout);

        $custom = dirname(__DIR__) . '/config/footer.php';
        if (is_file($custom)) {
            ob_start();
            require $custom;
            $html = (string)ob_get_clean();
        } else {
            $file = self::partialFile();
            ob_start();
            if ($file !== null) {
                require $file;
            }
            $html = (string)ob_get_clean();
        }

        return (string)Hooks::filter('footer.html', $html, $layout, $brand, $links);
    }

    private static function partialFile(): ?string
    {
        foreach ([Skins::active(), Skins::defaultSlug()] as $skin) {
            $file = Skins::dir() . '/' . $skin . '/partials/footer.php';
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }
}
