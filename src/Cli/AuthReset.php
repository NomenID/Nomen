<?php
namespace Nomen\Src\Cli;

use Nomen\Src\LoginMethods;
use Nomen\Src\Settings;
use Throwable;

/**
 * 登录方式全关进不去后台时的兜底：恢复默认（全部开启）。
 */
class AuthReset
{
    public static function run(array $args): int
    {
        $values = [];
        foreach (LoginMethods::METHODS as $method) {
            $values['login.method_' . $method] = '1';
        }

        try {
            Settings::setMany($values);
        } catch (Throwable $e) {
            fwrite(STDERR, '写不进去：' . $e->getMessage() . PHP_EOL);
            echo 'site_settings 表不存在的话，先跑 php bin/nomen upgrade。', PHP_EOL;
            return 1;
        }

        echo '已恢复默认登录方式：密码、邮箱验证码、生物识别、临时密钥、第三方登录全部开启。', PHP_EOL;
        return 0;
    }
}
