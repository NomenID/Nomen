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
 * 管理后台的菜单与页面注册表。
 *
 * 核心页面自己在控制器里渲染；插件用 Admin::page() 注册的页面由这里套后台外壳。
 * 菜单和页面分开：核心只登记菜单项，插件登记页面时自动带一个菜单项。
 */
class Admin
{
    /** @var array<string, array> */
    private static array $pages = [];
    /** @var array<string, array> */
    private static array $menu = [];
    /** @var array<string, string> */
    private static array $styles = [];
    /** @var array<string, string> */
    private static array $scripts = [];

    public static function menu(string $key, string $href, string $label, int $order = 100, string $group = ''): void
    {
        self::$menu[$key] = [
            'href'  => $href,
            'label' => $label,
            'order' => $order,
            'group' => $group,
        ];
    }

    /** 站点导航里的后台入口文字。 */
    public static function label(): string
    {
        return t('Admin');
    }

    /**
     * 注册一个插件管理页：/admin/<slug>。
     *
     * $def: title, description, handler, tabs, menu（false 表示不进菜单，字符串表示菜单文字）,
     *       order, group（侧栏分组标题）
     * tabs: [['id' => 'basic', 'label' => 'General', 'handler' => callable], ...]
     * 标题、说明、选项卡文字都是词典键，渲染时按当前语言翻。
     */
    public static function page(string $slug, array $def): void
    {
        $slug = strtolower(trim($slug));
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            error_log('[Admin] 页面 slug 只能用 a-z、0-9 和连字符：' . $slug);
            return;
        }

        $def += [
            'title'       => $slug,
            'description' => '',
            'handler'     => null,
            'tabs'        => [],
            'menu'        => true,
            'order'       => 100,
            'group'       => '',
        ];

        self::$pages[$slug] = $def;

        if ($def['menu'] !== false) {
            $label = is_string($def['menu']) ? $def['menu'] : (string)$def['title'];
            self::menu($slug, '/admin/' . $slug, $label, (int)$def['order'], (string)$def['group']);
        }
    }

    public static function hasPage(string $slug): bool
    {
        return isset(self::$pages[strtolower($slug)]);
    }

    /** @return array<string, array> */
    public static function pages(): array
    {
        return self::$pages;
    }

    /** @return array<string, array> */
    public static function menuItems(): array
    {
        $items = self::$menu;
        uasort($items, static function (array $a, array $b): int {
            return [$a['order'], $a['label']] <=> [$b['order'], $b['label']];
        });

        return Hooks::filter('admin.menu.items', $items);
    }

    /** 插件页面的公开信息（不含处理函数），给 SPA 侧栏和路由用。 */
    public static function pageMeta(): array
    {
        $out = [];
        foreach (self::$pages as $slug => $page) {
            $out[] = [
                'slug'        => $slug,
                'title'       => (string)$page['title'],
                'description' => (string)$page['description'],
                'path'        => '/admin/' . $slug,
                'group'       => (string)($page['group'] ?? ''),
                'tabs'        => self::tabsMeta($page),
            ];
        }

        return $out;
    }

    /** 必须在核心管理路由之前调用：插件管理页的数据接口。 */
    public static function applyRoutes(Router $router): void
    {
        foreach (self::$pages as $slug => $page) {
            $router->get('/api/admin/plugin/' . $slug, static function () use ($slug): void {
                self::renderApi($slug);
            }, ['admin']);
        }
    }

    /** 插件管理页返回 JSON：标题、选项卡、当前选项卡的 HTML。 */
    public static function renderApi(string $slug): void
    {
        $slug = strtolower($slug);
        $page = self::$pages[$slug] ?? null;

        if ($page === null) {
            Response::json(['success' => false, 'message' => I18n::t('Page not found')], 404);
            return;
        }
        if (!Router::isAdmin()) {
            Response::json(['success' => false, 'message' => I18n::t('You do not have permission')], 403);
            return;
        }

        $tabs = self::tabsMeta($page);
        $active = (string)($_GET['tab'] ?? ($tabs[0]['id'] ?? ''));
        $handler = null;
        foreach (array_values((array)($page['tabs'] ?? [])) as $tab) {
            if ((string)($tab['id'] ?? '') === $active) {
                $handler = $tab['handler'] ?? null;
                break;
            }
        }
        if ($tabs === []) {
            $handler = $page['handler'];
        }

        if (!is_callable($handler)) {
            Response::json(['success' => false, 'message' => I18n::t('This admin page has no handler')], 500);
            return;
        }

        $body = Hooks::filter('admin.plugin.page.body', (string)call_user_func($handler), $slug, $page, $active);

        Response::json([
            'success'     => true,
            'slug'        => $slug,
            'title'       => I18n::t((string)$page['title']),
            'description' => I18n::t((string)$page['description']),
            'tabs'        => array_map(static fn(array $tab): array => ['id' => $tab['id'], 'label' => I18n::t((string)$tab['label'])], $tabs),
            'activeTab'   => $active,
            'body'        => (string)$body,
        ]);
    }

    /** @return array<int, array{id: string, label: string}> */
    private static function tabsMeta(array $page): array
    {
        $out = [];
        foreach ((array)($page['tabs'] ?? []) as $tab) {
            $id = (string)($tab['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[] = ['id' => $id, 'label' => (string)($tab['label'] ?? $id)];
        }

        return $out;
    }

    public static function requireAdmin(): array
    {
        $userId = (int)Session::get('user_id');
        if ($userId <= 0) {
            Response::error(403);
        }

        $stmt = Database::getInstance()->prepare('SELECT id, username, role FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $admin = $stmt->fetch();

        if (!$admin || $admin['role'] !== 'admin') {
            Response::error(403);
        }

        return $admin;
    }

    public static function style(string $href): void
    {
        if ($href !== '') {
            self::$styles[$href] = $href;
        }
    }

    public static function script(string $src): void
    {
        if ($src !== '') {
            self::$scripts[$src] = $src;
        }
    }

    /** @return string[] */
    public static function styles(): array
    {
        return array_values(self::$styles);
    }

    /** @return string[] */
    public static function scripts(): array
    {
        return array_values(self::$scripts);
    }
}
