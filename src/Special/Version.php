<?php
namespace Nomen\Src\Special;

use Nomen\Src\{Brand, Cache, Database, Plugins, Skins, Upgrade};

class Version
{
    public const PROJECT_URL = 'https://nomen.caellab.com/';

    // 版本页要查库、扫插件，内容又不常变：缓存一分钟，够挡住刷新风暴
    private const CACHE_TTL = 60;

    public static function render(): string
    {
        return (string)Cache::remember('special:version', self::CACHE_TTL, static fn(): string => self::build());
    }

    private static function build(): string
    {
        $config = require dirname(__DIR__, 2) . '/config/app.php';

        return self::license()
            . self::software($config)
            . self::entryPoints($config)
            . self::pluginMounts()
            . self::skins()
            . self::plugins()
            . self::libraries();
    }

    private static function license(): string
    {
        return '<h2>Nomen 许可协议</h2>'
            . '<p>本站由 <a href="' . self::PROJECT_URL . '">Nomen</a> 驱动，版权所有 © 2025-'
            . date('Y') . ' Yunyun。</p>'
            . '<p>Nomen 是自由软件；你可以依据自由软件基金会发行的 GNU 通用公共许可协议第 3 版的条款，'
            . '传播和/或修改本软件。</p>'
            . '<p>Nomen 在分发时期望其有用，但对此<em>无任何担保</em>；更无隐含的'
            . '<strong>可以销售</strong>或<strong>适合特定目的</strong>的担保。'
            . '详情请见 GNU 通用公共许可协议。</p>'
            . '<p>你应该已经收到本程序附带的 GNU 通用公共许可协议的副本；如果没有，可以在'
            . '<a href="/special/version/core/nomen/license">本站阅读协议全文</a>。'
            . '附加条款与声明见 <a href="/special/version/core/nomen/notice">NOTICE</a>，'
            . '项目说明见 <a href="/special/version/core/nomen/readme">README</a>。</p>';
    }

    private static function software(array $config): string
    {
        $rows = [[
            self::link('Nomen', self::PROJECT_URL),
            self::esc(self::plain($config['version'] ?? '')),
        ], [
            self::link('PHP', 'https://www.php.net/'),
            self::esc(PHP_VERSION . ' (' . PHP_SAPI . ')'),
        ]];

        try {
            $db = Database::getInstance();
            $version = $db->dialect()->serverVersion($db);

            if (str_starts_with($version, 'SQLite')) {
                $rows[] = [self::link('SQLite', 'https://sqlite.org/'), self::esc(self::plain(substr($version, 7)))];
            } else {
                $mariadb = stripos($version, 'mariadb') !== false;
                $rows[] = [
                    self::link($mariadb ? 'MariaDB' : 'MySQL', $mariadb ? 'https://mariadb.org/' : 'https://www.mysql.com/'),
                    self::esc(self::plain($version)),
                ];
            }
            $mismatch = Upgrade::mismatch($db);
            if ($mismatch !== null) {
                $rows[] = [
                    '数据库结构',
                    self::esc(($mismatch['db'] ?? '未记录') . '（与程序版本不一致，站点管理员需要运行升级）'),
                ];
            }
        } catch (\Throwable) {
            $rows[] = ['数据库', '—'];
        }

        return '<h2>安装的软件</h2>' . self::table(['产品', '版本'], $rows);
    }

    private static function entryPoints(array $config): string
    {
        $base = rtrim((string)($config['base_url'] ?? ''), '/');

        $points = [
            ['授权', '/oauth/authorize'],
            ['令牌', '/oauth/token'],
            ['用户信息', '/api/userinfo'],
            ['令牌校验', '/api/token-info'],
            ['撤销', '/oauth/revoke'],
        ];

        $rows = [];
        foreach ($points as [$label, $path]) {
            $url = $base . $path;
            $rows[] = [
                self::esc($label),
                '<a href="' . self::esc($url) . '">' . self::esc($url) . '</a>',
            ];
        }

        return '<h2>接入点 URL</h2>' . self::table(['接入点', 'URL'], $rows);
    }

    private static function pluginMounts(): string
    {
        $mounts = Plugins::mounts();
        if ($mounts === []) {
            return '';
        }

        $base = rtrim((string)Brand::get('base_url'), '/');
        $rows = [];
        foreach ($mounts as $mount) {
            $url = $base . $mount['path'];
            $rows[] = [
                self::esc((string)$mount['plugin']),
                self::esc((string)$mount['title']),
                '<a href="' . self::esc($url) . '">' . self::esc($url) . '</a>',
                ($mount['access'] ?? 'public') === 'admin' ? '仅管理员' : '公开',
            ];
        }

        return '<h2>插件挂载点</h2>' . self::table(['插件', '名称', 'URL', '访问'], $rows);
    }

