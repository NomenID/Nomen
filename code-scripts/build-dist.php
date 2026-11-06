#!/usr/bin/env php
<?php
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

// 插件不在核心仓库里，打包机上得先 clone 到 plugins/；漏了就打出个没有插件的包
foreach (['pages', 'open-platform', 'geoip'] as $bundled) {
    if (!is_file($root . '/plugins/' . $bundled . '/plugin.json')) {
        fwrite(STDERR, '缺插件：plugins/' . $bundled . '（见 plugins/README.md，先 clone 到 plugins/）' . PHP_EOL);
        exit(1);
    }
}

// 源码、开发笔记、构建脚本和运行期数据都不进包
$skip = [
    '.git',
    '.zcode',
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
    'config/installed.php',
    'public/assets/avatars',
    'public/assets/recovery',
    'public/docs',
];

$files = [];
$walk = static function (string $dir, string $rel) use (&$walk, &$files, $skip): void {
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
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
        $files[] = $path;
    }
};
$walk($root, '');

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
foreach ($files as $rel) {
    $zip->addFile($root . '/' . $rel, 'nomen/' . $rel);
}
$zip->close();

printf('版本 %s，%d 个文件，%s 字节%s%s', $version, count($files), number_format(filesize($out)), PHP_EOL, $out);
