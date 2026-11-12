<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src\Cli;

use Nomen\Src\Database;
use PDO;

/**
 * 备份 / 恢复数据库。搬家、升级前、手滑之前各跑一次。
 * 只处理数据库本身；config/、上传的头像和证明材料这些文件要另外拷。
 */
class Backup
{
    public static function run(array $args): int
    {
        $target = trim((string)($args[0] ?? ''));

        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $db = $config['db'];
        $driver = strtolower((string)$db['driver']);

        $dir = self::resolveDir($target !== '' ? $target : dirname(__DIR__, 2) . '/data/backups');
        if ($dir === null) {
            return 1;
        }

        $method = $driver === 'sqlite' ? 'sqlite' : 'mysql';
        $file = $dir . '/nomen-' . date('Ymd-His') . '.' . ($method === 'sqlite' ? 'sqlite' : 'sql');

        $code = $method === 'sqlite'
            ? self::dumpSqlite($db, $file)
            : self::dumpMysql($db, $file);

        if ($code === 0) {
            echo '备份完成：', $file, '（', self::humanSize((int)@filesize($file)), '）', PHP_EOL;
            echo '这个文件里有全站账号和密码哈希，别放进 web 目录，也别传给别人。', PHP_EOL;
        }

        return $code;
    }

    public static function restore(array $args): int
    {
        $source = trim((string)($args[0] ?? ''));
        $assumeYes = in_array('--yes', $args, true) || in_array('-y', $args, true);

        if ($source === '' || !is_file($source)) {
            fwrite(STDERR, '用法：php bin/nomen restore <备份文件> [--yes]' . PHP_EOL);
            return 1;
        }

        $config = require dirname(__DIR__, 2) . '/config/app.php';
        $db = $config['db'];
        $driver = strtolower((string)$db['driver']);
        $isSqlite = $driver === 'sqlite';

        $expected = $isSqlite ? '.sqlite' : '.sql';
        if (!str_ends_with($source, $expected)) {
            fwrite(STDERR, '这个文件看起来不是' . ($isSqlite ? ' SQLite ' : ' MySQL ') . '备份（应以 ' . $expected . ' 结尾）。' . PHP_EOL);
            return 1;
        }

        echo '要恢复的是：', $source, PHP_EOL;
        echo '会覆盖当前数据库', $isSqlite ? '文件' : '（' . $db['name'] . '）', '，先自动留一份现在的。', PHP_EOL;

        if (!$assumeYes) {
            echo '确认请输入 yes：';
            if (trim((string)fgets(STDIN)) !== 'yes') {
                echo '什么都没做。', PHP_EOL;
                return 1;
            }
        }

        // 先把现状留一份，恢复错了还能退回来
        $dir = self::resolveDir(dirname(__DIR__, 2) . '/data/backups');
        if ($dir === null) {
            return 1;
        }

        $safety = $dir . '/pre-restore-' . date('Ymd-His') . $expected;
        echo '先备份当前数据库……', PHP_EOL;
        $code = $isSqlite ? self::dumpSqlite($db, $safety) : self::dumpMysql($db, $safety);
        if ($code !== 0) {
            fwrite(STDERR, '当前数据库没能备份成功，恢复中止。' . PHP_EOL);
            return 1;
        }
        echo '  已存到 ', $safety, PHP_EOL;

        return $isSqlite
            ? self::restoreSqlite($db, $source)
            : self::restoreMysql($db, $source);
    }

    private static function dumpSqlite(array $db, string $file): int
    {
        try {
            $pdo = Database::connect($db);
            // VACUUM INTO 生成的是一个一致快照，直接拷 .sqlite 文件在 WAL 模式下会漏数据
            $pdo->prepare('VACUUM INTO :f')->execute([':f' => $file]);
        } catch (\Throwable $e) {
            fwrite(STDERR, '  SQLite 备份失败：' . $e->getMessage() . PHP_EOL);
            return 1;
        }

        return 0;
    }

    private static function dumpMysql(array $db, string $file): int
    {
        $bin = self::findBinary('mysqldump');
        if ($bin === null) {
            fwrite(STDERR, '  找不到 mysqldump。装上 mysql-client，或改用 SQLite 部署。' . PHP_EOL);
            return 1;
        }

        $defaults = self::writeDefaultsFile($db);
        if ($defaults === null) {
            return 1;
        }

        $cmd = [
            $bin,
            '--defaults-extra-file=' . $defaults,
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--default-character-set=utf8mb4',
            '--no-tablespaces',
            (string)$db['name'],
        ];

        try {
            $result = self::exec($cmd, $file, null);
        } finally {
            @unlink($defaults);
        }

        if ($result['code'] !== 0) {
            fwrite(STDERR, '  mysqldump 失败：' . trim($result['stderr']) . PHP_EOL);
            @unlink($file);
            return 1;
        }

        return 0;
    }

