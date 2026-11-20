<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src\Cli;

use Nomen\Src\Cache;
use Nomen\Src\Maintenance;
use Nomen\Src\PackageRules;
use Nomen\Src\Settings;
use Nomen\Src\UpdateException;
use Nomen\Src\UpdatePackage;

/**
 * 手工部署：把一个本地打包好的 zip 装到当前站点上。
 *
 * 和自动更新走同一套 UpdatePackage：同一份「哪些文件不能碰」的规则、
 * 同一份预演结果、同一个备份和回滚。手工部署出事故，都是因为绕过了它、
 * 改用 `rsync --delete` 加一份手抄的排除清单。
 */
class Deploy
{
    public static function run(array $args): int
    {
        if (($args[0] ?? '') === 'exclude') {
            return self::exclude();
        }

        return self::apply($args);
    }

    /**
     * 打印 rsync 参数。
     *
     * 给坚持用 rsync 的场景兜底：规则从 PackageRules 读，不用手抄，
     * 也就不会和自动更新的规则漂移。
     */
    private static function exclude(): int
    {
        $rules = PackageRules::rsyncRules();

        echo '站点私有、部署时不能覆盖的路径（规则来自 src/PackageRules.php）：', PHP_EOL, PHP_EOL;
        foreach (PackageRules::PROTECTED_FILES as $file) {
            echo '  ', $file, PHP_EOL;
        }
        foreach (PackageRules::PROTECTED_DIRS as $dir) {
            echo '  ', $dir, PHP_EOL;
        }
        foreach (PackageRules::PRESERVE_IF_EXISTS as $prefix) {
            echo '  ', $prefix, '（已存在的保留，缺的补上）', PHP_EOL;
        }

        echo PHP_EOL, 'rsync 直接这样写：', PHP_EOL, PHP_EOL;
        echo '  rsync -a -c ', PackageRules::rsyncArgs(), ' <源>/ <站点根>/', PHP_EOL;
        echo PHP_EOL, 'include 必须排在 exclude 前面：rsync 是先匹配先生效。', PHP_EOL;
        echo 'config/ 只放行 ', PackageRules::CONFIG_TRACKS_RELEASE, '（版本号和新配置键靠它下发），其余全归站点。', PHP_EOL;
        echo PHP_EOL, '更好的做法是不用 rsync：', PHP_EOL;
        echo '  php bin/nomen deploy <包.zip> --dry-run   先看会动哪些文件', PHP_EOL;
        echo '  php bin/nomen deploy <包.zip>             确认后装上去', PHP_EOL;

        return 0;
    }

    private static function apply(array $args): int
    {
        $root = dirname(__DIR__, 2);
        $zip = '';
        $dryRun = false;
        $yes = false;
        $wantSha = '';
        $withBackup = true;
        $withMigrate = true;

        foreach ($args as $arg) {
            if ($arg === '--dry-run') {
                $dryRun = true;
            } elseif ($arg === '--yes' || $arg === '-y') {
                $yes = true;
            } elseif ($arg === '--no-backup') {
                $withBackup = false;
            } elseif ($arg === '--no-migrate') {
                $withMigrate = false;
            } elseif (str_starts_with($arg, '--sha256=')) {
                $wantSha = strtolower(trim(substr($arg, 9)));
            } elseif (str_starts_with($arg, '-')) {
                fwrite(STDERR, '未知参数：' . $arg . PHP_EOL);
                return 1;
            } else {
                $zip = $arg;
            }
        }

        if ($zip === '' || !is_file($zip)) {
            return self::help();
        }

        $version = self::packageVersion($zip);
        if ($version === '') {
            fwrite(STDERR, '这个包没有 .nomen-package.json，或者里面没有版本号：' . $zip . PHP_EOL);
            fwrite(STDERR, '不认来源不明的包。' . PHP_EOL);
            return 1;
        }

        $current = self::currentVersion($root);
        $sha = $wantSha !== '' ? $wantSha : (string)hash_file('sha256', $zip);

        echo '包：', basename($zip), PHP_EOL;
        echo '目标版本：', $version, '（当前 ', $current, '）', PHP_EOL;
        echo 'SHA-256：', $sha, $wantSha === '' ? '（本地实算，只保证包完整）' : '（按参数校验来源）', PHP_EOL;

        // UpdatePackage 只认 data/updates/ 下的固定文件名，先把包放过去
        $dir = $root . '/data/updates';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            fwrite(STDERR, '建不了 ' . $dir . PHP_EOL);
            return 1;
        }
        $local = $dir . '/' . UpdatePackage::zipName($version);
        $sameZip = realpath($zip) === realpath($local);
        if (!$sameZip && !@copy($zip, $local)) {
            fwrite(STDERR, '复制包到 ' . $local . ' 失败' . PHP_EOL);
            return 1;
        }

        $package = new UpdatePackage($root, ['version' => $version, 'sha256' => $sha], $current);
        $started = false;

