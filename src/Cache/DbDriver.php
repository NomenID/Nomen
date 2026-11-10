<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src\Cache;

use Nomen\Src\Database;
use PDO;

class DbDriver implements Driver
{
    private ?PDO $db = null;

    public function __construct()
    {
        // 表不存在（还没跑升级）就在这里抛出来，由 Cache 回退到文件
        $this->db()->query('SELECT 1 FROM cache_items LIMIT 1');
    }

    public function get(string $key): mixed
    {
        $stmt = $this->db()->prepare('SELECT v, expires_at FROM cache_items WHERE k = :k LIMIT 1');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        if ((int)$row['expires_at'] < time()) {
            $this->forget($key);
            return null;
        }

        $payload = @unserialize((string)$row['v'], ['allowed_classes' => false]);

        return is_array($payload) ? ($payload['value'] ?? null) : null;
    }

    public function put(string $key, mixed $value, int $ttl): void
    {
        $db = $this->db();
        $stmt = $db->prepare(
            'INSERT INTO cache_items (k, v, expires_at) VALUES (:k, :v, :e) '
            . Database::dialectFor($db)->upsert(['k'], 'v = :v2, expires_at = :e2')
        );
        $expires = time() + max(1, $ttl);
        $stmt->execute([
            ':k'  => $key,
            ':v'  => serialize(['value' => $value]),
            ':e'  => $expires,
            ':v2' => serialize(['value' => $value]),
            ':e2' => $expires,
        ]);

        // 过期行别一直堆着：偶尔清一次，不建定时任务
        if (random_int(1, 50) === 1) {
            $db->prepare('DELETE FROM cache_items WHERE expires_at < :now')->execute([':now' => time()]);
        }
    }

    public function forget(string $key): void
    {
        $this->db()->prepare('DELETE FROM cache_items WHERE k = :k')->execute([':k' => $key]);
    }

    private function db(): PDO
    {
        return $this->db ??= Database::getInstance();
    }
}
