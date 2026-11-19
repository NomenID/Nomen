<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

use Nomen\Src\SiteHealth;

test('站点健康总体状态取最严重的一条', static function (): void {
    assertSame('good', SiteHealth::worstStatus(['good', 'good']));
    assertSame('recommended', SiteHealth::worstStatus(['good', 'recommended', 'good']));
    assertSame('critical', SiteHealth::worstStatus(['recommended', 'critical', 'good']));
    assertSame('good', SiteHealth::worstStatus([]));
    assertSame('recommended', SiteHealth::worstStatus(['unknown', 'recommended']));
});
