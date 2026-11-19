<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src;

use PDO;
use Throwable;

/**
 * 站点健康：把 CLI doctor 和后台零散的检查收成一份结构化清单。
 *
 * 每条测试形如 ['id','label','status','description','value']，status 是
 * good / recommended / critical。插件可以用 admin.site_health.tests 钩子追加。
 */
final class SiteHealth
{
    private const RANK = ['good' => 0, 'recommended' => 1, 'critical' => 2];

    /** @return array{status: string, tests: array<int, array<string, string>>, generatedAt: int} */
    public static function run(): array
    {
        $tests = [];

        self::add($tests, 'php', t('PHP version'), self::phpTest(), PHP_VERSION);

        [$extStatus, $extDescription] = self::extensionsTest();
        self::add($tests, 'extensions', t('PHP extensions'), [$extStatus, $extDescription], '');

        [$dbStatus, $dbDescription] = self::databaseTest();
        self::add($tests, 'database', t('Database'), [$dbStatus, $dbDescription], '');

        [$migrationStatus, $migrationDescription] = self::migrationTest();
        self::add($tests, 'migrations', t('Database migrations'), [$migrationStatus, $migrationDescription], '');

        [$writableStatus, $writableDescription] = self::writableTest();
        self::add($tests, 'writable', t('Writable directories'), [$writableStatus, $writableDescription], '');

        [$schedulerStatus, $schedulerDescription] = self::schedulerTest();
        self::add($tests, 'scheduler', t('Scheduler'), [$schedulerStatus, $schedulerDescription], '');

        [$mailStatus, $mailDescription] = self::mailTest();
        self::add($tests, 'mail', t('Mail'), [$mailStatus, $mailDescription], '');

        [$urlStatus, $urlDescription] = self::urlTest();
        self::add($tests, 'site-url', t('Site URL'), [$urlStatus, $urlDescription], (string)Brand::get('base_url'));

        [$debugStatus, $debugDescription] = self::debugTest();
        self::add($tests, 'debug', t('Debug mode'), [$debugStatus, $debugDescription], '');

        [$pepperStatus, $pepperDescription] = self::pepperTest();
        self::add($tests, 'pepper', t('Security pepper'), [$pepperStatus, $pepperDescription], '');

        [$cacheStatus, $cacheDescription] = self::cacheTest();
        self::add($tests, 'cache', t('Cache'), [$cacheStatus, $cacheDescription], Cache::driverName());

        [$updateStatus, $updateDescription] = self::updateTest();
        self::add($tests, 'updates', t('Automatic updates'), [$updateStatus, $updateDescription], '');

        [$opcacheStatus, $opcacheDescription] = self::opcacheTest();
        self::add($tests, 'opcache', t('Opcache'), [$opcacheStatus, $opcacheDescription], '');

        [$diskStatus, $diskDescription] = self::diskTest();
        self::add($tests, 'disk', t('Disk space'), [$diskStatus, $diskDescription], '');

        [$pluginStatus, $pluginDescription] = self::pluginsTest();
        self::add($tests, 'plugins', t('Plugin requirements'), [$pluginStatus, $pluginDescription], '');

        try {
            $filtered = Hooks::filter('admin.site_health.tests', $tests, [
                'version' => self::version(),
                'php'     => PHP_VERSION,
            ]);
        } catch (Throwable $e) {
            error_log('[site-health] 插件检查项失败: ' . $e->getMessage());
            $filtered = $tests;
        }

        $clean = [];
        foreach ((array)$filtered as $test) {
            if (!is_array($test)) {
                continue;
            }
            $id = (string)($test['id'] ?? '');
            if (preg_match('/^[a-z][a-z0-9._-]{0,63}$/', $id) !== 1) {
                continue;
            }
            $status = (string)($test['status'] ?? 'good');
            if (!isset(self::RANK[$status])) {
                $status = 'good';
            }
            $clean[] = [
                'id'          => $id,
                'label'       => (string)($test['label'] ?? $id),
                'status'      => $status,
                'description' => (string)($test['description'] ?? ''),
                'value'       => (string)($test['value'] ?? ''),
            ];
        }

        $overall = 'good';
        foreach ($clean as $test) {
            if (self::RANK[$test['status']] > self::RANK[$overall]) {
                $overall = $test['status'];
            }
        }

        return [
            'status'      => $overall,
            'tests'       => $clean,
            'generatedAt' => time(),
        ];
    }

