#!/usr/bin/env php
<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

$root = dirname(__DIR__);
$args = array_slice($argv, 1);

if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo '用法：php code-scripts/build-dist.php [--skip-frontend]', PHP_EOL, PHP_EOL;
    echo '  跑一次 vite build，再把发布用的文件打成 dist/nomen-<版本>.zip', PHP_EOL;
    echo '  --skip-frontend  跳过前端构建', PHP_EOL;
    exit(0);
}

if (!in_array('--skip-frontend', $args, true)) {
    $npm = PHP_OS_FAMILY === 'Windows' ? 'npm.cmd' : 'npm';
    passthru($npm . ' --prefix ' . escapeshellarg($root . '/frontend') . ' run build', $code);
    if ($code !== 0) {
        fwrite(STDERR, '前端构建失败' . PHP_EOL);
        exit(1);
    }
}

$manifest = $root . '/public/assets/dist/.vite/manifest.json';
if (!is_file($manifest)) {
    fwrite(STDERR, '没有前端产物：' . $manifest . PHP_EOL);
    exit(1);
}

if (!preg_match("/'version'\s*=>\s*'([^']+)'/", (string)file_get_contents($root . '/config/app.php'), $m)) {
    fwrite(STDERR, 'config/app.php 里找不到 version' . PHP_EOL);
    exit(1);
}
$version = $m[1];

// 插件和官方皮肤是独立仓库。默认在 plugins/、skins/ 下找；本机把源码放在仓库外时，
// 由不进 git 的 paths.local.php 指出位置（见 src/LocalPaths.php）。
$paths = is_file($root . '/paths.local.php') ? (array)(require $root . '/paths.local.php') : [];
$localPlugins = rtrim(str_replace('\\', '/', (string)($paths['plugins'] ?? '')), '/');
$localSkins = rtrim(str_replace('\\', '/', (string)($paths['skins'] ?? '')), '/');
$pluginsDir = $localPlugins === '' ? $root . '/plugins' : $localPlugins;
$skinsDir = $localSkins === '' ? $root . '/skins' : $localSkins;

$bundledPlugins = ['pages', 'open-platform', 'geoip', 'totp-qr'];
$bundledSkins = ['nomen-2026', 'nomen-ssr'];
foreach ($bundledPlugins as $bundled) {
    if (!is_file($pluginsDir . '/' . $bundled . '/plugin.json')) {
        fwrite(STDERR, '缺插件：' . $pluginsDir . '/' . $bundled . '（见 plugins/README.md）' . PHP_EOL);
        exit(1);
    }
}
foreach ($bundledSkins as $bundled) {
    if (!is_file($skinsDir . '/' . $bundled . '/skin.json')) {
        fwrite(STDERR, '缺皮肤：' . $skinsDir . '/' . $bundled . '（见 skins/README.md）' . PHP_EOL);
        exit(1);
    }
}

// 源码、开发笔记、构建脚本和运行期数据都不进包
$skip = [
    '.git',
    '.github',
    '.zcode',
    'tests',
    'node_modules',
    'frontend',
    'docs-internal',
    'dist',
    'code-scripts',
    'data',
    '.env',
    '.gitignore',
    'AGENTS.md',
    'CLAUDE.md',
    'paths.local.php',
    'config/installed.php',
    'config/home-nomenid.php',
    'public/assets/avatars',
    'public/assets/recovery',
    'public/docs',
];

/** @var array<string, string> $files 包内路径 => 源文件绝对路径 */
$files = [];
$walk = static function (string $dir, string $rel) use (&$walk, &$files, $skip, $bundledPlugins, $bundledSkins): void {
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        // 插件是独立仓库，plugins/<slug>/.git 不是发布内容
        if ($name === '.git' || $name === 'node_modules') {
            continue;
        }
        // plugins/、skins/ 顶层只打清单内的，本地多 clone 的（如只在个别实例部署的插件）不进包
        if ($rel === 'plugins' && $name !== 'README.md' && !in_array($name, $bundledPlugins, true)) {
            continue;
        }
        if ($rel === 'skins' && $name !== 'README.md' && !in_array($name, $bundledSkins, true)) {
            continue;
        }
        $path = $rel === '' ? $name : $rel . '/' . $name;
        if (in_array($path, $skip, true)) {
            continue;
        }
        if (is_dir($dir . '/' . $name)) {
            $walk($dir . '/' . $name, $path);
            continue;
        }
        $files[$path] = $dir . '/' . $name;
    }
};
$walk($root, '');

// 插件 / 皮肤源码在仓库外时，按包内路径 plugins/<slug>、skins/<slug> 补进来
foreach ($bundledPlugins as $bundled) {
    $walk($pluginsDir . '/' . $bundled, 'plugins/' . $bundled);
}
foreach ($bundledSkins as $bundled) {
    $walk($skinsDir . '/' . $bundled, 'skins/' . $bundled);
}

$out = $root . '/dist/nomen-' . $version . '.zip';
if (!is_dir($root . '/dist')) {
    mkdir($root . '/dist', 0777, true);
}
if (is_file($out)) {
    unlink($out);
}

$zip = new ZipArchive();
if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, '打不开输出文件：' . $out . PHP_EOL);
    exit(1);
}
foreach ($files as $rel => $source) {
    $zip->addFile($source, $rel);
}
$zip->close();

printf('版本 %s，%d 个文件，%s 字节%s%s', $version, count($files), number_format(filesize($out)), PHP_EOL, $out);
