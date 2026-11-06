<?php
/**
 * 扩展注册表测试。跑法：
 *
 *     php docs-internal/tests/extensions-test.php
 *
 * 覆盖：插件加载、挂载点、后台页面、后台菜单、插件模板解析、插件资源 URL。
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

define('PROJ', dirname(__DIR__, 2));

spl_autoload_register(function (string $class) {
    $prefixes = [
        'Nomen\\Src\\'         => PROJ . '/src/',
        'Nomen\\Controllers\\' => PROJ . '/controllers/',
        'Nomen\\Plugin\\'      => PROJ . '/plugins/',
    ];
    foreach ($prefixes as $prefix => $baseDir) {
        if (strpos($class, $prefix) === 0) {
            $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (file_exists($file)) {
                require $file;
                return;
            }
        }
    }
});

require PROJ . '/src/I18n.php';

$_SESSION = [];
$_SERVER['REQUEST_URI'] = '/';

\Nomen\Src\Plugins::boot();

$fails = 0;
$check = function (string $label, $got, $want) use (&$fails) {
    $ok = $got === $want;
    if (!$ok) { $fails++; }
    printf("[%s] %-45s got=%s\n", $ok ? 'OK ' : 'FAIL', $label, var_export($got, true));
};

echo "==== 插件加载 ====\n";
$loaded = array_keys(\Nomen\Src\Plugins::loaded());
$check('open-platform 已加载', in_array('open-platform', $loaded, true), true);
$check('pages 已加载', in_array('pages', $loaded, true), true);

echo "\n==== 挂载点 ====\n";
$mounts = \Nomen\Src\Plugins::mounts();
$check('开放平台挂载点', \Nomen\Src\Plugins::mountUrl('open-platform'), '/open');
$check('文档挂载点', \Nomen\Src\Plugins::mountUrl('pages'), '/w');
$check('挂载点数量', count($mounts), 2);

echo "\n==== 后台页面与菜单 ====\n";
$check('开发者页已注册', \Nomen\Src\Admin::hasPage('developers'), true);
$menu = \Nomen\Src\Admin::menuItems();
$check('后台菜单有开发者', isset($menu['developers']), true);
$check('开发者菜单指向 /admin/developers', $menu['developers']['href'] ?? null, '/admin/developers');

echo "\n==== 插件模板与资源 ====\n";
$check('插件页面模板可解析', str_ends_with((string)\Nomen\Src\Skins::template('@open-platform/landing'), 'plugins/open-platform/templates/landing.php'), true);
$asset = \Nomen\Src\Plugins::assetUrl('open-platform', 'portal.css');
$check('资源 URL 前缀', str_starts_with($asset, '/plugin-assets/open-platform/portal.css?v='), true);
$check('资源文件存在', is_file(PROJ . '/plugins/open-platform/assets/portal.css'), true);

echo "\n==== 路由编译 ====\n";
$router = new \Nomen\Src\Router();
$compile = new ReflectionMethod(\Nomen\Src\Router::class, 'compile');
$compile->setAccessible(true);

$regex = $compile->invoke($router, '/plugin-assets/{slug}/{path:.*}');
$check('多段通配能匹配', (bool)preg_match($regex, '/plugin-assets/open-platform/img/x.svg', $m), true);
$check('slug 捕获', $m['slug'] ?? null, 'open-platform');
$check('path 捕获', $m['path'] ?? null, 'img/x.svg');

$check('普通占位不跨段', (bool)preg_match($compile->invoke($router, '/w/{slug}'), '/w/a/b'), false);
$check('静态点号不当作正则', (bool)preg_match($compile->invoke($router, '/a.b/{id}'), '/aXb/1'), false);

echo $fails === 0 ? "\nALL PASS\n" : "\n{$fails} FAILED\n";
exit($fails === 0 ? 0 : 1);