    /** @param string[] $statuses */
    public static function worstStatus(array $statuses): string
    {
        $worst = 'good';
        foreach ($statuses as $status) {
            if (isset(self::RANK[$status]) && self::RANK[$status] > self::RANK[$worst]) {
                $worst = $status;
            }
        }

        return $worst;
    }

    private static function add(array &$tests, string $id, string $label, array $result, string $value): void
    {
        $tests[] = [
            'id'          => $id,
            'label'       => $label,
            'status'      => $result[0],
            'description' => $result[1],
            'value'       => $value,
        ];
    }

    /** @return array{0: string, 1: string} */
    private static function phpTest(): array
    {
        return version_compare(PHP_VERSION, '8.1.0', '>=')
            ? ['good', t('PHP 8.1 or newer is required.')]
            : ['critical', t('PHP 8.1 or newer is required; this server is running an older version.')];
    }

    /** @return array{0: string, 1: string} */
    private static function extensionsTest(): array
    {
        $required = ['pdo', 'openssl', 'mbstring', 'json', 'fileinfo'];
        $recommended = ['zip', 'curl', 'gd', 'redis'];
        $missing = array_values(array_filter($required, static fn(string $ext): bool => !extension_loaded($ext)));
        $optional = array_values(array_filter($recommended, static fn(string $ext): bool => !extension_loaded($ext)));

        if ($missing !== []) {
            return ['critical', sprintf(t('Missing required PHP extensions: %s'), implode(', ', $missing))];
        }
        if ($optional !== []) {
            return ['recommended', sprintf(t('Some optional PHP extensions are missing: %s'), implode(', ', $optional))];
        }

        return ['good', t('All required PHP extensions are loaded.')];
    }

    /** @return array{0: string, 1: string} */
    private static function databaseTest(): array
    {
        try {
            $db = Database::getInstance();
            $db->query('SELECT 1');

            return ['good', sprintf(t('Connected to %s.'), (string)$db->getAttribute(PDO::ATTR_DRIVER_NAME))];
        } catch (Throwable $e) {
            return ['critical', t('The database is not reachable.') . ' ' . $e->getMessage()];
        }
    }

    /** @return array{0: string, 1: string} */
    private static function migrationTest(): array
    {
        try {
            $mismatch = Upgrade::mismatch(Database::getInstance());
        } catch (Throwable $e) {
            return ['critical', t('Could not check pending migrations.')];
        }

        if ($mismatch !== null) {
            return [
                'critical',
                sprintf(
                    t('Database schema is at %s but the code is %s. Run php bin/nomen upgrade.'),
                    (string)($mismatch['db'] ?? '?'),
                    (string)($mismatch['code'] ?? '?')
                ),
            ];
        }

        return ['good', t('No pending database migrations.')];
    }

    /** @return array{0: string, 1: string} */
    private static function writableTest(): array
    {
        $root = dirname(__DIR__);
        $dirs = ['data', 'public/assets/avatars', 'public/assets/recovery', 'public/assets/develops'];
        $bad = [];
        foreach ($dirs as $dir) {
            $path = $root . '/' . $dir;
            if (!is_dir($path) || !is_writable($path)) {
                $bad[] = $dir;
            }
        }

        return $bad === []
            ? ['good', t('All runtime directories are writable.')]
            : ['critical', sprintf(t('These directories are not writable: %s'), implode(', ', $bad))];
    }

    /** @return array{0: string, 1: string} */
    private static function schedulerTest(): array
    {
        if (!Schedule::ready()) {
            return ['recommended', t('The scheduled_tasks table is missing. Run php bin/nomen upgrade.')];
        }

        $banner = Schedule::banner();
        if (!empty($banner['stale'])) {
            return ['recommended', t('Cron is not running. Add the schedule run entry to the server crontab.')];
        }

        return ['good', t('The scheduler heartbeat is running.')];
    }

    /** @return array{0: string, 1: string} */
    private static function mailTest(): array
    {
        return Email::isConfigured()
            ? ['good', t('SMTP is configured.')]
            : ['recommended', t('SMTP is not configured; verification and update emails cannot be sent.')];
    }

