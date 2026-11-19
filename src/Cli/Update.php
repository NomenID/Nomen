<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src\Cli;

use Nomen\Src\AutoUpdate;

class Update
{
    public static function run(array $args): int
    {
        $sub = $args[0] ?? 'status';

        return match ($sub) {
            'check'  => self::runCheck(false),
            'run'    => self::runCheck(true),
            'status' => self::showStatus(),
            'reset'  => self::reset(),
            default  => self::help(),
        };
    }

    private static function runCheck(bool $apply): int
    {
        $result = AutoUpdate::check($apply, true, static function (string $line): void {
            echo '  ', $line, PHP_EOL;
        });

        echo PHP_EOL, $result['message'] !== '' ? $result['message'] : $result['status'], PHP_EOL;

        if ($result['status'] === 'available' && $apply) {
            echo '自动更新未开启；到后台「设置 → 自动更新」打开后才会自动安装。', PHP_EOL;
        }

        return in_array($result['status'], ['failed', 'critical', 'check_failed'], true) ? 1 : 0;
    }

    private static function showStatus(): int
    {
        $status = AutoUpdate::status();

        echo '自动开关：', $status['autoSetting'] ? '开' : '关', PHP_EOL;
        echo '更新源：', $status['feedConfigured'] ? $status['feed'] : '未配置（设置 NOMEN_UPDATE_FEED 后才会检查）', PHP_EOL;
        echo '自动范围：', $status['scope'], '（', $status['scopes'][$status['scope']] ?? '', '）', PHP_EOL;
        echo '最低优先级：', $status['minPriority'], PHP_EOL;
        echo '检查计划：', $status['checkAt'] !== ''
            ? '每天 ' . $status['checkAt']
            : '每 ' . max(1, (int)round($status['interval'] / 3600)) . ' 小时', PHP_EOL;
        $window = '不限';
        if ($status['window'] !== '') {
            $window = $status['windowMode'] === 'deny'
                ? '除 ' . $status['window'] . ' 外'
                : '仅 ' . $status['window'];
        }
        echo '安装窗口：', $window, PHP_EOL;
        echo '当前版本：', $status['currentVersion'], PHP_EOL;
        echo '最新版本：', $status['latest'] !== '' ? $status['latest'] : '未知', PHP_EOL;
        echo '上次检查：', self::time($status['lastCheckAt']),
            ($status['lastCheckAt'] > 0 && !$status['lastCheckOk']) ? '（失败）' : '', PHP_EOL;
        echo '通知邮箱：', $status['effectiveNotifyEmail'] !== '' ? $status['effectiveNotifyEmail'] : '未配置', PHP_EOL;

        if ($status['eol'] !== null) {
            echo PHP_EOL, '提醒：当前版本已低于最低支持版本 ', $status['eol']['min_supported'] ?? '', PHP_EOL;
        }

        if ($status['pending'] !== '') {
            echo PHP_EOL, '待安装：', $status['pending'], '（', $status['pendingKind'], '）';
            if ($status['pendingPriority'] !== '') {
                echo '，优先级 ', $status['pendingPriority'];
            }
            echo PHP_EOL;
        }

        $last = $status['lastRun'];
        if (is_array($last)) {
            echo PHP_EOL, '上次更新：', $last['from'] ?? '?', ' → ', $last['to'] ?? '?', '，', $last['status'] ?? '', PHP_EOL;
            echo '  时间：', self::time((int)($last['finished_at'] ?? 0)), PHP_EOL;
            if (!empty($last['error'])) {
                echo '  错误：', $last['error_code'] ?? '', ' ', $last['error'], PHP_EOL;
            }
            if (!empty($last['rollback']) && $last['rollback'] !== 'not_needed') {
                echo '  回滚：', $last['rollback'], PHP_EOL;
            }
        }

        if (!empty($status['failed'])) {
            echo PHP_EOL, '失败记录：', $status['failed']['version'] ?? '', '（', $status['failed']['reason'] ?? '', '，'
                , (int)($status['failed']['attempts'] ?? 0), ' 次）', PHP_EOL;
        }

        $tail = array_slice($status['logTail'], -10);
        if ($tail !== []) {
            echo PHP_EOL, '最近日志：', PHP_EOL;
            foreach ($tail as $line) {
                echo '  ', $line['ts'] ?? '', ' ', strtoupper((string)($line['level'] ?? '')), ' '
                    , $line['step'] ?? '', ': ', $line['message'] ?? '', PHP_EOL;
            }
        }

        return 0;
    }

    private static function reset(): int
    {
        AutoUpdate::resetFailure();
        echo '失败记录已清除。', PHP_EOL;

        return 0;
    }

    private static function help(): int
    {
        echo '用法：php bin/nomen update [status|check|run|reset]', PHP_EOL, PHP_EOL;
        echo '  status  显示自动更新状态、上次结果和最近日志（默认）', PHP_EOL;
        echo '  check   立即查一次版本，不安装', PHP_EOL;
        echo '  run     立即查并安装（需要后台已打开自动更新）', PHP_EOL;
        echo '  reset   清除失败记录，让同一版本可以重新尝试', PHP_EOL;

        return 1;
    }

    private static function time(int $ts): string
    {
        return $ts > 0 ? date('Y-m-d H:i:s', $ts) : '从未';
    }
}
