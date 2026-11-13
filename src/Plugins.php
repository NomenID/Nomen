<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src;

class Plugins
{
    private static ?array $all = null;
    private static array $loaded = [];
    /** @var array<int, array{method: string, path: string, handler: callable|array, middleware: string[]}> */
    private static array $routes = [];
    /** @var array<string, array> */
    private static array $mounts = [];
    /** @var array<string, string> */
    private static array $styles = [];
    /** @var array<string, string> */
    private static array $scripts = [];
    /** @var array<string, array{handler: callable, description: string}> */
    private static array $commands = [];

    private const ASSET_TYPES = [
        'css'   => 'text/css; charset=utf-8',
        'js'    => 'text/javascript; charset=utf-8',
        'svg'   => 'image/svg+xml',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'json'  => 'application/json; charset=utf-8',
    ];

    public static function dir(): string
    {
        return LocalPaths::get('plugins', dirname(__DIR__) . '/plugins');
    }

    /**
     * 扫 plugins/* /plugin.json。
     *
     * @return array<string, array> slug => manifest
     */
    public static function all(): array
    {
        if (self::$all !== null) {
            return self::$all;
        }

        $plugins = [];
        foreach (glob(self::dir() . '/*/plugin.json') ?: [] as $file) {
            $meta = json_decode((string)file_get_contents($file), true);
            if (!is_array($meta) || empty($meta['name'])) {
                continue;
            }
            $slug = basename(dirname($file));
            $meta['slug'] = $slug;
            $plugins[$slug] = $meta;
        }
        ksort($plugins);

        return self::$all = $plugins;
    }

    /** @return string[] */
    public static function enabled(): array
    {
        $file = dirname(__DIR__) . '/config/plugins.php';
        $config = is_file($file) ? require $file : [];

        return array_values(array_map('strval', (array)($config['enabled'] ?? [])));
    }

    public static function boot(): void
    {
        $config = require dirname(__DIR__) . '/config/app.php';
        $coreVersion = (string)($config['version'] ?? '0');

        foreach (self::enabled() as $slug) {
            $manifest = self::all()[$slug] ?? null;
            if ($manifest === null) {
                error_log('[Plugins] 找不到插件：' . $slug);
                continue;
            }

            $need = (string)($manifest['requires']['Nomen'] ?? '');
            if ($need !== '' && !self::satisfies($coreVersion, $need)) {
                error_log('[Plugins] ' . $slug . ' 要求 Nomen ' . $need . '，当前 ' . $coreVersion);
                continue;
            }

            $missing = array_filter(
                (array)($manifest['requires']['php'] ?? []),
                static fn(string $ext): bool => !extension_loaded($ext)
            );
            if ($missing !== []) {
                error_log('[Plugins] ' . $slug . ' 缺 PHP 扩展：' . implode(', ', $missing));
                continue;
            }

            self::registerAutoload($slug, $manifest);
            self::registerHooks($manifest);
            self::registerSpecialPages($manifest);
            self::registerAdminPages($slug, $manifest);
            self::registerMounts($slug, $manifest);

            if (!empty($manifest['boot']) && is_callable($manifest['boot'])) {
                call_user_func($manifest['boot']);
            }

            self::$loaded[$slug] = $manifest;
        }
    }

    /** @return array<string, array> */
    public static function loaded(): array
    {
        return self::$loaded;
    }

    /**
     * 插件配置：config/plugins/<slug>.json 或 .ini。
     *
     * 配置放核心的 config/ 目录、不放插件目录，这样升级插件不会覆盖站长的设置。
     * 没有配置文件时用调用方给的默认值。
     */
    public static function config(string $slug, array $defaults = []): array
    {
        $base = dirname(__DIR__) . '/config/plugins/' . $slug;

        foreach (['json', 'ini'] as $format) {
            $file = $base . '.' . $format;
            if (!is_file($file)) {
                continue;
            }

            $data = $format === 'json'
                ? json_decode((string)file_get_contents($file), true)
                : parse_ini_file($file, true);

            if (!is_array($data)) {
                error_log('[Plugins] 配置文件格式不对：' . $file);
                return $defaults;
            }

            return array_replace_recursive($defaults, $data);
        }

        return $defaults;
    }

