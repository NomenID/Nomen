<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

/**
 * 不接受哪些域名下的邮箱。
 *
 * 拦下来的地址一律回同一句文案（EmailPolicy::REJECT_MESSAGE），
 * 别按域名分几句报错 —— 那等于告诉对方「只差一个域名就过了」。
 */
return [
    // 保留域（RFC 2606）：DNS 上永远收不到信，只可能是编的或者拿来占位的
    'reserved_domains' => [
        'example.com',
        'example.net',
        'example.org',
        'example.edu',
    ],

    // 保留后缀：整段顶级域 / 特殊用途后缀（RFC 2606 / 6761 / 7686）
    'reserved_tlds' => [
        'test',
        'example',
        'invalid',
        'localhost',
        'local',
        'onion',
    ],

    // 以后要额外拦的域名往这里加，文案还是那一句
    'blocked_domains' => [],
];
