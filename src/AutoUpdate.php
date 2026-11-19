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
 * 设计参照 WordPress 的后台自动更新：
 *  - 默认关闭，站长在后台打开；
 *  - 同一时间只允许一个更新（文件锁 + 调度器抢占）；
 *  - 覆盖前先打备份，失败自动回滚；
 *  - 下载包必须校验 SHA-256；
 *  - 成功 / 失败各通知管理员一次，同一版本不重复发；
 *  - 下载这类瞬时失败隔一小时重试一次，再失败才发信。
 *
 * 状态和日志都在 data/updates/ 下，不进发布包、不暴露给公网。
 */
final class AutoUpdate
{
    public const CHANNELS = [
        'patch' => 'Patch releases only (x.y.z)',
        'minor' => 'Same major version (x.y)',
        'all'   => 'All releases',
    ];

    private const STATE_DIR = '/data/updates';
    private const LOG_MAX_BYTES = 2 * 1024 * 1024;
    private const HISTORY_MAX = 10;
    private const TRANSIENT = ['download_failed', 'checksum_mismatch', 'unzip_failed', 'disk_full'];
    private const CRITICAL = ['copy_failed', 'migration_failed', 'post_check_failed', 'rollback_failed', 'internal_error'];

    private static ?array $config = null;

    // ==================== 对外入口 ====================

    public static function enabled(): bool
    {
        return (string)Settings::get('update.auto', '0') === '1';
    }

    public static function channel(): string
    {
        $value = (string)Settings::get('update.channel', 'minor');

        return isset(self::CHANNELS[$value]) ? $value : 'minor';
    }

    public static function interval(): int
    {
        return max(3600, (int)(self::config()['interval'] ?? 43200));
    }

    /** 后台 / 邮件用的收件人：设置 > 品牌里的管理员信箱 > 最早的管理员账号 */
    public static function notifyEmail(): string
    {
        $email = trim((string)Settings::get('update.notify_email', ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
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

        return [
            'enabled'             => self::enabled(),
            'channel'             => self::channel(),
            'channels'            => self::CHANNELS,
            'notifyEmail'         => (string)Settings::get('update.notify_email', ''),
            'effectiveNotifyEmail' => self::notifyEmail(),
            'currentVersion'      => self::currentVersion(),
            'latest'              => (string)($state['latest'] ?? ''),
            'pending'             => (string)($state['pending'] ?? ''),
            'lastCheckAt'         => (int)($state['last_check_at'] ?? 0),
            'lastCheckOk'         => (bool)($state['last_check_ok'] ?? false),
            'lastCheckError'      => (string)($state['last_check_error'] ?? ''),
            'lastRun'             => $state['history'][0] ?? null,
            'history'             => array_slice((array)($state['history'] ?? []), 0, 5),
            'failed'              => $state['failed'] ?? null,
            'running'             => $state['running'] ?? null,
            'logTail'             => self::logTail(60),
            'mailConfigured'      => Email::isConfigured(),
            'zipAvailable'        => class_exists(\ZipArchive::class),
            'curlAvailable'       => function_exists('curl_init'),
            'writable'            => is_writable($root) && is_writable($root . '/src')
                && is_writable($root . '/public') && is_writable($root . '/config')
                && self::stateDirWritable(),
            'phpOk'               => version_compare(PHP_VERSION, (string)(self::config()['min_php'] ?? '8.1'), '>='),
            'interval'            => self::interval(),
        ];
    }

    /**
     * 查一次。$apply 为 false 时只检查，不安装（后台/CLI 的「立即检查」）。
     *
     * @return array{status: string, current: string, latest: string, message: string, run?: array}
     */
    public static function check(bool $apply = true, bool $force = false, ?callable $onStep = null): array
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

        $pending = (string)($state['pending'] ?? '');
        $offer = (array)($state['offer'] ?? []);

        if (!$force && $pending === '' && !self::retryDue($state)
            && (int)($state['last_check_at'] ?? 0) > 0
            && time() - (int)$state['last_check_at'] < self::interval()) {
            return ['status' => 'idle', 'current' => $current, 'latest' => (string)($state['latest'] ?? ''), 'message' => ''];
        }

        if ($offer === [] || $force) {
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

        if (!UpdateCheck::isNewer($latest, $current)) {
            $state['pending'] = '';
            unset($state['offer']);
            self::saveState($state);

            return ['status' => 'current', 'current' => $current, 'latest' => $latest, 'message' => '已是最新版本'];
        }

        if (!self::channelAllows($current, $latest, self::channel())) {
            $state['pending'] = '';
            self::saveState($state);

            return [
                'status'  => 'channel_skipped',
                'current' => $current,
                'latest'  => $latest,
                'message' => '新版本不在当前更新通道内',
            ];
        }

        $state['pending'] = $latest;
        self::saveState($state);

        if (self::enabled() && self::blocked($state, $latest)) {
            return [
                'status'  => 'blocked',
                'current' => $current,
                'latest'  => $latest,
                'message' => '这个版本上次失败过，已暂停自动重试；可在后台清除失败记录',
            ];
        }

        if (!$apply || !self::enabled()) {
            return [
                'status'  => 'available',
                'current' => $current,
                'latest'  => $latest,
                'message' => self::enabled() ? '有新版本，等待安装' : '有新版本；自动更新未开启',
            ];
        }

        $preflight = self::preflight($offer);
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

    public static function resetFailure(): void
    {
        $state = self::state();
        unset($state['failed']);
        self::saveState($state);
        self::log('info', 'reset', '管理员清除了失败记录');
    }

    /** 通道判断；版本只看前三段数字，+qb.<日期> 后缀不参与 */
    public static function channelAllows(string $current, string $target, string $channel): bool
    {
        if (!UpdateCheck::isNewer($target, $current)) {
            return false;
        }

        [$cm, $cn] = self::versionParts($current);
        [$tm, $tn] = self::versionParts($target);

        return match ($channel) {
            'patch' => $cm === $tm && $cn === $tn,
            'minor' => $cm === $tm,
            'all'   => true,
            default => false,
        };
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
            Cache::forget('update_check.latest');

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

        $url = rtrim((string)Brand::get('base_url'), '/') . '/api/version?action=thissite&_=' . time();
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
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw) || $status === 0) {
            self::log('warning', 'post_check', '回访失败：' . ($error ?: '无响应'));

            return null;
        }
        if ($status >= 500) {
            return false;
        }
        if ($status !== 200) {
            self::log('warning', 'post_check', '回访返回 HTTP ' . $status . '，跳过判断');

            return null;
        }

        $data = json_decode($raw, true);
        $running = is_array($data) ? (string)($data['version'] ?? '') : '';
        if ($running === '') {
            self::log('warning', 'post_check', '回访响应里没有版本号，跳过判断');

            return null;
        }

        return self::sameVersion($running, $expected);
    }

    // ==================== 状态 / 日志 / 邮件 ====================

    private static function preflight(array $offer): string
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

        return '';
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

        $file = self::root() . '/config/app.php';
        $config = is_file($file) ? require $file : [];
        self::$config = is_array($config['updates'] ?? null) ? $config['updates'] : [];

        return self::$config;
    }
}
