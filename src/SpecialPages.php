<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

namespace Nomen\Src;

class SpecialPages
{
    /** @var array<string, array> */
    private static array $pages = [];

    /**
     * @param array $def title / handler / access(public|admin)
     */
    public static function add(string $slug, array $def): void
    {
        self::$pages[strtolower($slug)] = $def + [
            'title' => $slug,
            'access' => 'public',
            'handler' => null,
        ];
    }

    public static function has(string $slug): bool
    {
        return isset(self::$pages[strtolower($slug)]);
    }

    /** @return array<string, array> */
    public static function all(): array
    {
        return self::$pages;
    }

    public static function render(string $slug): void
    {
        $page = self::$pages[strtolower($slug)] ?? null;
        if ($page === null) {
            Response::error(404);
            return;
        }

        if (($page['access'] ?? 'public') === 'admin') {
            if (!Session::get('user_id')) {
                Response::redirect('/login?redirect_to=' . urlencode($_SERVER['REQUEST_URI']));
                return;
            }
            if (!Router::isAdmin()) {
                Response::error(403);
                return;
            }
        }

        if (!is_callable($page['handler'])) {
            Response::error(500, '这个特殊页面没有处理函数');
            return;
        }

        Response::view('special', [
            'title' => t((string)$page['title']),
            'body' => (string)call_user_func($page['handler']),
        ]);
    }
}
