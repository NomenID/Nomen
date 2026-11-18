<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

use Nomen\Src\Hooks;
use Nomen\Src\Schedule;

test('调度器按秒数算下一次，间隔有下限', static function (): void {
    assertSame(1600, Schedule::nextRunAt(['every' => 600, 'daily_at' => null], 1000));
    assertSame(1030, Schedule::nextRunAt(['every' => 5, 'daily_at' => null], 1000));
});

test('daily_at 按站点时区算，过点排到明天', static function (): void {
    $zone = new DateTimeZone(date_default_timezone_get());
    $task = ['every' => 86400, 'daily_at' => '03:10'];

    $before = (new DateTimeImmutable('2026-11-15 02:00:00', $zone))->getTimestamp();
    $next = (new DateTimeImmutable('@' . Schedule::nextRunAt($task, $before)))->setTimezone($zone);
    assertSame('2026-11-15 03:10:00', $next->format('Y-m-d H:i:s'));

    $after = (new DateTimeImmutable('2026-11-15 04:00:00', $zone))->getTimestamp();
    $next = (new DateTimeImmutable('@' . Schedule::nextRunAt($task, $after)))->setTimezone($zone);
    assertSame('2026-11-16 03:10:00', $next->format('Y-m-d H:i:s'));
});

test('插件任务走 schedule.tasks，非法条目被忽略，核心不被顶掉', static function (): void {
    Hooks::add('schedule.tasks', static function (array $tasks): array {
        $tasks[] = ['id' => 'demo.ok', 'label' => 'Demo', 'every' => 120, 'run' => static fn() => null];
        $tasks[] = ['id' => 'demo.bad id', 'run' => static fn() => null];
        $tasks[] = ['id' => 'demo.norun', 'every' => 60];
        $tasks[] = ['id' => 'demo.baddaily', 'daily_at' => '25:00', 'run' => static fn() => null];
        $tasks[] = ['id' => 'core.tick', 'label' => 'Shadow', 'every' => 60, 'run' => static fn() => null];

        return $tasks;
    });

    $tasks = Schedule::tasks();

    assertTrue(isset($tasks['demo.ok']), '合法任务要收下');
    assertSame(120, $tasks['demo.ok']['every']);
    assertTrue(!isset($tasks['demo.bad id']), 'id 不合法要忽略');
    assertTrue(!isset($tasks['demo.norun']), '没有回调要忽略');
    assertTrue(!isset($tasks['demo.baddaily']), 'daily_at 不合法要忽略');
    assertSame('Scheduler heartbeat', $tasks['core.tick']['label'], '核心任务不能被插件顶掉');
});

test('crontab 那行指向 schedule run', static function (): void {
    assertMatches('/schedule run/', Schedule::crontab());
});
