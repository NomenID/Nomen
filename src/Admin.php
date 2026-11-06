<?php
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

    public static function menu(string $key, string $href, string $label, int $order = 100): void
    {
        self::$menu[$key] = [
            'href'  => $href,
            'label' => $label,
            'order' => $order,
        ];
    }

    /** 站点导航里的后台入口文字；后台内部仍然固定中文。 */
    public static function label(): string
    {
        return t('管理后台');
    }

    /**
     * 注册一个插件管理页：/admin/<slug>。
     *
     * $def: title, description, handler, menu（false 表示不进菜单，字符串表示菜单文字）, order
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
            'menu'        => true,
            'order'       => 100,
        ];

        self::$pages[$slug] = $def;

        if ($def['menu'] !== false) {
            $label = is_string($def['menu']) ? $def['menu'] : (string)$def['title'];
            self::menu($slug, '/admin/' . $slug, $label, (int)$def['order']);
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

        return $items;
    }

    /** 必须在核心管理路由之前调用：同名路径由核心覆盖。 */
    public static function applyRoutes(Router $router): void
    {
        foreach (self::$pages as $slug => $page) {
            $router->get('/admin/' . $slug, static function () use ($slug): void {
                self::render($slug);
            }, ['admin']);
        }
    }

    public static function render(string $slug): void
    {
        $slug = strtolower($slug);
        $page = self::$pages[$slug] ?? null;

        if ($page === null) {
            Response::error(404);
            return;
        }
        if (!Router::isAdmin()) {
            Response::error(403);
            return;
        }
        if (!is_callable($page['handler'])) {
            Response::error(500, '管理页面没有处理函数');
            return;
        }

        $body = call_user_func($page['handler']);
        if ($body === null) {
            return;
        }

        Response::view('admin/plugin-page', [
            'layout'     => 'admin',
            'pageTitle'  => (string)$page['title'],
            'activeMenu' => $slug,
            'page'       => $page,
            'body'       => (string)$body,
        ]);
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
