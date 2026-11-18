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
use PDOException;
use Throwable;

/**
 * 定时任务：服务器上一条 crontab 每分钟调用 `php bin/nomen schedule run`，
 * 每个任务自己声明间隔，由这里决定这一分钟该跑谁。
 *
 * 没有 cron 的站点照常工作 —— 任务只是不按点跑，功能本身不依赖它们。
 */
final class Schedule
{
    /** 任务卡住多久之后允许重试（秒） */
    private const LOCK_TTL = 600;

    /** 间隔下限，避免插件写 every => 1 把机器打满 */
    private const MIN_INTERVAL = 30;

    /** 心跳超过这么久没来就算 cron 没配（秒） */
    private const STALE_AFTER = 180;

    /**
     * 核心任务 + 插件通过 schedule.tasks 钩子追加的任务。
     * 同 id 先注册的生效，核心的不会被插件顶掉。
     *
     * @return array<string, array{id: string, label: string, owner: string, every: int, daily_at: ?string, run: callable}>
     */
    public static function tasks(): array
    {
        $all = [];

        $core = [
            [
                'id'    => 'core.tick',
                'label' => 'Scheduler heartbeat',
                'owner' => 'core',
                'every' => 60,
                'run'   => static function (): void {
                },
            ],
        ];

        foreach ($core as $task) {
            $task = self::normalize($task);
            if ($task !== null) {
                $all[$task['id']] = $task;
            }
        }

        $extra = Hooks::filter('schedule.tasks', []);
        if (is_array($extra)) {
            foreach ($extra as $task) {
                $task = self::normalize($task);
                if ($task !== null && !isset($all[$task['id']])) {
                    $all[$task['id']] = $task;
                }
            }
        }

        return $all;
    }

