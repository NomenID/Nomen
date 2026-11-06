<?php
namespace Nomen\Src;

/**
 * 首页渲染：默认落地页、指定 Pages 页面、或 config 下的自定义模板。
 * 三种模式都走站点默认布局，站长不用改皮肤就能换首页。
 */
final class Homepage
{
    public const MODES = ['default', 'page', 'template'];

    /** @return array<string, string> */
    public static function settings(): array
    {
        $defaults = [
            'mode'            => 'default',
            'page_slug'       => '',
            'title'           => '',
            'subtitle'        => '自由的账号星系',
            'primary_label'   => '',
            'primary_url'     => '',
            'secondary_label' => '',
            'secondary_url'   => '',
            'template'        => '',
        ];

        $out = [];
        foreach ($defaults as $key => $default) {
            $out[$key] = (string)Settings::get('homepage.' . $key, $default);
        }

        if (!in_array($out['mode'], self::MODES, true)) {
            $out['mode'] = 'default';
        }

        return $out;
    }

    public static function render(): void
    {
        $home = self::settings();

        if ($home['mode'] === 'page') {
            $page = self::findPage($home['page_slug']);
            if ($page !== null) {
                Response::view('home-page', [
                    'page' => $page,
                    'html' => self::renderMarkdown((string)$page['body']),
                ]);
                return;
            }
        }

        if ($home['mode'] === 'template') {
            $file = self::templateFile($home['template']);
            if ($file !== null) {
                $brand = Brand::all();
                ob_start();
                require $file;
                $body = (string)ob_get_clean();
                Response::view('home-custom', ['body' => $body]);
                return;
            }
        }

        Response::view('home', [
            'home'  => $home,
            'brand' => Brand::all(),
            'urls'  => self::defaultUrls(),
            'links' => self::links(),
        ]);
    }

    /** @return array<int, array{label: string, href: string}> */
    public static function links(): array
    {
        $links = [];
        $docs = Plugins::mountUrl('pages');
        if ($docs !== null) {
            $links[] = ['label' => '文档', 'href' => $docs];
        }
        $open = Plugins::mountUrl('open-platform');
        if ($open !== null) {
            $links[] = ['label' => '开放平台', 'href' => $open];
        }
        $links[] = ['label' => '版本信息', 'href' => '/special/version'];

        return Hooks::filter('homepage.links', $links);
    }

    /** @return array{primary: array{label: string, href: string}, secondary: array{label: string, href: string}} */
    private static function defaultUrls(): array
    {
        $loggedIn = (bool)Session::get('user_id');

        return [
            'primary' => $loggedIn
                ? ['label' => '进入控制台', 'href' => '/dashboard']
                : ['label' => '创建账号', 'href' => '/register'],
            'secondary' => $loggedIn
                ? ['label' => '账户设置', 'href' => '/settings']
                : ['label' => '登录', 'href' => '/login'],
        ];
    }

    /** @return array<int, array{slug: string, title: string, status: int}> */
    public static function availablePages(): array
    {
        if (!class_exists(\Nomen\Plugin\Pages\Store::class)) {
            return [];
        }

        try {
            return array_map(static fn(array $page): array => [
                'slug'   => (string)$page['slug'],
                'title'  => (string)$page['title'],
                'status' => (int)$page['status'],
            ], \Nomen\Plugin\Pages\Store::all(true));
        } catch (\Throwable $e) {
            error_log('[homepage] 读取 Pages 列表失败: ' . $e->getMessage());
            return [];
        }
    }

    /** @return string[] */
    public static function templateOptions(): array
    {
        $files = glob(dirname(__DIR__) . '/config/home*.php') ?: [];
        $out = [];
        foreach ($files as $file) {
            $name = basename($file);
            if ($name !== 'home.example.php') {
                $out[] = $name;
            }
        }

        return $out;
    }

    public static function templateFile(string $name): ?string
    {
        $name = basename(trim($name));
        if ($name === '' || !str_ends_with($name, '.php') || $name === 'home.example.php') {
            return null;
        }

        $file = dirname(__DIR__) . '/config/' . $name;

        return is_file($file) ? $file : null;
    }

    private static function findPage(string $slug): ?array
    {
        if ($slug === '' || !class_exists(\Nomen\Plugin\Pages\Store::class)) {
            return null;
        }

        try {
            return \Nomen\Plugin\Pages\Store::find($slug, false);
        } catch (\Throwable $e) {
            error_log('[homepage] 读取首页页面失败: ' . $e->getMessage());
            return null;
        }
    }

    private static function renderMarkdown(string $body): string
    {
        if (!class_exists(\Nomen\Plugin\Pages\Markdown::class)) {
            return '<p>' . nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
        }

        return \Nomen\Plugin\Pages\Markdown::render($body);
    }
}
