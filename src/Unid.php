<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src;

use PDO;

/**
 * 对外编号 UNID：26 位 ULID，按时间有序、不可枚举。
 *
 * 主键仍然是自增（外键和插件契约都不动），UNID 只出现在地址、详情页和导出里。
 */
class Unid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** 需要对外编号的表；回填也只碰这几张 */
    public const TABLES = ['users', 'activity_logs', 'account_recovery_requests', 'family_groups'];

    public static function generate(): string
    {
        $ms = (int)floor(microtime(true) * 1000);

        $out = '';
        for ($i = 9; $i >= 0; $i--) {
            $out .= self::ALPHABET[($ms >> ($i * 5)) & 31];
        }

        $bytes = random_bytes(10);
        for ($i = 0; $i < 16; $i++) {
            $bit = $i * 5;
            $index = $bit >> 3;
            $offset = $bit & 7;
            $value = ord($bytes[$index]) >> $offset;
            if ($offset > 3) {
                $value |= ord($bytes[$index + 1]) << (8 - $offset);
            }
            $out .= self::ALPHABET[$value & 31];
        }

        return $out;
    }

    public static function isValid(string $value): bool
    {
        return (bool)preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $value);
    }

    /**
     * 老库升级时补号：迁移只加列，值在这里生成。
     *
     * @return int 补了多少行
     */
    public static function backfill(PDO $pdo): int
    {
        $filled = 0;

        foreach (self::TABLES as $table) {
            try {
                $select = $pdo->prepare("SELECT id FROM {$table} WHERE unid IS NULL OR unid = '' ORDER BY id LIMIT 500");
                $update = $pdo->prepare("UPDATE {$table} SET unid = ? WHERE id = ?");

                while (true) {
                    $select->execute();
                    $ids = $select->fetchAll(PDO::FETCH_COLUMN);
                    if (!$ids) {
                        break;
                    }

                    foreach ($ids as $id) {
                        $update->execute([self::generate(), $id]);
                        $filled++;
                    }
                }
            } catch (\Throwable $e) {
                // 还没跑加列迁移的老库直接跳过；迁移之后下次 upgrade 会补上
                error_log('[Unid] ' . $table . ' 回填失败: ' . $e->getMessage());
            }
        }

        return $filled;
    }
}
