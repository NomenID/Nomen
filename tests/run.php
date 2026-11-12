<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

/**
 * 零依赖测试器：`php tests/run.php`。
 *
 * 仓库里没有 vendor/（.gitignore 挡着）时用自带的 PSR-4 映射，
 * 所以 CI 不需要 composer。
 */

declare(strict_types=1);

$root = dirname(__DIR__);

$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        $map = ['Nomen\\Src\\' => '/src/', 'Nomen\\Controllers\\' => '/controllers/'];
        foreach ($map as $prefix => $dir) {
            if (str_starts_with($class, $prefix)) {
                $file = $root . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
                return;
            }
        }
    });
}

$tests = [];

function test(string $name, callable $body): void
{
    $GLOBALS['tests'][$name] = $body;
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "%s 期望 %s，实际 %s",
            $message === '' ? '值不相等：' : $message . '：',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertTrue(mixed $value, string $message = ''): void
{
    if ($value !== true) {
        throw new RuntimeException($message === '' ? '期望 true' : $message);
    }
}

function assertMatches(string $pattern, string $value, string $message = ''): void
{
    if (preg_match($pattern, $value) !== 1) {
        throw new RuntimeException(($message === '' ? '不匹配：' : $message . '：') . $pattern . ' vs ' . $value);
    }
}

function assertThrows(callable $body, string $message = ''): void
{
    try {
        $body();
    } catch (Throwable $e) {
        return;
    }

    throw new RuntimeException($message === '' ? '期望抛异常，但没有' : $message);
}

foreach (glob(__DIR__ . '/cases/*.php') ?: [] as $case) {
    require $case;
}

$failed = 0;
$count = 0;

foreach ($tests as $name => $body) {
    $count++;
    try {
        $body();
        echo 'ok   ' . $name . PHP_EOL;
    } catch (Throwable $e) {
        $failed++;
        echo 'FAIL ' . $name . PHP_EOL;
        echo '     ' . str_replace("\n", "\n     ", $e->getMessage()) . PHP_EOL;
    }
}

echo PHP_EOL, $failed === 0
    ? sprintf('%d 项全部通过', $count)
    : sprintf('%d 项中 %d 项失败', $count, $failed), PHP_EOL;

exit($failed === 0 ? 0 : 1);
