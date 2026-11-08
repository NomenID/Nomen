<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

namespace Nomen\Src;

use Nomen\Src\Api\Core;

/**
 * 站点 API（/nomen-api）：action=query&meta=siteinfo&format=json。
 *
 * 插件在 boot() 里用 action() / meta() / list() / prop() 挂模块。
 * 权限只有两级：public 匿名可读，admin 需要管理员会话或管理员 OAuth Bearer token。
 * 总开关在后台设置里（site_settings.api.nomen_api），关掉后整个入口回 404。
 */
final class Api
{
    /** @var array<string, array{handler: callable, access: string, description: string}> */
    private static array $actions = [];
    /** @var array<string, array{handler: callable, access: string, description: string}> */
    private static array $meta = [];
    /** @var array<string, array{handler: callable, access: string, description: string}> */
    private static array $lists = [];
    /** @var array<string, array{handler: callable, access: string, description: string}> */
    private static array $props = [];

    private static ?bool $admin = null;

    public static function action(string $name, callable $handler, string $access = 'public', string $description = ''): void
    {
        self::$actions[$name] = ['handler' => $handler, 'access' => $access, 'description' => $description];
    }

    public static function meta(string $name, callable $handler, string $access = 'public', string $description = ''): void
    {
        self::$meta[$name] = ['handler' => $handler, 'access' => $access, 'description' => $description];
    }

    /** list 回调收完整参数，返回数组会被并进 query（带 continue 键的话提到顶层） */
    public static function list(string $name, callable $handler, string $access = 'public', string $description = ''): void
    {
        self::$lists[$name] = ['handler' => $handler, 'access' => $access, 'description' => $description];
    }

    /** prop 回调是 fn(array $titles, array $params): array，返回 slug => 字段 */
    public static function prop(string $name, callable $handler, string $access = 'public', string $description = ''): void
    {
        self::$props[$name] = ['handler' => $handler, 'access' => $access, 'description' => $description];
    }

    public static function enabled(): bool
    {
        return (string)Settings::get('api.nomen_api', '1') !== '0';
    }

