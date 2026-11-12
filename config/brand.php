<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
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

    // 页脚版权行：站点所有者自己填，留空就不显示。要加链接写成 [文字](https://example.com)
    'copyright'          => getenv('BRAND_COPYRIGHT') ?: ($app['copyright'] ?? ''),

    // 对外的政策页和开发者守则；留空时界面不显示对应链接
    'privacy_url'        => getenv('BRAND_PRIVACY_URL') ?: ($app['privacy_url'] ?? ''),
    'terms_url'          => getenv('BRAND_TERMS_URL') ?: ($app['terms_url'] ?? ''),
    'developer_guidelines_url' => getenv('BRAND_DEVELOPER_GUIDELINES_URL') ?: ($app['developer_guidelines_url'] ?? ''),

    // 站点政策，最多三条。role 取 privacy / terms / other，决定注册页勾选框和政策链接的用途；
    // label 留空时 privacy / terms 用词典键，other 必须有 label；url 留空则该条不显示。
    // 后台「设置」改了以后以数据库为准，这里只是默认值。
    'policies'           => [],

    // 附加条款：不限条数，单独一组展示，不占上面三条政策的名额。
    // 每项 ['label' => ..., 'url' => ...]
    'additional_terms'   => [],

    // 认证页（登录 / 临时密钥）法律声明的链接列表，每项 ['label' => ..., 'url' => ...]。
    // label 写什么显示什么；写 'Privacy Policy' 这类词典键会按当前语言翻译。
    // 留空时回退为 privacy_url / terms_url / developer_guidelines_url 按序合成。
    'legal_links'        => [],

    // 支持团队名称，登录页/恢复页用它；留空就写「支持团队」
    'support_name'       => getenv('BRAND_SUPPORT_NAME') ?: ($app['support_name'] ?? ''),

    // 页脚链接，按需加：['label' => '隐私政策', 'url' => '/privacy']
    // Pages 插件启用时，Brand::footerLinks() 会自动补一个「文档」入口。
    'footer_links'       => [],

    // 页脚署名，纯建议：关掉不影响任何许可（NOTICE 是建议性文档）。
    // 保留的话它会链到 /special/version；无 JS 时 href 直接指版本页
    'powered_by'         => true,
];
