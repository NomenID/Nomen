<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

namespace Nomen\Src;

use Nomen\Src\Cache\DbDriver;
use Nomen\Src\Cache\Driver;
use Nomen\Src\Cache\FileDriver;
use Nomen\Src\Cache\RedisDriver;

/**
 * 通用缓存：Redis / 文件 / 数据库三种内置驱动，后台选，插件也能注册自己的。
 *
 * 选中的驱动不可用（Redis 挂了、DB 还没建表）时逐个回退，最后落到文件；
 * 缓存是加速，不该让页面直接报错。
 */
class Cache
{
    private const BUILTIN = [
        'redis' => 'Redis',
        'file'  => '文件',
        'db'    => '数据库',
    ];

    /** @var array<string, callable> */
    private static array $drivers = [];

    private static ?Driver $instance = null;
    private static string $name = '';
    private static ?object $miss = null;

    /** 插件注册自定义驱动：$factory 返回一个 Nomen\Src\Cache\Driver */
    public static function registerDriver(string $name, callable $factory): void
    {
        $name = strtolower(trim($name));
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name)) {
            error_log('[cache] 驱动名只能用 a-z、0-9、下划线和连字符: ' . $name);
            return;
        }

        self::$drivers[$name] = $factory;
    }

    /** @return array<string, string> 驱动名 => 后台显示名 */
    public static function available(): array
    {
        $all = self::BUILTIN;
        foreach (array_keys(self::$drivers) as $name) {
            $all[$name] ??= $name;
        }

        return $all;
    }

    /** 实际生效的驱动（选了不可用的会回退，这里返回回退后的） */
    public static function driverName(): string
    {
        self::resolve();

        return self::$name;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $driver = self::resolve();
        if ($driver === null) {
            return $default;
        }

        try {
            $value = $driver->get($key);
        } catch (\Throwable $e) {
            error_log('[cache] 读取失败: ' . $e->getMessage());
            return $default;
        }

        return $value ?? $default;
    }

    public static function put(string $key, mixed $value, int $ttl = 60): bool
    {
        $driver = self::resolve();
        if ($driver === null) {
            return false;
        }

        try {
            $driver->put($key, $value, $ttl);
            return true;
        } catch (\Throwable $e) {
            error_log('[cache] 写入失败: ' . $e->getMessage());
            return false;
        }
    }

    public static function remember(string $key, int $ttl, callable $factory): mixed
    {
        $cached = self::get($key, self::miss());
        if ($cached !== self::miss()) {
            return $cached;
        }

        $value = $factory();
        self::put($key, $value, $ttl);

        return $value;
    }

    public static function forget(string $key): void
    {
        $driver = self::resolve();
        if ($driver === null) {
            return;
        }

        try {
            $driver->forget($key);
        } catch (\Throwable $e) {
            error_log('[cache] 删除失败: ' . $e->getMessage());
        }
    }

    private static function resolve(): ?Driver
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        if (self::$name === 'none') {
            return null;
        }

        $choice = (string)Settings::get('cache.backend', 'auto');
        if (!isset(self::BUILTIN[$choice]) && !isset(self::$drivers[$choice])) {
            $choice = 'auto';
        }

        $candidates = $choice === 'auto' ? ['redis', 'file'] : [$choice, 'file'];

        foreach (array_unique($candidates) as $name) {
            try {
                self::$instance = self::create($name);
                self::$name = $name;

                return self::$instance;
            } catch (\Throwable $e) {
                error_log('[cache] ' . $name . ' 不可用：' . $e->getMessage());
            }
        }

        self::$name = 'none';

        return null;
    }

    private static function create(string $name): Driver
    {
        switch ($name) {
            case 'redis':
                $client = RedisClient::get();
                if ($client === null) {
                    throw new \RuntimeException('Redis 连接失败或 phpredis 扩展没装');
                }

                return new RedisDriver($client, self::redisPrefix());

            case 'file':
                return new FileDriver(dirname(__DIR__) . '/data/cache');

            case 'db':
                return new DbDriver();
        }

        $factory = self::$drivers[$name] ?? null;
        if (!is_callable($factory)) {
            throw new \RuntimeException('未知缓存驱动：' . $name);
        }

        $driver = $factory();
        if (!$driver instanceof Driver) {
            throw new \RuntimeException('缓存驱动 ' . $name . ' 没有返回 ' . Driver::class);
        }

        return $driver;
    }

    private static function miss(): object
    {
        return self::$miss ??= new \stdClass();
    }

    /** Redis 可能被多个站点共用，键里带上站点地址的哈希 */
    private static function redisPrefix(): string
    {
        $site = (string)Brand::get('base_url');

        return 'nomen:cache:' . substr(hash('sha256', $site === '' ? 'default' : $site), 0, 16) . ':';
    }
}
