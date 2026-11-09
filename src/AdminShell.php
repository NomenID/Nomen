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
 * 管理后台 SPA 的首屏数据：导航、当前管理员、CSRF。
 * 各页面的数据走 /api/admin/*，这里只保证外壳能先画出来。
 */
final class AdminShell
{
    public static function render(): void
    {
        $userId = (int)Session::get('user_id');

        $stmt = Database::getInstance()->prepare(
            'SELECT id, username, display_name, avatar, role FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch() ?: [];

        $menu = [];
        foreach (Admin::menuItems() as $key => $item) {
            $menu[] = [
                'key'   => $key,
                'href'  => (string)$item['href'],
                'label' => (string)$item['label'],
                'group' => (string)($item['group'] ?? ''),
            ];
        }

        $props = Hooks::filter('admin.shell.props', [
            'viewer' => [
                'id'          => $userId,
                'username'    => (string)($user['username'] ?? ''),
                'displayName' => (string)($user['display_name'] ?? $user['username'] ?? ''),
                'avatarUrl'   => \Nomen\Controllers\DashboardController::avatarUrlForUser($userId) ?? '',
                'role'        => (string)($user['role'] ?? ''),
            ],
            'brand' => [
                'name' => (string)Brand::get('name'),
                'logo' => (string)Brand::get('logo'),
                'url'  => (string)Brand::get('base_url'),
            ],
            'csrf'  => Csrf::getToken(),
            'menu'  => $menu,
            'pages' => Admin::pageMeta(),
        ], $userId);

        Response::view('admin-shell', ['appProps' => $props], 'admin-app');
    }
}
