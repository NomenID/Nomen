<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

// 密码哈希算法与参数。改动只影响以后写入的新哈希；已有哈希在用户下次登录时按 upgrade 自动改写。
// 换算法不会让老用户登不上：验证按库里那条哈希自己的算法走。
return [
    // auto（默认，有 Argon2id 且内存够就用它）/ argon2id / bcrypt
    'algorithm' => 'auto',

    // 传给 password_hash 的选项；留空用 PHP 默认（argon2id 64 MiB / 4 次 / 1 线程，bcrypt cost 12）
    'options'   => [],

    // on_login（默认，登录成功时把老算法 / 老参数的哈希就地改写）/ off（不改写，等用户自己改密码）
    'upgrade'   => 'on_login',
];