    private static function skins(): string
    {
        $skins = Skins::all();
        if ($skins === []) {
            return '<h2>安装的皮肤</h2><p>当前未安装任何皮肤。</p>';
        }

        $rows = [];
        foreach ($skins as $manifest) {
            $rows[] = [
                self::esc($manifest['name'] ?? ''),
                self::esc(self::plain($manifest['version'] ?? '')),
                self::licenseCell($manifest['license'] ?? '', 'skin', (string)($manifest['slug'] ?? '')),
                self::esc(t(self::plain($manifest['description'] ?? ''))),
                self::esc(self::authors($manifest['author'] ?? [])),
            ];
        }

        return '<h2>安装的皮肤</h2>' . self::table(['皮肤', '版本', '许可协议', '描述', '作者'], $rows);
    }

    private static function plugins(): string
    {
        $plugins = Plugins::loaded();
        if ($plugins === []) {
            return '<h2>安装的插件</h2><p>当前未安装任何插件。</p>';
        }

        $rows = [];
        foreach ($plugins as $manifest) {
            $name = self::plain($manifest['name'] ?? '');
            $homepage = (string)($manifest['homepage'] ?? '');
            $rows[] = [
                $homepage !== '' ? self::link($name, $homepage) : self::esc($name),
                self::esc(self::plain($manifest['version'] ?? '')),
                self::licenseCell($manifest['license'] ?? '', 'plugin', (string)($manifest['slug'] ?? '')),
                self::esc(t(self::plain($manifest['description'] ?? ''))),
                self::esc(self::authors($manifest['author'] ?? [])),
            ];
        }

        return '<h2>安装的插件</h2>'
            . self::table(['插件', '版本', '许可协议', '描述', '作者'], $rows)
            . '<p>插件由各自的作者维护，按各自的许可协议发布，不属于 Nomen 本体。</p>';
    }

    private static function libraries(): string
    {
        $html = '';
        $server = self::serverLibraries();
        if ($server !== []) {
            $html .= '<h3>安装的服务器端库</h3>'
                . self::table(['库', '版本', '许可协议', '描述', '作者'], $server);
        }

        $client = self::clientLibraries();
        if ($client !== []) {
            $html .= '<h3>安装的客户端库</h3>'
                . self::table(['库', '版本', '许可协议'], $client);
        }

        return $html === '' ? '' : '<h2>安装的库</h2>' . $html;
    }

    private static function serverLibraries(): array
    {
        $file = dirname(__DIR__, 2) . '/vendor/composer/installed.json';
        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data)) {
            return [];
        }

        $packages = $data['packages'] ?? $data;
        $dev = array_flip((array)($data['dev-package-names'] ?? []));

        $rows = [];
        foreach ($packages as $package) {
            if (!is_array($package) || empty($package['name']) || isset($dev[$package['name']])) {
                continue;
            }
            $rows[] = [
                self::esc($package['name']),
                self::esc(self::plain($package['version'] ?? '')),
                self::esc(self::plain($package['license'] ?? '')),
                self::esc(self::plain($package['description'] ?? '')),
                self::esc(self::authors($package['authors'] ?? [])),
            ];
        }

        return $rows;
    }

    private static function clientLibraries(): array
    {
        $file = dirname(__DIR__, 2) . '/frontend/package-lock.json';
        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string)file_get_contents($file), true);
        $dependencies = $data['packages']['']['dependencies'] ?? null;
        if (!is_array($dependencies)) {
            return [];
        }

        $rows = [];
        foreach (array_keys($dependencies) as $name) {
            $package = $data['packages']['node_modules/' . $name] ?? null;
            if (!is_array($package)) {
                continue;
            }
            $rows[] = [
                self::esc($name),
                self::esc(self::plain($package['version'] ?? '')),
                self::esc(self::plain($package['license'] ?? '')),
            ];
        }

        return $rows;
    }

    private static function table(array $headers, array $rows): string
    {
        $wide = count($headers) >= 4 ? ' special-table--wide' : '';
        $html = '<div class="special-table-wrap"><table class="table' . $wide . '"><thead><tr>';
        foreach ($headers as $header) {
            $html .= '<th>' . self::esc($header) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . $cell . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table></div>';
    }

    private static function link(string $text, string $url): string
    {
        return '<a href="' . self::esc($url) . '">' . self::esc($text) . '</a>';
    }

    private static function licenseCell(mixed $license, string $type, string $slug): string
    {
        $text = self::esc(self::plain($license));
        $url = License::url($type, $slug);

        return $url === null ? $text : '<a href="' . self::esc($url) . '">' . $text . '</a>';
    }

    /** 清单里的值可能是字符串、数组，也可能没写 */
    private static function plain(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        }
        $value = trim((string)$value);

        return $value === '' ? '—' : $value;
    }

    private static function authors(mixed $author): string
    {
        $list = [];
        foreach ((array)$author as $item) {
            if (is_array($item)) {
                $item = $item['name'] ?? '';
            }
            $item = trim((string)$item);
            if ($item !== '') {
                $list[] = $item;
            }
        }

        if ($list === []) {
            return '—';
        }
        if (count($list) === 1) {
            return $list[0];
        }

        $last = array_pop($list);

        return implode('、', $list) . ' 和 ' . $last;
    }

    private static function esc(mixed $value): string
    {
        return htmlspecialchars((string)$value);
    }
}
