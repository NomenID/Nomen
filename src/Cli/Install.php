<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src\Cli;

use Nomen\Src\EmailPolicy;
use Nomen\Src\Installer\ConfigFile;
use Nomen\Src\Installer\Database as InstallDb;
use PDO;
use PDOException;

class Install
{
    public static function run(array $args): int
    {
        $root = dirname(__DIR__, 2);
        $force = in_array('--force', $args, true);

        echo PHP_EOL, 'Nomen 安装器', PHP_EOL, PHP_EOL;

        if (is_file($root . '/config/installed.php') && !$force) {
            return self::fail('已经装过了（config/installed.php 存在）。确认重装就加 --force。');
        }

        if (!self::checkRequirements($root)) {
            return 1;
        }

        $db = self::askDatabase();
        $pepper = bin2hex(random_bytes(32));

        try {
            $pdo = InstallDb::connect($db, ($db['driver'] ?? 'mysql') !== 'sqlite');

            if (($db['driver'] ?? 'mysql') === 'sqlite') {
                // schema 文件里是 DROP TABLE IF EXISTS，指着已有的库跑会把同名表清掉。
                $existing = InstallDb::tables($pdo);
                if ($existing && !$force) {
                    return self::fail('这个库里已经有 ' . count($existing) . ' 张表。确认要覆盖就加 --force。');
                }
            } else {
                $name = str_replace('`', '', $db['name']);
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `$name`");

                $existing = InstallDb::tables($pdo);
                if ($existing && !$force) {
                    return self::fail('这个库里已经有 ' . count($existing) . ' 张表。确认要覆盖就加 --force。');
                }
            }

            $count = InstallDb::importSchema($pdo, $pdo->dialect()->schemaFile($root));
            InstallDb::markMigrationsApplied($pdo, $root . '/database/migrations');
        } catch (PDOException $e) {
            return self::fail('数据库操作失败：' . $e->getMessage());
        }

        echo '  表结构导入完成（', $count, ' 条语句）', PHP_EOL;

        $site = self::askSite();
        $plugins = self::askPlugins();
        $admin = self::askAdmin($site['support_email']);

        try {
            InstallDb::createAdmin($pdo, $admin['username'], $admin['email'], $admin['password'], $pepper);
        } catch (PDOException $e) {
            return self::fail('创建管理员失败：' . $e->getMessage());
        }

        self::writeConfig($root . '/config/installed.php', $db, $site, $pepper);
        self::writePluginsConfig($root . '/config/plugins.php', $plugins);

        echo PHP_EOL, '装好了。', PHP_EOL, PHP_EOL;
        echo '  配置文件    config/installed.php（有数据库密码和 pepper，别提交）', PHP_EOL;
        echo '  管理员      ', $admin['username'], PHP_EOL, PHP_EOL;
        echo '还差三步：', PHP_EOL;
        echo '  1. nginx 的 root 指到 public/，参考 config/nginx.conf', PHP_EOL;
        echo '  2. cd frontend && npm install && npm run build', PHP_EOL;
        echo '  3. php scripts/mail-drain.php 常驻（通知邮件靠它发）', PHP_EOL, PHP_EOL;
        echo '网页安装器 public/nomen-install/ 用不上可以删掉；本地测试环境留着也行。', PHP_EOL;
        echo '页脚 Powered by Nomen 和 /special/version 版本页都只是建议，删掉不违反 NOTICE；', PHP_EOL;
        echo '但插件和皮肤各自的许可证可能要求保留声明，装了什么就核对什么。', PHP_EOL, PHP_EOL;

        return 0;
    }

    private static function checkRequirements(string $root): bool
    {
        $ok = true;

        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            echo '  PHP 版本太低：需要 8.1+，当前 ', PHP_VERSION, PHP_EOL;
            $ok = false;
        }

        if (!extension_loaded('pdo_mysql') && !extension_loaded('pdo_sqlite')) {
            echo '  缺少数据库扩展：pdo_mysql 和 pdo_sqlite 至少装一个', PHP_EOL;
            $ok = false;
        }

        foreach (['mbstring', 'openssl'] as $ext) {
            if (!extension_loaded($ext)) {
                echo '  缺少必需扩展：', $ext, PHP_EOL;
                $ok = false;
            }
        }

