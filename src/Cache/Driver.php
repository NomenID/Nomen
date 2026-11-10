<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src\Cache;

interface Driver
{
    /** 命中返回值，未命中返回 null */
    public function get(string $key): mixed;

    public function put(string $key, mixed $value, int $ttl): void;

    public function forget(string $key): void;
}
