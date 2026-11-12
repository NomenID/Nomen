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
 * 命令行管账号：管理员被锁在门外、或者要批量扶正一个管理员时用。
 */
class User
{
    public static function run(array $args): int
    {
        $action = $args[0] ?? '';
        $account = trim((string)($args[1] ?? ''));

        if ($account === '') {
            fwrite(STDERR, '用法：php bin/nomen user:<passwd|promote|demote> <用户名或邮箱>' . PHP_EOL);
            return 1;
        }

        $config = require dirname(__DIR__, 2) . '/config/app.php';

        try {
            $db = Database::connect($config['db']);
        } catch (\Throwable $e) {
            fwrite(STDERR, '连不上数据库：' . $e->getMessage() . PHP_EOL);
            return 1;
        }

        $user = self::find($db, $account);
        if ($user === null) {
            fwrite(STDERR, '找不到账号：' . $account . PHP_EOL);
            return 1;
        }

        switch ($action) {
            case 'passwd':
                return self::passwd($db, $user, (string)($config['pepper'] ?? ''));
            case 'promote':
                return self::setRole($db, $user, 'admin');
            case 'demote':
                return self::setRole($db, $user, 'user');
            default:
                fwrite(STDERR, '未知动作：' . $action . PHP_EOL);
                return 1;
        }
    }

    private static function passwd(PDO $db, array $user, string $pepper): int
    {
        echo '给 ', $user['username'], ' 设置新密码。', PHP_EOL;

        $password = self::askHidden('  新密码（至少 8 位，含字母和数字）');
        if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            fwrite(STDERR, '  密码太简单：至少 8 位，且要同时有字母和数字。' . PHP_EOL);
            return 1;
        }

        if (self::askHidden('  再输一次') !== $password) {
            fwrite(STDERR, '  两次不一致，什么都没改。' . PHP_EOL);
            return 1;
        }

        $db->prepare('UPDATE users SET password_hash = :p WHERE id = :id')
            ->execute([':p' => password_hash($password . $pepper, PASSWORD_ARGON2ID), ':id' => $user['id']]);

        // 密码换了，之前发出去的令牌跟着失效，免得旧会话还能继续用
        $db->prepare('UPDATE oauth_access_tokens SET revoked = 1 WHERE user_id = :u')->execute([':u' => $user['id']]);
        $db->prepare('UPDATE oauth_refresh_tokens SET revoked = 1 WHERE user_id = :u')->execute([':u' => $user['id']]);

        echo '  已更新，该账号的 OAuth 令牌已撤销。', PHP_EOL;
        return 0;
    }

    private static function setRole(PDO $db, array $user, string $role): int
    {
        if ($user['role'] === $role) {
            echo $user['username'], ' 已经是 ', $role, '。', PHP_EOL;
            return 0;
        }

        $db->prepare('UPDATE users SET role = :r WHERE id = :id')
            ->execute([':r' => $role, ':id' => $user['id']]);

        echo '  已把 ', $user['username'], ' 设为 ', $role, '。', PHP_EOL;
        return 0;
    }

    private static function find(PDO $db, string $account): ?array
    {
        $stmt = $db->prepare(
            'SELECT id, username, role FROM users WHERE LOWER(username) = LOWER(:a) OR LOWER(email) = LOWER(:a) LIMIT 1'
        );
        $stmt->execute([':a' => $account]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private static function askHidden(string $label): string
    {
        echo $label, ': ';

        $canHide = PHP_OS_FAMILY !== 'Windows' && function_exists('shell_exec');
        $restore = $canHide ? @shell_exec('stty -g 2>/dev/null') : null;
        if ($restore) {
            @shell_exec('stty -echo 2>/dev/null');
        }

        $value = fgets(STDIN);
        if ($value === false) {
            $value = '';
        }

        if ($restore) {
            @shell_exec('stty ' . escapeshellarg(trim($restore)) . ' 2>/dev/null');
            echo PHP_EOL;
        }

        return trim($value, " \t\r\n");
    }
}