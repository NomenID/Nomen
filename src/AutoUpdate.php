<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src;

use Nomen\Src\Cli\Migrate;
use Throwable;

/**
 * 自动更新：查版本、判策略、加锁、安装、回滚、记账、发信。
 *
 * 设计参照 WordPress 的后台自动更新，但自动安装范围收得更紧：
 *  - 只有补丁版（x.y.z -> x.y.z+1）会自动装；第二位 / 第一位变动一律只提示；
 *  - 更新源（config/autoupdate.php 的 feed）留空就完全不检查；
 *  - 每个站点自己在配置里定检查间隔 / 每天检查时间 / 维护窗口 / 最低优先级；
 *  - 同一时间只允许一个更新（文件锁 + 调度器抢占）；
 *  - 覆盖前先打备份，失败自动回滚；下载包必须校验 SHA-256；
 *  - 成功 / 失败各通知管理员一次，同一版本不重复发；
 *  - 下载这类瞬时失败隔一小时重试一次，再失败才发信。
 *
 * 状态和日志都在 data/updates/ 下，不进发布包、不暴露给公网。
 */
final class AutoUpdate
{
    /** 优先级从高到低；security / critical 不受维护窗口限制 */
    public const PRIORITY_RANK = [
        'security' => 3,
        'critical' => 2,
        'normal'   => 1,
        'feature'  => 0,
    ];

    public const PRIORITY_LABELS = [
        'security' => 'Security update',
        'critical' => 'Critical update',
        'normal'   => 'Normal update',
        'feature'  => 'Feature update',
    ];

    /** 允许自动更新的版本范围；默认只允许最后一位 */
    public const SCOPES = [
        'patch' => 'Patch releases only',
        'minor' => 'Same major version',
        'major' => 'All releases',
    ];

    private const STATE_DIR = '/data/updates';
    private const LOG_MAX_BYTES = 2 * 1024 * 1024;
    private const HISTORY_MAX = 10;
    private const TRANSIENT = ['download_failed', 'checksum_mismatch', 'unzip_failed', 'disk_full'];
    private const CRITICAL = ['copy_failed', 'migration_failed', 'post_check_failed', 'rollback_failed', 'internal_error'];

    private static ?array $config = null;

    // ==================== 对外入口 ====================

    /** 更新源地址；空 = 这个站点不检查更新 */
    public static function feed(): string
    {
        return UpdateCheck::feed();
    }

    public static function feedConfigured(): bool
    {
        return self::feed() !== '';
    }

    public static function autoSetting(): bool
    {
        return (string)Settings::get('update.auto', '1') === '1';
    }

    /** 总开关 + 更新源都就绪才算真正开启 */
    public static function enabled(): bool
    {
        return self::autoSetting() && self::feedConfigured();
    }

    public static function interval(): int
    {
        return max(3600, (int)(self::config()['interval'] ?? 43200));
    }

