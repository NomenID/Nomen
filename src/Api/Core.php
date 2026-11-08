<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

namespace Nomen\Src\Api;

use Nomen\Src\{Api, Brand, Hooks, I18n, Plugins, Skins};
use Nomen\Src\Special\Version;

/**
 * 核心自带的 query 模块。页面相关的模块由 Pages 插件自己注册。
 */
final class Core
{
    public static function siteinfo(array $params): array
    {
        $props = Api::pipe($params['siprop'] ?? 'general') ?: ['general'];
        $out = [];

        foreach ($props as $prop) {
            switch ($prop) {
                case 'general':
                    $out['general'] = self::general();
                    break;
                case 'extensions':
                    $out['extensions'] = self::extensions();
                    break;
                case 'skins':
                    $out['skins'] = self::skins();
                    break;
                case 'rights':
                    $out['rights'] = self::rights();
                    $out['groups'] = self::groups();
                    break;
                default:
                    Api::error(400, 'badvalue', 'Unknown siprop: ' . $prop);
            }
        }

        return Hooks::filter('api.siteinfo', $out, $props, $params);
    }

    public static function version(array $params): array
    {
        return ['version' => [
            'product' => 'Nomen',
            'version' => self::versionNumber(),
            'url' => Version::PROJECT_URL,
        ]];
    }

    private static function general(): array
    {
        $base = rtrim((string)Brand::get('base_url'), '/');
        $logo = (string)Brand::get('logo', '');
        if ($logo !== '' && str_starts_with($logo, '/')) {
            $logo = $base . $logo;
        }

        return [
            'sitename' => (string)Brand::get('name'),
            'server' => $base,
            'base' => $base . '/',
            'mainpage' => '/',
            'generator' => 'Nomen ' . self::versionNumber(),
            'version' => self::versionNumber(),
            'lang' => I18n::locale(),
            'logo' => $logo,
        ];
    }

    private static function extensions(): array
    {
        $out = [];

        foreach (Plugins::loaded() as $slug => $manifest) {
            $out[] = [
                'name' => (string)($manifest['name'] ?? $slug),
                'slug' => (string)$slug,
                'version' => (string)($manifest['version'] ?? ''),
                'description' => t((string)($manifest['description'] ?? '')),
                'license' => (string)($manifest['license'] ?? ''),
                'author' => self::authors($manifest['author'] ?? []),
                'homepage' => (string)($manifest['homepage'] ?? ''),
                'type' => 'plugin',
            ];
        }

        return $out;
    }

    private static function skins(): array
    {
        $out = [];

        foreach (Skins::all() as $manifest) {
            $out[] = [
                'name' => (string)($manifest['name'] ?? ''),
                'slug' => (string)($manifest['slug'] ?? ''),
                'version' => (string)($manifest['version'] ?? ''),
                'description' => t((string)($manifest['description'] ?? '')),
                'license' => (string)($manifest['license'] ?? ''),
                'author' => self::authors($manifest['author'] ?? []),
                'homepage' => (string)($manifest['homepage'] ?? ''),
                'type' => 'skin',
            ];
        }

        return $out;
    }

    private static function rights(): array
    {
        $rights = ['read'];
        if (Api::isAdmin()) {
            $rights[] = 'admin';
        }

        return $rights;
    }

    private static function groups(): array
    {
        $groups = ['*'];
        if (Api::isAdmin()) {
            $groups[] = 'admin';
        }

        return $groups;
    }

    private static function versionNumber(): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        return (string)($config['version'] ?? '');
    }

    private static function authors(mixed $author): string
    {
        $names = [];
        foreach ((array)$author as $item) {
            if (is_array($item)) {
                $item = $item['name'] ?? '';
            }
            $item = trim((string)$item);
            if ($item !== '') {
                $names[] = $item;
            }
        }

        return implode(', ', $names);
    }
}
