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
 * 页面 head 里由程序本身给出的 meta：谁生成的页面、哪个版本、什么皮肤和语言，
 * 以及给分享预览用的 og 标签。皮肤不用各自写，经 layout.head 钩子统一取。
 */
final class Meta
{
    private static string $title = '';

    /** 模板里设的 $title 由 Response::view 在渲染布局前交过来。 */
    public static function setTitle(string $title): void
    {
        self::$title = trim($title);
    }

    public static function head(string $layout = 'default'): string
    {
        $version = self::version();

        $tags = [
            'generator'     => 'Nomen' . ($version !== '' ? ' ' . $version : ''),
            'nomen_version' => $version,
            'nomen_skin'    => Skins::active(),
            'nomen_locale'  => I18n::locale(),
        ];

        // 后台是给站长看的，不参与分享预览
        if ($layout !== 'admin') {
            $brand = Brand::all();
            $name = (string)($brand['name'] ?? '');
            $title = self::$title !== '' ? self::$title : $name;

            $tags['og:site_name'] = $name;
            $tags['og:title'] = $title;
            $tags['og:type'] = 'website';
            $tags['og:url'] = self::currentUrl();

            $image = self::absoluteUrl((string)($brand['logo'] ?? ''));
            if ($image !== '') {
                $tags['og:image'] = $image;
            }
        }

        // 插件可增删改任意一条（键名以 og: 开头按 property 输出，其余按 name）
        $tags = Hooks::filter('meta.tags', $tags, $layout);

        $out = [];
        foreach ((array)$tags as $key => $content) {
            $key = (string)$key;
            $content = (string)$content;
            if ($key === '' || $content === '') {
                continue;
            }
            $attr = str_starts_with($key, 'og:') ? 'property' : 'name';
            $out[] = '<meta ' . $attr . '="' . htmlspecialchars($key) . '" content="' . htmlspecialchars($content) . '">';
        }

        return implode("\n    ", $out);
    }

    private static function currentUrl(): string
    {
        $base = rtrim((string)Brand::get('base_url', ''), '/');
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = strtolower(rtrim($path, '/'));

        return $base . ($path === '' ? '/' : $path);
    }

    private static function absoluteUrl(string $url): string
    {
        if ($url === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            return $url;
        }

        return rtrim((string)Brand::get('base_url', ''), '/') . '/' . ltrim($url, '/');
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