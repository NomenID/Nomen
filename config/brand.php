<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

$installed = is_file(__DIR__ . '/installed.php') ? require __DIR__ . '/installed.php' : [];
$app = $installed['app'] ?? [];

return [
    'name'               => getenv('BRAND_NAME') ?: ($app['name'] ?? 'Nomen'),
    'base_url'           => rtrim(getenv('APP_URL') ?: ($app['base_url'] ?? 'http://localhost:8080'), '/'),
    'support_email'      => getenv('SUPPORT_EMAIL') ?: ($app['support_email'] ?? ''),
    'admin_notify_email' => getenv('ADMIN_NOTIFY_EMAIL') ?: ($app['admin_notify_email'] ?? ''),
    'session_name'       => getenv('SESSION_NAME') ?: 'NOMEN_SESSION',

    // 站点图标：布局左上角、favicon、页脚都用它。留空就用包里带的 Nomen 图标
    'logo'               => getenv('BRAND_LOGO') ?: (($app['logo'] ?? '') ?: '/assets/brand/nomen-icon.svg'),

    // 页脚版权行：站点所有者自己填，留空就不显示
    'copyright'          => getenv('BRAND_COPYRIGHT') ?: ($app['copyright'] ?? ''),

    // 对外的政策页和开发者守则；留空时界面不显示对应链接
    'privacy_url'        => getenv('BRAND_PRIVACY_URL') ?: ($app['privacy_url'] ?? ''),
    'terms_url'          => getenv('BRAND_TERMS_URL') ?: ($app['terms_url'] ?? ''),
    'developer_guidelines_url' => getenv('BRAND_DEVELOPER_GUIDELINES_URL') ?: ($app['developer_guidelines_url'] ?? ''),

    // 认证页（登录 / 临时密钥）法律声明的链接列表，每项 ['label' => ..., 'url' => ...]。
    // label 写什么显示什么；写 'Privacy Policy' 这类词典键会按当前语言翻译。
    // 留空时回退为 privacy_url / terms_url / developer_guidelines_url 按序合成。
    'legal_links'        => [],

    // 支持团队名称，登录页/恢复页用它；留空就写「支持团队」
    'support_name'       => getenv('BRAND_SUPPORT_NAME') ?: ($app['support_name'] ?? ''),

    // 页脚链接，按需加：['label' => '隐私政策', 'url' => '/privacy']
    // Pages 插件启用时，Brand::footerLinks() 会自动补一个「文档」入口。
    'footer_links'       => [],

    // 页脚署名。关掉后首页要自己满足两条：看得到「Powered by Nomen」，且有链到 /special/version
    // 的入口；两条合一或分开都行（GPLv3 第 7 条 (b) 款，见 NOTICE）
    'powered_by'         => true,
];