    /** @return array{0: string, 1: string} */
    private static function urlTest(): array
    {
        $url = trim((string)Brand::get('base_url'));
        if ($url === '') {
            return ['critical', t('The site URL is empty. Set APP_URL or installed.php app.base_url.')];
        }
        if (preg_match('#^https://#i', $url) !== 1) {
            return ['recommended', t('The site URL is not HTTPS. Cookies and OAuth callbacks should use HTTPS in production.')];
        }
        if (preg_match('#^https://(localhost|127\.0\.0\.1|\[::1\])#i', $url) === 1) {
            return ['recommended', t('The site URL points at localhost. Change it before going live.')];
        }

        return ['good', t('The site URL looks correct.')];
    }

    /** @return array{0: string, 1: string} */
    private static function debugTest(): array
    {
        $debug = filter_var(getenv('APP_DEBUG') ?: '0', FILTER_VALIDATE_BOOLEAN);

        return $debug
            ? ['recommended', t('APP_DEBUG is on. Turn it off in production.')]
            : ['good', t('APP_DEBUG is off.')];
    }

    /** @return array{0: string, 1: string} */
    private static function pepperTest(): array
    {
        $config = self::config();
        $pepper = (string)($config['pepper'] ?? '');
        if ($pepper === '' || str_contains($pepper, 'change-me') || strlen($pepper) < 32) {
            return ['critical', t('APP_PEPPER looks like a default or too short. Set a long random value before creating users.')];
        }

        return ['good', t('APP_PEPPER is configured.')];
    }

    /** @return array{0: string, 1: string} */
    private static function cacheTest(): array
    {
        $driver = Cache::driverName();

        return $driver === 'none'
            ? ['recommended', t('No cache backend is available; pages will be slower.')]
            : ['good', sprintf(t('Cache backend: %s.'), $driver)];
    }

    /** @return array{0: string, 1: string} */
    private static function updateTest(): array
    {
        $status = AutoUpdate::status();

        if (is_array($status['eol'])) {
            return [
                'critical',
                sprintf(
                    t('This version is no longer supported. Update to at least %s.'),
                    (string)($status['eol']['min_supported'] ?? '')
                ),
            ];
        }
        if (!$status['feedConfigured']) {
            return ['recommended', t('No update feed is configured; update checks are off.')];
        }
        if (is_array($status['failed'])) {
            return ['recommended', t('The last update attempt failed. Check the update log.')];
        }

        return ['good', t('The update feed is configured.')];
    }

    /** @return array{0: string, 1: string} */
    private static function opcacheTest(): array
    {
        if (!function_exists('opcache_get_status') || !(bool)ini_get('opcache.enable')) {
            return ['good', t('Opcache is not enabled.')];
        }

        $validate = filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOLEAN);
        if (!$validate) {
            return ['recommended', t('Opcache timestamp validation is off. Reload PHP-FPM after updating files.')];
        }

        return ['good', t('Opcache is enabled and checks file timestamps.')];
    }

    /** @return array{0: string, 1: string} */
    private static function diskTest(): array
    {
        $free = @disk_free_space(dirname(__DIR__));
        if ($free === false) {
            return ['good', t('Could not read free disk space.')];
        }

        $mb = (int)round($free / 1048576);
        if ($mb < 100) {
            return ['critical', sprintf(t('Only %s MB of disk space is free.'), $mb)];
        }
        if ($mb < 500) {
            return ['recommended', sprintf(t('Only %s MB of disk space is free.'), $mb)];
        }

        return ['good', sprintf(t('%s MB of disk space is free.'), $mb)];
    }

    /** @return array{0: string, 1: string} */
    private static function pluginsTest(): array
    {
        $enabled = Plugins::enabled();
        $all = Plugins::all();
        $bad = [];

        foreach ($enabled as $slug) {
            $manifest = $all[$slug] ?? null;
            if ($manifest === null) {
                $bad[] = $slug . ' (missing)';
                continue;
            }
            $missing = array_filter(
                (array)($manifest['requires']['php'] ?? []),
                static fn(string $ext): bool => !extension_loaded($ext)
            );
            if ($missing !== []) {
                $bad[] = $slug . ' (' . implode(', ', $missing) . ')';
            }
        }

        return $bad === []
            ? ['good', t('Enabled plugins meet their PHP requirements.')]
            : ['recommended', sprintf(t('These plugins are missing PHP extensions: %s'), implode('; ', $bad))];
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        $file = dirname(__DIR__) . '/config/app.php';
        $config = is_file($file) ? require $file : [];

        return is_array($config) ? $config : [];
    }

    private static function version(): string
    {
        return (string)(self::config()['version'] ?? '');
    }
}
