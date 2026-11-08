<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

// 安装向导的公共部分：会话、状态、环境检测和几个渲染小工具。入口是 index.php，接口是 api.php。

use Nomen\Src\Plugins;

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('NOMEN_INSTALL');
    session_start();
}

function nomen_install_root(string $path = ''): string
{
    $base = dirname(__DIR__, 2);

    return $path === '' ? $base : $base . '/' . ltrim($path, '/');
}

function nomen_install_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function nomen_install_config_file(): string
{
    return nomen_install_root('config/installed.php');
}

function nomen_install_is_installed(): bool
{
    return is_file(nomen_install_config_file());
}

function nomen_install_csrf(): string
{
    if (empty($_SESSION['nomen_install_csrf'])) {
        $_SESSION['nomen_install_csrf'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['nomen_install_csrf'];
}

function nomen_install_csrf_ok(): bool
{
    $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_token'] ?? ''));
    $expected = (string)($_SESSION['nomen_install_csrf'] ?? '');

    return $token !== '' && $expected !== '' && hash_equals($expected, $token);
}

function nomen_install_state(): array
{
    return (array)($_SESSION['nomen_install'] ?? []);
}

function nomen_install_save_state(array $state): void
{
    $_SESSION['nomen_install'] = $state;
}

function nomen_install_guess_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function nomen_install_languages(): array
{
    return (array)(require nomen_install_root('locales/languages.php'));
}

function nomen_install_lang_slugs(): array
{
    return array_map(static fn(array $language): string => (string)$language['slug'], nomen_install_languages());
}

function nomen_install_lang_valid(string $slug): bool
{
    return in_array($slug, nomen_install_lang_slugs(), true);
}

/** 当前请求的语言：请求显式带上的（请求头 / ?lang=）> 会话里选过的 > 浏览器语言 > 英文。 */
function nomen_install_lang(): string
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }

    $explicit = [
        (string)($_SERVER['HTTP_X_NOMEN_LANG'] ?? ''),
        (string)($_GET['lang'] ?? ''),
        (string)($_SESSION['nomen_install_lang'] ?? ''),
    ];
    foreach ($explicit as $candidate) {
        if ($candidate !== '' && nomen_install_lang_valid($candidate)) {
            return $lang = $candidate;
        }
    }

    return $lang = nomen_install_match_accept_language((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) ?? 'en';
}

function nomen_install_set_lang(string $slug): void
{
    if (nomen_install_lang_valid($slug)) {
        $_SESSION['nomen_install_lang'] = $slug;
    }
}

function nomen_install_locale_code(): string
{
    $slug = nomen_install_lang();
    foreach (nomen_install_languages() as $language) {
        if ((string)$language['slug'] === $slug) {
            return (string)$language['code'];
        }
    }

    return 'en';
}

/** 安装向导自己的翻译助手：此刻核心还没装（config/installed.php 不存在），不能依赖站点配置。 */
function nomen_install_t(string $key, array $params = []): string
{
    static $dict = null;
    if ($dict === null) {
        $dict = [];
        $lang = nomen_install_lang();
        if ($lang !== 'en') {
            $file = nomen_install_root('locales/' . $lang . '.json');
            $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
            if (is_array($data)) {
                $dict = $data;
            }
        }
    }

    $text = (string)($dict[$key] ?? $key);
    foreach ($params as $name => $value) {
        $text = strtr($text, ['{:' . $name . '}' => (string)$value]);
    }

    return $text;
}

/** 跟核心 I18n 同一套 Accept-Language 匹配规则的最小实现（en-US → en、zh-TW → zh-hant）。 */
function nomen_install_match_accept_language(string $header): ?string
{
    if ($header === '') {
        return null;
    }

    $tags = [];
    foreach (explode(',', $header) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $q = 1.0;
        if (str_contains($part, ';')) {
            [$tag, $param] = array_pad(explode(';', $part, 2), 2, '');
            if (preg_match('/q=([0-9.]+)/', $param, $m)) {
                $q = (float)$m[1];
            }
        } else {
            $tag = $part;
        }
        $tag = strtolower(str_replace('_', '-', trim($tag)));
        if ($tag !== '' && $tag !== '*') {
            $tags[] = ['tag' => $tag, 'q' => $q];
        }
    }

    usort($tags, static fn(array $a, array $b): int => $b['q'] <=> $a['q']);
    foreach ($tags as $entry) {
        $tag = $entry['tag'];
        if (str_starts_with($tag, 'zh')) {
            $sub = substr($tag, 2);
            if (str_contains($sub, 'hant') || str_contains($sub, '-tw') || str_contains($sub, '-hk') || str_contains($sub, '-mo')) {
                return 'zh-hant';
            }
            return 'zh-hans';
        }
        foreach (nomen_install_languages() as $language) {
            $code = strtolower((string)$language['code']);
            if ($code === $tag || (string)$language['slug'] === $tag
                || explode('-', $code)[0] === explode('-', $tag)[0]) {
                return (string)$language['slug'];
            }
        }
    }

    return null;
}

function nomen_install_resume_step(array $state): string
{
    $next = 'env';
    if (isset($state['db'])) {
        $next = 'site';
    }
    if (isset($state['site'])) {
        $next = 'admin';
    }
    if (isset($state['admin'])) {
        $next = 'plugins';
    }
    if (isset($state['plugins'])) {
        $next = 'mail';
    }
    if (array_key_exists('smtp', $state)) {
        $next = 'review';
    }

    return $next;
}

function nomen_install_started(array $state): bool
{
    return isset($state['db']) || isset($state['site']) || isset($state['admin'])
        || isset($state['plugins']) || array_key_exists('smtp', $state);
}

/** 会话里给浏览器看的状态：管理员密码永远不出去。 */
function nomen_install_public_state(array $state): array
{
    if (isset($state['admin'])) {
        $state['admin'] = (array)$state['admin'];
        $state['admin']['has_password'] = isset($state['admin']['password']);
        unset($state['admin']['password']);
    }

    return $state;
}

function nomen_install_env_checks(): array
{
    $required = [
        [
            'key' => 'php',
            'label' => 'PHP ' . PHP_VERSION,
            'pass' => version_compare(PHP_VERSION, '8.1.0', '>='),
            'hint' => nomen_install_t('PHP 8.1 or newer is required — ask your host to upgrade PHP.'),
        ],
        [
            'key' => 'pdo',
            'label' => nomen_install_t('Database extension'),
            'pass' => extension_loaded('pdo_mysql') || extension_loaded('pdo_sqlite'),
            'hint' => nomen_install_t('Install at least one of the pdo_mysql and pdo_sqlite extensions.'),
        ],
        [
            'key' => 'mbstring',
            'label' => nomen_install_t('Extension mbstring'),
            'pass' => extension_loaded('mbstring'),
            'hint' => nomen_install_t('Used for multilingual text and string handling.'),
        ],
        [
            'key' => 'openssl',
            'label' => nomen_install_t('Extension openssl'),
            'pass' => extension_loaded('openssl'),
            'hint' => nomen_install_t('Used for HTTPS, SMTP and passkeys.'),
        ],
        [
            'key' => 'vendor',
            'label' => nomen_install_t('Composer dependencies'),
            'pass' => is_readable(nomen_install_root('vendor/autoload.php')),
            'hint' => nomen_install_t('Bundled with the release package; for a source deployment run composer install first.'),
        ],
        [
            'key' => 'schema',
            'label' => nomen_install_t('Database schema files'),
            'pass' => is_readable(nomen_install_root('database/schema.sql'))
                && is_readable(nomen_install_root('database/schema.sqlite.sql')),
            'hint' => nomen_install_t('database/schema*.sql are incomplete — extract the release package again.'),
        ],
    ];

    $optional = [
        [
            'key' => 'gd',
            'label' => nomen_install_t('Extension gd'),
            'pass' => extension_loaded('gd'),
            'hint' => nomen_install_t('Captcha images and appeal screenshot thumbnails will be disabled.'),
        ],
        [
            'key' => 'fileinfo',
            'label' => nomen_install_t('Extension fileinfo'),
            'pass' => extension_loaded('fileinfo'),
            'hint' => nomen_install_t('Avatar and app icon uploads will be disabled.'),
        ],
        [
            'key' => 'redis',
            'label' => nomen_install_t('Extension redis'),
            'pass' => extension_loaded('redis'),
            'hint' => nomen_install_t('Rate limiting and multi-device sign-out fall back to weaker modes.'),
        ],
        [
            'key' => 'config',
            'label' => nomen_install_t('config/ writable'),
            'pass' => is_writable(nomen_install_root('config')),
            'hint' => nomen_install_t('After installing you must upload installed.php manually.'),
        ],
    ];

    $all = true;
    foreach ($required as $row) {
        if (!$row['pass']) {
            $all = false;
        }
    }

    return ['required' => $required, 'optional' => $optional, 'all' => $all];
}

function nomen_install_upload_available(): array
{
    $dir = nomen_install_root('public/assets/develops');
    $writable = is_dir($dir) ? is_writable($dir) : is_writable(dirname($dir));

    if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
        $reason = nomen_install_t('The server is missing the GD (with WebP) extension, so avatar uploads are unavailable — paste an image URL instead.');
    } elseif (!extension_loaded('fileinfo') || !function_exists('finfo_open')) {
        $reason = nomen_install_t('The server is missing the fileinfo extension, so image types cannot be verified — paste an image URL instead.');
    } elseif (!$writable) {
        $reason = nomen_install_t('public/assets/ is not writable, so avatar uploads are unavailable — paste an image URL instead.');
    } else {
        $reason = '';
    }

    return ['enabled' => $reason === '', 'reason' => $reason];
}

