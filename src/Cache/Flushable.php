<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src\Cache;

/**
 * 能一次性清空本站缓存的后端。
 *
 * 只删本站前缀下的键，别碰同一个库 / 同一个实例里的其他程序。
 */
interface Flushable
{
    /** @return int 实际删掉的条目数 */
    public function flush(): int;
}