    /**
     * 插件交上来的任务长这样：
     * ['id' => 'pages.sitemap', 'label' => 'Sitemap', 'every' => 600, 'run' => fn() => ...]
     * 或者用 'daily_at' => '03:10' 代替 'every'（按 PHP 时区算）。
     *
     * @return array{id: string, label: string, owner: string, every: int, daily_at: ?string, run: callable}|null
     */
    private static function normalize(mixed $task): ?array
    {
        if (!is_array($task) || !is_callable($task['run'] ?? null)) {
            return null;
        }

        $id = (string)($task['id'] ?? '');
        if (preg_match('/^[a-z][a-z0-9._-]{0,63}$/i', $id) !== 1) {
            error_log('[schedule] 任务 id 不合法，已忽略: ' . $id);
            return null;
        }

        $dailyAt = trim((string)($task['daily_at'] ?? ''));
        if ($dailyAt !== '') {
            if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $dailyAt, $m) !== 1) {
                error_log('[schedule] ' . $id . ' 的 daily_at 不是 HH:MM，已忽略');
                return null;
            }
            $dailyAt = sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
        }

        return [
            'id'       => $id,
            'label'    => (string)($task['label'] ?? $id),
            'owner'    => (string)($task['owner'] ?? ''),
            'every'    => $dailyAt === '' ? max(self::MIN_INTERVAL, (int)($task['every'] ?? 300)) : 86400,
            'daily_at' => $dailyAt === '' ? null : $dailyAt,
            'run'      => $task['run'],
        ];
    }

    /** 下一次该跑的时间（Unix 时间戳） */
    public static function nextRunAt(array $task, int $now): int
    {
        if (!empty($task['daily_at'])) {
            [$hour, $minute] = array_map('intval', explode(':', (string)$task['daily_at']));
            $zone = new \DateTimeZone(date_default_timezone_get());
            $at = (new \DateTimeImmutable('@' . $now))->setTimezone($zone)->setTime($hour, $minute);

            return $at->getTimestamp() > $now ? $at->getTimestamp() : $at->modify('+1 day')->getTimestamp();
        }

        return $now + max(self::MIN_INTERVAL, (int)($task['every'] ?? 300));
    }

    /**
     * 跑这一分钟到点的任务。返回要打印的日志行。
     *
     * @return string[]
     */
    public static function run(int $budget = 45): array
    {
        $out = [];
        $db = Database::getInstance();
        $deadline = time() + max(5, $budget);

        foreach (self::tasks() as $task) {
            if (time() >= $deadline) {
                $out[] = '时间预算用完，剩下的任务下一次心跳继续';
                break;
            }

            self::ensureRow($db, $task);
            if (!self::claim($db, $task['id'])) {
                continue;
            }

            $started = microtime(true);
            $status = 'ok';
            $error = '';

            try {
                ($task['run'])();
            } catch (Throwable $e) {
                $status = 'error';
                $error = mb_substr($e->getMessage(), 0, 1000);
                error_log('[schedule] ' . $task['id'] . ' 失败: ' . $e->getMessage());
            }

            self::finish($db, $task, $status, $error);
            $out[] = sprintf(
                '%s %s %s (%.2fs)',
                date('Y-m-d H:i:s'),
                $task['id'],
                $status,
                microtime(true) - $started
            );
        }

        return $out;
    }

    /** 任务第一次出现时补一行；间隔改了跟着更新 */
    private static function ensureRow(PDO $db, array $task): void
    {
        $db->prepare(Database::dialectFor($db)->insertIgnore(
            'INSERT IGNORE INTO scheduled_tasks (name, interval_seconds, next_run_at) VALUES (:name, :every, 0)'
        ))->execute([':name' => $task['id'], ':every' => $task['every']]);

        $db->prepare(
            'UPDATE scheduled_tasks SET interval_seconds = :every WHERE name = :name AND interval_seconds <> :every2'
        )->execute([':every' => $task['every'], ':name' => $task['id'], ':every2' => $task['every']]);
    }

    /** 一条 UPDATE 抢任务：next_run_at 到点、而且没被别人占着 */
    private static function claim(PDO $db, string $id): bool
    {
        $now = time();
        $stmt = $db->prepare(
            'UPDATE scheduled_tasks SET running_until = :until, last_run_at = :now, last_status = :running '
            . 'WHERE name = :name AND next_run_at <= :now2 AND running_until < :now3'
        );
        $stmt->execute([
            ':until'   => $now + self::LOCK_TTL,
            ':now'     => $now,
            ':running' => 'running',
            ':name'    => $id,
            ':now2'    => $now,
            ':now3'    => $now,
        ]);

        return $stmt->rowCount() === 1;
    }

    private static function finish(PDO $db, array $task, string $status, string $error): void
    {
        $now = time();
        $stmt = $db->prepare(
            'UPDATE scheduled_tasks SET running_until = 0, last_finished_at = :now, last_status = :status, '
            . 'last_error = :error, next_run_at = :next, '
            . 'failures = CASE WHEN :is_error = 1 THEN failures + 1 ELSE 0 END '
            . 'WHERE name = :name'
        );
        $stmt->execute([
            ':now'      => $now,
            ':status'   => $status,
            ':error'    => $error,
            ':next'     => self::nextRunAt($task, $now),
            ':is_error' => $status === 'error' ? 1 : 0,
            ':name'     => $task['id'],
        ]);
    }

    /** 表在不在：老站还没跑 upgrade 时后台只提示，不炸 */
    public static function ready(): bool
    {
        try {
            Database::getInstance()->query('SELECT 1 FROM scheduled_tasks LIMIT 1');

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /** 后台「设置」页用：任务清单 + 库里记录的状态 */
    public static function status(): array
    {
        $rows = [];
        if (self::ready()) {
            try {
                foreach (Database::getInstance()->query('SELECT * FROM scheduled_tasks')->fetchAll() ?: [] as $row) {
                    $rows[(string)$row['name']] = $row;
                }
            } catch (PDOException $e) {
                error_log('[schedule] 读取任务状态失败: ' . $e->getMessage());
            }
        }

        $tasks = [];
        foreach (self::tasks() as $id => $task) {
            $row = $rows[$id] ?? [];
            $tasks[] = [
                'id'        => $id,
                'label'     => $task['label'],
                'owner'     => $task['owner'],
                'every'     => $task['every'],
                'dailyAt'   => $task['daily_at'],
                'lastRun'   => (int)($row['last_run_at'] ?? 0),
                'nextRun'   => (int)($row['next_run_at'] ?? 0),
                'status'    => (string)($row['last_status'] ?? ''),
                'error'     => (string)($row['last_error'] ?? ''),
                'failures'  => (int)($row['failures'] ?? 0),
                'running'   => (int)($row['running_until'] ?? 0) > time(),
            ];
        }

        return [
            'ready'    => self::ready(),
            'crontab'  => self::crontab(),
            'lastTick' => (int)($rows['core.tick']['last_run_at'] ?? 0),
            'tasks'    => $tasks,
        ];
    }

    /** 后台顶部提示用：cron 有没有在跑 */
    public static function banner(): array
    {
        if (!self::ready()) {
            return ['ready' => false, 'stale' => false, 'lastTick' => 0];
        }

        try {
            $stmt = Database::getInstance()->prepare('SELECT last_run_at FROM scheduled_tasks WHERE name = :name');
            $stmt->execute([':name' => 'core.tick']);
            $last = (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            $last = 0;
        }

        return [
            'ready'    => true,
            'stale'    => $last === 0 || time() - $last > self::STALE_AFTER,
            'lastTick' => $last,
        ];
    }

    /** 后台「立即运行」：只把 next_run_at 拨到现在，真正执行交给下一次心跳 */
    public static function requestNow(string $id): bool
    {
        if (!isset(self::tasks()[$id]) || !self::ready()) {
            return false;
        }

        $stmt = Database::getInstance()->prepare('UPDATE scheduled_tasks SET next_run_at = 0 WHERE name = :name');
        $stmt->execute([':name' => $id]);

        return true;
    }

    /** 文档和后台里给站长抄的那一条 */
    public static function crontab(): string
    {
        $root = dirname(__DIR__);
        $bin = $root . '/bin/nomen';
        if (str_contains($bin, ' ')) {
            $bin = "'" . $bin . "'";
        }

        return '* * * * * php ' . $bin . ' schedule run >/dev/null 2>&1';
    }
}