    private static function restoreSqlite(array $db, string $source): int
    {
        $path = Database::sqlitePath((string)$db['path']);
        $base = preg_replace('/(\.sqlite)$/', '', $path) ?: $path;

        // WAL/SHM 是旧库的旁支文件，留着会和恢复回来的主文件对不上
        foreach ([$path . '-wal', $path . '-shm', $base . '-wal', $base . '-shm'] as $sidecar) {
            if (is_file($sidecar)) {
                @unlink($sidecar);
            }
        }

        if (!@copy($source, $path)) {
            fwrite(STDERR, '  写不进 ' . $path . '：检查目录权限。' . PHP_EOL);
            return 1;
        }

        echo '已恢复到 ', $path, PHP_EOL;
        return 0;
    }

    private static function restoreMysql(array $db, string $source): int
    {
        $bin = self::findBinary('mysql');
        if ($bin === null) {
            fwrite(STDERR, '  找不到 mysql 客户端。' . PHP_EOL);
            return 1;
        }

        $defaults = self::writeDefaultsFile($db);
        if ($defaults === null) {
            return 1;
        }

        $cmd = [
            $bin,
            '--defaults-extra-file=' . $defaults,
            '--default-character-set=utf8mb4',
            (string)$db['name'],
        ];

        try {
            $result = self::exec($cmd, null, $source);
        } finally {
            @unlink($defaults);
        }

        if ($result['code'] !== 0) {
            fwrite(STDERR, '  mysql 导入失败：' . trim($result['stderr']) . PHP_EOL);
            return 1;
        }

        echo '已导入 ', $source, PHP_EOL;
        return 0;
    }

    /** 密码走临时选项文件，不出现在命令行上（ps 能看到 argv）。 */
    private static function writeDefaultsFile(array $db): ?string
    {
        $file = tempnam(sys_get_temp_dir(), 'nomen-db-');
        if ($file === false) {
            fwrite(STDERR, '  建不了临时文件。' . PHP_EOL);
            return null;
        }

        $lines = [
            '[client]',
            'user=' . $db['user'],
            'password=' . $db['pass'],
            'host=' . $db['host'],
            'port=' . $db['port'],
            'default-character-set=utf8mb4',
        ];

        file_put_contents($file, implode(PHP_EOL, $lines) . PHP_EOL);
        @chmod($file, 0600);

        return $file;
    }

    /**
     * 跑一个外部命令，可选把 stdout 写进文件、stdin 从文件读。
     * 数组形式的 cmd 不经过 shell，路径带空格也不用引号。
     */
    private static function exec(array $cmd, ?string $stdoutFile, ?string $stdinFile): array
    {
        $stdout = $stdoutFile !== null ? ['file', $stdoutFile, 'wb'] : ['pipe', 'w'];
        $stdin = $stdinFile !== null ? ['file', $stdinFile, 'rb'] : ['pipe', 'r'];

        $proc = proc_open($cmd, [0 => $stdin, 1 => $stdout, 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['code' => 1, 'stderr' => '启动不了外部命令'];
        }

        if ($stdinFile === null) {
            fclose($pipes[0]);
        }
        if ($stdoutFile === null) {
            fclose($pipes[1]);
        }

        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return ['code' => proc_close($proc), 'stderr' => $stderr];
    }

    private static function findBinary(string $name): ?string
    {
        $path = getenv('PATH') ?: '';
        $exts = PHP_OS_FAMILY === 'Windows' ? ['.exe', '.cmd', '.bat', ''] : [''];

        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            if ($dir === '') {
                continue;
            }
            foreach ($exts as $ext) {
                $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name . $ext;
                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private static function resolveDir(string $dir): ?string
    {
        $dir = rtrim($dir, '/\\');

        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            fwrite(STDERR, '建不了目录：' . $dir . PHP_EOL);
            return null;
        }

        if (!is_writable($dir)) {
            fwrite(STDERR, '目录不可写：' . $dir . PHP_EOL);
            return null;
        }

        return $dir;
    }

    private static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }
}
