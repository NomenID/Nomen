<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

namespace Nomen\Src;

/**
 * 站点品牌信息：名称、地址、图标、页脚。布局和模板都从这里取，别写死。
 */
final class Brand
{
    private static ?array $data = null;

    public static function all(): array
    {
        if (self::$data === null) {
            self::$data = require dirname(__DIR__) . '/config/brand.php';
        }

        return self::$data;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    /** @return array<int, array{label: string, url: string}> */
    public static function footerLinks(): array
    {
        $links = (array)self::get('footer_links', []);

        $docs = Plugins::mountUrl('pages');
        if ($docs !== null) {
            $links[] = ['label' => 'Docs', 'url' => $docs];
        }

        return $links;
    }

    /** 认证页法律声明：legal_links 配置优先；没配时用 privacy / terms / 开发者守则合成。 */
    public static function legalLinks(): array
    {
        $out = [];
        foreach ((array)self::get('legal_links', []) as $link) {
            if (!is_array($link)) {
                continue;
            }
            $label = trim((string)($link['label'] ?? ''));
            $url = trim((string)($link['url'] ?? ''));
            if ($label !== '' && $url !== '') {
                $out[] = ['label' => $label, 'url' => $url];
            }
        }
        if ($out !== []) {
            return $out;
        }
        $fallback = [
            ['url' => trim((string)self::get('privacy_url', '')), 'label' => t('Privacy Policy')],
            ['url' => trim((string)self::get('terms_url', '')), 'label' => t('Terms of Service')],
            ['url' => trim((string)self::get('developer_guidelines_url', '')), 'label' => t('Developer Guidelines')],
        ];
        foreach ($fallback as $link) {
            if ($link['url'] !== '') {
                $out[] = $link;
            }
        }
        return $out;
    }
}
