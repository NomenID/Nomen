<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src\Special;

use Nomen\Src\{Brand, Build, Cache, Database, I18n, Plugins, Skins, Upgrade};

class Version
{
    public const PROJECT_URL = 'https://nomenid.org/';

    // 版本页要查库、扫插件，内容又不常变：缓存一分钟，够挡住刷新风暴
    // 键里带语言：渲染结果是翻译过的 HTML，串语言就等于给所有人发英文
    private const CACHE_TTL = 60;

    public static function render(): string
    {
        $key = 'special:version:' . I18n::locale();

        return (string)Cache::remember($key, self::CACHE_TTL, static fn(): string => self::build());
    }

    private static function build(): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        return self::license()
            . self::software($config)
            . self::entryPoints($config)
            . self::pluginMounts()
            . self::skins()
            . self::plugins()
            . self::libraries();
    }

    private static function license(): string
    {
        return '<h2>' . t('Nomen license') . '</h2>'
            . '<p>' . t('This site is powered by {:link}. Copyright © 2025-{:year} Yunyun (Wang Yaoze) and other contributors.', [
                'link' => '<a href="' . self::PROJECT_URL . '">Nomen</a>',
                'year' => date('Y'),
            ]) . '</p>'
            . '<p>' . t('Nomen is developed by the NomenID Team and other contributors to its core, plugins and skins.') . '</p>'
            . '<p>' . t('Thanks to all the translators who helped translate Nomen core and its plugins.') . '</p>'
            . '<p>' . t('Nomen is free software; you may redistribute and/or modify it under the terms of version 3 of the GNU General Public License as published by the Free Software Foundation.') . '</p>'
            . '<p>' . t('Nomen is distributed in the hope that it will be useful, but with <em>no warranty</em>; without even the implied warranty of <strong>merchantability</strong> or <strong>fitness for a particular purpose</strong>. See the GNU General Public License for more details.') . '</p>'
            . '<p>' . t('You should have received a copy of the GNU General Public License along with this program; if not, you can {:link}. Additional terms and notices are in the {:notice}, and project information is in the {:readme}.', [
                'link' => '<a href="/special/version/core/nomen/license">' . t('read the full license on this site') . '</a>',
                'notice' => '<a href="/special/version/core/nomen/notice">NOTICE</a>',
                'readme' => '<a href="/special/version/core/nomen/readme">README</a>',
            ]) . '</p>';
    }

    private static function software(array $config): string
    {
        $rows = [[
            self::link('Nomen', self::PROJECT_URL),
            self::esc(self::plain($config['version'] ?? '')),
        ]];

        $build = Build::summary((string)($config['version'] ?? ''));
        if ($build !== null) {
            $rows[] = [t('Build'), self::esc($build)];
        }

        $rows[] = [
            self::link('PHP', 'https://www.php.net/'),
            self::esc(PHP_VERSION . ' (' . PHP_SAPI . ')'),
        ];

        try {
            $db = Database::getInstance();
            $version = $db->dialect()->serverVersion($db);

            if (str_starts_with($version, 'SQLite')) {
                $rows[] = [self::link('SQLite', 'https://sqlite.org/'), self::esc(self::plain(substr($version, 7)))];
            } else {
                $mariadb = stripos($version, 'mariadb') !== false;
                $rows[] = [
                    self::link($mariadb ? 'MariaDB' : 'MySQL', $mariadb ? 'https://mariadb.org/' : 'https://www.mysql.com/'),
                    self::esc(self::plain($version)),
                ];
            }
            $mismatch = Upgrade::mismatch($db);
            if ($mismatch !== null) {
                $rows[] = [
                    t('Database schema'),
                    self::esc(($mismatch['db'] ?? t('Not recorded')) . t(' (differs from the application version — the site administrator needs to run the upgrade)')),
                ];
            }
        } catch (\Throwable) {
            $rows[] = [t('Database'), '—'];
        }

        return '<h2>' . t('Installed software') . '</h2>' . self::table([t('Product'), t('Version')], $rows);
    }

    private static function entryPoints(array $config): string
    {
        $base = rtrim((string)($config['base_url'] ?? ''), '/');

        $points = [
            [t('Authorize'), '/oauth/authorize'],
            [t('Token'), '/oauth/token'],
            [t('User info'), '/api/userinfo'],
            [t('Token verification'), '/api/token-info'],
            [t('Revoke'), '/oauth/revoke'],
            [t('Site API'), '/nomen-api'],
        ];

        $rows = [];
        foreach ($points as [$label, $path]) {
            $url = $base . $path;
            $rows[] = [
                self::esc($label),
                '<a href="' . self::esc($url) . '">' . self::esc($url) . '</a>',
            ];
        }

        return '<h2>' . t('Endpoint URLs') . '</h2>' . self::table([t('Endpoint'), 'URL'], $rows);
    }

    private static function pluginMounts(): string
    {
        $mounts = Plugins::mounts();
        if ($mounts === []) {
            return '';
        }

        $base = rtrim((string)Brand::get('base_url'), '/');
        $rows = [];
        foreach ($mounts as $mount) {
            $url = $base . $mount['path'];
            $rows[] = [
                self::esc((string)$mount['plugin']),
                self::esc((string)$mount['title']),
                '<a href="' . self::esc($url) . '">' . self::esc($url) . '</a>',
                ($mount['access'] ?? 'public') === 'admin' ? t('Admin only') : t('Public'),
            ];
        }

        return '<h2>' . t('Plugin mount points') . '</h2>' . self::table([t('Plugin'), t('Name'), 'URL', t('Access')], $rows);
    }

    private static function skins(): string
    {
        $skins = Skins::all();
        if ($skins === []) {
            return '<h2>' . t('Installed skins') . '</h2><p>' . t('No skins are installed.') . '</p>';
        }

        $rows = [];
        foreach ($skins as $manifest) {
            $rows[] = [
                self::esc($manifest['name'] ?? ''),
                self::esc(self::plain($manifest['version'] ?? '')),
                self::licenseCell($manifest['license'] ?? '', 'skin', (string)($manifest['slug'] ?? '')),
                self::esc(t(self::plain($manifest['description'] ?? ''))),
                self::esc(self::authors($manifest['author'] ?? [])),
            ];
        }

        return '<h2>' . t('Installed skins') . '</h2>' . self::table([t('Skins'), t('Version'), t('License'), t('Description'), t('Authors')], $rows);
    }

    private static function plugins(): string
    {
        $plugins = Plugins::loaded();
        if ($plugins === []) {
            return '<h2>' . t('Installed plugins') . '</h2><p>' . t('No plugins are installed.') . '</p>';
        }

        $rows = [];
        foreach ($plugins as $manifest) {
            $name = self::plain($manifest['name'] ?? '');
            $homepage = (string)($manifest['homepage'] ?? '');
            $rows[] = [
                $homepage !== '' ? self::link($name, $homepage) : self::esc($name),
                self::esc(self::plain($manifest['version'] ?? '')),
                self::licenseCell($manifest['license'] ?? '', 'plugin', (string)($manifest['slug'] ?? '')),
                self::esc(t(self::plain($manifest['description'] ?? ''))),
                self::esc(self::authors($manifest['author'] ?? [])),
            ];
        }

        return '<h2>' . t('Installed plugins') . '</h2>'
            . self::table([t('Plugins'), t('Version'), t('License'), t('Description'), t('Authors')], $rows)
            . '<p>' . t('Plugins are maintained by their respective authors and released under their own licenses; they are not part of Nomen itself.') . '</p>';
    }

    private static function libraries(): string
    {
        $html = '';
        $server = self::serverLibraries();
        if ($server !== []) {
            $html .= '<h3>' . t('Installed server-side libraries') . '</h3>'
                . self::table([t('Library'), t('Version'), t('License'), t('Description'), t('Authors')], $server);
        }

        $client = self::clientLibraries();
        if ($client !== []) {
            $html .= '<h3>' . t('Installed client-side libraries') . '</h3>'
                . self::table([t('Library'), t('Version'), t('License')], $client);
        }

        return $html === '' ? '' : '<h2>' . t('Installed libraries') . '</h2>' . $html;
    }

    private static function serverLibraries(): array
    {
        $file = dirname(__DIR__, 2) . '/vendor/composer/installed.json';
        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data)) {
            return [];
        }

        $packages = $data['packages'] ?? $data;
        $dev = array_flip((array)($data['dev-package-names'] ?? []));

        $rows = [];
        foreach ($packages as $package) {
            if (!is_array($package) || empty($package['name']) || isset($dev[$package['name']])) {
                continue;
            }
            $rows[] = [
                self::esc($package['name']),
                self::esc(self::plain($package['version'] ?? '')),
                self::esc(self::plain($package['license'] ?? '')),
                self::esc(self::plain($package['description'] ?? '')),
                self::esc(self::authors($package['authors'] ?? [])),
            ];
        }

        return $rows;
    }

    private static function clientLibraries(): array
    {
        $file = dirname(__DIR__, 2) . '/frontend/package-lock.json';
        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string)file_get_contents($file), true);
        $dependencies = $data['packages']['']['dependencies'] ?? null;
        if (!is_array($dependencies)) {
            return [];
        }

        $rows = [];
        foreach (array_keys($dependencies) as $name) {
            $package = $data['packages']['node_modules/' . $name] ?? null;
            if (!is_array($package)) {
                continue;
            }
            $rows[] = [
                self::esc($name),
                self::esc(self::plain($package['version'] ?? '')),
                self::esc(self::plain($package['license'] ?? '')),
            ];
        }

        return $rows;
    }

    private static function table(array $headers, array $rows): string
    {
        $wide = count($headers) >= 4 ? ' special-table--wide' : '';
        $html = '<div class="special-table-wrap"><table class="table' . $wide . '"><thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th>' . self::esc($header) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . $cell . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table></div>';
    }

    private static function link(string $text, string $url): string
    {
        return '<a href="' . self::esc($url) . '">' . self::esc($text) . '</a>';
    }

    private static function licenseCell(mixed $license, string $type, string $slug): string
    {
        $text = self::esc(self::plain($license));
        $url = License::url($type, $slug);

        return $url === null ? $text : '<a href="' . self::esc($url) . '">' . $text . '</a>';
    }

    /** 清单里的值可能是字符串、数组，也可能没写 */
    private static function plain(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        }
        $value = trim((string)$value);

        return $value === '' ? '—' : $value;
    }

    private static function authors(mixed $author): string
    {
        $list = [];
        foreach ((array)$author as $item) {
            if (is_array($item)) {
                $item = $item['name'] ?? '';
            }
            $item = trim((string)$item);
            if ($item !== '') {
                $list[] = $item;
            }
        }

        if ($list === []) {
            return '—';
        }
        if (count($list) === 1) {
            return $list[0];
        }

        $last = array_pop($list);

        return implode(t(', '), $list) . t(' and {:last}', ['last' => $last]);
    }

    private static function esc(mixed $value): string
    {
        return htmlspecialchars((string)$value);
    }
}
