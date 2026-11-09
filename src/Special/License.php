<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

namespace Nomen\Src\Special;

use Nomen\Src\{Plugins, Response, Skins};

class License
{
    /** 单项许可全文：/special/version/plugin/geoip/license */
    public static function render(string $type, string $name): void
    {
        $type = strtolower($type);
        $item = self::locate($type, $name);

        if ($item === null || !is_file($item['file'])) {
            Response::error(404);
            return;
        }

        $links = '<a href="/special/version">' . t('Back to version info') . '</a>';
        $notice = self::noticeUrl($type, $name);
        if ($notice !== null) {
            $links .= ' · <a href="' . self::esc($notice) . '">NOTICE</a>';
        }
        $readme = self::readmeUrl($type, $name);
        if ($readme !== null) {
            $links .= ' · <a href="' . self::esc($readme) . '">README</a>';
        }

        $meta = $item['license'] === ''
            ? ''
            : '<p>' . self::esc(t(self::typeLabel($type))) . ' · ' . self::esc($item['license']) . '</p>';

        Response::view('special', [
            'title' => t('{:name} license', ['name' => $item['name']]),
            'body' => '<p>' . $links . '</p>'
                . $meta
                . '<pre class="special-license">' . self::esc((string)file_get_contents($item['file'])) . '</pre>',
        ]);
    }

    /** 附加条款与声明：/special/version/core/nomen/notice */
    public static function renderNotice(string $type, string $name): void
    {
        $type = strtolower($type);
        $item = self::locate($type, $name);
        $file = $item['notice'] ?? null;

        if ($file === null || !is_file($file)) {
            Response::error(404);
            return;
        }

        $links = '<a href="/special/version">' . t('Back to version info') . '</a>';
        $license = self::url($type, $name);
        if ($license !== null) {
            $links .= ' · <a href="' . self::esc($license) . '">' . t('License') . '</a>';
        }

        Response::view('special', [
            'title' => t('{:name} NOTICE', ['name' => $item['name']]),
            'body' => '<p>' . $links . '</p>'
                . '<pre class="special-license">' . self::esc((string)file_get_contents($file)) . '</pre>',
        ]);
    }

    /** 插件作者自己写的说明：/special/version/plugin/geoip/readme */
    public static function renderReadme(string $type, string $name): void
    {
        $type = strtolower($type);
        $item = self::locate($type, $name);

        if ($item === null || $item['readme'] === null || !is_file($item['readme'])) {
            Response::error(404);
            return;
        }

        $links = '<a href="/special/version">' . t('Back to version info') . '</a>';
        $license = self::url($type, $name);
        if ($license !== null) {
            $links .= ' · <a href="' . self::esc($license) . '">' . t('License') . '</a>';
        }

        $text = (string)file_get_contents($item['readme']);
        $body = ($item['readme_markdown'] ?? true) && class_exists(\Nomen\Plugin\Pages\Markdown::class)
            ? \Nomen\Plugin\Pages\Markdown::render($text)
            : '<pre class="special-license">' . self::esc($text) . '</pre>';

        Response::view('special', [
            'title' => t('About {:name}', ['name' => $item['name']]),
            'body' => '<p>' . $links . '</p>' . $body,
        ]);
    }

    /** 版本页用：目录里有 LICENSE 才给链接 */
    public static function url(string $type, string $name): ?string
    {
        $type = strtolower($type);
        $item = self::locate($type, $name);
        if ($item === null || !is_file($item['file'])) {
            return null;
        }

        return '/special/version/' . $type . '/' . rawurlencode($name) . '/license';
    }

    private static function noticeUrl(string $type, string $name): ?string
    {
        $item = self::locate($type, $name);
        $file = $item['notice'] ?? null;
        if ($file === null || !is_file($file)) {
            return null;
        }

        return '/special/version/' . $type . '/' . rawurlencode($name) . '/notice';
    }

    private static function readmeUrl(string $type, string $name): ?string
    {
        $item = self::locate($type, $name);
        if ($item === null || $item['readme'] === null || !is_file($item['readme'])) {
            return null;
        }

        return '/special/version/' . $type . '/' . rawurlencode($name) . '/readme';
    }

    /** @return array{name: string, license: string, file: string, notice: ?string, readme: ?string, readme_markdown?: bool}|null */
    private static function locate(string $type, string $name): ?array
    {
        if ($type === 'core') {
            if ($name !== 'nomen') {
                return null;
            }
            $root = dirname(__DIR__, 2);

            return [
                'name' => 'Nomen',
                'license' => 'GPL-3.0-only',
                'file' => $root . '/LICENSE',
                'notice' => $root . '/NOTICE',
                'readme' => $root . '/README.md',
                // README 开头是一段 HTML 图标，Markdown 渲染会把它当转义文本吐出来
                'readme_markdown' => false,
            ];
        }

        if ($type === 'plugin' || $type === 'skin') {
            $manifest = $type === 'plugin'
                ? (Plugins::all()[$name] ?? null)
                : (Skins::all()[$name] ?? null);
            if ($manifest === null) {
                return null;
            }

            $dir = ($type === 'plugin' ? Plugins::dir() : Skins::dir()) . '/' . $name;

            return [
                'name' => (string)($manifest['name'] ?? $name),
                'license' => self::licenseName($manifest['license'] ?? ''),
                'file' => $dir . '/LICENSE',
                'notice' => null,
                'readme' => $dir . '/README.md',
                'readme_markdown' => true,
            ];
        }

        return null;
    }

    private static function licenseName(mixed $license): string
    {
        if (is_array($license)) {
            $license = implode(', ', array_map('strval', $license));
        }

        return trim((string)$license);
    }

    private static function typeLabel(string $type): string
    {
        return ['core' => '软件', 'plugin' => '插件', 'skin' => '皮肤'][$type] ?? $type;
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value);
    }
}
