<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

/*
 * 权限体系默认配置（学 MediaWiki 的 $wgGroupPermissions）。
 *
 * 这里定义「有哪些组、每个组默认有哪些能力」。能力（ability）由核心和插件
 * 在代码里注册，站长在这里决定把它们分给哪些组。
 *
 * 三条规矩：
 * 1. 一个用户可以同时在多个组，能力取所有组的并集（MediaWiki 式叠加）。
 * 2. 同一能力在多个组里冲突时：任一组写了 deny，一律拒绝——deny 盖过一切 allow。
 * 3. 后台「权限」页写进数据库的规则会覆盖这里的默认值；删掉后台规则就回落到这里。
 *
 * 特殊组：
 * - '*'     隐式组，所有请求者都在内（含未登录）。别往这里放敏感能力。
 * - 'user'  隐式组，所有已登录且状态正常的账号自动在内。不可手动指派。
 *
 * abilities 里可以写：
 * - 具体能力名，如 'users.manage'
 * - 前缀通配，如 'users.*'（匹配该前缀下所有能力）
 * - '*'（该组拥有全部能力，包括插件将来注册的）
 *
 * 站长想加组就在 groups 里照抄一段；想把某个能力收回来，写 '组名' => [.., '能力名' => false]。
 * 升级不会覆盖这个文件；核心新增的内置能力默认只给 administrator 组（它写的是 '*'）。
 */
return [
    'groups' => [
        '*' => [
            'label' => 'Everyone',          // 所有请求者，含未登录
            // 隐式组：不出现在后台网格，也不可指派
            'implicit' => true,
            'abilities' => [],
        ],

        'user' => [
            'label' => 'Signed-in users',   // 已登录且状态正常的账号
            'implicit' => true,
            'abilities' => [],
        ],

        'administrator' => [
            'label' => 'Administrators',    // 管理员组，旧版的 users.role = 'admin'
            // 全部能力。等价于旧版 users.role = 'admin' 的一切权限。
            'abilities' => ['*'],
        ],

        // 示例：建一个只能看用户和日志的客服组（要启用就取消注释，
        // 再用后台用户详情页或 `php bin/nomen group:assign` 把人加进来）
        /*
        'support' => [
            'label' => 'Support',
            'abilities' => [
                'users.manage',
                'logs.view',
            ],
        ],
        */
    ],
];