    /** HH:MM；设置后每天这个时间检查一次，优先于 interval */
    public static function checkAt(): string
    {
        $at = trim((string)(self::config()['check_at'] ?? ''));

        return preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $at) === 1 ? $at : '';
    }

    /** HH:MM-HH:MM；空 = 不限时间。支持跨午夜 */
    public static function window(): string
    {
        $window = trim((string)(self::config()['window'] ?? ''));

        return preg_match('/^([01]?\d|2[0-3]):([0-5]\d)\s*-\s*([01]?\d|2[0-3]):([0-5]\d)$/', $window) === 1
            ? $window
            : '';
    }

    /** allow = 只在窗口内安装（白名单）；deny = 窗口内不安装（黑名单）；窗口留空时两种模式都不限制 */
    public static function windowMode(): string
    {
        $mode = strtolower(trim((string)(self::config()['window_mode'] ?? 'allow')));

        return $mode === 'deny' ? 'deny' : 'allow';
    }

    public static function minPriority(): string
    {
        $value = strtolower(trim((string)(self::config()['min_priority'] ?? 'normal')));

        return isset(self::PRIORITY_RANK[$value]) ? $value : 'normal';
    }

    public static function scope(): string
    {
        $value = strtolower(trim((string)(self::config()['scope'] ?? 'patch')));

        return isset(self::SCOPES[$value]) ? $value : 'patch';
    }

    /** 后台 / 邮件用的收件人：设置 > 品牌里的管理员信箱 > 最早的管理员账号 */
    public static function notifyEmail(): string
    {
        $email = trim((string)Settings::get('update.notify_email', ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        $configured = trim((string)UpdateConfig::get('notify_email', ''));
        if ($configured !== '' && filter_var($configured, FILTER_VALIDATE_EMAIL)) {
            return $configured;
        }

        $brand = trim((string)Brand::get('admin_notify_email', ''));
        if ($brand !== '' && filter_var($brand, FILTER_VALIDATE_EMAIL)) {
            return $brand;
        }

        try {
            $stmt = Database::getInstance()->query(
                "SELECT email FROM users WHERE role = 'admin' AND status = 1 AND email <> '' ORDER BY id ASC LIMIT 1"
            );
            $found = $stmt->fetchColumn();
            if (is_string($found) && filter_var($found, FILTER_VALIDATE_EMAIL)) {
                return $found;
            }
        } catch (Throwable $e) {
            error_log('[update] 读取管理员邮箱失败: ' . $e->getMessage());
        }

        return '';
    }

    /** 后台设置页和 CLI status 共用 */
    public static function status(): array
    {
        $root = self::root();
        $state = self::state();
        $current = self::currentVersion();
        $pending = (string)($state['pending'] ?? '');
        $offer = (array)($state['offer'] ?? []);
        $manual = (array)($state['manual'] ?? []);

        return [
            'feed'                 => self::feed(),
            'feedConfigured'       => self::feedConfigured(),
            'autoSetting'          => self::autoSetting(),
            'enabled'              => self::enabled(),
            'scope'                => self::scope(),
            'scopes'               => self::SCOPES,
            'minPriority'          => self::minPriority(),
            'priorities'           => self::PRIORITY_LABELS,
            'checkAt'              => self::checkAt(),
            'window'               => self::window(),
            'windowMode'           => self::windowMode(),
            'inWindow'             => self::inWindow(),
            'interval'             => self::interval(),
            'nextCheckAt'          => self::nextCheckAt($state),
            'notifyEmail'          => (string)Settings::get('update.notify_email', ''),
            'effectiveNotifyEmail' => self::notifyEmail(),
            'currentVersion'       => $current,
            'latest'               => (string)($state['latest'] ?? ''),
            'latestUrl'            => (string)($offer['url'] ?? ''),
            'latestPriority'       => (string)($offer['priority'] ?? ''),
            'latestReleasedAt'     => (string)($offer['released_at'] ?? ''),
            'pending'              => $pending,
            'pendingKind'          => $pending !== '' ? self::updateKind($current, $pending) : '',
            'pendingPriority'      => (string)($offer['priority'] ?? ''),
            'pendingAuto'          => $pending !== ''
                && self::scopeAllows($current, $pending)
                && self::priorityAllows((string)($offer['priority'] ?? 'normal')),
            'minSupported'         => (string)($offer['min_supported'] ?? ''),
            'eol'                  => ($state['eol'] ?? []) !== [] ? $state['eol'] : null,
            'lastCheckAt'          => (int)($state['last_check_at'] ?? 0),
            'lastCheckOk'          => (bool)($state['last_check_ok'] ?? false),
            'lastCheckError'       => (string)($state['last_check_error'] ?? ''),
            'lastRun'              => $state['history'][0] ?? null,
            'history'              => array_slice((array)($state['history'] ?? []), 0, 5),
            'failed'               => ($state['failed'] ?? []) !== [] ? $state['failed'] : null,
            'running'              => ($state['running'] ?? []) !== [] ? $state['running'] : null,
            'manualQueued'         => $manual !== [] && time() - (int)($manual['requested_at'] ?? 0) <= 600,
            'manualRequestedAt'    => (int)($manual['requested_at'] ?? 0),
            'logTail'              => self::logTail(60),
            'mailConfigured'       => Email::isConfigured(),
            'zipAvailable'         => class_exists(\ZipArchive::class),
            'curlAvailable'        => function_exists('curl_init'),
            'writable'             => is_writable($root) && is_writable($root . '/src')
                && is_writable($root . '/public') && is_writable($root . '/config')
                && self::stateDirWritable(),
            'phpOk'                => version_compare(PHP_VERSION, (string)(self::config()['min_php'] ?? '8.1'), '>='),
        ];
    }

    /**
     * 查一次。$apply 为 false 时只检查，不安装（后台/CLI 的「立即检查」）。
     * $manual = true 是管理员在后台点「立即更新」：绕过自动开关、scope、优先级和维护窗口，
     * 但仍然走 preflight、备份和失败记忆。
     *
     * @return array{status: string, current: string, latest: string, message: string, run?: array}
     */
    public static function check(bool $apply = true, bool $force = false, ?callable $onStep = null, bool $manual = false): array
    {
        $current = self::currentVersion();
        $state = self::state();

        // 上一次进程死在半路：标记成中断，别让后台一直显示 running
        $running = (array)($state['running'] ?? []);
        if ($running !== [] && time() - (int)($running['started_at'] ?? 0) > (int)(self::config()['lock_ttl'] ?? 900)) {
            self::log('error', 'recover', '上一次更新进程中断', ['run' => $running['id'] ?? '']);
            array_unshift($state['history'], array_merge($running, [
                'status'      => 'failed',
                'error_code'  => 'interrupted',
                'error'       => '更新进程中断（超时或进程被杀）',
                'finished_at' => time(),
            ]));
            $state['history'] = array_slice($state['history'], 0, self::HISTORY_MAX);
            unset($state['running']);
            self::saveState($state);
        }

        if ((array)($state['running'] ?? []) !== []) {
            return [
                'status'  => 'locked',
                'current' => $current,
                'latest'  => (string)($state['latest'] ?? ''),
                'message' => '另一个更新正在进行',
            ];
        }

        // feed = off 或者地址无效：这个站点不检查更新
        if (!self::feedConfigured()) {
            return [
                'status'  => 'disabled',
                'current' => $current,
                'latest'  => '',
                'message' => '更新检查已关闭（config/autoupdate.php 的 feed 设成了 off 或不是有效地址）',
            ];
        }

        $pending = (string)($state['pending'] ?? '');
        $offer = (array)($state['offer'] ?? []);
        $due = $force || self::dueForCheck($state);

        if (!$due && $pending === '' && !self::retryDue($state)) {
            return ['status' => 'idle', 'current' => $current, 'latest' => (string)($state['latest'] ?? ''), 'message' => ''];
        }

        if ($force || $offer === [] || $due) {
            $fetched = UpdateCheck::latest($force);
            if ($fetched === null) {
                $state['last_check_at'] = time();
                $state['last_check_ok'] = false;
                $state['last_check_error'] = '更新源不可达';
                self::saveState($state);
                self::log('warning', 'check', '更新源不可达');

                return ['status' => 'check_failed', 'current' => $current, 'latest' => '', 'message' => '更新源不可达'];
            }
            $offer = $fetched;
            $state['last_check_at'] = time();
            $state['last_check_ok'] = true;
            $state['last_check_error'] = '';
            $state['latest'] = (string)($offer['version'] ?? '');
            $state['offer'] = $offer;
        }

        $latest = (string)($offer['version'] ?? '');
        if ($latest === '') {
            self::saveState($state);

            return ['status' => 'check_failed', 'current' => $current, 'latest' => '', 'message' => '更新源没有返回版本号'];
        }

        self::syncEol($state, $offer, $current);

        if (!UpdateCheck::isNewer($latest, $current)) {
            $state['pending'] = '';
            unset($state['offer']);
            self::saveState($state);

            return ['status' => 'current', 'current' => $current, 'latest' => $latest, 'message' => '已是最新版本'];
        }

        // 先记下待安装版本；范围 / 优先级不允许时只提示，不下载
        $state['pending'] = $latest;
        $state['offer'] = $offer;
        self::saveState($state);

        if (!$manual && !self::scopeAllows($current, $latest)) {
            return [
                'status'  => 'manual_required',
                'current' => $current,
                'latest'  => $latest,
                'message' => '第二位或第一位版本需要手动更新；要自动装需在 config/autoupdate.php 里把 scope 改成 minor 或 major',
            ];
        }

        $priority = (string)($offer['priority'] ?? 'normal');
        if (!$manual && !self::priorityAllows($priority)) {
            return [
                'status'  => 'manual_required',
                'current' => $current,
                'latest'  => $latest,
                'message' => '这个更新的优先级低于站点设置的最低优先级，需要手动更新',
            ];
        }

        if (($manual || self::enabled()) && self::blocked($state, $latest)) {
            return [
                'status'  => 'blocked',
                'current' => $current,
                'latest'  => $latest,
                'message' => '这个版本上次失败过，已暂停自动重试；可在后台清除失败记录',
            ];
        }

        if (!$apply || (!$manual && !self::enabled())) {
            return [
                'status'  => 'available',
                'current' => $current,
                'latest'  => $latest,
                'message' => self::enabled() ? '有新版本，等待安装' : '有新版本；自动更新未开启',
            ];
        }

        if (!$manual && !self::priorityBypassesWindow($priority) && !self::inWindow()) {
            return [
                'status'  => 'waiting',
                'current' => $current,
                'latest'  => $latest,
                'message' => '有新版本，等待维护窗口 ' . self::window(),
            ];
        }

        $preflight = self::preflight($offer, $current);
        if ($preflight !== '') {
            $state = self::markCheckFailure($state, $preflight);
            self::saveState($state);
            self::log('error', 'preflight', $preflight);

            return ['status' => 'failed', 'current' => $current, 'latest' => $latest, 'message' => $preflight];
        }

        return self::apply($offer, $current, $onStep);
    }

    /** 调度器每秒都能调，真正要不要查由 interval 决定；异常不能把心跳打挂 */
    public static function scheduled(): void
    {
        try {
            self::check(true, false);
        } catch (Throwable $e) {
            self::log('error', 'scheduled', $e->getMessage());
            error_log('[update] 定时检查失败: ' . $e->getMessage());
        }
    }

    /** 后台「立即更新」：记下请求，由调度器在下一分钟执行 */
    public static function requestManual(int $adminId = 0): array
    {
        $state = self::state();
        if ((array)($state['running'] ?? []) !== []) {
            return ['success' => false, 'message' => '另一个更新正在进行'];
        }
        if (!self::feedConfigured()) {
            return ['success' => false, 'message' => '更新检查已关闭（config/autoupdate.php 的 feed 设成了 off 或不是有效地址）'];
        }

        $current = self::currentVersion();
        $pending = (string)($state['pending'] ?? '');
        if ($pending === '' || !UpdateCheck::isNewer($pending, $current)) {
            return ['success' => false, 'message' => '没有可安装的新版本，先点「立即检查」'];
        }
        if (self::blocked($state, $pending)) {
            return ['success' => false, 'message' => '这个版本上次失败过，已暂停重试；请先清除失败记录'];
        }

        $state['manual'] = [
            'requested_at' => time(),
            'admin_id'     => $adminId,
            'version'      => $pending,
        ];
        self::saveState($state);
        self::log('info', 'manual', '管理员排入了手动更新：' . $pending, ['admin' => $adminId]);

        return ['success' => true, 'version' => $pending, 'message' => '已排队，调度器会在一分钟内开始更新'];
    }

    /** 调度器每分钟调用：有手动请求就执行一次（请求 10 分钟没被捡起来就作废） */
    public static function runManualIfQueued(): void
    {
        $state = self::state();
        $manual = (array)($state['manual'] ?? []);
        if ($manual === []) {
            return;
        }

        if (time() - (int)($manual['requested_at'] ?? 0) > 600) {
            unset($state['manual']);
            self::saveState($state);
            self::log('warning', 'manual', '手动更新请求已过期，未执行');
            return;
        }

        if ((array)($state['running'] ?? []) !== []) {
            return; // 有更新在跑，下一分钟再看
        }

        unset($state['manual']);
        self::saveState($state);
        self::log('info', 'manual', '开始执行手动更新');

        try {
            $result = self::check(true, true, null, true);
            if (($result['status'] ?? '') === 'locked') {
                $state = self::state();
                $state['manual'] = $manual;
                self::saveState($state);
            }
        } catch (Throwable $e) {
            self::log('error', 'manual', '手动更新失败：' . $e->getMessage());
        }
    }

    /**
     * 自动更新成功后的第一次正常请求里触发 update.completed。
     * 更新进程内不能触发：那时内存里还是旧代码，磁盘上已经是新文件。
     */
    public static function fireCompleted(): void
    {
        $file = self::dir() . '/completed.json';
        if (!is_file($file)) {
            return;
        }

        $claimed = $file . '.firing';
        if (!@rename($file, $claimed)) {
            return; // 另一个请求先拿到了
        }

        $info = json_decode((string)@file_get_contents($claimed), true);
        $info = is_array($info) ? $info : [];

        try {
            Hooks::run('update.completed', [
                (string)($info['from'] ?? ''),
                (string)($info['to'] ?? ''),
                $info,
            ]);
        } catch (Throwable $e) {
            self::log('error', 'completed_hook', 'update.completed 钩子失败：' . $e->getMessage());
            error_log('[update] update.completed 钩子失败: ' . $e->getMessage());
        } finally {
            @unlink($claimed);
        }
    }

    public static function resetFailure(): void
    {
        $state = self::state();
        unset($state['failed']);
        self::saveState($state);
        self::log('info', 'reset', '管理员清除了失败记录');
    }

    /** 版本变动类型：patch（最后一位）/ minor（第二位）/ major（第一位） */
    public static function updateKind(string $current, string $target): string
    {
        if (!UpdateCheck::isNewer($target, $current)) {
            return 'none';
        }

        [$cm, $cn, $cp] = self::versionParts($current);
        [$tm, $tn, $tp] = self::versionParts($target);

        if ($tm !== $cm) {
            return 'major';
        }
        if ($tn !== $cn) {
            return 'minor';
        }
        if ($tp !== $cp) {
            return 'patch';
        }

        return 'none';
    }

    /** 站点配置的自动更新范围是否覆盖这次变动 */
    public static function scopeAllows(string $current, string $target): bool
    {
        $kind = self::updateKind($current, $target);
        if ($kind === 'none') {
            return false;
        }

        return match (self::scope()) {
            'patch' => $kind === 'patch',
            'minor' => in_array($kind, ['patch', 'minor'], true),
            'major' => true,
            default => false,
        };
    }

    public static function priorityAllows(string $priority): bool
    {
        $rank = self::PRIORITY_RANK[$priority] ?? self::PRIORITY_RANK['normal'];

        return $rank >= self::PRIORITY_RANK[self::minPriority()];
    }

    public static function priorityBypassesWindow(string $priority): bool
    {
        return in_array($priority, ['security', 'critical'], true);
    }

    /** 窗口判断抽成纯函数，方便测试；跨午夜也支持 */
    public static function windowAllows(string $window, int $minutes): bool
    {
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)\s*-\s*([01]?\d|2[0-3]):([0-5]\d)$/', $window, $m) !== 1) {
            return true;
        }

        $start = (int)$m[1] * 60 + (int)$m[2];
        $end = (int)$m[3] * 60 + (int)$m[4];
        if ($start === $end) {
            return true;
        }

        return $start < $end
            ? ($minutes >= $start && $minutes < $end)
            : ($minutes >= $start || $minutes < $end);
    }

    public static function inWindow(): bool
    {
        $window = self::window();
        if ($window === '') {
            return true;
        }

        return self::windowAllowsMode($window, self::windowMode(), (int)date('G') * 60 + (int)date('i'));
    }

    /** 白名单 / 黑名单两种模式下的窗口判断；窗口留空一律放行，方便测试 */
    public static function windowAllowsMode(string $window, string $mode, int $minutes): bool
    {
        if ($window === '') {
            return true;
        }

        $inside = self::windowAllows($window, $minutes);

        return $mode === 'deny' ? !$inside : $inside;
    }

    /** 按 interval 或每天固定时间判断这次该不该查 */
    private static function dueForCheck(array $state): bool
    {
        $at = self::checkAt();
        if ($at !== '') {
            $today = self::todayAt($at);

            return $today > 0 && time() >= $today && (int)($state['last_check_at'] ?? 0) < $today;
        }

        $last = (int)($state['last_check_at'] ?? 0);

        return $last === 0 || time() - $last >= self::interval();
    }

    private static function todayAt(string $hhmm): int
    {
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $hhmm, $m) !== 1) {
            return 0;
        }

        $zone = new \DateTimeZone(date_default_timezone_get());

        return (new \DateTimeImmutable('now', $zone))
            ->setTime((int)$m[1], (int)$m[2])
            ->getTimestamp();
    }

    private static function nextCheckAt(array $state): int
    {
        $at = self::checkAt();
        if ($at !== '') {
            $today = self::todayAt($at);

            return $today > time() ? $today : $today + 86400;
        }

        $last = (int)($state['last_check_at'] ?? 0);

        return $last > 0 ? $last + self::interval() : time();
    }

    /** 当前版本低于发布源声明的最低支持版本时，记一条提醒 */
    private static function syncEol(array &$state, array $offer, string $current): void
    {
        $min = (string)($offer['min_supported'] ?? '');
        if ($min === '' || !UpdateCheck::isNewer($min, $current)) {
            unset($state['eol']);

            return;
        }

        $changed = (string)($state['eol']['min_supported'] ?? '') !== $min;
        $state['eol'] = [
            'current'       => $current,
            'min_supported' => $min,
            'at'            => time(),
        ];

        if ($changed) {
            self::log('warning', 'eol', '当前版本 ' . $current . ' 已低于最低支持版本 ' . $min);
        }
    }

    // ==================== 安装流程 ====================

    private static function apply(array $offer, string $from, ?callable $onStep): array
    {
        $to = (string)($offer['version'] ?? '');
        $root = self::root();
        $lock = self::lock();
        if ($lock === null) {
            return ['status' => 'locked', 'current' => $from, 'latest' => $to, 'message' => '另一个更新正在进行'];
        }

        $run = [
            'id'          => gmdate('Ymd\THis\Z') . '-' . preg_replace('/[^0-9A-Za-z._-]/', '_', $to),
            'from'        => $from,
            'to'          => $to,
            'status'      => 'running',
            'started_at'  => time(),
            'finished_at' => 0,
            'duration'    => 0,
            'steps'       => [],
            'error_code'  => '',
            'error'       => '',
            'rollback'    => 'not_needed',
            'warning'     => '',
        ];

        $step = static function (string $name, string $message, string $level = 'info') use (&$run, $onStep): void {
            $entry = ['at' => time(), 'name' => $name, 'level' => $level, 'message' => $message];
            $run['steps'][] = $entry;
            self::log($level, $name, $message, ['run' => $run['id']]);
            if ($onStep !== null) {
                $onStep($message);
            }
        };

        $state = self::state();
        $state['running'] = $run;
        $state['pending'] = $to;
        self::saveState($state);

        $package = new UpdatePackage($root, $offer, $from);
        $copyStarted = false;
        $backup = '';

        try {
            $step('prepare', '准备更新目录');
            $package->prepare();

            $step('download', '下载 ' . $to);
            $package->download();

            $step('verify', '校验 SHA-256');
            $package->verify();

            $step('extract', '解包 ' . $to);
            $package->extract();

            $step('validate', '校验包内容');
            $package->validate();

            $step('backup', '备份当前文件');
            $package->backup();
            $backup = $package->backupPath();
            $run['backup'] = basename($backup);

            if ((bool)UpdateConfig::get('backup.database', true)) {
                $step('backup_db', '备份数据库');
                $run['db_backup'] = basename(self::backupDatabase());
            } else {
                $run['warning'] = trim(($run['warning'] ?? '') . ' 数据库备份已关闭（backup.database=false）。');
                self::log('warning', 'backup_db', '数据库备份已关闭', ['run' => $run['id']]);
            }

            $step('maintenance', '进入维护模式');
            Maintenance::start((int)(self::config()['maintenance_ttl'] ?? 900), 'Automatic update to ' . $to);

            $copyStarted = true;
            $step('copy', '覆盖程序文件');
            $applied = $package->apply();
            $step(
                'copy_done',
                '覆盖 ' . $applied['copied'] . ' 个文件，跳过 ' . count($applied['skipped'])
                . ' 个站点文件，删除 ' . count($applied['deleted']) . ' 个旧文件'
            );

            $step('migrate', '运行数据库迁移');
            self::migrate();

            $step('cache', '清理缓存');
            Cache::flush();
            Settings::forgetCache();
            UpdateCheck::forget();

            Maintenance::stop();

            try {
                $package->writeManifest();
            } catch (Throwable $e) {
                $run['warning'] = '更新完成，但写更新清单失败：' . $e->getMessage();
                self::log('warning', 'manifest', $run['warning'], ['run' => $run['id']]);
            }

            $step('post_check', '检查站点是否正常');
            $post = self::postCheck($to);
            if ($post === false) {
                throw new UpdateException('post_check_failed', '更新后站点没有返回预期版本');
            }
            if ($post === null) {
                $run['warning'] = trim(($run['warning'] ?? '') . ' 更新后无法通过 HTTP 回访站点，已跳过在线检查。');
                self::log('warning', 'post_check', '回访不可达，跳过在线检查', ['run' => $run['id']]);
            }

            $run['status'] = 'success';
            $run['finished_at'] = time();
            $run['duration'] = $run['finished_at'] - $run['started_at'];
            $package->cleanup();

            $state = self::state();
            unset($state['running'], $state['failed'], $state['offer']);
            $state['pending'] = '';
            $state['latest'] = $to;
            $state['last_check_at'] = time();
            $state['last_check_ok'] = true;
            $state['last_check_error'] = '';
            $state['history'] = array_slice(array_merge([$run], (array)($state['history'] ?? [])), 0, self::HISTORY_MAX);
            self::saveState($state);

            try {
                self::queueCompleted($run);
            } catch (Throwable $e) {
                self::log('warning', 'completed_hook', '写更新完成标记失败：' . $e->getMessage(), ['run' => $run['id']]);
            }

            self::activity('system.update_success', '自动更新 ' . $from . ' → ' . $to, $from, $to);
            try {
                self::sendEmail('success', $run, $offer);
            } catch (Throwable $e) {
                self::log('error', 'email', '通知发送异常：' . $e->getMessage(), ['run' => $run['id']]);
            }
            $step('done', '已更新到 ' . $to . '（' . $run['duration'] . ' 秒）');

            return ['status' => 'updated', 'current' => $to, 'latest' => $to, 'message' => '已更新到 ' . $to, 'run' => $run];
        } catch (Throwable $e) {
            $reason = $e instanceof UpdateException ? $e->reason : 'internal_error';
            $message = $e->getMessage();

            $step('failed', $reason . ': ' . $message, 'error');

            $rollback = 'not_needed';
            if ($copyStarted) {
                Maintenance::start((int)(self::config()['maintenance_ttl'] ?? 900), 'Rolling back ' . $to);
                try {
                    $step('rollback', '回滚到 ' . $from);
                    $package->rollback();
                    $rollback = 'ok';
                } catch (Throwable $rollbackError) {
                    $rollback = 'failed';
                    self::log('error', 'rollback', $rollbackError->getMessage(), ['run' => $run['id']]);
                    $step('rollback_failed', $rollbackError->getMessage(), 'error');
                } finally {
                    Maintenance::stop();
                }
            }

            [$transient, $critical] = self::classify($reason);
            if ($rollback === 'failed') {
                $critical = true;
                $transient = false;
            }

            $state = self::state();
            unset($state['running']);
            $state['pending'] = $to;
            $state['offer'] = $offer;
            $state['last_check_at'] = time();
            $state['last_check_ok'] = false;
            $state['last_check_error'] = $reason . ': ' . $message;
            $state['failed'] = self::nextFailure($state, $to, $reason, $message, $transient, $critical);

            $run['status'] = $rollback === 'failed' ? 'critical' : ($rollback === 'ok' ? 'rolled_back' : 'failed');
            $run['error_code'] = $reason;
            $run['error'] = $message;
            $run['rollback'] = $rollback;
            $run['finished_at'] = time();
            $run['duration'] = $run['finished_at'] - $run['started_at'];
            $state['history'] = array_slice(array_merge([$run], (array)($state['history'] ?? [])), 0, self::HISTORY_MAX);
            self::saveState($state);

            self::activity('system.update_failed', '自动更新失败 ' . $from . ' → ' . $to . '（' . $reason . '）', $from, $to);

            // 瞬时失败第一次不打扰站长，一小时后自动再试；回滚成功按普通失败通知
            $attempts = (int)($state['failed']['attempts'] ?? 1);
            if (!($transient && $attempts < 2 && $rollback !== 'failed')) {
                try {
                    self::sendEmail($rollback === 'failed' ? 'critical' : 'fail', $run, $offer);
                } catch (Throwable $e) {
                    self::log('error', 'email', '通知发送异常：' . $e->getMessage(), ['run' => $run['id']]);
                }
            }

            try {
                $package->cleanup(true);
            } catch (Throwable $e2) {
                self::log('warning', 'cleanup', $e2->getMessage(), ['run' => $run['id']]);
            }

            return [
                'status'  => $rollback === 'failed' ? 'critical' : 'failed',
                'current' => $from,
                'latest'  => $to,
                'message' => $reason . ': ' . $message,
                'run'     => $run,
            ];
        } finally {
            self::unlock($lock);
        }
    }

    /** 跑 bin/nomen backup 导出数据库；失败/超时都中止更新，不带病往下走 */
    private static function backupDatabase(): string
    {
        $dir = self::root() . '/data/backups';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new UpdateException('backup_failed', '建不了数据库备份目录：' . $dir);
        }
        Ownership::adopt($dir);

        $before = self::databaseBackups($dir);

        $php = PHP_BINDIR . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
        if (!is_file($php) || !is_executable($php)) {
            $php = PHP_BINARY;
        }
        $cmd = [$php, self::root() . '/bin/nomen', 'backup', $dir];
        $timeout = max(30, (int)UpdateConfig::get('backup.timeout', 300));

        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new UpdateException('backup_failed', '启动不了数据库备份进程');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $exit = null;
        $started = microtime(true);

        while (true) {
            $status = proc_get_status($proc);
            $out .= (string)stream_get_contents($pipes[1]);
            $err .= (string)stream_get_contents($pipes[2]);

            if (!$status['running']) {
                $exit = (int)$status['exitcode'];
                break;
            }
            if (microtime(true) - $started > $timeout) {
                @proc_terminate($proc, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                @proc_close($proc);
                throw new UpdateException('backup_failed', '数据库备份超时（' . $timeout . ' 秒）');
            }
            usleep(200000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        @proc_close($proc);

        if ($exit !== 0) {
            $detail = trim($err !== '' ? $err : strip_tags($out));
            throw new UpdateException('backup_failed', '数据库备份失败：' . ($detail !== '' ? $detail : '未知错误'));
        }

        $new = array_values(array_diff(self::databaseBackups($dir), $before));
        if ($new === []) {
            throw new UpdateException('backup_failed', '数据库备份命令结束了，但没有生成备份文件');
        }
        usort($new, static fn(string $a, string $b): int => (int)@filemtime($b) <=> (int)@filemtime($a));
        $file = $new[0];
        if ((int)@filesize($file) <= 0) {
            throw new UpdateException('backup_failed', '数据库备份文件是空的：' . $file);
        }

        @chmod($file, 0600);
        Ownership::adopt($file);
        self::log('info', 'backup_db', '数据库备份：' . $file);

        return $file;
    }

    /** @return string[] */
    private static function databaseBackups(string $dir): array
    {
        return array_merge(
            glob($dir . '/nomen-*.sql') ?: [],
            glob($dir . '/nomen-*.sqlite') ?: []
        );
    }

    private static function migrate(): void
    {
        ob_start();
        try {
            $code = Migrate::run([]);
            $output = (string)ob_get_clean();
        } catch (Throwable $e) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            throw new UpdateException('migration_failed', '核心迁移失败：' . $e->getMessage());
        }

        if ($code !== 0) {
            $detail = trim(strip_tags($output));
            throw new UpdateException(
                'migration_failed',
                '核心迁移失败' . ($detail !== '' ? '：' . $detail : '（详情见服务器错误日志）')
            );
        }

        $plugin = PluginMigrator::run();
        if (($plugin['errors'] ?? []) !== []) {
            throw new UpdateException('migration_failed', '插件迁移失败：' . implode('; ', $plugin['errors']));
        }
    }

    /** true = 新版本已生效；false = 明确不对；null = 回访不通，无法判断 */
    private static function postCheck(string $expected): ?bool
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        // 文件刚覆盖完，FPM 那边不一定立刻按新版跑：opcache 的 revalidate_freq（宝塔默认 3 秒）
        // 没到点就还用着旧 config/app.php，回访会拿到上一个版本号。一次对不上就回滚太狠，
        // 给几次机会；也顺手把实际拿到的版本记下来，别只留一句「没有返回预期版本」。
        $attempts = 5;
        $running = '';
        $lastStatus = 0;
        $lastError = '';

        for ($i = 1; $i <= $attempts; $i++) {
            if ($i > 1) {
                sleep(2);
            }

            $url = rtrim((string)Brand::get('base_url'), '/') . '/api/version?_=' . time() . '.' . $i;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'Nomen-AutoUpdate',
            ]);
            $raw = curl_exec($ch);
            $lastStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $lastError = curl_error($ch);
            curl_close($ch);

            if (!is_string($raw) || $lastStatus !== 200) {
                continue;
            }

            $data = json_decode($raw, true);
            $seen = is_array($data) ? (string)($data['version'] ?? '') : '';
            if ($seen === '') {
                continue;
            }
            if (self::sameVersion($seen, $expected)) {
                return true;
            }
            $running = $seen;
        }

        if ($running !== '') {
            self::log('error', 'post_check', '回访版本不符：期望 ' . $expected . '，实际 ' . $running, ['attempts' => $attempts]);

            return false;
        }
        if ($lastStatus >= 500) {
            self::log('warning', 'post_check', '回访返回 HTTP ' . $lastStatus, ['attempts' => $attempts]);

            return false;
        }
        if ($lastStatus !== 200 && $lastStatus !== 0) {
            self::log('warning', 'post_check', '回访返回 HTTP ' . $lastStatus . '，跳过判断');

            return null;
        }

        self::log('warning', 'post_check', '回访失败：' . ($lastError ?: '无响应'));

        return null;
    }

    // ==================== 状态 / 日志 / 邮件 ====================

    private static function preflight(array $offer, string $current): string
    {
        $minPhp = (string)($offer['min_php'] ?? (self::config()['min_php'] ?? '8.1'));
        if ($minPhp !== '' && version_compare(PHP_VERSION, $minPhp, '<')) {
            return '这个版本要求 PHP ' . $minPhp . '，当前是 ' . PHP_VERSION;
        }
        if (!class_exists(\ZipArchive::class)) {
            return 'PHP 没有 zip 扩展，无法解包更新';
        }
        if (!function_exists('curl_init')) {
            return 'PHP 没有 curl 扩展，无法下载更新';
        }

        $root = self::root();
        foreach ([$root, $root . '/src', $root . '/public', $root . '/config'] as $dir) {
            if (!is_dir($dir) || !is_writable($dir)) {
                return $dir . ' 不可写';
            }
        }
        if (!self::stateDirWritable()) {
            return self::dir() . ' 不可写';
        }

        $free = @disk_free_space($root);
        $needed = max(1, (int)(self::config()['min_free_mb'] ?? 100)) * 1024 * 1024;
        if ($free !== false && $free < $needed) {
            return '磁盘剩余空间不足 ' . number_format($needed / 1048576) . ' MB';
        }

        return (string)Hooks::filter('update.preflight', '', $offer, $current);
    }

    private static function blocked(array $state, string $version): bool
    {
        $failed = (array)($state['failed'] ?? []);
        if ((string)($failed['version'] ?? '') !== $version) {
            return false;
        }
        if (!empty($failed['critical']) || empty($failed['transient'])) {
            return true;
        }
        if ((int)($failed['attempts'] ?? 0) >= 2) {
            return true;
        }

        return (int)($failed['retry_after'] ?? 0) > time();
    }

    private static function retryDue(array $state): bool
    {
        $failed = (array)($state['failed'] ?? []);

        return !empty($failed['transient'])
            && (int)($failed['attempts'] ?? 0) < 2
            && (int)($failed['retry_after'] ?? 0) <= time();
    }

    private static function nextFailure(array $state, string $version, string $reason, string $message, bool $transient, bool $critical): array
    {
        $previous = (array)($state['failed'] ?? []);
        $attempts = (string)($previous['version'] ?? '') === $version ? (int)($previous['attempts'] ?? 0) + 1 : 1;

        return [
            'version'     => $version,
            'reason'      => $reason,
            'message'     => mb_substr($message, 0, 500),
            'attempts'    => $attempts,
            'transient'   => $transient,
            'critical'    => $critical,
            'at'          => time(),
            'retry_after' => $transient ? time() + 3600 : 0,
        ];
    }

    private static function markCheckFailure(array $state, string $message): array
    {
        $state['last_check_at'] = time();
        $state['last_check_ok'] = false;
        $state['last_check_error'] = $message;

        return $state;
    }

    private static function classify(string $reason): array
    {
        return [
            in_array($reason, self::TRANSIENT, true),
            in_array($reason, self::CRITICAL, true),
        ];
    }

    private static function sendEmail(string $type, array $run, array $offer): void
    {
        I18n::init();

        $to = self::notifyEmail();
        $version = (string)($offer['version'] ?? ($run['to'] ?? ''));
        if ($to === '') {
            self::log('warning', 'email', '没有可用的管理员邮箱，跳过通知');
            return;
        }
        if (!Email::isConfigured()) {
            self::log('warning', 'email', 'SMTP 未配置，跳过通知');
            return;
        }

        $state = self::state();
        $notified = (array)($state['notified'] ?? []);
        if ((string)($notified['type'] ?? '') === $type
            && (string)($notified['version'] ?? '') === $version
            && (string)($notified['email'] ?? '') === $to) {
            return;
        }

        $locale = I18n::locale();
        $x = static fn(string $key): string => I18n::tIn($locale, $key);
        $site = rtrim((string)Brand::get('base_url'), '/');
        $from = (string)($run['from'] ?? '');
        $badge = match ($type) {
            'success'  => ['text' => $x('Updated'), 'tone' => 'success'],
            'critical' => ['text' => $x('Needs attention'), 'tone' => 'danger'],
            default    => ['text' => $x('Update failed'), 'tone' => 'warning'],
        };

        $blocks = [];
        if ($type === 'success') {
            $blocks[] = [
                'type' => 'paragraph',
                'text' => sprintf(
                    $x('Your site at %s was updated automatically from %s to %s.'),
                    $site,
                    $from,
                    $version
                ),
            ];
            $blocks[] = ['type' => 'info', 'items' => [
                ['label' => $x('Version'), 'value' => $from . ' → ' . $version],
                ['label' => $x('Time'), 'value' => gmdate('Y-m-d H:i', (int)($run['finished_at'] ?: time())) . ' UTC'],
                ['label' => $x('Duration'), 'value' => (int)($run['duration'] ?? 0) . ' s'],
            ]];
            $blocks[] = ['type' => 'paragraph', 'text' => $x('No further action is needed.')];
            if (!empty($run['warning'])) {
                $blocks[] = ['type' => 'alert', 'tone' => 'warning', 'text' => (string)$run['warning']];
            }
        } else {
            $blocks[] = [
                'type' => 'paragraph',
                'text' => sprintf(
                    $x('Your site at %s could not be updated automatically to %s.'),
                    $site,
                    $version
                ),
            ];
            $blocks[] = ['type' => 'info', 'items' => [
                ['label' => $x('Version'), 'value' => $from . ' → ' . $version],
                ['label' => $x('Time'), 'value' => gmdate('Y-m-d H:i', (int)($run['finished_at'] ?: time())) . ' UTC'],
                ['label' => $x('Error'), 'value' => (string)($run['error_code'] ?? '')],
            ]];
            $blocks[] = ['type' => 'alert', 'tone' => 'danger', 'text' => (string)($run['error'] ?? '')];
            $rollback = (string)($run['rollback'] ?? 'not_needed');
            if ($rollback === 'ok') {
                $blocks[] = [
                    'type' => 'paragraph',
                    'text' => sprintf(
                        $x('The site is still running %s. The update was rolled back.'),
                        $from
                    ),
                ];
            } elseif ($rollback === 'failed') {
                $blocks[] = [
                    'type' => 'alert',
                    'tone' => 'danger',
                    'text' => $x('The rollback also failed. Check the site and the update log now.'),
                ];
            } else {
                $blocks[] = [
                    'type' => 'paragraph',
                    'text' => sprintf($x('The site is still running %s.'), $from),
                ];
            }
        }

        $title = match ($type) {
            'success'  => $x('Nomen was updated automatically'),
            'critical' => $x('URGENT: automatic update failed'),
            default    => $x('Automatic update failed'),
        };
        $subject = EmailTemplate::subjectPrefix() . $title . ' ' . $version;

        $html = EmailTemplate::render([
            'preheader' => $title,
            'badge'     => $badge,
            'title'     => $title,
            'subtitle'  => $site,
            'blocks'    => $blocks,
            'button'    => ['text' => $x('Open the admin panel'), 'url' => EmailTemplate::url('/admin/settings')],
        ], $locale);

        $sent = Email::send($to, $subject, $html);

        $state = self::state();
        $state['notified'] = [
            'type'    => $type,
            'version' => $version,
            'email'   => $to,
            'at'      => time(),
            'sent'    => $sent,
        ];
        self::saveState($state);

        self::log($sent ? 'info' : 'error', 'email', $sent ? '已通知 ' . $to : '通知发送失败：' . $to, ['run' => $run['id'] ?? '']);
    }

    private static function activity(string $action, string $summary, string $from, string $to): void
    {
        ActivityLog::record([
            'action'         => $action,
            'category'       => ActivityLog::CAT_SYSTEM,
            'actor_type'     => 'system',
            'skip_ip'        => true,
            'target_type'    => 'update',
            'target_name'    => $to,
            'summary'        => $summary,
            'changes'        => ['version' => ['old' => $from, 'new' => $to]],
        ]);
    }

    // ==================== 状态与日志文件 ====================

    /** @return array<string, mixed> */
    private static function state(): array
    {
        $file = self::dir() . '/state.json';
        $default = [
            'last_check_at'    => 0,
            'last_check_ok'    => false,
            'last_check_error' => '',
            'latest'           => '',
            'offer'            => [],
            'pending'          => '',
            'failed'           => [],
            'eol'              => [],
            'notified'         => [],
            'running'          => [],
            'history'          => [],
        ];

        if (!is_file($file)) {
            return $default;
        }

        $data = json_decode((string)@file_get_contents($file), true);

        return is_array($data) ? array_merge($default, $data) : $default;
    }

    private static function saveState(array $state): void
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('[update] 建不了状态目录: ' . $dir);
            return;
        }
        Ownership::adopt($dir);

        $tmp = $dir . '/state.json.tmp';
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
        if (@file_put_contents($tmp, (string)$json, LOCK_EX) === false || !@rename($tmp, $dir . '/state.json')) {
            @unlink($tmp);
            error_log('[update] 写不了更新状态');
        } else {
            Ownership::adopt($dir . '/state.json');
        }
    }

    private static function log(string $level, string $step, string $message, array $context = []): void
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        Ownership::adopt($dir);

        $file = $dir . '/update.log';
        if (is_file($file) && (int)@filesize($file) > self::LOG_MAX_BYTES) {
            @unlink($file . '.1');
            @rename($file, $file . '.1');
        }

        $line = json_encode([
            'ts'      => gmdate('Y-m-d\TH:i:s\Z'),
            'level'   => $level,
            'step'    => $step,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($line !== false) {
            @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
            Ownership::adopt($file);
        }

        if ($level === 'error') {
            error_log('[update] ' . $step . ': ' . $message);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private static function logTail(int $lines): array
    {
        $file = self::dir() . '/update.log';
        if (!is_file($file)) {
            return [];
        }

        $all = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $out = [];
        foreach (array_slice($all, -max(1, $lines)) as $line) {
            $data = json_decode($line, true);
            if (is_array($data)) {
                $out[] = $data;
            }
        }

        return $out;
    }

    private static function lock(): mixed
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }
        Ownership::adopt($dir);

        $fh = @fopen($dir . '/update.lock', 'c');
        if ($fh === false) {
            return null;
        }
        Ownership::adopt($dir . '/update.lock');
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);

            return null;
        }

        ftruncate($fh, 0);
        fwrite($fh, (string)json_encode(['pid' => getmypid(), 'at' => time()]));
        fflush($fh);

        return $fh;
    }

    private static function unlock(mixed $handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** 写一个标记，等下一次正常请求（新代码、插件已启动）再触发 update.completed */
    private static function queueCompleted(array $run): void
    {
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建 ' . $dir);
        }

        $payload = json_encode([
            'from'        => (string)($run['from'] ?? ''),
            'to'          => (string)($run['to'] ?? ''),
            'run'         => (string)($run['id'] ?? ''),
            'finished_at' => (int)($run['finished_at'] ?? time()),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false || @file_put_contents($dir . '/completed.json', $payload . "\n") === false) {
            throw new \RuntimeException('无法写入 ' . $dir . '/completed.json');
        }

        Ownership::adopt($dir . '/completed.json');
    }

    private static function dir(): string
    {
        return self::root() . self::STATE_DIR;
    }

    private static function stateDirWritable(): bool
    {
        $dir = self::dir();
        if (is_dir($dir)) {
            return is_writable($dir);
        }

        $parent = dirname($dir);

        return is_dir($parent) && is_writable($parent);
    }

    private static function root(): string
    {
        return dirname(__DIR__);
    }

    private static function currentVersion(): string
    {
        $config = self::config();
        $version = (string)($config['version'] ?? '');
        if ($version !== '') {
            return $version;
        }

        $file = self::root() . '/config/app.php';
        if (is_file($file) && preg_match("/'version'\s*=>\s*'([^']+)'/", (string)file_get_contents($file), $m) === 1) {
            return $m[1];
        }

        return '0.0.0';
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function versionParts(string $version): array
    {
        $base = preg_split('/[+-]/', $version)[0] ?? $version;
        $parts = array_map('intval', explode('.', $base));
        $parts = array_slice(array_pad($parts, 3, 0), 0, 3);

        return [$parts[0], $parts[1], $parts[2]];
    }

    private static function sameVersion(string $a, string $b): bool
    {
        return self::versionParts($a) === self::versionParts($b);
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        if (self::$config !== null) {
            return self::$config;
        }

        return self::$config = UpdateConfig::all();
    }
}
