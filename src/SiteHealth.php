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
 * 每条测试形如 ['id','label','status','description','value','links']，status 是
 * good / recommended / critical。插件可以用 admin.site_health.tests 钩子追加。
 *
 * 管理员可以把某一项加入忽略列表（site_settings 的 health.ignored），被忽略的项
 * 不参与总体状态，也不再出现在检查结果里，只留在忽略列表里等恢复。
 */
final class SiteHealth
{
    private const RANK = ['good' => 0, 'recommended' => 1, 'critical' => 2];

    /** 检查项 id 的格式，插件追加的也走这一条 */
    public const ID_PATTERN = '/^[a-z][a-z0-9._-]{0,63}$/';

    private const IGNORE_KEY = 'health.ignored';

    /** PHP2Shell 各分支的修复版本；8.2 以下只剩源码补丁，不再发官方二进制 */
    private const PHP2SHELL_FIXED = [
        '8.2' => '8.2.35',
        '8.3' => '8.3.36',
        '8.4' => '8.4.27',
        '8.5' => '8.5.12',
    ];

    /** @return array{status: string, tests: array<int, array<string, mixed>>, ignored: array<int, array<string, string>>, generatedAt: int} */
    public static function run(): array
    {
        $tests = [];

        $php = self::phpTest();
        self::add($tests, 'php', t('PHP version'), [$php['status'], $php['description']], PHP_VERSION, $php['links']);

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
            if (preg_match(self::ID_PATTERN, $id) !== 1) {
                continue;
            }
            $status = (string)($test['status'] ?? 'good');
            if (!isset(self::RANK[$status])) {
                $status = 'good';
            }
            $links = [];
            foreach ((array)($test['links'] ?? []) as $link) {
                if (!is_array($link)) {
                    continue;
                }
                $url = (string)($link['url'] ?? '');
                if (preg_match('#^https?://#i', $url) !== 1) {
                    continue;
                }
                $links[] = [
                    'label' => (string)($link['label'] ?? $url),
                    'url'   => $url,
                ];
                if (count($links) >= 6) {
                    break;
                }
            }
            $clean[] = [
                'id'          => $id,
                'label'       => (string)($test['label'] ?? $id),
                'status'      => $status,
                'description' => (string)($test['description'] ?? ''),
                'value'       => (string)($test['value'] ?? ''),
                'links'       => $links,
            ];
        }

        $ignoredIds = self::ignoredIds();
        $tests = [];
        $ignored = [];
        $seen = [];
        foreach ($clean as $test) {
            if (in_array($test['id'], $ignoredIds, true)) {
                $ignored[] = ['id' => $test['id'], 'label' => $test['label']];
                $seen[] = $test['id'];
                continue;
            }
            $tests[] = $test;
        }
        // 插件被停用后，它的检查项已经跑不到了；仍让管理员能在忽略列表里看到并恢复
        foreach ($ignoredIds as $id) {
            if (!in_array($id, $seen, true)) {
                $ignored[] = ['id' => $id, 'label' => $id];
            }
        }

        $overall = 'good';
        foreach ($tests as $test) {
            if (self::RANK[$test['status']] > self::RANK[$overall]) {
                $overall = $test['status'];
            }
        }