function nomen_install_is_upload_url(?string $url): bool
{
    return $url !== null && preg_match('#^/assets/develops/[0-9]+-[a-f0-9]{8}\.(png|webp)$#', $url) === 1;
}

function nomen_install_delete_upload(?string $url): void
{
    if (!nomen_install_is_upload_url($url)) {
        return;
    }

    $path = nomen_install_root('public' . $url);
    if (is_file($path)) {
        @unlink($path);
    }
}

function nomen_install_payload(): array
{
    $state = nomen_install_state();
    $done = $_SESSION['nomen_install_done'] ?? null;
    // 只有配置文件没写进去时才留在完成页；写成功了还留着只是刷新后的旧状态，直接从头来。
    $done = (is_array($done) && empty($done['written'])) ? $done : null;

    $plugins = [];
    $order = ['open-platform' => 0, 'pages' => 1];
    $all = Plugins::all();
    uksort($all, static function (string $a, string $b) use ($order): int {
        return ($order[$a] ?? 100) <=> ($order[$b] ?? 100) ?: strcmp($a, $b);
    });
    foreach ($all as $slug => $manifest) {
        $plugins[] = [
            'slug' => (string)$slug,
            'name' => (string)($manifest['name'] ?? $slug),
            'description' => (string)($manifest['description'] ?? ''),
        ];
    }

    $languages = [];
    foreach (nomen_install_languages() as $language) {
        $languages[] = [
            'slug' => (string)$language['slug'],
            'name' => (string)$language['name'],
            'english' => (string)$language['english'],
        ];
    }

    return [
        'csrf' => nomen_install_csrf(),
        'step' => $done !== null
            ? 'done'
            : (nomen_install_started($state) ? nomen_install_resume_step($state) : 'start'),
        'resume' => nomen_install_resume_step($state),
        'started' => nomen_install_started($state),
        'state' => nomen_install_public_state($state),
        'done' => $done,
        'languages' => $languages,
        'plugins' => $plugins,
        'env' => nomen_install_env_checks(),
        'upload' => nomen_install_upload_available(),
        'baseUrl' => nomen_install_guess_base_url(),
        'lang' => nomen_install_lang(),
        'defaultLogo' => '/assets/brand/nomen-icon.svg',
    ];
}