    public static function handle(): void
    {
        self::registerCore();

        if (!self::enabled()) {
            self::error(404, 'api-disabled', t('The site API is disabled on this site.'));
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $site = (string)Brand::get('base_url');
        $rateKey = 'nomen-api:' . substr(hash('sha256', $site === '' ? 'default' : $site), 0, 12) . ':' . $ip;
        $rate = RateLimiter::check($rateKey, 120, 60);
        if (!$rate['allowed']) {
            header('Retry-After: ' . $rate['retry_after']);
            self::error(429, 'ratelimited', t('Too many requests. Try again later.'));
        }

        // 数组参数（?x[]=1）直接丢掉，后面都按字符串处理
        $params = array_filter(array_replace($_GET, $_POST), 'is_string');

        if (strtolower($params['format'] ?? 'json') !== 'json') {
            self::error(400, 'badvalue', 'Only format=json is supported.');
        }

        $action = trim($params['action'] ?? 'help');

        if ($action === 'help') {
            self::respond(['help' => self::help()]);
        }
        if ($action === 'query') {
            self::respond(self::query($params));
        }

        $module = self::$actions[$action] ?? null;
        if ($module === null) {
            self::error(400, 'nosuchaction', 'Unknown action: ' . $action);
        }

        self::requireAccess($module['access']);
        $result = ($module['handler'])($params);
        self::respond(is_array($result) ? $result : ['result' => $result]);
    }

    public static function requireAdmin(): void
    {
        if (!self::isAdmin()) {
            self::error(403, 'permissiondenied', t('This module requires an administrator.'));
        }
    }

    public static function isAdmin(): bool
    {
        if (self::$admin !== null) {
            return self::$admin;
        }

        if (Router::isAdmin()) {
            return self::$admin = true;
        }

        return self::$admin = self::tokenAdmin();
    }

    /** @return string[] */
    public static function pipe(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $out = [];
        foreach (explode('|', $value) as $item) {
            $item = trim($item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    public static function error(int $status, string $code, string $info): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['error' => ['code' => $code, 'info' => $info]],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }

    private static function registerCore(): void
    {
        // 核心模块永远压过同名插件模块
        self::$meta = [
            'siteinfo' => [
                'handler' => [Core::class, 'siteinfo'],
                'access' => 'public',
                'description' => '站点信息（siprop=general|extensions|skins|rights）',
            ],
            'version' => [
                'handler' => [Core::class, 'version'],
                'access' => 'public',
                'description' => 'Nomen 版本号',
            ],
        ] + self::$meta;
    }

    private static function query(array $params): array
    {
        $metas = self::pipe($params['meta'] ?? '');
        $lists = self::pipe($params['list'] ?? '');
        $props = self::pipe($params['prop'] ?? '');

        if ($metas === [] && $lists === [] && $props === []) {
            self::error(400, 'missingparam', 'Specify at least one of meta=, list= or prop=.');
        }

        $query = [];
        $continue = null;

        foreach ($metas as $name) {
            $module = self::$meta[$name] ?? null;
            if ($module === null) {
                self::error(400, 'unknownmodule', 'Unknown meta module: ' . $name);
            }
            self::requireAccess($module['access']);
            $data = ($module['handler'])($params);
            if (!is_array($data)) {
                continue;
            }
            if (isset($data['continue'])) {
                $continue = $data['continue'];
                unset($data['continue']);
            }
            $query = array_replace($query, $data);
        }

        foreach ($lists as $name) {
            $module = self::$lists[$name] ?? null;
            if ($module === null) {
                self::error(400, 'unknownmodule', 'Unknown list module: ' . $name);
            }
            self::requireAccess($module['access']);
            $data = ($module['handler'])($params);
            if (!is_array($data)) {
                continue;
            }
            if (isset($data['continue'])) {
                $continue = $data['continue'];
                unset($data['continue']);
            }
            $query = array_replace($query, $data);
        }

        if ($props !== []) {
            $titles = self::titles($params);
            $pages = [];

            foreach ($props as $name) {
                $module = self::$props[$name] ?? null;
                if ($module === null) {
                    self::error(400, 'unknownmodule', 'Unknown prop module: ' . $name);
                }
                self::requireAccess($module['access']);

                foreach ((array)($module['handler'])($titles, $params) as $slug => $fields) {
                    if (is_array($fields)) {
                        $pages[(string)$slug] = array_replace($pages[(string)$slug] ?? [], $fields);
                    }
                }
            }

            $query['pages'] = [];
            foreach ($titles as $title) {
                $query['pages'][] = isset($pages[$title])
                    ? ['slug' => $title] + $pages[$title]
                    : ['slug' => $title, 'missing' => true];
            }
        }

        $result = ['batchcomplete' => true, 'query' => $query];
        if ($continue !== null) {
            unset($result['batchcomplete']);
            $result['continue'] = $continue;
        }

        return $result;
    }

    /** @return string[] */
    private static function titles(array $params): array
    {
        $raw = trim((string)($params['titles'] ?? ''));
        if ($raw === '') {
            self::error(400, 'missingparam', t('The titles parameter is required when prop= is used.'));
        }

        $titles = [];
        foreach (explode('|', $raw) as $title) {
            $title = trim($title);
            if ($title !== '') {
                $titles[$title] = true;
            }
        }
        $titles = array_keys($titles);

        if (count($titles) > 50) {
            self::error(400, 'toomanyvalues', 'At most 50 titles per request.');
        }

        return $titles;
    }

    private static function requireAccess(string $access): void
    {
        if ($access === 'public') {
            return;
        }
        if ($access === 'admin') {
            self::requireAdmin();
            return;
        }

        // 插件写错权限名时拒绝，不能当成 public 放出去
        self::error(500, 'internalerror', 'Unknown access level: ' . $access);
    }

    private static function tokenAdmin(): bool
    {
        $token = self::bearerToken();
        if ($token === null) {
            return false;
        }

        $config = require dirname(__DIR__) . '/config/app.php';
        $algorithms = [$config['token_hash_algorithm'] ?? 'sha512', 'sha256'];

        $db = Database::getInstance();
        foreach ($algorithms as $algorithm) {
            $stmt = $db->prepare(
                'SELECT u.role FROM oauth_access_tokens t JOIN users u ON u.id = t.user_id '
                . 'WHERE t.token_hash = :hash AND t.revoked = 0 AND t.expires_at > NOW() '
                . 'AND u.status = 1 LIMIT 1'
            );
            $stmt->execute([':hash' => hash($algorithm, $token)]);
            $role = $stmt->fetchColumn();

            if ($role !== false) {
                return $role === 'admin';
            }
        }

        return false;
    }

    private static function bearerToken(): ?string
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        if (preg_match('/^Bearer\s+(.+)$/i', $auth, $matches)) {
            return $matches[1];
        }

        $token = $_POST['access_token'] ?? $_GET['access_token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    private static function help(): array
    {
        $config = require dirname(__DIR__) . '/config/app.php';

        return [
            'product' => 'Nomen',
            'version' => (string)($config['version'] ?? ''),
            'endpoint' => rtrim((string)Brand::get('base_url'), '/') . '/nomen-api',
            'format' => 'json',
            'formatversion' => 2,
            'actions' => [
                'help' => ['description' => '显示这份帮助', 'access' => 'public'],
                'query' => ['description' => '读取数据（meta / list / prop）', 'access' => 'public'],
            ] + self::describe(self::$actions),
            'query' => [
                'meta' => self::describe(self::$meta),
                'list' => self::describe(self::$lists),
                'prop' => self::describe(self::$props),
            ],
        ];
    }

    private static function describe(array $modules): array
    {
        $out = [];
        foreach ($modules as $name => $module) {
            $out[$name] = ['description' => $module['description'], 'access' => $module['access']];
        }
        ksort($out);

        return $out;
    }

    private static function respond(array $data): void
    {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}
