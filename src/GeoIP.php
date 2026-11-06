<?php
namespace Nomen\Src;

/**
 * GeoIP 的门面：核心只认这两个方法，真正的查询在 plugins/geoip 里。
 * 没装插件（或数据文件缺失）时返回空值，调用方照常降级。
 */
class GeoIP
{
    private static $country = null;
    private static $location = null;

    public static function setResolvers(?callable $country, ?callable $location = null): void
    {
        self::$country = $country;
        self::$location = $location;
    }

    public static function getCountryCode(string $ip): ?string
    {
        if (self::$country === null) {
            return null;
        }

        $code = (self::$country)($ip);

        return is_string($code) && $code !== '' ? $code : null;
    }

    public static function getLocation(string $ip): string
    {
        if (self::$location === null) {
            return '未知位置';
        }

        $location = (self::$location)($ip);

        return is_string($location) && $location !== '' ? $location : '未知位置';
    }
}