        foreach (['gd' => '验证码和缩略图', 'fileinfo' => '上传', 'redis' => '限流和多设备踢出'] as $ext => $what) {
            if (!extension_loaded($ext)) {
                echo '  可选扩展 ', $ext, ' 没装：', $what, '会降级', PHP_EOL;
            }
        }

        if (!is_writable($root . '/config')) {
            echo '  config/ 不可写，装完要自己把 installed.php 放进去', PHP_EOL;
        }

        foreach (['database/schema.sql', 'database/schema.sqlite.sql'] as $file) {
            if (!is_readable($root . '/' . $file)) {
                echo '  找不到 ', $file, PHP_EOL;
                $ok = false;
            }
        }

        return $ok;
    }

    private static function askDatabase(): array
    {
        echo PHP_EOL, '数据库', PHP_EOL;

        $driver = strtolower(self::ask('  类型（mysql / mariadb / sqlite）', 'mysql'));
        while (!in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            echo '  只认 mysql、mariadb、sqlite', PHP_EOL;
            $driver = strtolower(self::ask('  类型', 'mysql'));
        }

        if ($driver === 'sqlite') {
            return [
                'driver' => 'sqlite',
                'path' => self::ask('  数据库文件', 'database/nomen.sqlite'),
            ];
        }

        return [
            'driver' => $driver,
            'host' => self::ask('  主机', '127.0.0.1'),
            'port' => (int)self::ask('  端口', '3306'),
            'name' => self::ask('  库名', 'nomen'),
            'user' => self::ask('  用户名', 'nomen'),
            'pass' => self::askHidden('  密码'),
        ];
    }

    private static function askSite(): array
    {
        echo PHP_EOL, '站点', PHP_EOL;

        $langs = require dirname(__DIR__, 2) . '/locales/languages.php';
        $slugs = array_column($langs, 'slug');

        $language = self::ask('  默认语言（' . implode(' / ', $slugs) . '）', 'zh-hans');
        while (!in_array($language, $slugs, true)) {
            echo '  不在语言清单里，重选', PHP_EOL;
            $language = self::ask('  默认语言', 'zh-hans');
        }
        $switch = strtolower(self::ask('  允许用户切换界面语言？（y/n）', 'y'));
        $guardians = strtolower(self::ask('  启用家庭与未成年监护？（y/n）', 'n'));
        $country = strtolower(self::ask('  注册时填写国家 / 地区？（y/n）', 'n'));

        $yes = static fn(string $value): bool => !in_array($value, ['n', 'no', '0', ''], true);

        return [
            'name' => self::ask('  站点名', 'Nomen'),
            'base_url' => rtrim(self::ask('  访问地址', 'http://localhost:8080'), '/'),
            'support_email' => self::ask('  支持邮箱（显示给用户，可留空）'),
            'admin_email' => self::ask('  管理员通知邮箱（新工单提醒，可留空）'),
            'logo' => self::ask('  站点图标 URL（留空用 Nomen 图标）'),
            'language_default' => $language,
            'language_allow_switch' => $yes($switch),
            'features' => [
                'guardians' => $yes($guardians),
                'country' => $yes($country),
            ],
        ];
    }

    private static function askPlugins(): array
    {
        $available = \Nomen\Src\Plugins::all();
        if ($available === []) {
            return [];
        }

        echo PHP_EOL, '扩展', PHP_EOL;
        foreach ($available as $slug => $manifest) {
            echo '  ', $slug, ' — ', ($manifest['description'] ?? ''), PHP_EOL;
        }

        $input = self::ask('  启用哪些（逗号分隔）', 'open-platform,pages');
        $list = array_values(array_filter(array_map('trim', explode(',', $input))));
        $selected = array_values(array_intersect($list, array_keys($available)));

        foreach (array_diff($list, array_keys($available)) as $bad) {
            echo '  跳过不存在的插件：', $bad, PHP_EOL;
        }

        return $selected;
    }

    private static function writePluginsConfig(string $file, array $enabled): void
    {
        $list = implode(', ', array_map(
            static fn(string $slug): string => "'" . addslashes($slug) . "'",
            $enabled
        ));
        $content = "<?php\nreturn [\n    // plugins/ 下的目录名。装插件不用改代码，改这里就行。\n"
            . "    'enabled' => [" . $list . "],\n];\n";

        if (@file_put_contents($file, $content) === false) {
            echo '  config/plugins.php 没写进去，装完手动把 enabled 改成：', implode(', ', $enabled), PHP_EOL;
        }
    }

    private static function askAdmin(string $defaultEmail): array
    {        echo PHP_EOL, '管理员账号', PHP_EOL;

        while (true) {
            $username = self::ask('  用户名（3-32 位小写字母、数字、下划线）');
            if (preg_match('/^[a-z0-9_]{3,32}$/', $username)) {
                break;
            }
            echo '  格式不对，再试一次', PHP_EOL;
        }

        while (true) {
            $email = self::ask('  邮箱', $defaultEmail);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo '  邮箱格式不对，再试一次', PHP_EOL;
                continue;
            }
            if (EmailPolicy::isBlocked($email)) {
                echo '  ', EmailPolicy::REJECT_MESSAGE, PHP_EOL;
                continue;
            }
            break;
        }

        while (true) {
            $password = self::askHidden('  密码（至少 8 位，含字母和数字）');
            $again = self::askHidden('  再输一次');
            if ($password !== $again) {
                echo '  两次不一致，重来', PHP_EOL;
                continue;
            }
            if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
                echo '  密码太弱：至少 8 位，且要有字母和数字', PHP_EOL;
                continue;
            }
            break;
        }

        return ['username' => $username, 'email' => $email, 'password' => $password];
    }




    private static function writeConfig(string $path, array $db, array $site, string $pepper): void
    {
        $data = [
            'app' => [
                'name' => $site['name'],
                'base_url' => $site['base_url'],
                'support_email' => $site['support_email'],
                'admin_notify_email' => $site['admin_email'],
                'logo' => $site['logo'] ?? '',
                'language_default' => $site['language_default'] ?? 'zh-hans',
                'language_allow_switch' => (bool)($site['language_allow_switch'] ?? true),
                'features' => [
                    'guardians' => (bool)($site['features']['guardians'] ?? false),
                    'country' => (bool)($site['features']['country'] ?? false),
                ],
            ],
            'pepper' => $pepper,
            'secret' => bin2hex(random_bytes(32)),
            'db' => $db,
            'redis' => ['host' => '127.0.0.1', 'port' => 6379, 'db' => 1, 'password' => ''],
            'smtp' => [
                'host' => '',
                'port' => 587,
                'username' => '',
                'password' => '',
                'encryption' => 'tls',
                'from_email' => '',
                'from_name' => $site['name'],
            ],
        ];

        file_put_contents($path, ConfigFile::render($data));
        @chmod($path, 0640);

        // 用 root 跑安装器时，配置文件按目录属主交给 web 用户，否则 PHP-FPM 读不到
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $owner = fileowner(dirname($path));
            $group = filegroup(dirname($path));
            if ($owner !== false) {
                @chown($path, $owner);
            }
            if ($group !== false) {
                @chgrp($path, $group);
            }
        }
    }

    private static function ask(string $label, string $default = ''): string
    {
        echo $label, $default !== '' ? " [$default]" : '', ': ';
        $value = self::readLine();

        return $value === '' ? $default : $value;
    }

    private static function askHidden(string $label): string
    {
        echo $label, ': ';

        // Windows 没有 stty，密码会明着敲；Linux 上尽量关掉回显。
        $canHide = PHP_OS_FAMILY !== 'Windows' && function_exists('shell_exec');
        $restore = $canHide ? @shell_exec('stty -g 2>/dev/null') : null;
        if ($restore) {
            @shell_exec('stty -echo 2>/dev/null');
        }

        $value = self::readLine();

        if ($restore) {
            @shell_exec('stty ' . escapeshellarg(trim($restore)) . ' 2>/dev/null');
            echo PHP_EOL;
        }

        return $value;
    }

    private static function readLine(): string
    {
        $line = fgets(STDIN);
        if ($line === false) {
            self::fail('没读到输入，安装中止。');
            exit(1);
        }

        return trim($line, " \t\r\n");
    }

    private static function fail(string $message): int
    {
        fwrite(STDERR, PHP_EOL . '  ' . $message . PHP_EOL);

        return 1;
    }
}
