<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

namespace Nomen\Src;

class Router
{
    /** @var array<string, array<string, array{handler: callable|array, middleware: string[]}>> */
    private array $routes = [];

    public function get(string $path, callable|array $handler, array $middleware = []): self
    {
        $this->routes['GET'][$path] = ['handler' => $handler, 'middleware' => $middleware];
        return $this;
    }

    public function post(string $path, callable|array $handler, array $middleware = []): self
    {
        $this->routes['POST'][$path] = ['handler' => $handler, 'middleware' => $middleware];
        return $this;
    }

    public function any(string $path, callable|array $handler, array $middleware = []): self
    {
        $this->routes['GET'][$path] = ['handler' => $handler, 'middleware' => $middleware];
        $this->routes['POST'][$path] = ['handler' => $handler, 'middleware' => $middleware];
        return $this;
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // 去掉 base path
        $base = dirname($_SERVER['SCRIPT_NAME']);
        if ($base !== '/' && $base !== '\\') {
            $uri = substr($uri, strlen($base));
        }
        $uri = '/' . trim($uri, '/');

        // 尝试精确匹配
        if (isset($this->routes[$method][$uri])) {
            $this->call($this->routes[$method][$uri]);
            return;
        }

        // 尝试带参数的路由匹配
        foreach ($this->routes[$method] ?? [] as $pattern => $route) {
            if (preg_match($this->compile($pattern), $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $this->call($route, $params);
                return;
            }
        }

        Response::error(404);
    }

    /**
     * 路由模式编译：{name} 匹配一段，{path:.*} 可以匹配多段（插件静态资源用）。
     * 静态部分一律转义，别让路径里的点号、括号变成正则元字符。
     */
    private function compile(string $pattern): string
    {
        $parts = preg_split('/(\{\w+(?::[^}]+)?\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex = '';

        foreach ($parts as $part) {
            if (preg_match('/^\{(\w+)(?::([^}]+))?\}$/', $part, $m)) {
                $regex .= '(?P<' . $m[1] . '>' . ($m[2] ?? '[^/]+') . ')';
            } else {
                $regex .= preg_quote($part, '#');
            }
        }

        return '#^' . $regex . '$#';
    }

    private function call(array $route, array $params = []): void
    {
        if (!self::passMiddleware($route['middleware'])) {
            return;
        }

        $handler = $route['handler'];

        if (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = new $class();
            call_user_func_array([$controller, $method], $params);
        } else {
            call_user_func_array($handler, $params);
        }
    }

    /**
     * 依次执行路由中间件
     *
     * 返回 false 表示请求已被中间件拦截（响应已输出，无需再进控制器）
     */
    private static function passMiddleware(array $middleware): bool
    {
        foreach ($middleware as $name) {
            switch ($name) {
                case 'auth':
                    if (!Session::get('user_id')) {
                        Response::redirect('/login?redirect_to=' . urlencode($_SERVER['REQUEST_URI']));
                        return false;
                    }
                    break;

                case 'admin':
                    // 每次查库判定，不信 session 里的 role 快照（降权后旧会话仍带着 admin）
                    if (!self::isAdmin()) {
                        Response::error(403);
                        return false;
                    }
                    break;

                default:
                    // 名字写错时必须拒绝，不能放行
                    error_log('[Router] 未知中间件: ' . $name);
                    Response::error(500);
                    return false;
            }
        }

        return true;
    }

    public static function isAdmin(): bool
    {
        $userId = Session::get('user_id');
        if (!$userId) {
            return false;
        }

        $stmt = Database::getInstance()->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => (int)$userId]);
        return $stmt->fetchColumn() === 'admin';
    }
}
