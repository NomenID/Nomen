<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use ZipArchive;

/**
 * 自动更新里的「包」：下载、校验、解包、备份、覆盖、回滚。
 *
 * 这里不做策略判断（该不该更新、发不发邮件由 AutoUpdate 决定），只保证
 * 每一步要么成功、要么抛 UpdateException，并且不会把站点带进半更新状态。
 */
final class UpdatePackage
{
    /** 站点自己的配置 / 运行期数据，更新包永远不碰 */
    private const PROTECTED_FILES = [
        '.env',
        'config/installed.php',
        'config/plugins.php',
        'config/skins.php',
        'public/.user.ini',
        '.nomen-package.json',
    ];

    private const PROTECTED_DIRS = [
        'data/',
        'config/plugins/',
        'public/nomen-install/',
        'public/assets/avatars/',
        'public/assets/recovery/',
        'public/assets/develops/',
    ];

    /** 站点的依赖与品牌资源：文件已存在就不覆盖，缺的补上 */
    private const PRESERVE_IF_EXISTS = [
        'vendor/',
        'composer.json',
        'composer.lock',
        'config/',
        'public/assets/brand/',
    ];

    private string $dir;
    private string $zip;
    private string $stage;
    private string $backup = '';

    /** @var string[] 包内文件（相对站点根） */
    private array $files = [];

    /** @var string[] 上一次自动更新时落下的文件清单，可能为空 */
    private array $previousFiles = [];

    /** @var array<string, mixed> */
    private array $previousManifest = [];

    /**
     * @param array{version: string, download?: string, sha256?: string, min_php?: string} $offer
     */
    public function __construct(
        private readonly string $root,
        private readonly array $offer,
        private readonly string $fromVersion
    ) {
        $this->dir = $root . '/data/updates';
        $version = (string)($offer['version'] ?? 'unknown');
        $this->zip = $this->dir . '/nomen-' . self::safeName($version) . '.zip';
        $this->stage = $this->dir . '/stage-' . self::safeName($version) . '-' . bin2hex(random_bytes(4));
    }