    /** 写回 JSON 配置（管理后台改挂载点这类用），只合并传入的键。 */
    public static function setConfig(string $slug, array $data): bool
    {
        $dir = dirname(__DIR__) . '/config/plugins';
        if (!is_dir($dir)) {
            return false;
        }

        $file = $dir . '/' . $slug . '.json';
        $existing = [];
        if (is_file($file)) {
            $existing = json_decode((string)file_get_contents($file), true);
            if (!is_array($existing)) {
                $existing = [];
            }
        }

        $merged = array_replace_recursive($existing, $data);
        $json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json !== false && file_put_contents($file, $json . "\n") !== false;
    }

    /**
     * 插件在 boot 里注册路由。路径由插件自己决定（通常来自 Plugins::config()），
     * 在 index.php 建好 Router 之后统一灌进去。
     */
    public static function route(string $method, string $path, callable|array $handler, array $middleware = []): void
    {
        self::$routes[] = [
            'method'     => strtoupper($method),
            'path'       => '/' . trim($path, '/'),
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    /** @return array<int, array> */
    public static function routes(): array
    {
        return self::$routes;
    }

    /**
     * 把插件路由灌进 Router。必须在核心路由之前调用：
     * 核心路由后注册，同名的精确路径会被核心覆盖，插件顶不掉登录、OAuth 这些入口。
     */
    public static function applyRoutes(Router $router): void
    {
        foreach (self::$routes as $route) {
            if ($route['method'] === 'POST') {
                $router->post($route['path'], $route['handler'], $route['middleware']);
            } elseif ($route['method'] === 'ANY') {
                $router->any($route['path'], $route['handler'], $route['middleware']);
            } else {
                $router->get($route['path'], $route['handler'], $route['middleware']);
            }
        }
    }

    /**
     * 登记一个公开挂载点：后台「接入点」页会列出来，站长还能改路径。
     *
     * $def: id, path, title, description, access(public|admin), config（配置键名）
     */
    public static function mount(string $slug, array $def): void
    {
        $id = trim((string)($def['id'] ?? $slug));
        if ($id === '') {
            $id = $slug;
        }

        self::$mounts[$slug . ':' . $id] = $def + [
            'id'          => $id,
            'plugin'      => $slug,
            'path'        => '',
            'title'       => $id,
            'description' => '',
            'access'      => 'public',
            'config'      => null,
        ];
    }

    /** @return array<int, array> */
    public static function mounts(): array
    {
        $out = [];

        foreach (self::$mounts as $key => $mount) {
            $path = (string)($mount['path'] ?? '');
            if (!empty($mount['config'])) {
                $config = self::config((string)$mount['plugin'], []);
                $path = (string)($config[(string)$mount['config']] ?? $path);
            }

            $mount['path'] = '/' . trim($path, '/');
            $out[$key] = $mount;
        }

        uasort($out, static fn(array $a, array $b): int => [$a['plugin'], $a['id']] <=> [$b['plugin'], $b['id']]);

        return array_values($out);
    }

    /** 取某个插件的挂载点 URL；没装或没声明返回 null。 */
    public static function mountUrl(string $slug, ?string $id = null): ?string
    {
        foreach (self::mounts() as $mount) {
            if ($mount['plugin'] === $slug && ($id === null || $mount['id'] === $id)) {
                return $mount['path'];
            }
        }

        return null;
    }

    /** 插件静态资源 URL；路径相对插件的 assets/ 目录。 */
    public static function assetUrl(string $slug, string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        $file = self::dir() . '/' . $slug . '/assets/' . $path;
        $version = is_file($file) ? (string)filemtime($file) : '0';
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));

        return Url::to('/plugin-assets/' . rawurlencode($slug) . '/' . $encoded, ['v' => $version]);
    }

    public static function style(string $slug, string $path): void
    {
        $url = self::assetUrl($slug, $path);
        self::$styles[$url] = $url;
    }

    public static function script(string $slug, string $path): void
    {
        $url = self::assetUrl($slug, $path);
        self::$scripts[$url] = $url;
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

    /** 插件注册一个 CLI 命令，用 php bin/nomen plugins <名字> 跑。 */
    public static function command(string $name, callable $handler, string $description = ''): void
    {
        self::$commands[$name] = ['handler' => $handler, 'description' => $description];
    }

    /** @return array<string, array{handler: callable, description: string}> */
    public static function commands(): array
    {
        return self::$commands;
    }

    /** 由 /plugin-assets/{slug}/{path} 路由调用；只发插件 assets/ 下的白名单类型。 */
    public static function serveAsset(string $slug, string $path): void
    {
        $slug = strtolower(trim($slug));
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) || !in_array($slug, self::enabled(), true)) {
            self::assetNotFound();
            return;
        }

