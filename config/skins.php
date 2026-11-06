<?php
return [
    // 站点默认皮肤（环境变量 DEFAULT_SKIN 优先）
    'default' => getenv('DEFAULT_SKIN') ?: 'nomen-2026',

    // 站点启用的皮肤。站长想禁用某个，从列表里删掉即可；整个键不写表示不限制。
    'enabled' => ['nomen-2026', 'caellabid-2026'],
];