        try {
            echo PHP_EOL, '解包…', PHP_EOL;
            $package->prepare();
            $package->verify();
            $package->extract();
            $package->validate();

            $plan = $package->plan();

            echo PHP_EOL, '会覆盖 ', count($plan['copy']), ' 个文件，跳过 ', count($plan['skip']),
                ' 个站点文件，删除 ', count($plan['delete']), ' 个旧文件。', PHP_EOL;

            if ($plan['skip'] !== []) {
                echo PHP_EOL, '不动的（站点自己的东西）：', PHP_EOL;
                foreach (array_slice($plan['skip'], 0, 12) as $rel) {
                    echo '  ', $rel, PHP_EOL;
                }
                if (count($plan['skip']) > 12) {
                    echo '  …… 还有 ', count($plan['skip']) - 12, ' 个', PHP_EOL;
                }
            }

            if ($plan['delete'] !== []) {
                echo PHP_EOL, '要删的旧文件：', PHP_EOL;
                foreach ($plan['delete'] as $rel) {
                    echo '  ', $rel, PHP_EOL;
                }
            }

            if ($dryRun) {
                echo PHP_EOL, '这是预演，什么都没动。', PHP_EOL;
                // 包是从别处复制过来的就删掉，别在 data/updates 里留垃圾
                $package->cleanup($sameZip);
                return 0;
            }

            if (!$yes) {
                echo PHP_EOL, '确认覆盖这些文件？输入 yes 继续：';
                $line = trim((string)fgets(STDIN));
                if ($line !== 'yes') {
                    echo '已取消，什么都没动。', PHP_EOL;
                    $package->cleanup($sameZip);
                    return 1;
                }
            }

            if ($withBackup) {
                echo PHP_EOL, '备份当前文件…', PHP_EOL;
                $package->backup();
                echo '  备份包：', $package->backupPath(), PHP_EOL;
            } else {
                echo PHP_EOL, '跳过文件备份（--no-backup）。出问题只能靠 git 或上一版包。', PHP_EOL;
            }

            $started = true;
            Maintenance::start(900, 'Manual deploy to ' . $version);

            echo '覆盖…', PHP_EOL;
            $applied = $package->apply();
            echo '  覆盖 ', $applied['copied'], ' 个，跳过 ', count($applied['skipped']),
                ' 个，删除 ', count($applied['deleted']), ' 个', PHP_EOL;

            if ($withMigrate) {
                echo '数据库迁移…', PHP_EOL;
                $code = Upgrade::run([]);
                if ($code !== 0) {
                    throw new UpdateException('migrate_failed', '迁移失败，站点停在维护模式，按下面的备份包回滚');
                }
            } else {
                echo '跳过数据库迁移（--no-migrate）。记得稍后跑 php bin/nomen upgrade。', PHP_EOL;
            }

            Cache::flush();
            Settings::forgetCache();
            Maintenance::stop();

            if ($withBackup) {
                $package->writeManifest();
            }

            $package->cleanup();

            echo PHP_EOL, '完成。站点版本：', self::currentVersion($root), PHP_EOL;
            if ($withBackup) {
                echo '回滚：php bin/nomen rollback ', $package->backupPath(), PHP_EOL;
            }

            return 0;
        } catch (UpdateException $e) {
            if ($started) {
                Maintenance::stop();
                echo PHP_EOL, '中途失败：', $e->getMessage(), PHP_EOL;
                echo '文件可能只覆盖了一半。回滚包在 data/updates/ 下（刚才打印的路径），', PHP_EOL;
                echo '或用 /tmp 里的整站备份。', PHP_EOL;
            } else {
                echo PHP_EOL, '准备阶段失败：', $e->getMessage(), PHP_EOL;
            }
            $package->cleanup(true);

            return 1;
        }
    }

    /** 包内 .nomen-package.json 的 version；读不到就返回空串 */
    private static function packageVersion(string $zip): string
    {
        $archive = new \ZipArchive();
        if ($archive->open($zip) !== true) {
            return '';
        }

        try {
            $raw = $archive->getFromName('.nomen-package.json');
            if (!is_string($raw) || $raw === '') {
                return '';
            }
            $data = json_decode($raw, true);

            return is_array($data) ? trim((string)($data['version'] ?? '')) : '';
        } finally {
            $archive->close();
        }
    }

    private static function currentVersion(string $root): string
    {
        $config = require $root . '/config/app.php';

        return (string)($config['version'] ?? '');
    }

    private static function help(): int
    {
        echo '把一个本地包装到当前站点（和自动更新同一套规则、同一份预演）。', PHP_EOL, PHP_EOL;
        echo '  php bin/nomen deploy <包.zip> --dry-run   只列会动哪些文件', PHP_EOL;
        echo '  php bin/nomen deploy <包.zip>             确认后装上去', PHP_EOL;
        echo '  php bin/nomen deploy:exclude              打印 rsync 的排除参数', PHP_EOL, PHP_EOL;
        echo '选项：', PHP_EOL;
        echo '  --dry-run       预演，不写任何文件', PHP_EOL;
        echo '  --yes, -y       不询问，直接执行（给脚本用）', PHP_EOL;
        echo '  --sha256=<值>   按给定的哈希校验来源；不给就本地实算', PHP_EOL;
        echo '  --no-backup     跳过文件备份（不推荐）', PHP_EOL;
        echo '  --no-migrate    不跑数据库迁移（不推荐）', PHP_EOL;

        return 1;
    }
}