        $base = realpath(self::dir() . '/' . $slug . '/assets');
        if ($base === false) {
            self::assetNotFound();
            return;
        }

        $relative = ltrim(str_replace('\\', '/', rawurldecode($path)), '/');
        if ($relative === '' || preg_match('/[\x00-\x1F]/', $relative)) {
            self::assetNotFound();
            return;
        }

        $file = realpath($base . '/' . $relative);
        if ($file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
            self::assetNotFound();
            return;
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!isset(self::ASSET_TYPES[$ext])) {
            self::assetNotFound();
            return;
        }

        header('Content-Type: ' . self::ASSET_TYPES[$ext]);
        header('Cache-Control: public, max-age=604800');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    private static function assetNotFound(): void
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not Found';
        exit;
    }

    private static function satisfies(string $version, string $constraint): bool
    {
        if (!preg_match('/^(>=|<=|>|<|=)?\s*([0-9][0-9A-Za-z.\-]*)$/', $constraint, $m)) {
            return true;
        }

        // quickbuild 的 1.5.0+qb.<日期> 按基础版本比较，否则会被当成预发布版
        $version = (string)preg_replace('/\+.*$/', '', $version);

        return version_compare($version, $m[2], $m[1] !== '' ? $m[1] : '>=');
    }

    private static function registerAutoload(string $slug, array $manifest): void
    {
        foreach ((array)($manifest['autoload'] ?? []) as $prefix => $dir) {
            $base = self::dir() . '/' . $slug . '/' . trim((string)$dir, '/') . '/';
            spl_autoload_register(static function (string $class) use ($prefix, $base): void {
                if (!str_starts_with($class, $prefix)) {
                    return;
                }
                $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (file_exists($file)) {
                    require $file;
                }
            });
        }
    }

    private static function registerHooks(array $manifest): void
    {
        foreach ((array)($manifest['hooks'] ?? []) as $event => $handlers) {
            foreach (self::normalizeHandlers($handlers) as [$handler, $priority]) {
                if (!is_callable($handler)) {
                    error_log('[Plugins] ' . $manifest['slug'] . ' 的钩子 ' . $event . ' 不可调用');
                    continue;
                }
                Hooks::add((string)$event, $handler, $priority);
            }
        }
    }

    /**
     * 清单里可以写 ["Class", "method"]，也可以写一组回调；
     * 想调优先级就写 {"handler": ["Class", "method"], "priority": 5}。
     *
     * @return array<int, array{0: callable|array, 1: int}>
     */
    private static function normalizeHandlers(mixed $handlers): array
    {
        if (!is_array($handlers)) {
            return [[$handlers, 10]];
        }

        if (isset($handlers[0]) && is_string($handlers[0])) {
            return [[$handlers, 10]];
        }

        $out = [];
        foreach ($handlers as $item) {
            if (is_array($item) && isset($item['handler'])) {
                $out[] = [$item['handler'], (int)($item['priority'] ?? 10)];
            } else {
                $out[] = [$item, 10];
            }
        }

        return $out;
    }

    private static function registerSpecialPages(array $manifest): void
    {
        foreach ((array)($manifest['specialPages'] ?? []) as $slug => $def) {
            if (!is_array($def)) {
                continue;
            }
            SpecialPages::add((string)$slug, $def);
        }
    }

    private static function registerAdminPages(string $slug, array $manifest): void
    {
        foreach ((array)($manifest['adminPages'] ?? []) as $pageSlug => $def) {
            if (!is_array($def)) {
                continue;
            }
            Admin::page((string)$pageSlug, $def);
        }
    }

    private static function registerMounts(string $slug, array $manifest): void
    {
        foreach ((array)($manifest['mounts'] ?? []) as $id => $def) {
            if (!is_array($def)) {
                continue;
            }
            self::mount($slug, ['id' => (string)$id] + $def);
        }
    }
}