    public function prepare(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            throw new UpdateException('files_not_writable', '无法创建 ' . $this->dir);
        }
        if (!is_writable($this->dir)) {
            throw new UpdateException('files_not_writable', $this->dir . ' 不可写');
        }
    }

    public function download(): void
    {
        $url = trim((string)($this->offer['download'] ?? ''));
        if ($url === '') {
            // 老版本更新源只报 version；按发布约定拼出下载地址
            $base = rtrim((string)(self::config()['download_base'] ?? ''), '/');
            if ($base !== '') {
                $url = $base . '/nomen-' . (string)($this->offer['version'] ?? '') . '.zip';
            }
        }
        if ($url === '') {
            throw new UpdateException('download_failed', '更新源没有给出下载地址');
        }
        if (!function_exists('curl_init')) {
            throw new UpdateException('download_failed', 'PHP 没有 curl 扩展');
        }

        $part = $this->zip . '.part';
        @unlink($part);
        $fh = @fopen($part, 'wb');
        if ($fh === false) {
            throw new UpdateException('files_not_writable', '无法写入 ' . $part);
        }

        $maxBytes = $this->maxPackageBytes();
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Nomen-AutoUpdate',
            CURLOPT_MAXFILESIZE    => $maxBytes,
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        $size = (int)curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        curl_close($ch);
        fclose($fh);

        if ($ok === false || $status !== 200) {
            @unlink($part);
            throw new UpdateException('download_failed', '下载失败（HTTP ' . $status . '）：' . ($error ?: $url));
        }
        if ($size < 100 * 1024 || $size > $maxBytes) {
            @unlink($part);
            throw new UpdateException('download_failed', '包大小异常：' . $size . ' 字节');
        }

        if (!@rename($part, $this->zip)) {
            @unlink($part);
            throw new UpdateException('files_not_writable', '无法保存下载包');
        }
        Ownership::adopt($this->zip);
    }

    /** 官方源优先用 API 给的哈希，没有就取同目录的 .sha256 文件 */
    public function verify(): void
    {
        $expected = strtolower(trim((string)($this->offer['sha256'] ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
            $expected = (string)$this->fetchChecksum();
        }
        if (preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
            throw new UpdateException('checksum_mismatch', '更新源没有提供可用的 SHA-256');
        }

        $actual = hash_file('sha256', $this->zip);
        if (!is_string($actual) || !hash_equals($expected, strtolower($actual))) {
            throw new UpdateException(
                'checksum_mismatch',
                'SHA-256 不匹配（期望 ' . substr($expected, 0, 12) . '…，实际 ' . substr((string)$actual, 0, 12) . '…）'
            );
        }
    }

    public function extract(): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new UpdateException('unzip_failed', 'PHP 没有 zip 扩展');
        }

        $zip = new ZipArchive();
        if ($zip->open($this->zip) !== true) {
            throw new UpdateException('unzip_failed', '打不开更新包');
        }

        try {
            $count = $zip->numFiles;
            if ($count <= 0 || $count > 20000) {
                throw new UpdateException('invalid_package', '包内文件数量异常：' . $count);
            }

            $maxTotal = $this->maxPackageBytes() * 2;
            $total = 0;
            $this->files = [];

            for ($i = 0; $i < $count; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) {
                    continue;
                }
                $raw = (string)($stat['name'] ?? '');
                if ($raw === '' || str_starts_with($raw, '/') || str_starts_with($raw, '\\')
                    || str_contains($raw, '\\') || str_contains($raw, "\0")
                    || preg_match('/^[A-Za-z]:/', $raw) === 1) {
                    throw new UpdateException('invalid_package', '包内有非法路径：' . $raw);
                }
                $name = self::normalizeEntry($raw);
                if ($name === '') {
                    continue;
                }
                if (!self::isSafeEntry($name)) {
                    throw new UpdateException('invalid_package', '包内有非法路径：' . $name);
                }
                if (self::isSymlink($zip, $i)) {
                    throw new UpdateException('invalid_package', '包内有符号链接：' . $name);
                }

                $total += max(0, (int)($stat['size'] ?? 0));
                if ($total > $maxTotal) {
                    throw new UpdateException('invalid_package', '解压后体积超过上限');
                }

                if (str_ends_with($name, '/')) {
                    $this->mkdir($this->stage . '/' . rtrim($name, '/'));
                    continue;
                }

                $target = $this->stage . '/' . $name;
                $this->mkdir(dirname($target));
                $this->extractEntry($zip, $raw, $target, 'unzip_failed');
                $this->files[] = $name;
            }
        } finally {
            $zip->close();
        }

        sort($this->files);
        if ($this->files === []) {
            throw new UpdateException('invalid_package', '更新包是空的');
        }
    }

    public function validate(): void
    {
        $version = (string)($this->offer['version'] ?? '');
        foreach (['src/bootstrap.php', 'public/index.php', 'config/app.php', 'bin/nomen'] as $required) {
            if (!is_file($this->stage . '/' . $required)) {
                throw new UpdateException('invalid_package', '包内缺少 ' . $required);
            }
        }

        $config = (string)@file_get_contents($this->stage . '/config/app.php');
        if (preg_match("/'version'\s*=>\s*'([^']+)'/", $config, $m) !== 1) {
            throw new UpdateException('invalid_package', '包内 config/app.php 没有版本号');
        }
        if ($version === '' || $m[1] !== $version) {
            throw new UpdateException(
                'invalid_package',
                '包内版本 ' . $m[1] . ' 与更新源声明的 ' . $version . ' 不一致'
            );
        }

        if (is_file($this->stage . '/config/installed.php') || is_file($this->stage . '/.env')) {
            throw new UpdateException('invalid_package', '更新包里带了站点配置文件，拒绝安装');
        }

        $manifest = $this->stage . '/.nomen-package.json';
        if (is_file($manifest)) {
            $data = json_decode((string)@file_get_contents($manifest), true);
            $listed = is_array($data) ? ($data['files'] ?? null) : null;
            if (!is_array($listed) || $listed === []) {
                throw new UpdateException('invalid_package', '包内清单文件损坏');
            }
            if ((string)($data['version'] ?? '') !== $version) {
                throw new UpdateException('invalid_package', '包内清单的版本与更新源不一致');
            }
            $clean = [];
            foreach ($listed as $rel) {
                $rel = self::normalizeEntry((string)$rel);
                if ($rel === '' || !self::isSafeEntry($rel) || !is_file($this->stage . '/' . $rel)) {
                    continue;
                }
                $clean[] = $rel;
            }
            if ($clean !== []) {
                $this->files = $clean;
            }
        }

        $this->previousFiles = $this->readPreviousManifest();
    }

    /** 覆盖之前把会被改动 / 删除的文件打进 data/updates 下的备份包 */
    public function backup(): void
    {
        $targets = [];
        foreach ($this->files as $rel) {
            if ($this->shouldSkip($rel, true)) {
                continue;
            }
            if (is_file($this->root . '/' . $rel)) {
                $targets[$rel] = true;
            }
        }
        foreach ($this->deletions() as $rel) {
            if (is_file($this->root . '/' . $rel)) {
                $targets[$rel] = true;
            }
        }

        if ($targets === []) {
            throw new UpdateException('backup_failed', '没有可备份的文件');
        }

        $estimated = 0;
        foreach (array_keys($targets) as $rel) {
            $estimated += (int)@filesize($this->root . '/' . $rel);
        }
        $this->ensureDiskSpace($estimated + (int)@filesize($this->zip) + 50 * 1024 * 1024);

        $this->mkdir($this->dir);
        $this->backup = $this->dir . '/backup-' . self::safeName($this->fromVersion) . '-'
            . gmdate('YmdHis') . '-' . bin2hex(random_bytes(2)) . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($this->backup, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new UpdateException('backup_failed', '建不了备份包');
        }
        foreach (array_keys($targets) as $rel) {
            $zip->addFile($this->root . '/' . $rel, $rel);
        }
        $zip->close();

        if (!is_file($this->backup) || (int)@filesize($this->backup) <= 0) {
            throw new UpdateException('backup_failed', '备份包是空的');
        }
        Ownership::adopt($this->backup);
    }

    public function backupPath(): string
    {
        return $this->backup;
    }

    /**
     * 把新文件覆盖上去，再按上一次的清单删掉新版本已经不要的文件。
     *
     * @return array{copied: int, skipped: string[], deleted: string[]}
     */
    public function apply(): array
    {
        $this->ensureWritable();

        $copied = 0;
        $skipped = [];
        foreach ($this->files as $rel) {
            if ($this->shouldSkip($rel, is_file($this->root . '/' . $rel))) {
                $skipped[] = $rel;
                continue;
            }

            $src = $this->stage . '/' . $rel;
            $dst = $this->root . '/' . $rel;
            $this->mkdir(dirname($dst));
            if (is_link($dst) && !@unlink($dst)) {
                throw new UpdateException('copy_failed', '换不掉符号链接：' . $rel);
            }
            if (!@copy($src, $dst)) {
                throw new UpdateException('copy_failed', '覆盖失败：' . $rel);
            }
            @chmod($dst, $rel === 'bin/nomen' ? 0755 : 0644);
            Ownership::adopt($dst);
            self::invalidate($dst);
            $copied++;
        }

        $deleted = [];
        foreach ($this->deletions() as $rel) {
            if (!is_file($this->root . '/' . $rel)) {
                continue;
            }
            if (@unlink($this->root . '/' . $rel)) {
                self::invalidate($this->root . '/' . $rel);
                $deleted[] = $rel;
            }
        }

        return ['copied' => $copied, 'skipped' => $skipped, 'deleted' => $deleted];
    }

    /** 回滚：把备份包原样铺回去（只覆盖，不删新版本多出来的文件） */
    public function rollback(): void
    {
        if ($this->backup === '' || !is_file($this->backup)) {
            throw new UpdateException('rollback_failed', '没有可用的备份包');
        }

        $zip = new ZipArchive();
        if ($zip->open($this->backup) !== true) {
            throw new UpdateException('rollback_failed', '打不开备份包');
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) {
                    continue;
                }
                $raw = (string)($stat['name'] ?? '');
                if ($raw === '' || str_starts_with($raw, '/') || str_starts_with($raw, '\\')
                    || str_contains($raw, '\\') || str_contains($raw, "\0")
                    || preg_match('/^[A-Za-z]:/', $raw) === 1) {
                    throw new UpdateException('rollback_failed', '备份包内有非法路径：' . $raw);
                }
                $rel = self::normalizeEntry($raw);
                if ($rel === '' || !self::isSafeEntry($rel) || self::isSymlink($zip, $i)) {
                    throw new UpdateException('rollback_failed', '备份包内有非法路径：' . $rel);
                }
                if (str_ends_with($rel, '/')) {
                    $this->mkdir($this->root . '/' . rtrim($rel, '/'));
                    continue;
                }
                $this->mkdir(dirname($this->root . '/' . $rel));
                $this->extractEntry($zip, $raw, $this->root . '/' . $rel, 'rollback_failed');
                @chmod($this->root . '/' . $rel, $rel === 'bin/nomen' ? 0755 : 0644);
                Ownership::adopt($this->root . '/' . $rel);
                self::invalidate($this->root . '/' . $rel);
            }
        } finally {
            $zip->close();
        }
    }

    /** 成功后记下这一版装了哪些文件，下次更新据此清理旧文件 */
    public function writeManifest(): void
    {
        $payload = json_encode([
            'version' => (string)($this->offer['version'] ?? ''),
            'built_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'files' => $this->files,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $tmp = $this->dir . '/manifest.json.tmp';
        if (@file_put_contents($tmp, (string)$payload, LOCK_EX) === false || !@rename($tmp, $this->dir . '/manifest.json')) {
            @unlink($tmp);
            throw new UpdateException('manifest_failed', '写不了更新清单');
        }
        Ownership::adopt($this->dir . '/manifest.json');
    }

    public function cleanup(bool $keepZip = false): void
    {
        self::removeTree($this->stage);
        if (!$keepZip) {
            @unlink($this->zip);
            @unlink($this->zip . '.part');
        }

        $keep = max(1, (int)(self::config()['keep_backups'] ?? 2));
        $backups = glob($this->dir . '/backup-*.zip') ?: [];
        usort($backups, static fn(string $a, string $b): int => (int)@filemtime($b) <=> (int)@filemtime($a));
        foreach (array_slice($backups, $keep) as $old) {
            @unlink($old);
        }

        // 失败时保留当前包方便排查，但旧版本的包别越攒越多
        $zips = glob($this->dir . '/nomen-*.zip') ?: [];
        usort($zips, static fn(string $a, string $b): int => (int)@filemtime($b) <=> (int)@filemtime($a));
        foreach (array_slice($zips, 2) as $old) {
            @unlink($old);
        }
    }

    /** @return string[] 新版本里不再提供、且属于核心目录的文件 */
    private function deletions(): array
    {
        if ($this->previousFiles === []) {
            return [];
        }

        $version = (string)($this->offer['version'] ?? '');
        $manifestVersion = (string)($this->previousManifest['version'] ?? '');
        if ($manifestVersion !== $this->fromVersion) {
            // 中间换过版本（手动升级等），旧清单已经不可信，宁可不删
            return [];
        }

        $new = array_fill_keys($this->files, true);
        $out = [];
        foreach ($this->previousFiles as $rel) {
            if (isset($new[$rel])) {
                continue;
            }
            if (!$this->deletable($rel)) {
                continue;
            }
            if ($version !== '' && is_file($this->root . '/' . $rel)) {
                $out[] = $rel;
            }
        }

        sort($out);

        return $out;
    }

    private function deletable(string $rel): bool
    {
        foreach (self::PROTECTED_FILES as $file) {
            if ($rel === $file) {
                return false;
            }
        }
        foreach (self::PROTECTED_DIRS as $dir) {
            if (str_starts_with($rel, $dir)) {
                return false;
            }
        }

        foreach (['src/', 'controllers/', 'templates/', 'public/', 'locales/', 'docs/', 'plugins/', 'skins/'] as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function shouldSkip(string $rel, bool $exists): bool
    {
        foreach (self::PROTECTED_FILES as $file) {
            if ($rel === $file) {
                return true;
            }
        }
        foreach (self::PROTECTED_DIRS as $dir) {
            if (str_starts_with($rel, $dir)) {
                return true;
            }
        }

        if (!$exists) {
            return false;
        }

        foreach (self::PRESERVE_IF_EXISTS as $prefix) {
            if ($rel === $prefix || str_starts_with($rel, $prefix)) {
                // config/app.php 例外：版本号和新配置键要跟着新版走
                return !($rel === 'config/app.php');
            }
        }

        return false;
    }

    /** @return string[] */
    private function readPreviousManifest(): array
    {
        $file = $this->dir . '/manifest.json';
        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data)) {
            return [];
        }

        $this->previousManifest = $data;
        $files = $data['files'] ?? [];
        if (!is_array($files)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn(mixed $v): string => self::normalizeEntry((string)$v),
            $files
        ), self::isSafeEntry(...)));
    }

    private function ensureWritable(): void
    {
        foreach ([$this->root, $this->root . '/src', $this->root . '/public', $this->root . '/config'] as $dir) {
            if (!is_dir($dir) || !is_writable($dir)) {
                throw new UpdateException('files_not_writable', $dir . ' 不可写');
            }
            if ($dir !== $this->root && is_link($dir)) {
                throw new UpdateException('files_not_writable', $dir . ' 是符号链接，自动更新不处理这种目录');
            }
        }
    }

    private function ensureDiskSpace(int $needed): void
    {
        $free = @disk_free_space($this->root);
        if ($free === false) {
            return;
        }
        if ($free < $needed) {
            throw new UpdateException(
                'disk_full',
                '磁盘空间不足：需要约 ' . self::mb($needed) . ' MB，剩余 ' . self::mb((int)$free) . ' MB'
            );
        }
    }

    /** @return string|null 64 位十六进制，拉不到返回 null */
    private function fetchChecksum(): ?string
    {
        $url = trim((string)($this->offer['download'] ?? ''));
        if ($url === '' || !function_exists('curl_init')) {
            return null;
        }
        $url = preg_replace('/[?#].*$/', '', $url) . '.sha256';

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
            CURLOPT_RANGE          => '0-1023',
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($raw) || !in_array($status, [200, 206], true)
            || preg_match('/[a-f0-9]{64}/i', $raw, $m) !== 1) {
            return null;
        }

        return strtolower($m[0]);
    }

    /** @return array<string, mixed> */
    private static function config(): array
    {
        $file = dirname(__DIR__) . '/config/app.php';
        $config = is_file($file) ? require $file : [];

        return is_array($config['updates'] ?? null) ? $config['updates'] : [];
    }

    private function maxPackageBytes(): int
    {
        $mb = max(1, (int)(self::config()['max_package_mb'] ?? 200));

        return $mb * 1024 * 1024;
    }

    private function mkdir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new UpdateException('files_not_writable', '建不了目录：' . $dir);
        }
        Ownership::adopt($dir);
    }

    private static function normalizeEntry(string $name): string
    {
        $name = str_replace('\\', '/', trim($name));
        $name = preg_replace('#/+#', '/', $name) ?? $name;
        $trailingSlash = str_ends_with($name, '/');

        $parts = [];
        foreach (explode('/', ltrim($name, '/')) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            $parts[] = $part;
        }

        return implode('/', $parts) . ($trailingSlash && $parts !== [] ? '/' : '');
    }

    /** 拒绝绝对路径、上级目录、Windows 盘符和 NUL */
    public static function isSafeEntry(string $rel): bool
    {
        if ($rel === '' || str_contains($rel, "\0") || preg_match('/^[A-Za-z]:/', $rel) === 1) {
            return false;
        }
        if (str_starts_with($rel, '/')) {
            return false;
        }
        foreach (explode('/', $rel) as $part) {
            if ($part === '..') {
                return false;
            }
        }

        return true;
    }

    private static function isSymlink(ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attr = 0;
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }
        // Unix 模式的高 16 位里 S_IFLNK = 0120000
        return $opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000;
    }

    /** 用流复制，不依赖 ZipArchive::extractTo 对条目名的处理 */
    private function extractEntry(ZipArchive $zip, string $raw, string $target, string $reason): void
    {
        $in = $zip->getStream($raw);
        if ($in === false) {
            throw new UpdateException($reason, '读不出包内文件：' . $raw);
        }

        $out = @fopen($target, 'wb');
        if ($out === false) {
            fclose($in);
            throw new UpdateException($reason, '写不了文件：' . $target);
        }

        $ok = stream_copy_to_stream($in, $out) !== false;
        fclose($in);
        fclose($out);

        if (!$ok) {
            throw new UpdateException($reason, '解包失败：' . $raw);
        }
    }

    private static function invalidate(string $file): void
    {
        if (function_exists('opcache_invalidate') && (bool)ini_get('opcache.enable')) {
            @opcache_invalidate($file, true);
        }
    }

    private static function safeName(string $version): string
    {
        return preg_replace('/[^0-9A-Za-z._+-]/', '_', $version) ?: 'unknown';
    }

    private static function mb(int $bytes): string
    {
        return number_format($bytes / 1048576, 1);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                if ($item->isDir() && !$item->isLink()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                }
            }
        } catch (Throwable $e) {
            error_log('[update] 清理临时目录失败: ' . $e->getMessage());
        }

        @rmdir($dir);
    }
}
