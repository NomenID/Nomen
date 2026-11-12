<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src\Cli;

use Nomen\Src\Brand;
use Nomen\Src\Database;

/**
 * 装完 / 搬家 / 升完级之后跑一遍：把「网站打不开但日志里看不出所以然」的那些前提逐条查掉。
 * 只读，不改任何东西。
 */
class Doctor
{
    private static int $warnings = 0;
    private static int $failures = 0;

    public static function run(array $args): int
    {
        $root = dirname(__DIR__, 2);

        echo 'Nomen 自检', PHP_EOL, PHP_EOL;

        self::check('PHP 版本', PHP_VERSION_ID >= 80100, PHP_VERSION, true);

        $required = ['pdo', 'openssl', 'mbstring', 'json', 'fileinfo'];
        foreach ($required as $ext) {
            self::check('扩展 ' . $ext, extension_loaded($ext), extension_loaded($ext) ? '已加载' : '缺失');
        }

        $driver = strtolower((string)getenv('DB_DRIVER'));
        $installed = is_file($root . '/config/installed.php') ? require $root . '/config/installed.php' : [];
        $driver = $driver !== '' ? $driver : strtolower((string)($installed['db']['driver'] ?? 'mysql'));
        $pdoExt = $driver === 'sqlite' ? 'pdo_sqlite' : 'pdo_mysql';
        self::check('扩展 ' . $pdoExt, extension_loaded($pdoExt), extension_loaded($pdoExt) ? '已加载' : '缺失（数据库要用）');

        self::check('扩展 gd', extension_loaded('gd'), extension_loaded('gd') ? '已加载' : '没装（头像上传会降级成链接）', false);
        self::check('扩展 redis', extension_loaded('redis'), extension_loaded('redis') ? '已加载' : '没装（限流与会话清理会降级）', false);

        $configReady = is_file($root . '/config/installed.php') || is_file($root . '/.env');
        self::check('安装状态', $configReady, $configReady ? '已安装' : '还没装：先跑 php bin/nomen install');

        $writable = [
            'data/' => $root . '/data',
            'public/assets/avatars/' => $root . '/public/assets/avatars',
            'public/assets/recovery/' => $root . '/public/assets/recovery',
            'public/assets/develops/' => $root . '/public/assets/develops',
        ];
        foreach ($writable as $label => $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            self::check('可写 ' . $label, is_dir($dir) && is_writable($dir), is_dir($dir) ? (is_writable($dir) ? '可写' : '不可写') : '目录不存在');
        }

        $pepper = (string)(getenv('APP_PEPPER') ?: ($installed['pepper'] ?? ''));
        self::check('APP_PEPPER', $pepper !== '' && !str_starts_with($pepper, 'change-me-to'), $pepper === '' ? '没配置' : '已配置');

        $baseUrl = (string)Brand::get('base_url', '');
        $looksLocal = $baseUrl === '' || str_contains($baseUrl, 'localhost') || str_contains($baseUrl, '127.0.0.1');
        self::check('站点地址', $baseUrl !== '' && !$looksLocal, $baseUrl === '' ? '没配置' : $baseUrl, false);

        if ($configReady) {
            self::database($root, $installed);
        }

        echo PHP_EOL;
        if (self::$failures > 0) {
            echo self::$failures, ' 项没通过', self::$warnings > 0 ? '，' . self::$warnings . ' 项提醒' : '', '。', PHP_EOL;
            return 1;
        }
        echo '全部通过', self::$warnings > 0 ? '（' . self::$warnings . ' 项提醒）' : '', '。', PHP_EOL;
        return 0;
    }

    private static function database(string $root, array $installed): void
    {
        $config = require $root . '/config/app.php';

        try {
            $pdo = Database::connect($config['db']);
            $pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            self::check('数据库连接', false, $e->getMessage());
            return;
        }

        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $version = $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
        self::check('数据库连接', true, $driver . ' ' . $version);

        try {
            $done = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            self::check('迁移记录', false, 'schema_migrations 表不存在，跑 php bin/nomen upgrade');
            return;
        }

        $files = glob($root . '/database/migrations/*.sql') ?: [];
        $pending = [];
        foreach ($files as $file) {
            $name = basename($file);
            $legacy = (string)preg_replace('/\.(mysql|sqlite)\.sql$/', '.sql', $name);
            if (!in_array($name, $done, true) && !in_array($legacy, $done, true)) {
                $pending[] = $name;
            }
        }

        $pendingCount = count(array_filter($pending, static function (string $name) use ($driver): bool {
            return str_ends_with($name, '.' . ($driver === 'sqlite' ? 'sqlite' : 'mysql') . '.sql');
        }));

        self::check('待应用迁移', $pendingCount === 0, $pendingCount === 0 ? '没有' : $pendingCount . ' 个：php bin/nomen upgrade');
    }

    private static function check(string $label, bool $ok, string $detail, bool $fatal = true): void
    {
        if ($ok) {
            echo '  [ OK ] ', $label, $detail !== '' ? ' — ' . $detail : '', PHP_EOL;
            return;
        }

        if ($fatal) {
            self::$failures++;
            echo '  [FAIL] ', $label, ' — ', $detail, PHP_EOL;
        } else {
            self::$warnings++;
            echo '  [WARN] ', $label, ' — ', $detail, PHP_EOL;
        }
    }
}