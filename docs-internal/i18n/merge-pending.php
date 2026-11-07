<?php
/**
 * 把待补翻译合并进语言文件，是补翻译流程的下半场：
 *   1. php docs-internal/i18n/extract.php                 生成 zh-hans.json（含新键）
 *   2. 准备 pending/<语言>.json，只含该语言的新键翻译
 *   3. php docs-internal/i18n/merge-pending.php <pending目录或单个json> [词典目录]
 *      （词典目录默认核心的 locales/；插件/皮肤传各自的 i18n/ 目录）
 *   4. php docs-internal/i18n/extract.php --check         全绿才算完
 *
 * 只新增条目、不改已有条目；合并后按键排序重写，格式与 extract.php 生成的一致。
 */

$root = dirname(__DIR__, 2);
require $root . '/src/I18n.php';

use Nomen\Src\I18n;

$target = $argv[1] ?? '';
$domainDir = $argv[2] ?? ($root . '/locales');

if ($target === '' || (!is_dir($target) && !is_file($target))) {
    echo "用法: php merge-pending.php <pending目录或单个<语言>.json> [词典目录，默认 locales/]\n";
    exit(1);
}
if (!is_dir($domainDir)) {
    echo "词典目录不存在: {$domainDir}\n";
    exit(1);
}

$files = is_dir($target)
    ? (glob(rtrim($target, '/\\') . '/*.json') ?: [])
    : [$target];

$bad = 0;
foreach ($files as $file) {
    $slug = basename($file, '.json');
    if (!I18n::isValid($slug)) {
        echo "[跳过] {$slug} 不是语言注册表里的语言\n";
        $bad++;
        continue;
    }
    $data = json_decode((string)file_get_contents($file), true);
    if (!is_array($data)) {
        echo "[跳过] {$slug} 的待补文件不是合法 JSON\n";
        $bad++;
        continue;
    }

    $localeFile = rtrim($domainDir, '/\\') . '/' . $slug . '.json';
    $existing = is_file($localeFile) ? (json_decode((string)file_get_contents($localeFile), true) ?: []) : [];

    $added = 0;
    foreach ($data as $k => $v) {
        if (!is_string($k) || !is_string($v) || $v === '') {
            continue;
        }
        if (!isset($existing[$k])) {
            $existing[$k] = $v;
            $added++;
        } elseif ($existing[$k] !== $v) {
            echo "[保留现有] {$slug}: {$k}\n";
        }
    }

    ksort($existing, SORT_STRING);
    file_put_contents(
        $localeFile,
        json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
    );
    echo "{$slug}: +{$added} 条 → " . str_replace('\\', '/', $localeFile) . "\n";
}

exit($bad === 0 ? 0 : 1);
