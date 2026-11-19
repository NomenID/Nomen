<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src;

/**
 * 自动更新可能由 root 的 crontab 触发，覆盖出来的文件会变成 root 属主，
 * 之后 www 用户改配置、写缓存就会没权限。这里按 public/index.php 的属主
 * 把新文件改回去；非 root 进程什么也不做（文件本来就是 www 建的）。
 */
final class Ownership
{
    private static int|false|null $uid = null;
    private static int|false|null $gid = null;

    public static function adopt(string $path): void
    {
        if (!function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return;
        }

        if (self::$uid === null || self::$gid === null) {
            $ref = dirname(__DIR__) . '/public/index.php';
            self::$uid = @fileowner($ref);
            self::$gid = @filegroup($ref);
        }

        if (self::$uid !== false) {
            @chown($path, self::$uid);
        }
        if (self::$gid !== false) {
            @chgrp($path, self::$gid);
        }
    }
}
