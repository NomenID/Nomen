<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

/**
 * 自动更新配置。这个文件是站点自己的，自动更新不会覆盖已存在的版本。
 *
 * ⚠️ 有特殊需求（额外目录、特殊备份、非标准部署）时：
 *    1. 先在后台关掉自动更新；
 *    2. 改这里的配置；
 *    3. 跑 `php bin/nomen update check` 确认能读到新版本；
 *    4. 再决定要不要重新打开，或者改更新流程。
 *
 * feed 留空 = 这个站点完全不检查更新。更新源可以指向官方站，也可以指向
 * 任何同格式的静态 JSON（见 docs/ops/auto-update.md）。
 */
return [
    // 发布源；空 = 关闭更新检查
    'feed' => getenv('NOMEN_UPDATE_FEED') ?: '',

    // 发布包所在目录；feed 没给 download 时用它拼 nomen-<版本>.zip
    'download_base' => getenv('NOMEN_UPDATE_DOWNLOAD_BASE') ?: 'https://dl.caellab.com/file/nomen/',

    // 新版本要求的最低 PHP；低于它不自动更新
    'min_php' => '8.1',

    // 允许自动更新的范围：patch = 只更最后一位（默认）；minor = 第二位也允许；major = 都允许。
    // 可以用环境变量 NOMEN_UPDATE_SCOPE 覆盖。
    'scope' => getenv('NOMEN_UPDATE_SCOPE') ?: 'patch',

    // 两次检查之间的最短间隔（秒）；check_at 非空时改按每天固定时间检查
    'interval' => 43200,

    // 每天固定检查时间（HH:MM）；留空则按 interval
    'check_at' => '',

    // 维护窗口（HH:MM-HH:MM）；普通更新只在窗口内安装，security/critical 会绕过。
    // 支持跨午夜，例如 23:00-02:00。留空 = 不限时间。
    'window' => '',

    // 低于这个优先级只提示、不自动装：security > critical > normal > feature
    'min_priority' => 'normal',

    // 通知邮箱；留空用后台设置，再退回 admin_notify_email / 最早的管理员账号
    'notify_email' => '',

    // 备份：任何一步备份失败或超出上限，更新直接中止，不动站点文件。
    'backup' => [
        // 更新前用 php bin/nomen backup 的逻辑导出数据库（SQLite 用 VACUUM INTO）。
        // 关掉之前先确认你有外部数据库备份；这里关了自动更新就不会再留数据库快照。
        'database' => true,

        // 数据库导出超时（秒）；超时就中止更新
        'timeout' => 300,

        // 头像 / 证明材料 / 开发者图标也打进文件备份；站点很大时可关掉并自行备份
        'uploads' => true,

        // 单次文件备份的体积上限（MB）；超了就中止，避免把磁盘写满
        'max_mb' => 512,

        // 保留最近几份文件备份
        'keep' => 5,

        // 额外要备份的路径（相对站点根），文件或目录都行
        'extra' => [],

        // 备份时排除的路径前缀（相对站点根）
        'exclude' => [],
    ],

    // 永远不覆盖 / 不删除的额外路径（相对站点根）；目录以 / 结尾
    'protect' => [],

    // 更新包体积上限（MB）
    'max_package_mb' => 200,

    // 更新前要求的最小剩余空间（MB）
    'min_free_mb' => 100,

    // 维护页最多挂多久（秒）；进程中途被杀也会自动过期
    'maintenance_ttl' => 900,

    // 更新锁 / 中断判定的超时（秒）
    'lock_ttl' => 900,
];
