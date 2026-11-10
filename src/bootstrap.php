<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

// 统一启动：加载 .env、Composer 依赖和自动加载。Web 入口与 CLI 共用。

$root = dirname(__DIR__);

$envFile = $root . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value, " \t\n\r\0\x0B\"'"));
    }
}

if (file_exists($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
}

spl_autoload_register(function (string $class) use ($root) {
    $prefixes = [
        'Nomen\\Src\\' => $root . '/src/',
        'Nomen\\Controllers\\' => $root . '/controllers/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (strpos($class, $prefix) === 0) {
            $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (file_exists($file)) {
                require $file;
                return;
            }
        }
    }
});

// 全局兜底：未捕获异常和致命错误都走独立报错页（细节受 APP_DEBUG 控制）
\Nomen\Src\ErrorPage::register();

// 核心给出的 head meta，皮肤经 layout.head 钩子拿到；优先级 1，排在插件追加的内容前面
\Nomen\Src\Hooks::add('layout.head', static fn(string $html): string => $html . \Nomen\Src\Meta::head(), 1);