function nomen_install_json(array $payload, int $status = 200): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function nomen_install_write_plugins_config(string $file, array $enabled): bool
{
    $list = implode(', ', array_map(
        static fn(string $slug): string => "'" . addslashes($slug) . "'",
        $enabled
    ));
    $content = "<?php\nreturn [\n    // plugins/ 下的目录名。装插件不用改代码，改这里就行。\n"
        . "    'enabled' => [" . $list . "],\n];\n";

    return @file_put_contents($file, $content) === strlen($content);
}

/** 已安装、缺前端资源、运行出错这类只需要一句话的场景。 */
function nomen_install_simple_page(string $title, string $body, bool $ok = false): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Frame-Options: DENY');
    header('X-Robots-Tag: noindex, nofollow');

    echo '<!doctype html><html lang="' . nomen_install_h(nomen_install_locale_code()) . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<title>' . nomen_install_h($title) . ' · Nomen</title>'
        . '<link rel="icon" href="/assets/brand/nomen-icon.svg">'
        . '<style>'
        . ':root{color-scheme:dark;--bg:#0d1117;--panel:#161b22;--border:#30363d;--text:#e6edf3;'
        . '--muted:#8b949e;--accent:#2f81f7;--accent-line:#8ab4ff;--ok:#3fb950;--bad:#f85149}'
        . '@media(prefers-color-scheme:light){:root{color-scheme:light;--bg:#f6f7f9;--panel:#fff;'
        . '--border:#d8dee4;--text:#1f2328;--muted:#59636e;--accent:#0969da;--accent-line:#0969da;'
        . '--ok:#1a7f37;--bad:#cf222e}}'
        . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
        . 'background:var(--bg);color:var(--text);font:14.5px/1.6 -apple-system,"Segoe UI","PingFang SC",'
        . '"Microsoft YaHei",sans-serif;padding:24px}'
        . '.card{width:100%;max-width:520px;background:var(--panel);border:1px solid var(--border);'
        . 'border-radius:16px;padding:28px 30px;box-shadow:0 24px 60px -40px rgba(0,0,0,.5)}'
        . '.mark{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;margin-bottom:16px;'
        . 'background:rgba(47,129,247,.12);color:var(--accent-line);font-size:20px}'
        . '.mark.ok{background:rgba(63,185,80,.12);color:var(--ok)}'
        . '.mark.bad{background:rgba(248,81,73,.1);color:var(--bad)}'
        . 'h1{margin:0 0 8px;font-size:20px;font-weight:600;letter-spacing:-.01em}'
        . 'p{margin:0 0 12px;color:var(--muted)}'
        . 'code{font-family:"Cascadia Mono",Consolas,monospace;font-size:.92em;background:rgba(127,127,127,.14);'
        . 'padding:1px 5px;border-radius:4px}'
        . 'a.btn{display:inline-flex;align-items:center;justify-content:center;height:42px;padding:0 18px;'
        . 'margin-top:8px;border-radius:9px;background:var(--accent);color:#fff;text-decoration:none;'
        . 'font-weight:600;font-size:14px}'
        . 'a.btn.ghost{background:transparent;border:1px solid var(--border);color:var(--text);margin-left:8px}'
        . '</style></head><body><div class="card">'
        . '<div class="mark ' . ($ok ? 'ok' : '') . '">' . ($ok ? '✓' : '!') . '</div>'
        . '<h1>' . nomen_install_h($title) . '</h1>' . $body
        . '</div></body></html>';
}