        return [
            'status'      => $overall,
            'tests'       => $tests,
            'ignored'     => $ignored,
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

    /** @param array{0: string, 1: string} $result @param array<int, array{label: string, url: string}> $links */
    private static function add(array &$tests, string $id, string $label, array $result, string $value, array $links = []): void
    {
        $tests[] = [
            'id'          => $id,
            'label'       => $label,
            'status'      => $result[0],
            'description' => $result[1],
            'value'       => $value,
            'links'       => $links,
        ];
    }

    /** @return array{status: string, description: string, links: array<int, array{label: string, url: string}>} */
    private static function phpTest(): array
    {
        $state = self::php2shell(PHP_VERSION);
        if ($state['affected']) {
            if ($state['fix'] === null) {
                return [
                    'status'      => 'critical',
                    'description' => sprintf(
                        t('PHP %s is affected by PHP2Shell (CVE-2026-109618 and CVE-2026-109619) and this branch no longer receives binary updates from the PHP project. If this site is reachable from the internet, move to PHP 8.2.35, 8.3.36, 8.4.27, 8.5.12 or newer, or apply your vendor\'s patch right now, or take the site offline until you can.'),
                        PHP_VERSION
                    ),
                    'links'       => self::php2shellLinks(),
                ];
            }

            return [
                'status'      => 'critical',
                'description' => sprintf(
                    t('PHP %s is affected by PHP2Shell (CVE-2026-109618 and CVE-2026-109619), which lets an unauthenticated attacker run code on this server. If this site is reachable from the internet, upgrade to PHP %s or newer right now, or take the site offline until you can. Updating is the only complete fix.'),
                    PHP_VERSION,
                    $state['fix']
                ),
                'links'       => self::php2shellLinks(),
            ];
        }

        return [
            'status'      => version_compare(PHP_VERSION, '8.1.0', '>=') ? 'good' : 'critical',
            'description' => version_compare(PHP_VERSION, '8.1.0', '>=')
                ? t('PHP 8.1 or newer is required.')
                : t('PHP 8.1 or newer is required; this server is running an older version.'),
            'links'       => [],
        ];
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
        // data/ 是硬依赖；上传目录发布包里是空的、解包后可能没有，先试着建出来
        $dirs = ['data', 'public/assets/avatars', 'public/assets/recovery', 'public/assets/develops'];
        $missing = [];
        $unwritable = [];
        foreach ($dirs as $dir) {
            $path = $root . '/' . $dir;
            if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
                $missing[] = $dir;
            } elseif (!is_writable($path)) {
                $unwritable[] = $dir;
            }
        }

        if ($missing === [] && $unwritable === []) {
            return ['good', t('All runtime directories are writable.')];
        }

        $parts = [];
        if ($missing !== []) {
            $parts[] = sprintf(
                t('These directories are missing and could not be created: %s. Create them manually and make sure the PHP process can write to them.'),
                implode(', ', $missing)
            );
        }
        if ($unwritable !== []) {
            $parts[] = sprintf(
                t('These directories exist but the PHP process cannot write to them: %s. Fix the ownership or permissions for the web server user.'),
                implode(', ', $unwritable)
            );
        }

        // 只有 data/ 出问题才算严重；三个上传目录坏了是功能受损，不至于整站告急
        $critical = in_array('data', $missing, true) || in_array('data', $unwritable, true);

        return [$critical ? 'critical' : 'recommended', implode(' ', $parts)];
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

    /**
     * PHP2Shell（CVE-2026-109618 / CVE-2026-109619）修复于 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12；
     * 8.1 及更早只有源码补丁，没有官方二进制。
     *
     * @return array{affected: bool, fix: ?string}
     */
    public static function php2shell(string $version): array
    {
        $minor = implode('.', array_slice(explode('.', $version), 0, 2));
        if (isset(self::PHP2SHELL_FIXED[$minor])) {
            $fix = self::PHP2SHELL_FIXED[$minor];

            return ['affected' => version_compare($version, $fix, '<'), 'fix' => $fix];
        }
        if (version_compare($version, '8.2.0', '<')) {
            return ['affected' => true, 'fix' => null];
        }

        // 8.6 及以后的分支在修复之后发布
        return ['affected' => false, 'fix' => null];
    }

    /** @return array<int, array{label: string, url: string}> */
    private static function php2shellLinks(): array
    {
        return [
            ['label' => t('PHP security advisory'), 'url' => 'https://www.php.net/archive/2026.php#2026-10-21-5'],
            ['label' => 'CVE-2026-109618', 'url' => 'https://github.com/php/php-src/security/advisories/GHSA-w8hw-w9fx-j79w'],
            ['label' => 'CVE-2026-109619', 'url' => 'https://github.com/php/php-src/security/advisories/GHSA-6wmf-4rv5-p3rp'],
            ['label' => '轻之舟百科', 'url' => 'https://130.wiki/wiki/PHP2Shell'],
            ['label' => t('Wikipedia'), 'url' => 'https://en.wikipedia.org/wiki/PHP2Shell'],
        ];
    }

    /** @return string[] */
    public static function ignoredIds(): array
    {
        $raw = Settings::get(self::IGNORE_KEY, '[]');
        $ids = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_filter($ids, static fn(mixed $id): bool => is_string($id) && preg_match(self::ID_PATTERN, $id) === 1));
    }

    /** 把一项加入忽略列表；id 不合法时返回 false */
    public static function ignoreId(string $id): bool
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return false;
        }

        $ids = self::ignoredIds();
        if (!in_array($id, $ids, true)) {
            $ids[] = $id;
            self::saveIgnored($ids);
        }

        return true;
    }

    /** 从忽略列表里移除一项；id 不合法时返回 false */
    public static function unignoreId(string $id): bool
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return false;
        }

        $ids = self::ignoredIds();
        self::saveIgnored(array_values(array_filter($ids, static fn(string $item): bool => $item !== $id)));

        return true;
    }

    /** @param string[] $ids */
    private static function saveIgnored(array $ids): void
    {
        Settings::set(self::IGNORE_KEY, (string)json_encode(array_values(array_unique($ids)), JSON_UNESCAPED_SLASHES));

        // 概览页的摘要按语言各缓一份，忽略规则变了要一起清掉，别让红条多留一分钟
        foreach (I18n::slugs() as $slug) {
            Cache::forget('admin.site_health.summary.' . $slug);
        }
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
        if (!$status['feedConfigured'] || !$status['autoSetting']) {
            // 默认是开的；走到这里说明站长自己关了（feed = off 或后台开关），不算问题
            return ['good', t('Automatic updates are turned off.')];
        }
        if (is_array($status['failed'])) {
            return ['recommended', t('The last update attempt failed. Check the update log.')];
        }

        return ['good', t('Automatic updates are enabled.')];
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
