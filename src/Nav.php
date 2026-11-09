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
 * 站点导航注册表。核心和插件都往这里登记，PHP 布局与 Vue 外壳共用同一份。
 */
class Nav
{
    /** @var array<string, array> */
    private static array $items = [];

    /**
     * $def: label, href, icon, order, spa（是否走前台 SPA 路由）, visible（bool 或 callable）
     */
    public static function add(string $key, array $def): void
    {
        self::$items[$key] = $def + [
            'label'   => $key,
            'href'    => '',
            'icon'    => '',
            'order'   => 100,
            'spa'     => false,
            'visible' => true,
        ];
    }

    /** @return array<int, array> */
    public static function items(?int $userId): array
    {
        $out = [];

        foreach (self::$items as $key => $item) {
            $visible = $item['visible'];
            if (is_callable($visible)) {
                $visible = (bool)$visible($userId);
            }
            if (!$visible) {
                continue;
            }

            $out[] = [
                'key'   => $key,
                'label' => t((string)$item['label']),
                'href'  => (string)$item['href'],
                'icon'  => (string)$item['icon'],
                'spa'   => (bool)$item['spa'],
                'order' => (int)$item['order'],
            ];
        }

        usort($out, static fn(array $a, array $b): int => [$a['order'], $a['key']] <=> [$b['order'], $b['key']]);

        return Hooks::filter('nav.items', $out, $userId);
    }
}