function nomen_install_render_installed(): void
{
    $config = [];
    try {
        $loaded = require nomen_install_config_file();
        if (is_array($loaded)) {
            $config = $loaded;
        }
    } catch (Throwable $e) {
        $config = [];
    }

    $base = rtrim((string)($config['app']['base_url'] ?? ''), '/');
    $siteUrl = $base !== '' ? $base : nomen_install_guess_base_url();
    $name = (string)($config['app']['name'] ?? 'Nomen');

    nomen_install_simple_page(nomen_install_t('This site is already installed'),
        '<p>' . nomen_install_t('{:name} is up and running.', ['name' => nomen_install_h($name)]) . '</p>'
        . '<p>' . nomen_install_t('After installing, delete or rename <code>public/nomen-install/</code> — do not leave the install wizard on the site. To reinstall, delete <code>config/installed.php</code> and refresh this page.') . '</p>'
        . '<a class="btn" href="' . nomen_install_h($siteUrl) . '">' . nomen_install_t('Open the site') . '</a>'
        . '<a class="btn ghost" href="' . nomen_install_h($siteUrl . '/login') . '">' . nomen_install_t('Sign in') . '</a>',
        true);
}

function nomen_install_render_missing_assets(): void
{
    nomen_install_simple_page(nomen_install_t('The install wizard front-end assets are missing'),
        '<p>' . nomen_install_t('The browser scripts live in <code>public/assets/dist/</code>, and the install wizard entry was not found this time.') . '</p>'
        . '<p>' . nomen_install_t('With the release package, extract it again and make sure <code>public/assets/dist/</code> was uploaded in full; for a source deployment run <code>npm ci</code> and <code>npm run build</code> inside <code>frontend/</code>.') . '</p>'
        . '<a class="btn" href="">' . nomen_install_t('Try again') . '</a>');
}
