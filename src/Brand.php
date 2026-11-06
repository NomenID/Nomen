<?php
namespace Nomen\Src;

/**
 * 站点品牌信息：名称、地址、图标、页脚。布局和模板都从这里取，别写死。
 */
final class Brand
{
    private static ?array $data = null;

    public static function all(): array
    {
        if (self::$data === null) {
            self::$data = require dirname(__DIR__) . '/config/brand.php';
        }

        return self::$data;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    /** @return array<int, array{label: string, url: string}> */
    public static function footerLinks(): array
    {
        $links = (array)self::get('footer_links', []);

        $docs = Plugins::mountUrl('pages');
        if ($docs !== null) {
            $links[] = ['label' => '文档', 'url' => $docs];
        }

        return $links;
    }
}
