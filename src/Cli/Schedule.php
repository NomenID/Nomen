<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src\Cli;

use Nomen\Src\Plugins;
use Nomen\Src\Schedule as Scheduler;

class Schedule
{
    public static function run(array $args): int
    {
        Plugins::boot();

        $sub = $args[0] ?? 'run';
        if ($sub === 'list') {
            return self::show();
        }
        if ($sub !== 'run') {
            fwrite(STDERR, '未知子命令：' . $sub . '（schedule run / schedule list）' . PHP_EOL);
            return 1;
        }

        if (!Scheduler::ready()) {
            fwrite(STDERR, 'scheduled_tasks 表不存在：先跑 php bin/nomen upgrade' . PHP_EOL);
            return 1;
        }

        $budget = (int)(getenv('NOMEN_SCHEDULE_BUDGET') ?: 45);
        foreach (Scheduler::run($budget) as $line) {
            echo $line, PHP_EOL;
        }

        return 0;
    }

    private static function show(): int
    {
        $status = Scheduler::status();

        echo '定时任务', PHP_EOL;
        foreach ($status['tasks'] as $task) {
            echo '  ', str_pad($task['id'], 22), self::describe($task), PHP_EOL;
            echo '  ', str_pad('', 22), '上次：', self::time($task['lastRun']), '　下次：', self::time($task['nextRun']);
            if ($task['status'] === 'error') {
                echo '　失败 ' . $task['failures'] . ' 次：' . $task['error'];
            }
            echo PHP_EOL;
        }

        echo PHP_EOL, '给服务器 crontab 加这一条（每分钟一次，具体跑什么由任务自己决定）：', PHP_EOL;
        echo '  ', $status['crontab'], PHP_EOL, PHP_EOL;

        if (!$status['ready']) {
            echo '表还没建：先跑 php bin/nomen upgrade', PHP_EOL;
        } elseif ($status['lastTick'] === 0) {
            echo '还没跑过 —— cron 配上之后等一分钟再看。', PHP_EOL;
        } else {
            echo '上次心跳：', self::time($status['lastTick']), PHP_EOL;
        }

        return 0;
    }

    private static function describe(array $task): string
    {
        if (!empty($task['dailyAt'])) {
            return '每天 ' . $task['dailyAt'];
        }

        $minutes = (int)($task['every'] / 60);

        return $minutes <= 1 ? '每分钟' : '每 ' . $minutes . ' 分钟';
    }

    private static function time(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '从未';
    }
}
