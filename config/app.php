<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

// 加载 .env 文件
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        putenv("{$key}={$value}");
    }
}

$brand = require __DIR__ . '/brand.php';
$installed = is_file(__DIR__ . '/installed.php') ? require __DIR__ . '/installed.php' : [];
$dbConf = $installed['db'] ?? [];
$redisConf = $installed['redis'] ?? [];
$appConf = $installed['app'] ?? [];

// 可选功能开关：环境变量优先，其次 installed.php 的 features 段，都没有就是关
$feature = static function (string $envKey, string $configKey) use ($appConf): bool {
    $env = getenv($envKey);
    if ($env !== false && $env !== '') {
        return filter_var($env, FILTER_VALIDATE_BOOLEAN);
    }
    return (bool)($appConf['features'][$configKey] ?? false);
};

return [
    // 基本信息
    'name'         => $brand['name'],
    'version'      => '1.3.9',
    'base_url'     => $brand['base_url'],

    // 安全
    // ⚠️ pepper 已参与全部密码哈希，一旦上线【绝不可更改】
    //    更改后所有用户密码将永久无法验证，且无法回滚
    'pepper'       => getenv('APP_PEPPER') ?: ($installed['pepper'] ?? 'change-me-to-another-random-string'),
    // 暂未被任何代码使用，保留待用
    'secret_key'   => getenv('APP_SECRET') ?: ($installed['secret'] ?? 'change-me-to-a-random-string'),
    'csrf_enabled' => true,

    // Session
    'session_lifetime' => 7200, // 2小时
    'session_name'     => $brand['session_name'],
    // 「记住我」有效期。注意 Session::start() 会把它算进 gc_maxlifetime，
    // 否则服务端提前回收 session，长有效期 cookie 后半段是空壳
    'remember_me_ttl'  => 30 * 24 * 3600, // 30天

    // 登录防护
    'login_captcha_threshold' => 5,  // 单 IP 每分钟登录尝试超过该值才要求图片验证码
    'login_ip_hard_limit'     => 20, // 单 IP 每分钟登录尝试硬上限，超过直接拒绝
    'two_factor_pending_ttl'  => 600, // 二验待验证会话有效期（秒）
    // 登录页「本次已尝试」的记忆时长（秒）：超出这个时间就不再提醒，
    // 免得用户隔天回来还看到「你刚才密码没登进去」
    'login_failed_method_ttl' => 900,

    // 对外联系方式（页面上给用户看的支持邮箱，别写成管理员私人信箱）
    'support_email' => $brand['support_email'],

    // 管理员「新工单」提醒的收件人：管理员自己的信箱，与对外的 support_email 分开。
    // 提醒本身按下面的 admin_notify_window 节流汇总，不会每单都发。
    'admin_notify_email' => $brand['admin_notify_email'],

    // 账号恢复
    'recovery_ctx_ttl'         => 7200,        // 申诉页账号上下文有效期（秒）
    'recovery_submit_limit'    => 5,           // 单 IP 申诉提交次数上限
    'recovery_submit_window'   => 3600,        // 上面那条的统计窗口（秒）
    'recovery_token_ttl'       => 7 * 24 * 3600, // 恢复登录链接有效期（秒）
    'recovery_attach_max_bytes' => 1048576,      // 单张证明材料上限（1MB，超了后端直接拒收）
    'recovery_attach_max_count' => 5,            // 单次申诉最多几张
    'recovery_attach_tmp_ttl'   => 7200,         // 暂存区里没提交的图多久清掉（秒）
    'recovery_upload_limit'     => 40,           // 单 IP 每小时上传次数上限
    'recovery_upload_window'    => 3600,
    // 管理员「新工单」提醒的节流窗口（秒）。
    // 全站共用一个窗口：窗口内第一个工单进来时立刻发一封汇总，
    // 之后窗口内的工单只入库不发信，等下一个窗口开启时连带一起发过去。
    // 86400 = 每天最多一封（用户要的档位）；想更及时就改小。
    'admin_notify_window'       => 86400,

    // OAuth
    'auth_code_ttl'      => 600,    // 授权码有效期 10分钟
    'access_token_ttl'   => 3600,   // 访问令牌有效期 1小时
    'refresh_token_ttl'  => 15552000, // 刷新令牌有效期 180天
    'token_hash_algorithm' => 'sha512', // Token 哈希算法（兼容旧的 SHA-256 记录）

    // 可选功能：默认全关，不需要的站长不用被迫接受
    //   guardians  家庭与未成年监护（家长同意、家长邮箱、家庭页）
    //   country    注册时填写国家 / 地区（含按 IP 预填）
    // 安装向导的「扩展」一步可以勾选，装完后改 config/installed.php 的 features 段或环境变量
    'features' => [
        'guardians' => $feature('FEATURE_GUARDIANS', 'guardians'),
        'country'   => $feature('FEATURE_COUNTRY', 'country'),
    ],

    // 多语言
    // 没登录、浏览器语言也匹配不上时用的语言；安装向导的「站点」一步可以选
    'language_default'      => getenv('APP_LANGUAGE') ?: ($appConf['language_default'] ?? 'zh-hans'),
    // 关掉后全站固定默认语言：语言选择器、?lang=、账号语言偏好全部停用
    'language_allow_switch' => filter_var($appConf['language_allow_switch'] ?? true, FILTER_VALIDATE_BOOLEAN),

    // 数据库：driver 取 mysql / mariadb / sqlite
    // SQLite 用 path，相对路径按项目根算；其余字段忽略
    'db' => [
        'driver' => strtolower(getenv('DB_DRIVER') ?: ($dbConf['driver'] ?? 'mysql')),
        'host' => getenv('DB_HOST') ?: ($dbConf['host'] ?? '127.0.0.1'),
        'port' => getenv('DB_PORT') ?: ($dbConf['port'] ?? '3306'),
        'name' => getenv('DB_NAME') ?: ($dbConf['name'] ?? 'nomen'),
        'user' => getenv('DB_USER') ?: ($dbConf['user'] ?? 'nomen'),
        'pass' => getenv('DB_PASS') ?: ($dbConf['pass'] ?? ''),
        'path' => getenv('DB_PATH') ?: ($dbConf['path'] ?? 'database/nomen.sqlite'),
    ],

    // Redis
    'redis' => [
        'host'     => getenv('REDIS_HOST') ?: ($redisConf['host'] ?? '127.0.0.1'),
        'port'     => getenv('REDIS_PORT') ?: ($redisConf['port'] ?? 6379),
        'db'       => getenv('REDIS_DB') ?: ($redisConf['db'] ?? 1),
        'password' => getenv('REDIS_PASSWORD') ?: ($redisConf['password'] ?? ''),
    ],

    // 可用的 OAuth scopes
    'scopes' => [
        'openid'  => 'Basic profile',
        'profile' => 'username, display name, avatar',
        'email'   => 'Verified email',
    ],

    'webauthn_origin' => $brand['base_url'],
    'webauthn_rp_name' => $brand['name'],
];
