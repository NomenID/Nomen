<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

namespace Nomen\Src;

class Skins
{
    private static ?array $all = null;
    private static ?array $available = null;
    private static ?string $default = null;

    public static function dir(): string
    {
        return dirname(__DIR__) . '/skins';
    }

    /**
     * 扫 skins/ 下一层带 skin.json 的目录。
     *
     * @return array<string, array> slug => manifest
     */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }

        $skins = [];
        foreach (glob(self::dir() . '/*/skin.json') ?: [] as $file) {
            $meta = json_decode((string)file_get_contents($file), true);
            if (!is_array($meta) || empty($meta['name'])) {
                continue;
            }
            $slug = basename(dirname($file));
            $meta['slug'] = $slug;
            $skins[$slug] = $meta;
        }
        ksort($skins);

        return self::$all = $skins;
    }

    public static function exists(string $slug): bool
    {
        return isset(self::available()[$slug]);
    }

    /**
     * 站点启用的皮肤：config/skins.php 的 enabled 列表，没配就表示全部可用。
     *
     * @return array<string, array> slug => manifest
     */
    public static function available(): array
    {
        if (self::$available !== null) {
            return self::$available;
        }

        $config = require dirname(__DIR__) . '/config/skins.php';
        $enabled = $config['enabled'] ?? null;
        if (!is_array($enabled)) {
            return self::$available = self::all();
        }

        $slugs = array_map('strval', $enabled);

        return self::$available = array_intersect_key(self::all(), array_flip($slugs));
    }

    public static function defaultSlug(): string
    {
        if (self::$default !== null) {
            return self::$default;
        }

        $config = require dirname(__DIR__) . '/config/skins.php';
        $slug = (string)($config['default'] ?? '');
        if (!self::exists($slug)) {
            $slug = (string)array_key_first(self::available());
        }

        return self::$default = $slug;
    }

    /**
     * 当前生效的皮肤：账号偏好 > cookie > 配置里的默认。
     */
    public static function active(): string
    {
        $candidates = [
            (string)(Session::get('user_skin') ?? ''),
            (string)($_COOKIE['nomen_skin'] ?? ''),
            self::defaultSlug(),
        ];

        foreach ($candidates as $slug) {
            if ($slug !== '' && self::exists($slug)) {
                return $slug;
            }
        }

        return self::defaultSlug();
    }

    /**
     * 皮肤里没有这个布局就退回默认皮肤的，再没有就返回 null（调用方报错）。
     * 插件可以用 @<slug>/<layout> 自带布局，皮肤仍可覆盖。
     */
    public static function layoutFile(string $layout): ?string
    {
        if (str_starts_with($layout, '@')) {
            [$plugin, $name] = self::splitExtensionView($layout);
            if ($plugin === null) {
                return null;
            }

            foreach ([self::active(), self::defaultSlug()] as $skin) {
                $file = self::dir() . '/' . $skin . '/layouts/@' . $plugin . '/' . $name . '.php';
                if (file_exists($file)) {
                    return $file;
                }
            }

            $file = dirname(__DIR__) . '/plugins/' . $plugin . '/layouts/' . $name . '.php';

            return file_exists($file) ? $file : null;
        }

        $active = self::dir() . '/' . self::active() . '/layouts/' . $layout . '.php';
        if (file_exists($active)) {
            return $active;
        }

        $fallback = self::dir() . '/' . self::defaultSlug() . '/layouts/' . $layout . '.php';

        return file_exists($fallback) ? $fallback : null;
    }

    /**
     * 模板解析：当前皮肤 templates/ → 默认皮肤 templates/ → 核心 templates/。
     * 皮肤想改哪个页面就把同名文件放进自己的 templates/，不用动核心。
     *
     * 插件模板写成 @<slug>/<name>：皮肤仍可覆盖（templates/@<slug>/<name>.php），
     * 没覆盖就用插件自己的 templates/<name>.php。
     */
    public static function template(string $name): ?string
    {
        if (str_starts_with($name, '@')) {
            [$plugin, $template] = self::splitExtensionView($name);
            if ($plugin === null) {
                return null;
            }

            foreach ([self::active(), self::defaultSlug()] as $skin) {
                $file = self::dir() . '/' . $skin . '/templates/@' . $plugin . '/' . $template . '.php';
                if (file_exists($file)) {
                    return $file;
                }
            }

            $file = dirname(__DIR__) . '/plugins/' . $plugin . '/templates/' . $template . '.php';

            return file_exists($file) ? $file : null;
        }

        $active = self::dir() . '/' . self::active() . '/templates/' . $name . '.php';
        if (file_exists($active)) {
            return $active;
        }

        $fallback = self::dir() . '/' . self::defaultSlug() . '/templates/' . $name . '.php';
        if (file_exists($fallback)) {
            return $fallback;
        }

        $core = dirname(__DIR__) . '/templates/' . $name . '.php';

        return file_exists($core) ? $core : null;
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private static function splitExtensionView(string $name): array
    {
        $parts = explode('/', substr($name, 1), 2);
        if (count($parts) !== 2) {
            return [null, ''];
        }

        [$plugin, $view] = $parts;
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $plugin) || !preg_match('#^[A-Za-z0-9_\-/]+$#', $view)) {
            return [null, ''];
        }

        return [$plugin, trim($view, '/')];
    }
}
