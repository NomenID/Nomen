<?php
use Nomen\Src\EmailPolicy;
use Nomen\Src\Installer\ConfigFile;
use Nomen\Src\Installer\Database;
use Nomen\Src\Plugins;

session_name('NOMEN_INSTALL');
session_start();

$root = dirname(__DIR__, 2);
require $root . '/src/bootstrap.php';

$configFile = $root . '/config/installed.php';

const WIZARD_STEPS = [
    'env'     => '环境检查',
    'db'      => '数据库',
    'site'    => '站点',
    'admin'   => '管理员',
    'plugins' => '扩展',
    'mail'    => '邮件',
    'review'  => '确认',
];

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['nomen_install_csrf'])) {
        $_SESSION['nomen_install_csrf'] = bin2hex(random_bytes(16));
    }

    return $_SESSION['nomen_install_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . h(csrf_token()) . '">';
}

function csrf_ok(): bool
{
    $token = (string)($_POST['_token'] ?? '');

    return $token !== '' && hash_equals(csrf_token(), $token);
}

function post(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function post_raw(string $key): string
{
    return (string)($_POST[$key] ?? '');
}

function wizard_state(): array
{
    return (array)($_SESSION['nomen_install'] ?? []);
}

function wizard_save(array $state): void
{
    $_SESSION['nomen_install'] = $state;
}

function guess_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function root_path(string $path = ''): string
{
    $base = dirname(__DIR__, 2);

    return $path === '' ? $base : $base . '/' . ltrim($path, '/');
}

function icon(string $name): string
{
    $paths = [
        'env' => '<rect x="4" y="4" width="16" height="16" rx="3.5"/>'
            . '<path d="m8 12.2 2.4 2.4 5-5.2"/>',
        'db' => '<ellipse cx="12" cy="5.5" rx="6.5" ry="2.8"/>'
            . '<path d="M5.5 5.5v6c0 1.55 2.91 2.8 6.5 2.8s6.5-1.25 6.5-2.8v-6"/>'
            . '<path d="M5.5 11.5v6c0 1.55 2.91 2.8 6.5 2.8s6.5-1.25 6.5-2.8v-6"/>',
        'site' => '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/>'
            . '<path d="M3.5 9h17"/>'
            . '<circle cx="12" cy="14.2" r="3.1"/>'
            . '<path d="M8.9 14.2h6.2M12 11.1c.9 1.1.9 5.1 0 6.2M12 11.1c-.9 1.1-.9 5.1 0 6.2"/>',
        'admin' => '<circle cx="12" cy="8" r="3.4"/>'
            . '<path d="M5.5 19.5c.7-3.4 3.3-5.2 6.5-5.2s5.8 1.8 6.5 5.2"/>',
        'plugins' => '<path d="M9.5 4.8a2.2 2.2 0 0 1 4.4 0v1.4h2.6c.55 0 1 .45 1 1v2.6h1.4a2.2 2.2 0 0 1 0 4.4h-1.4v2.6c0 .55-.45 1-1 1h-2.6v1.4a2.2 2.2 0 0 1-4.4 0v-1.4H6.9c-.55 0-1-.45-1-1v-2.6H4.5a2.2 2.2 0 0 1 0-4.4h1.4V7.2c0-.55.45-1 1-1h2.6V4.8z"/>',
        'mail' => '<rect x="3" y="5.5" width="18" height="13" rx="2.5"/>'
            . '<path d="m4 7.5 7 5.2c.6.45 1.4.45 2 0l7-5.2"/>',
        'review' => '<path d="M4.5 6.5h8M4.5 12h5.5M4.5 17.5h4"/>'
            . '<circle cx="16.5" cy="15.5" r="4.2"/>'
            . '<path d="m14.6 15.5 1.4 1.4 2.6-2.8"/>',
        'check' => '<path d="m5 12.5 4.2 4.2L19 6.8"/>',
        'alert' => '<path d="M10.2 4.4 3.4 17a2 2 0 0 0 1.8 3h13.6a2 2 0 0 0 1.8-3L13.8 4.4a2 2 0 0 0-3.6 0Z"/>'
            . '<path d="M12 9v4"/>'
            . '<circle cx="12" cy="16.5" r=".9" fill="currentColor" stroke="none"/>',
    ];

    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? '') . '</svg>';
}

function brand_icon(int $size): string
{
    return '<svg viewBox="0 0 512 512" width="' . $size . '" height="' . $size . '" aria-hidden="true">'
        . '<rect width="512" height="512" rx="112" fill="#0d1117"/>'
        . '<path d="M164 360V158l184 202V158" fill="none" stroke="#e6edf3" stroke-width="36" '
        . 'stroke-linecap="round" stroke-linejoin="round"/>'
        . '<circle cx="348" cy="158" r="26" fill="#7aa2f7"/></svg>';
}

function art(string $name): string
{
    if ($name === 'brand') {
        return '<div class="art art--brand">' . brand_icon(64) . '</div>';
    }

    $class = $name === 'done' ? ' art--done' : ($name === 'alert' ? ' art--alert' : '');

    return '<div class="art' . $class . '">' . icon($name === 'done' ? 'check' : $name) . '</div>';
}

function alert(string $message, string $type = 'error'): string
{
    return '<div class="alert alert--' . $type . '" role="alert">' . $message . '</div>';
}

function check_row(string $label, bool $pass, string $hint, bool $optional = false): string
{
    $state = $pass ? 'is-pass' : ($optional ? 'is-off' : 'is-fail');
    $mark = $pass
        ? '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3.5 8.5 3 3 6-6.5"/></svg>'
        : ($optional
            ? '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 8h8"/></svg>'
            : '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4.5 4.5l7 7M11.5 4.5l-7 7"/></svg>');
    $stateText = $pass ? ($optional ? '可用' : '通过') : ($optional ? '跳过' : '需要处理');

    return '<li class="check ' . $state . '">'
        . '<span class="check__icon" aria-hidden="true">' . $mark . '</span>'
        . '<span class="check__body"><span class="check__label">' . h($label) . '</span>'
        . (!$pass && $hint !== '' ? '<span class="check__hint">' . h($hint) . '</span>' : '')
        . '</span><span class="check__state">' . $stateText . '</span></li>';
}

function page(array $options): void
{
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, max-age=0');

    $stepKey = (string)($options['step'] ?? '');
    $title = (string)($options['title'] ?? '安装');
    $lead = (string)($options['lead'] ?? '');
    $body = (string)($options['body'] ?? '');
    $artName = (string)($options['art'] ?? 'brand');

    $progress = '';
    if (isset(WIZARD_STEPS[$stepKey])) {
        $keys = array_keys(WIZARD_STEPS);
        $index = (int)array_search($stepKey, $keys, true) + 1;
        $total = count($keys);
        $segs = '';
        foreach ($keys as $i => $key) {
            $class = $i + 1 < $index ? ' class="is-done"' : ($i + 1 === $index ? ' class="is-now"' : '');
            $segs .= '<span' . $class . '></span>';
        }
        $progress = '<div class="progress" aria-hidden="true">' . $segs . '</div>'
            . '<div class="progress-meta"><span>第 ' . $index . ' 步，共 ' . $total . ' 步</span>'
            . '<span>' . h(WIZARD_STEPS[$stepKey]) . '</span></div>';
    }

    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<title>' . h(str_contains($title, 'Nomen') ? $title : $title . ' · Nomen') . '</title>'
        . '<link rel="icon" href="/assets/brand/nomen-icon.svg">'
        . '<style>'
        . ':root{color-scheme:dark;--bg:#0d1117;--panel:#161b22;--raised:#1c2129;--border:#30363d;'
        . '--border-soft:#262c34;--text:#e6edf3;--muted:#8b949e;--subtle:#6e7681;--accent:#2f81f7;'
        . '--accent-hover:#1f6feb;--accent-soft:rgba(47,129,247,.12);--accent-line:#8ab4ff;'
        . '--ok:#3fb950;--bad:#f85149;--warn:#d29922;'
        . '--font:"Segoe UI Variable Text",-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC",'
        . '"Hiragino Sans GB","Microsoft YaHei UI","Microsoft YaHei","Noto Sans SC",sans-serif;'
        . '--mono:"Cascadia Mono","SF Mono","JetBrains Mono","Fira Code",ui-monospace,Consolas,monospace;'
        . '--ease:cubic-bezier(.16,1,.3,1)}'
        . '*,*::before,*::after{box-sizing:border-box}'
        . 'html,body{min-height:100%}'
        . 'body{margin:0;background:var(--bg);color:var(--text);font:14.5px/1.6 var(--font);'
        . '-webkit-font-smoothing:antialiased;display:flex;flex-direction:column}'
        . 'body::before{content:"";position:fixed;inset:0;pointer-events:none;'
        . 'background:radial-gradient(640px 420px at 50% -140px,rgba(47,129,247,.13),transparent 70%)}'
        . 'a{color:var(--accent-line);text-decoration:none}'
        . 'a:hover{color:#a8c7ff}'
        . '.topbar{position:relative;z-index:1;display:flex;align-items:center;gap:10px;'
        . 'width:100%;max-width:560px;margin:0 auto;padding:26px 24px 0}'
        . '.topbar svg{width:26px;height:26px;border-radius:7px;display:block;flex:none}'
        . '.topbar b{font-size:14px;font-weight:600;letter-spacing:.01em}'
        . '.topbar span{font-size:12px;color:var(--subtle);padding-left:10px;border-left:1px solid var(--border)}'
        . '.stage{position:relative;z-index:1;flex:1;display:flex;align-items:flex-start;'
        . 'justify-content:center;padding:36px 24px 56px}'
        . '@media(min-height:780px){.stage{align-items:safe center;padding-top:20px;padding-bottom:40px}}'
        . '.panel{position:relative;width:100%;max-width:560px;background:var(--panel);'
        . 'border:1px solid var(--border);border-radius:18px;overflow:hidden;'
        . 'box-shadow:inset 0 1px 0 rgba(255,255,255,.045),0 30px 80px -40px rgba(0,0,0,.9)}'
        . '.panel::before{content:"";position:absolute;top:0;left:20%;right:20%;height:1px;'
        . 'background:linear-gradient(90deg,transparent,rgba(127,179,255,.5),transparent);pointer-events:none}'
        . '.panel__inner{padding:26px 36px 30px}'
        . '@media(max-width:560px){.topbar{padding:18px 16px 0}.stage{padding:22px 14px 40px}'
        . '.panel__inner{padding:22px 20px 24px}.panel{border-radius:16px}}'
        . '.progress{display:flex;gap:5px}'
        . '.progress span{height:3px;flex:1;border-radius:99px;background:#262c34;transition:background .3s}'
        . '.progress span.is-done{background:rgba(47,129,247,.5)}'
        . '.progress span.is-now{background:var(--accent)}'
        . '.progress-meta{display:flex;justify-content:space-between;align-items:baseline;'
        . 'gap:12px;margin:9px 0 24px;font-size:12px;color:var(--subtle)}'
        . '.progress-meta span:last-child{color:var(--muted)}'
        . '.head{text-align:center;margin-bottom:24px}'
        . '.art{width:64px;height:64px;margin:0 auto 18px;border-radius:17px;display:grid;place-items:center;'
        . 'background:linear-gradient(180deg,rgba(122,162,247,.16),rgba(122,162,247,.06));'
        . 'border:1px solid rgba(122,162,247,.22);color:var(--accent-line);'
        . 'box-shadow:0 0 0 6px rgba(47,129,247,.045)}'
        . '.art svg{width:30px;height:30px;display:block}'
        . '.art--brand{background:none;border:0;box-shadow:none}'
        . '.art--brand svg{display:block;width:64px;height:64px;border-radius:17px;'
        . 'box-shadow:0 12px 32px -12px rgba(0,0,0,.9)}'
        . '.art--done{background:rgba(63,185,80,.1);border-color:rgba(63,185,80,.28);color:var(--ok)}'
        . '.art--done svg{width:34px;height:34px}'
        . '.art--alert{background:rgba(248,81,73,.08);border-color:rgba(248,81,73,.28);color:#ff7b72}'
        . '.head h1{margin:0;font-size:23px;line-height:1.3;font-weight:600;letter-spacing:-.02em;'
        . 'text-wrap:balance}'
        . '.head .lead{max-width:44ch;margin:8px auto 0;font-size:13.5px;line-height:1.7;color:var(--muted);'
        . 'text-wrap:pretty}'
        . '.alert{padding:11px 14px;border:1px solid;border-left-width:3px;border-radius:10px;'
        . 'font-size:13px;line-height:1.6;margin:0 0 18px;text-wrap:pretty;white-space:pre-line}'
        . '.alert strong{font-weight:600}'
        . 'code{font-family:var(--mono);font-size:.92em;background:#21262d;border-radius:4px;padding:1px 5px}'
        . '.alert--error{background:rgba(248,81,73,.08);border-color:rgba(248,81,73,.3);color:#ffb4ae}'
        . '.alert--warn{background:rgba(210,153,34,.08);border-color:rgba(210,153,34,.3);color:#e3b341}'
        . '.alert--ok{background:rgba(63,185,80,.08);border-color:rgba(63,185,80,.3);color:#7ee787}'
        . '.prep{list-style:none;margin:0 0 22px;padding:0;border:1px solid var(--border);'
        . 'border-radius:12px;overflow:hidden;background:#0d1117;text-align:left}'
        . '.prep li{display:flex;gap:11px;padding:12px 14px;font-size:13px}'
        . '.prep li+li{border-top:1px solid var(--border-soft)}'
        . '.prep i{flex:none;width:24px;height:24px;margin-top:1px;border-radius:50%;'
        . 'background:var(--accent-soft);color:var(--accent-line);font:600 11px/1 var(--font);'
        . 'display:grid;place-items:center;font-style:normal}'
        . '.prep b{display:block;font-size:13px;font-weight:600;line-height:1.5}'
        . '.prep small{display:block;margin-top:2px;font-size:12.5px;line-height:1.55;color:var(--muted)}'
        . '.checks{list-style:none;margin:0;padding:0;border:1px solid var(--border);'
        . 'border-radius:12px;overflow:hidden;background:#0d1117}'
        . '.check{display:flex;align-items:flex-start;gap:11px;padding:11px 14px;font-size:13px}'
        . '.check+.check{border-top:1px solid var(--border-soft)}'
        . '.check__icon{flex:none;width:20px;height:20px;margin-top:1px;border-radius:50%;'
        . 'display:grid;place-items:center}'
        . '.check__icon svg{width:11px;height:11px;display:block}'
        . '.is-pass .check__icon{background:rgba(63,185,80,.14);color:var(--ok)}'
        . '.is-fail .check__icon{background:rgba(248,81,73,.14);color:#ff7b72}'
        . '.is-off .check__icon{background:#21262d;color:var(--muted)}'
        . '.is-warn .check__icon{background:rgba(210,153,34,.14);color:var(--warn)}'
        . '.check__body{flex:1;min-width:0}'
        . '.check__label{display:block;font-weight:500;line-height:1.5;color:var(--text)}'
        . '.check__hint{display:block;margin-top:2px;font-size:12px;line-height:1.5;color:var(--subtle)}'
        . '.check__state{flex:none;padding-top:2px;font-size:12px;color:var(--subtle)}'
        . '.is-fail .check__state{color:#ff7b72}'
        . '.is-warn .check__state{color:var(--warn)}'
        . '.is-pass .check__state{color:var(--muted)}'
        . '.subhead{margin:24px 0 4px;font-size:13px;font-weight:600}'
        . '.sublabel{margin:0 0 12px;font-size:12.5px;color:var(--subtle)}'
        . '.fields{display:grid;gap:14px}'
        . '.field{min-width:0}'
        . '.field>label{display:block;margin-bottom:7px;font-size:13px;font-weight:500;color:var(--text)}'
        . '.field .hint{margin-top:6px;font-size:12.5px;line-height:1.55;color:var(--subtle)}'
        . '.field-row{display:grid;grid-template-columns:1fr 120px;gap:12px}'
        . '@media(max-width:480px){.field-row{grid-template-columns:1fr}}'
        . '.input{width:100%;height:42px;padding:0 12px;border:1px solid var(--border);border-radius:9px;'
        . 'background:#0d1117;color:var(--text);font:inherit;font-size:14px;'
        . 'transition:border-color .15s,box-shadow .15s}'
        . '.input::placeholder{color:#5b636e}'
        . '.input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-soft)}'
        . 'select.input{appearance:none;padding-right:36px;cursor:pointer;'
        . 'background-image:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 16 16\' fill=\'none\' stroke=\'%238b949e\' stroke-width=\'1.6\' stroke-linecap=\'round\' stroke-linejoin=\'round\'%3E%3Cpath d=\'m4 6 4 4 4-4\'/%3E%3C/svg%3E");'
        . 'background-repeat:no-repeat;background-position:right 12px center;background-size:14px}'
        . 'input:-webkit-autofill{-webkit-text-fill-color:var(--text);'
        . '-webkit-box-shadow:0 0 0 1000px #0d1117 inset}'
        . '.seg{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:18px;padding:4px;'
        . 'border:1px solid var(--border);border-radius:12px;background:#0d1117}'
        . '.seg label{position:relative;display:flex;align-items:center;justify-content:center;height:38px;'
        . 'border-radius:8px;font-size:13px;color:var(--muted);cursor:pointer;transition:.15s}'
        . '.seg input{position:absolute;opacity:0;pointer-events:none}'
        . '.seg label:has(input:checked){background:#21262d;color:var(--text);'
        . 'box-shadow:0 1px 2px rgba(0,0,0,.3)}'
        . '.seg label:has(input:focus-visible){outline:2px solid var(--accent);outline-offset:1px}'
        . '.choice{display:flex;gap:11px;padding:13px 14px;border:1px solid var(--border);border-radius:11px;'
        . 'background:#0d1117;cursor:pointer;transition:border-color .15s,background .15s}'
        . '.choice:hover{border-color:#3d444d;background:#10151b}'
        . '.choice:has(input:checked){border-color:rgba(47,129,247,.45);background:rgba(47,129,247,.06)}'
        . '.choice input{appearance:none;flex:none;position:relative;width:18px;height:18px;margin:2px 0 0;'
        . 'border:1.5px solid #4b5563;border-radius:5px;cursor:pointer;transition:.15s}'
        . '.choice input:checked{background:var(--accent);border-color:var(--accent)}'
        . '.choice input:checked::after{content:"";position:absolute;left:5px;top:1px;width:4px;height:8px;'
        . 'border:solid #fff;border-width:0 2px 2px 0;transform:rotate(45deg)}'
        . '.choice input:focus-visible{outline:2px solid var(--accent);outline-offset:2px}'
        . '.choice strong{display:block;font-size:13px;font-weight:600;line-height:1.5}'
        . '.choice small{display:block;margin-top:2px;font-size:12.5px;line-height:1.55;color:var(--muted)}'
        . 'label.switch{display:flex;align-items:center;gap:10px;font-size:13px;font-weight:400;'
        . 'color:var(--muted);cursor:pointer;user-select:none}'
        . '.switch input{position:absolute;opacity:0;pointer-events:none}'
        . '.switch__track{position:relative;flex:none;width:34px;height:20px;border-radius:99px;'
        . 'background:#30363d;transition:background .2s}'
        . '.switch__track::after{content:"";position:absolute;top:3px;left:3px;width:14px;height:14px;'
        . 'border-radius:50%;background:#8b949e;transition:transform .2s,background .2s}'
        . '.switch input:checked+.switch__track{background:var(--accent)}'
        . '.switch input:checked+.switch__track::after{transform:translateX(14px);background:#fff}'
        . '.switch input:focus-visible+.switch__track{outline:2px solid var(--accent);outline-offset:2px}'
        . '.actions{margin-top:22px}'
        . '.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;width:100%;height:44px;'
        . 'padding:0 18px;border:1px solid transparent;border-radius:10px;font:600 14.5px/1 var(--font);'
        . 'cursor:pointer;text-align:center;transition:background .15s,border-color .15s,opacity .15s}'
        . '.btn:active{transform:translateY(1px)}'
        . '.btn-primary{background:var(--accent);color:#fff}'
        . '.btn-primary:hover{background:var(--accent-hover);color:#fff}'
        . '.btn-primary.is-disabled{opacity:.5;pointer-events:none}'
        . '.btn-ghost{background:var(--raised);border-color:var(--border);color:var(--text)}'
        . '.btn-ghost:hover{border-color:#4b5563;background:#222933;color:var(--text)}'
        . '.btn-lg{height:48px;font-size:15px}'
        . '.btn+.btn{margin-top:10px}'
        . '.btn.is-busy::before{content:"";width:14px;height:14px;border:2px solid rgba(255,255,255,.35);'
        . 'border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}'
        . '@keyframes spin{to{transform:rotate(360deg)}}'
        . '.nav-links{display:flex;justify-content:space-between;align-items:center;gap:12px;'
        . 'margin-top:16px;font-size:12.5px;color:var(--subtle)}'
        . '.nav-links a,.nav-links button{color:var(--subtle)}'
        . '.nav-links a:hover,.nav-links button:hover{color:var(--text)}'
        . '.nav-links button{background:none;border:0;padding:0;font:inherit;cursor:pointer}'
        . '.more{margin-top:20px;padding-top:14px;border-top:1px solid var(--border-soft)}'
        . '.more>summary{list-style:none;display:flex;align-items:center;gap:6px;font-size:12.5px;'
        . 'color:var(--subtle);cursor:pointer;user-select:none}'
        . '.more>summary::-webkit-details-marker{display:none}'
        . '.more>summary::before{content:"+";width:14px;text-align:center;font-size:14px}'
        . '.more[open]>summary::before{content:"−"}'
        . '.more>summary:hover{color:var(--muted)}'
        . '.more .checks,.more .fields{margin-top:12px}'
        . '.code{font:12.5px/1.65 var(--mono);background:#0b0f14;border:1px solid var(--border);'
        . 'border-radius:10px;padding:12px 14px;overflow:auto;color:#c9d1d9;white-space:pre;margin:10px 0 0}'
        . 'textarea.code{width:100%;height:200px;resize:vertical;white-space:pre}'
        . '.summary{display:grid;margin:0;border:1px solid var(--border);border-radius:12px;'
        . 'overflow:hidden;background:#0d1117}'
        . '.summary div{display:grid;grid-template-columns:96px 1fr;gap:14px;padding:10px 14px;font-size:13px}'
        . '.summary div+div{border-top:1px solid var(--border-soft)}'
        . '.summary dt{color:var(--subtle)}'
        . '.summary dd{margin:0;color:var(--text);word-break:break-word}'
        . '.fine{margin:16px 0 0;padding-top:14px;border-top:1px solid var(--border-soft);'
        . 'font-size:11.5px;line-height:1.65;color:var(--subtle);text-wrap:pretty}'
        . '.fine a{color:var(--muted);text-decoration:underline;text-underline-offset:2px}'
        . '.fine a:hover{color:var(--accent-line)}'
        . '[hidden]{display:none!important}'
        . '@keyframes rise{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}'
        . '.panel__inner>*{animation:rise .45s var(--ease) both}'
        . '.panel__inner>*:nth-child(2){animation-delay:.04s}'
        . '.panel__inner>*:nth-child(3){animation-delay:.08s}'
        . '.panel__inner>*:nth-child(4){animation-delay:.12s}'
        . '@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;'
        . 'transition-duration:.01ms!important}}'
        . '</style></head><body>'
        . '<header class="topbar">' . brand_icon(26) . '<b>Nomen</b><span>安装</span></header>'
        . '<main class="stage"><section class="panel"><div class="panel__inner">'
        . $progress
        . '<div class="head">' . art($artName) . '<h1>' . h($title) . '</h1>'
        . ($lead !== '' ? '<p class="lead">' . h($lead) . '</p>' : '')
        . '</div>' . $body . '</div></section></main>'
        . '<script>'
        . '(function(){'
        . 'document.querySelectorAll("form[data-busy]").forEach(function(form){'
        . 'form.addEventListener("submit",function(e){'
        . 'if(form.dataset.sent)return;form.dataset.sent="1";'
        . 'if(e.submitter&&!e.submitter.classList.contains("btn-primary"))return;'
        . 'var btn=form.querySelector("button.btn-primary");'
        . 'if(btn){btn.classList.add("is-busy");btn.style.pointerEvents="none";'
        . 'btn.textContent=form.getAttribute("data-busy")||"处理中…";}'
        . '});});'
        . '})();'
        . '</script></body></html>';
}

function render_start(array $state): void
{
    $resume = isset($state['db']) || isset($state['site']) || isset($state['admin'])
        || isset($state['plugins']) || array_key_exists('smtp', $state);

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

    $body = '<ul class="prep">'
        . '<li><i>1</i><span><b>连接数据库</b><small>MySQL、MariaDB 或 SQLite。先在面板里建好库和用户最省事。</small></span></li>'
        . '<li><i>2</i><span><b>填写站点信息</b><small>站点名、访问地址和界面语言。</small></span></li>'
        . '<li><i>3</i><span><b>建管理员账号</b><small>装完就能用它登录，不需要再收验证邮件。</small></span></li>'
        . '</ul>'
        . '<div class="actions"><a class="btn btn-primary" href="?step=' . h($next) . '">'
        . ($resume ? '继续安装' : '开始安装') . '</a></div>'
        . ($resume ? '<div class="nav-links"><span></span><a href="?step=reset">重新开始</a></div>' : '');

    page([
        'art' => 'brand',
        'title' => '安装 Nomen',
        'lead' => $resume
            ? '上次填到一半，接着来。每一步只问一件事，随时可以回上一步。'
            : '把 Nomen 装到这台服务器上。向导分七步，每一步只问一件事，随时可以回上一步。',
        'body' => $body,
    ]);
}

function rewrite_probe_row(): string
{
    return '<li class="check is-off" id="rewrite-check">'
        . '<span class="check__icon" aria-hidden="true">'
        . '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 8h8"/></svg>'
        . '</span>'
        . '<span class="check__body"><span class="check__label">路由与伪静态</span>'
        . '<span class="check__hint" id="rewrite-hint">正在检测 /__rewrite_probe…</span></span>'
        . '<span class="check__state" id="rewrite-state">检测中</span></li>';
}

function rewrite_probe_help(): string
{
    $snippet = <<<'TXT'
nginx:

location / {
    try_files $uri $uri/ /index.php$is_args$args;
}

apache（public/.htaccess）:

RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]
TXT;

    return '<div class="alert alert--error" id="rewrite-help" hidden>'
        . '<strong>伪静态没生效。</strong>除首页外，<code>/login</code>、<code>/special/version</code> 这些地址都会 404。'
        . '加上下面的规则、reload 之后再点「重新检查」。'
        . '<pre class="code">' . h($snippet) . '</pre></div>'
        . '<script>document.addEventListener("DOMContentLoaded",function(){'
        . 'var row=document.getElementById("rewrite-check");'
        . 'var hint=document.getElementById("rewrite-hint");'
        . 'var state=document.getElementById("rewrite-state");'
        . 'var help=document.getElementById("rewrite-help");'
        . 'var next=document.getElementById("env-next");'
        . 'function ok(){row.className="check is-pass";hint.textContent="/__rewrite_probe 正常";'
        . 'state.textContent="通过";}'
        . 'function bad(){row.className="check is-fail";hint.textContent="除首页外的地址会 404，按下面配好再继续";'
        . 'state.textContent="需要处理";help.hidden=false;'
        . 'if(next&&next.dataset.serverOk==="1"){next.className="btn btn-ghost";'
        . 'next.href="?step=env";next.textContent="重新检查";}}'
        . 'fetch("/__rewrite_probe",{cache:"no-store"}).then(function(r){'
        . 'return r.ok?r.text():"";'
        . '}).then(function(t){t.trim()==="ok"?ok():bad();}).catch(function(){'
        . 'hint.textContent="检测失败，刷新本页再试";state.textContent="未知";});'
        . '});</script>';
}

function render_env(): void
{
    $required = [
        ['PHP ' . PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>='), '需要 8.1 或更高，先让主机商升级 PHP'],
        [
            '数据库扩展',
            extension_loaded('pdo_mysql') || extension_loaded('pdo_sqlite'),
            'pdo_mysql 和 pdo_sqlite 至少装一个',
        ],
        ['扩展 mbstring', extension_loaded('mbstring'), '多语言和字符串处理要用'],
        ['扩展 openssl', extension_loaded('openssl'), 'HTTPS、SMTP 和通行密钥要用'],
        ['Composer 依赖', is_readable(root_path('vendor/autoload.php')), '发布包自带；源码部署先跑 composer install'],
        [
            '数据库表结构',
            is_readable(root_path('database/schema.sql')) && is_readable(root_path('database/schema.sqlite.sql')),
            'database/schema*.sql 不全，重新解压发布包',
        ],
    ];

    $optional = [
        ['扩展 gd', extension_loaded('gd'), '验证码图片和申诉截图缩略图会关掉'],
        ['扩展 fileinfo', extension_loaded('fileinfo'), '头像和应用图标上传会关掉'],
        ['扩展 redis', extension_loaded('redis'), '限流和多设备踢出降级'],
        ['config/ 可写', is_writable(root_path('config')), '装完要手动把 installed.php 传上去'],
    ];

    $all = true;
    $rows = '';
    foreach ($required as [$label, $pass, $hint]) {
        $rows .= check_row($label, $pass, $hint);
        if (!$pass) {
            $all = false;
        }
    }
    $rows .= rewrite_probe_row();

    $optRows = '';
    foreach ($optional as [$label, $pass, $hint]) {
        $optRows .= check_row($label, $pass, $hint, true);
    }

    $body = ($all ? '' : alert('「需要处理」的项目先解决，再刷新本页。', 'error'))
        . '<ul class="checks">' . $rows . '</ul>'
        . rewrite_probe_help()
        . '<div class="actions">'
        . ($all
            ? '<a class="btn btn-primary" id="env-next" data-server-ok="1" href="?step=db">继续</a>'
            : '<a class="btn btn-ghost" href="?step=env">重新检查</a>')
        . '</div>'
        . '<details class="more"><summary>可选项（缺了也能装，对应功能会关掉）</summary>'
        . '<ul class="checks">' . $optRows . '</ul></details>'
        . '<div class="nav-links"><a href="?step=start">← 上一步</a><span></span></div>';

    page([
        'step' => 'env',
        'art' => 'env',
        'title' => '检查环境',
        'lead' => '先确认这台服务器能跑 Nomen。标红的项目解决后再继续。',
        'body' => $body,
    ]);
}

function connect_database(array $db): \Nomen\Src\Db\Connection
{
    if (($db['driver'] ?? 'mysql') === 'sqlite') {
        return Database::connect($db, false);
    }

    try {
        return Database::connect($db, true);
    } catch (\PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) !== 1049) {
            throw $e;
        }
    }

    $name = str_replace('`', '', (string)$db['name']);
    $server = Database::connect($db, false);
    try {
        $server->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        throw new RuntimeException('连上了，但这个账号没有建库权限。先在面板里把库和用户建好，再回来填。');
    }

    return Database::connect($db, true);
}

function render_db(array $state, string $error): void
{
    $db = ($state['db'] ?? []) + [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'nomen',
        'user' => 'nomen',
        'pass' => '',
        'path' => 'database/nomen.sqlite',
    ];
    $driver = (string)($db['driver'] ?? 'mysql');
    $isSqlite = $driver === 'sqlite';

    $body = ($error !== '' ? alert(h($error), 'error') : '')
        . '<form method="post" data-busy="正在连接数据库…">'
        . csrf_field()
        . '<input type="hidden" name="step" value="db">'
        . '<div class="seg">'
        . '<label><input type="radio" name="db_driver" value="mysql"' . (!$isSqlite ? ' checked' : '') . '><span>MySQL / MariaDB</span></label>'
        . '<label><input type="radio" name="db_driver" value="sqlite"' . ($isSqlite ? ' checked' : '') . '><span>SQLite</span></label>'
        . '</div>'
        . '<div id="db-mysql"' . ($isSqlite ? ' hidden' : '') . '><div class="fields">'
        . '<div class="field-row">'
        . '<div class="field"><label for="db_host">主机</label>'
        . '<input class="input" id="db_host" name="db_host" value="' . h($db['host']) . '"></div>'
        . '<div class="field"><label for="db_port">端口</label>'
        . '<input class="input" id="db_port" name="db_port" inputmode="numeric" value="' . h($db['port']) . '"></div>'
        . '</div>'
        . '<div class="field"><label for="db_name">库名</label>'
        . '<input class="input" id="db_name" name="db_name" value="' . h($db['name']) . '"></div>'
        . '<div class="field"><label for="db_user">用户名</label>'
        . '<input class="input" id="db_user" name="db_user" value="' . h($db['user']) . '"></div>'
        . '<div class="field"><label for="db_pass">密码</label>'
        . '<input class="input" id="db_pass" name="db_pass" type="password" value="' . h($db['pass']) . '" autocomplete="off"></div>'
        . '</div></div>'
        . '<div id="db-sqlite"' . (!$isSqlite ? ' hidden' : '') . '><div class="fields">'
        . '<div class="field"><label for="db_path">数据库文件</label>'
        . '<input class="input" id="db_path" name="db_path" value="' . h($db['path']) . '">'
        . '<div class="hint">相对路径按项目根算。文件不存在会自动创建，目录要让 PHP 能写。</div></div>'
        . '</div></div>'
        . '<div class="actions"><button class="btn btn-primary" type="submit">连接并继续</button></div>'
        . '<div class="nav-links"><a href="?step=env">← 上一步</a><span></span></div>'
        . '</form>'
        . '<details class="more"><summary>连不上？</summary><div class="fields">'
        . '<div class="hint">面板建的数据库账号一般只允许本机连接，主机可以试试 <code>localhost</code>。'
        . '库和用户要先在面板里建好；端口不是 3306 就改成实际端口。</div>'
        . '<pre class="code">CREATE DATABASE `nomen` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;</pre>'
        . '</div></details>'
        . '<script>(function(){'
        . 'var mysql=document.getElementById("db-mysql");'
        . 'var sqlite=document.getElementById("db-sqlite");'
        . 'var inputs=document.querySelectorAll("input[name=db_driver]");'
        . 'function sync(){var isSqlite=document.querySelector("input[name=db_driver]:checked").value==="sqlite";'
        . 'mysql.hidden=isSqlite;sqlite.hidden=!isSqlite;}'
        . 'for(var i=0;i<inputs.length;i++){inputs[i].addEventListener("change",sync);}sync();'
        . '})();</script>';

    page([
        'step' => 'db',
        'art' => 'db',
        'title' => '连接数据库',
        'lead' => '账号、日志和应用都放在这一个数据库里。库是空的就行，表由安装器来建。',
        'body' => $body,
    ]);
}

function render_site(array $state, string $error): void
{
    $languages = require root_path('locales/languages.php');
    $site = ($state['site'] ?? []) + [
        'name' => 'Nomen',
        'base_url' => guess_base_url(),
        'copyright' => '',
        'support_email' => '',
        'admin_notify_email' => '',
        'language_default' => 'zh-hans',
        'language_allow_switch' => true,
    ];

    $options = '';
    foreach ($languages as $language) {
        $slug = (string)$language['slug'];
        $options .= '<option value="' . h($slug) . '"'
            . (($site['language_default'] ?? 'zh-hans') === $slug ? ' selected' : '') . '>'
            . h($language['name'] . ' · ' . $language['english']) . '</option>';
    }

    $moreOpen = ($site['copyright'] ?? '') !== '' || ($site['support_email'] ?? '') !== ''
        || ($site['admin_notify_email'] ?? '') !== '';

    $body = ($error !== '' ? alert(h($error), 'error') : '')
        . '<form method="post">'
        . csrf_field()
        . '<input type="hidden" name="step" value="site">'
        . '<div class="fields">'
        . '<div class="field"><label for="site_name">站点名</label>'
        . '<input class="input" id="site_name" name="site_name" maxlength="60" required value="' . h($site['name']) . '">'
        . '<div class="hint">出现在登录页、页面标题和邮件里。</div></div>'
        . '<div class="field"><label for="base_url">访问地址</label>'
        . '<input class="input" id="base_url" name="base_url" type="url" required value="' . h($site['base_url']) . '">'
        . '<div class="hint">带协议，例如 https://id.example.com。装完发信和跳转都用它。</div></div>'
        . '<div class="field"><label for="language_default">默认语言</label>'
        . '<select class="input" id="language_default" name="language_default">' . $options . '</select>'
        . '<div class="hint">浏览器语言匹配不上时用它。</div></div>'
        . '<div class="field"><label class="switch"><input type="checkbox" name="language_allow_switch" value="1"'
        . (!empty($site['language_allow_switch']) ? ' checked' : '') . '><span class="switch__track"></span>'
        . '<span>允许访客切换界面语言</span></label></div>'
        . '</div>'
        . '<details class="more"' . ($moreOpen ? ' open' : '') . '><summary>可选：页脚版权和联系邮箱</summary>'
        . '<div class="fields">'
        . '<div class="field"><label for="site_copyright">页脚版权</label>'
        . '<input class="input" id="site_copyright" name="site_copyright" maxlength="200" placeholder="© 2026 Example" value="' . h($site['copyright']) . '">'
        . '<div class="hint">留空就不显示版权行。</div></div>'
        . '<div class="field"><label for="support_email">对外支持邮箱</label>'
        . '<input class="input" id="support_email" name="support_email" type="email" value="' . h($site['support_email']) . '">'
        . '<div class="hint">登录页、恢复页给访客看的联系地址。留空就不显示。</div></div>'
        . '<div class="field"><label for="admin_notify_email">管理员通知邮箱</label>'
        . '<input class="input" id="admin_notify_email" name="admin_notify_email" type="email" value="' . h($site['admin_notify_email']) . '">'
        . '<div class="hint">新恢复工单的提醒发到这里。留空就不发提醒。</div></div>'
        . '</div></details>'
        . '<div class="actions"><button class="btn btn-primary" type="submit">继续</button></div>'
        . '<div class="nav-links"><a href="?step=db">← 上一步</a><span></span></div>'
        . '</form>';

    page([
        'step' => 'site',
        'art' => 'site',
        'title' => '站点信息',
        'lead' => '这些内容会出现在页面上，装完之后也能改。',
        'body' => $body,
    ]);
}

function render_admin(array $state, string $error): void
{
    $admin = $state['admin'] ?? ['username' => '', 'email' => ''];
    $hasPassword = isset($state['admin']['password']);

    $body = ($error !== '' ? alert(h($error), 'error') : '')
        . '<form method="post">'
        . csrf_field()
        . '<input type="hidden" name="step" value="admin">'
        . '<div class="fields">'
        . '<div class="field"><label for="admin_username">用户名</label>'
        . '<input class="input" id="admin_username" name="admin_username" required minlength="3" maxlength="32"'
        . ' pattern="[a-z0-9_]+" autocomplete="username" value="' . h($admin['username'] ?? '') . '">'
        . '<div class="hint">3-32 位小写字母、数字或下划线。</div></div>'
        . '<div class="field"><label for="admin_email">邮箱</label>'
        . '<input class="input" id="admin_email" name="admin_email" type="email" required'
        . ' autocomplete="email" value="' . h($admin['email'] ?? '') . '">'
        . '<div class="hint">用来找回密码和接收管理员通知，装完就已经是已验证状态。</div></div>'
        . '<div class="field"><label for="admin_password">密码</label>'
        . '<input class="input" id="admin_password" name="admin_password" type="password"'
        . ($hasPassword ? '' : ' required') . ' minlength="8" autocomplete="new-password">'
        . '<div class="hint">' . ($hasPassword ? '留空表示沿用刚才输入的密码。' : '至少 8 位，要有字母和数字。') . '</div></div>'
        . '<div class="field"><label for="admin_password2">再输一次</label>'
        . '<input class="input" id="admin_password2" name="admin_password2" type="password"'
        . ($hasPassword ? '' : ' required') . ' minlength="8" autocomplete="new-password"></div>'
        . '</div>'
        . '<div class="actions"><button class="btn btn-primary" type="submit">继续</button></div>'
        . '<div class="nav-links"><a href="?step=site">← 上一步</a><span></span></div>'
        . '</form>';

    page([
        'step' => 'admin',
        'art' => 'admin',
        'title' => '建管理员账号',
        'lead' => '装完用这个账号登录。以后可以在后台再建别的账号。',
        'body' => $body,
    ]);
}

function render_plugins(array $state): void
{
    $available = Plugins::all();
    $order = ['open-platform' => 0, 'pages' => 1];
    uksort($available, static function (string $a, string $b) use ($order): int {
        return ($order[$a] ?? 100) <=> ($order[$b] ?? 100) ?: strcmp($a, $b);
    });
    $defaults = ['open-platform', 'pages'];
    $selected = $state['plugins'] ?? $defaults;
    $selected = array_values(array_intersect((array)$selected, array_keys($available)));
    $features = (array)($state['features'] ?? []);

    $rows = '';
    foreach ($available as $slug => $manifest) {
        $checked = in_array($slug, $selected, true) ? ' checked' : '';
        $rows .= '<label class="choice"><input type="checkbox" name="plugins[]" value="' . h($slug) . '"' . $checked . '>'
            . '<span><strong>' . h($manifest['name'] ?? $slug) . '</strong>'
            . '<small>' . h($manifest['description'] ?? '') . '</small></span></label>';
    }

    $body = '<form method="post">'
        . csrf_field()
        . '<input type="hidden" name="step" value="plugins">'
        . '<div class="fields">' . $rows . '</div>'
        . '<h2 class="subhead">可选功能</h2>'
        . '<p class="sublabel">默认关闭，以后也能在配置文件里打开。</p>'
        . '<div class="fields">'
        . '<label class="choice"><input type="checkbox" name="feature_guardians" value="1"'
        . (!empty($features['guardians']) ? ' checked' : '') . '><span><strong>家庭与未成年监护</strong>'
        . '<small>家长同意、家长邮箱、家庭页；按同意年龄判断未成年账号。</small></span></label>'
        . '<label class="choice"><input type="checkbox" name="feature_country" value="1"'
        . (!empty($features['country']) ? ' checked' : '') . '><span><strong>注册时填写国家 / 地区</strong>'
        . '<small>按 IP 预填，并按当地同意年龄判断。</small></span></label>'
        . '</div>'
        . '<div class="actions"><button class="btn btn-primary" type="submit">继续</button></div>'
        . '<div class="nav-links"><a href="?step=admin">← 上一步</a><span></span></div>'
        . '</form>';

    page([
        'step' => 'plugins',
        'art' => 'plugins',
        'title' => '选择扩展',
        'lead' => '默认两项够用，装完之后在后台或配置文件里还能改。',
        'body' => $body,
    ]);
}

function render_mail_choice(string $error): void
{
    $body = ($error !== '' ? alert(h($error), 'error') : '')
        . '<form method="post">'
        . csrf_field()
        . '<input type="hidden" name="step" value="mail">'
        . '<div class="actions">'
        . '<button class="btn btn-primary" type="submit" name="action" value="skip">暂时不</button>'
        . '<button class="btn btn-ghost" type="submit" name="action" value="configure">现在就配置</button>'
        . '</div>'
        . '<div class="nav-links"><a href="?step=plugins">← 上一步</a><span></span></div>'
        . '</form>';

    page([
        'step' => 'mail',
        'art' => 'mail',
        'title' => '需要现在配置邮箱么？',
        'lead' => '注册验证码、找回密码和通知都走 SMTP。现在不配也能装，装完在 config/installed.php 里补。',
        'body' => $body,
    ]);
}

function render_mail_form(array $state, string $error): void
{
    $smtp = ($state['smtp'] ?? []) + [
        'host' => '',
        'port' => 587,
        'username' => '',
        'password' => '',
        'from_email' => '',
        'encryption' => 'tls',
    ];
    $encryption = (string)($smtp['encryption'] ?? 'tls');
    $encOptions = '';
    foreach (['tls' => 'STARTTLS（587）', 'ssl' => 'SSL（465）', '' => '不加密'] as $value => $label) {
        $encOptions .= '<option value="' . h($value) . '"' . ($encryption === (string)$value ? ' selected' : '') . '>'
            . h($label) . '</option>';
    }
    $back = (($_GET['form'] ?? '') !== '') ? 'mail' : 'plugins';

    $body = ($error !== '' ? alert(h($error), 'error') : '')
        . '<form method="post" data-busy="正在保存…">'
        . csrf_field()
        . '<input type="hidden" name="step" value="mail">'
        . '<div class="fields">'
        . '<div class="field-row">'
        . '<div class="field"><label for="smtp_host">SMTP 主机</label>'
        . '<input class="input" id="smtp_host" name="smtp_host" placeholder="smtp.example.com" value="' . h($smtp['host']) . '"></div>'
        . '<div class="field"><label for="smtp_port">端口</label>'
        . '<input class="input" id="smtp_port" name="smtp_port" inputmode="numeric" value="' . h($smtp['port']) . '"></div>'
        . '</div>'
        . '<div class="field"><label for="smtp_encryption">加密</label>'
        . '<select class="input" id="smtp_encryption" name="smtp_encryption">' . $encOptions . '</select></div>'
        . '<div class="field"><label for="smtp_username">用户名</label>'
        . '<input class="input" id="smtp_username" name="smtp_username" autocomplete="off" value="' . h($smtp['username']) . '">'
        . '<div class="hint">需要认证的邮箱填上；本机中继可以留空。</div></div>'
        . '<div class="field"><label for="smtp_password">密码</label>'
        . '<input class="input" id="smtp_password" name="smtp_password" type="password" autocomplete="new-password" value="' . h($smtp['password']) . '"></div>'
        . '<div class="field"><label for="smtp_from">发件地址</label>'
        . '<input class="input" id="smtp_from" name="smtp_from" type="email" placeholder="no-reply@example.com" value="' . h($smtp['from_email']) . '"></div>'
        . '</div>'
        . '<div class="actions"><button class="btn btn-primary" type="submit" name="action" value="next">保存并继续</button></div>'
        . '<div class="nav-links"><a href="?step=' . h($back) . '">← 上一步</a>'
        . '<button type="submit" name="action" value="skip">暂时跳过</button></div>'
        . '</form>'
        . '<script>(function(){'
        . 'var enc=document.getElementById("smtp_encryption");'
        . 'var port=document.getElementById("smtp_port");'
        . 'if(!enc||!port)return;'
        . 'enc.addEventListener("change",function(){'
        . 'if(port.value!=="587"&&port.value!=="465")return;'
        . 'if(enc.value==="ssl")port.value="465";'
        . 'else if(enc.value==="tls")port.value="587";'
        . '});})();</script>';

    page([
        'step' => 'mail',
        'art' => 'mail',
        'title' => '配置发信',
        'lead' => '注册验证码、找回密码和通知都走 SMTP。',
        'body' => $body,
    ]);
}

function render_review(array $state): void
{
    $db = (array)($state['db'] ?? []);
    $site = (array)($state['site'] ?? []);
    $admin = (array)($state['admin'] ?? []);
    $smtp = (array)($state['smtp'] ?? []);
    $plugins = (array)($state['plugins'] ?? []);
    $features = (array)($state['features'] ?? []);
    $available = Plugins::all();

    $dbLabel = ($db['driver'] ?? 'mysql') === 'sqlite'
        ? 'SQLite · ' . ($db['path'] ?? '')
        : ($db['user'] ?? '') . '@' . ($db['host'] ?? '') . ':' . ($db['port'] ?? '') . '/' . ($db['name'] ?? '');

    $pluginNames = [];
    foreach ($plugins as $slug) {
        if (isset($available[$slug])) {
            $pluginNames[] = (string)($available[$slug]['name'] ?? $slug);
        }
    }

    $featureNames = array_filter([
        !empty($features['guardians']) ? '家庭与未成年监护' : '',
        !empty($features['country']) ? '注册时填写国家 / 地区' : '',
    ]);

    $languageName = (string)($site['language_default'] ?? 'zh-hans');
    foreach ((require root_path('locales/languages.php')) as $language) {
        if ($language['slug'] === $languageName) {
            $languageName = (string)$language['name'];
            break;
        }
    }

    $summary = '<dl class="summary">'
        . '<div><dt>站点</dt><dd>' . h($site['name'] ?? '') . ' · ' . h($site['base_url'] ?? '') . '</dd></div>'
        . '<div><dt>数据库</dt><dd>' . h($dbLabel) . '</dd></div>'
        . '<div><dt>管理员</dt><dd>' . h(($admin['username'] ?? '') . ' · ' . ($admin['email'] ?? '')) . '</dd></div>'
        . '<div><dt>默认语言</dt><dd>' . h($languageName
            . (!empty($site['language_allow_switch']) ? '（允许访客切换）' : '（固定）')) . '</dd></div>'
        . '<div><dt>扩展</dt><dd>' . h($pluginNames ? implode('、', $pluginNames) : '不启用') . '</dd></div>'
        . '<div><dt>可选功能</dt><dd>' . h($featureNames ? implode('、', $featureNames) : '都不开启') . '</dd></div>'
        . '<div><dt>邮件</dt><dd>' . h(($smtp['host'] ?? '') !== '' ? $smtp['host'] : '暂不配置') . '</dd></div>'
        . '</dl>';

    $body = $summary
        . '<div class="actions"><form method="post" data-busy="正在安装…">'
        . csrf_field()
        . '<input type="hidden" name="step" value="review">'
        . '<button class="btn btn-primary btn-lg" type="submit">开始安装</button>'
        . '</form></div>'
        . '<div class="nav-links"><a href="?step=mail">← 上一步</a><span></span></div>'
        . '<p class="fine">页脚会显示 Powered by Nomen，并链到版本页。这是 GPLv3 第 7 条 (b) 款的署名要求，'
        . '详见 <code>NOTICE</code>。</p>';

    page([
        'step' => 'review',
        'art' => 'review',
        'title' => '确认一下',
        'lead' => '最后看一眼。数据库密码不会显示在这里。',
        'body' => $body,
    ]);
}

function render_done(array $done): void
{
    $base = rtrim((string)($done['base_url'] ?? ''), '/');
    $siteUrl = $base !== '' ? $base : guess_base_url();
    $loginUrl = $siteUrl . '/login?username=' . rawurlencode((string)($done['username'] ?? ''));
    $written = !empty($done['written']);

    $status = '<ul class="checks">'
        . '<li class="check is-pass"><span class="check__icon" aria-hidden="true">'
        . '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3.5 8.5 3 3 6-6.5"/></svg>'
        . '</span><span class="check__body"><span class="check__label">' . h($done['username'] ?? '')
        . '</span><span class="check__hint">' . h($done['email'] ?? '') . '</span></span>'
        . '<span class="check__state">已创建</span></li>'
        . '<li class="check ' . ($written ? 'is-pass' : 'is-fail') . '"><span class="check__icon" aria-hidden="true">'
        . ($written
            ? '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3.5 8.5 3 3 6-6.5"/></svg>'
            : '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4.5 4.5l7 7M11.5 4.5l-7 7"/></svg>')
        . '</span><span class="check__body"><span class="check__label">配置文件</span><span class="check__hint">'
        . ($written ? '已写入 config/installed.php' : 'PHP 没权限写 config/，要手动放进去')
        . '</span></span><span class="check__state">' . ($written ? '完成' : '待处理') . '</span></li>'
        . '<li class="check is-warn"><span class="check__icon" aria-hidden="true">'
        . '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 8h8"/></svg>'
        . '</span><span class="check__body"><span class="check__label">安装目录</span>'
        . '<span class="check__hint">public/nomen-install/ 还留着，确认站点没问题后删掉</span></span>'
        . '<span class="check__state">要删</span></li>'
        . '</ul>';

    $body = ($written
        ? alert('数据库、管理员和配置文件都已就位。', 'ok')
            . '<div class="actions"><a class="btn btn-primary btn-lg" href="' . h($siteUrl) . '">进入网站</a>'
            . '<a class="btn btn-ghost" href="' . h($loginUrl) . '">用管理员账号登录</a></div>'
        : alert('数据库和账号都建好了，但 PHP 没权限写 <code>config/installed.php</code>。'
            . '把下面的内容保存成这个文件放到 config/ 里，站点才会启动。', 'warn')
            . '<div class="actions"><button class="btn btn-primary" type="button" id="dl">下载 installed.php</button></div>'
            . '<textarea class="code" id="config" readonly>' . h((string)($done['content'] ?? '')) . '</textarea>'
            . '<script>document.getElementById("dl").onclick=function(){'
            . 'var b=new Blob([document.getElementById("config").value],{type:"text/plain"});'
            . 'var a=document.createElement("a");a.href=URL.createObjectURL(b);'
            . 'a.download="installed.php";a.click();};</script>'
            . '<p class="fine">这份内容里有数据库密码和密钥，别贴到聊天或工单里。放好文件后重新打开站点首页即可。</p>')
        . '<div style="margin-top:22px">' . $status . '</div>'
        . (!empty($done['plugins_note']) ? alert(h($done['plugins_note']), 'warn') : '')
        . '<p class="fine">第一次登录后可以到后台建 OAuth 应用、改站点设置。'
        . '没填 SMTP 的话，注册验证码和找回密码要等配置好邮件之后才能用。'
        . ($written ? '如果 PHP 进程和网站不是同一个用户，确认站点能读到 config/installed.php。' : '')
        . '</p>';

    page([
        'art' => 'done',
        'title' => '装好了',
        'lead' => '「' . (string)($done['site_name'] ?? 'Nomen') . '」已经跑起来了。',
        'body' => $body,
    ]);
}

function render_installed(string $configFile): void
{
    $config = [];
    try {
        $loaded = require $configFile;
        if (is_array($loaded)) {
            $config = $loaded;
        }
    } catch (Throwable $e) {
        $config = [];
    }

    $base = rtrim((string)($config['app']['base_url'] ?? ''), '/');
    $siteUrl = $base !== '' ? $base : guess_base_url();
    $name = (string)($config['app']['name'] ?? 'Nomen');

    $body = alert('检测到 config/installed.php，这个站点已经装过了。', 'warn')
        . '<div class="actions"><a class="btn btn-primary btn-lg" href="' . h($siteUrl) . '">进入网站</a>'
        . '<a class="btn btn-ghost" href="' . h($siteUrl . '/login') . '">登录</a></div>'
        . '<p class="fine">装完要把 <code>public/nomen-install/</code> 删掉或改名，别把安装向导留在网站上。'
        . '要重装的话，先删掉 <code>config/installed.php</code> 再刷新。</p>';

    page([
        'art' => 'done',
        'title' => '这个站点已经装好了',
        'lead' => '「' . h($name) . '」正在运行。',
        'body' => $body,
    ]);
}

function render_fail(string $message): void
{
    $body = alert(h($message), 'error')
        . '<div class="actions"><a class="btn btn-primary" href="?step=review">回到确认页</a>'
        . '<a class="btn btn-ghost" href="?step=db">换一个空库重试</a></div>'
        . '<p class="fine">如果表已经建了一半，先在面板里把库清空再试。安装过程不会自动回滚已经执行的建表语句。'
        . '也可以 <a href="?step=reset">从头再填一次</a>。</p>';

    page([
        'step' => 'review',
        'art' => 'alert',
        'title' => '安装没有完成',
        'lead' => '数据库那边出了问题。按下面的提示处理后可以重试。',
        'body' => $body,
    ]);
}

function write_plugins_config(string $file, array $enabled): bool
{
    $list = implode(', ', array_map(
        static fn(string $slug): string => "'" . addslashes($slug) . "'",
        $enabled
    ));
    $content = "<?php\nreturn [\n    // plugins/ 下的目录名。装插件不用改代码，改这里就行。\n"
        . "    'enabled' => [" . $list . "],\n];\n";

    return @file_put_contents($file, $content) === strlen($content);
}

// ---------- 已经装过 / 刚装完 ----------

$requestedStep = (string)($_POST['step'] ?? $_GET['step'] ?? 'start');

if ($requestedStep === 'done') {
    $done = $_SESSION['nomen_install_done'] ?? null;
    if (is_array($done)) {
        if (empty($done['written']) && is_file($configFile)) {
            unset($_SESSION['nomen_install_done']);
        } else {
            render_done($done);
            exit;
        }
    }
}

if (is_file($configFile)) {
    render_installed($configFile);
    exit;
}

if ($requestedStep === 'reset') {
    unset($_SESSION['nomen_install'], $_SESSION['nomen_install_done']);
    header('Location: ?step=start');
    exit;
}

$state = wizard_state();

if ($requestedStep === 'start') {
    render_start($state);
    exit;
}

if ($requestedStep === 'env') {
    render_env();
    exit;
}

if ($requestedStep === 'db') {
    $error = '';
    $db = (array)($state['db'] ?? []);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_ok()) {
            $error = '页面过期了，刷新一下再试。';
        } else {
            $driver = post('db_driver', 'mysql');
            if (!in_array($driver, ['mysql', 'sqlite'], true)) {
                $driver = 'mysql';
            }

            if ($driver === 'sqlite') {
                $db = [
                    'driver' => 'sqlite',
                    'path' => post('db_path', 'database/nomen.sqlite'),
                ];

                if (!extension_loaded('pdo_sqlite')) {
                    $error = 'PHP 没装 pdo_sqlite，用不了 SQLite。换个类型，或装好扩展再回来。';
                } elseif ($db['path'] === '') {
                    $error = 'SQLite 要填一个数据库文件路径。';
                } else {
                    try {
                        $pdo = Database::connect($db, false);
                        if (Database::tables($pdo) !== []) {
                            $error = '这个数据库文件里已经有表了，换一个空文件，或先把旧表清掉。';
                        }
                    } catch (RuntimeException $e) {
                        $error = $e->getMessage();
                    } catch (Throwable $e) {
                        $error = '连不上数据库：' . $e->getMessage();
                    }
                }
            } else {
                $db = [
                    'driver' => 'mysql',
                    'host' => post('db_host', '127.0.0.1'),
                    'port' => (int)post('db_port', '3306'),
                    'name' => post('db_name', 'nomen'),
                    'user' => post('db_user', 'nomen'),
                    'pass' => post_raw('db_pass'),
                ];

                if (!extension_loaded('pdo_mysql')) {
                    $error = 'PHP 没装 pdo_mysql，用不了 MySQL / MariaDB。换 SQLite，或装好扩展再回来。';
                } elseif ($db['host'] === '' || $db['name'] === '' || $db['user'] === '') {
                    $error = '主机、库名、用户名都要填。';
                } elseif ($db['port'] < 1 || $db['port'] > 65535) {
                    $error = '端口要在 1-65535 之间。';
                } else {
                    try {
                        $pdo = connect_database($db);
                        if (Database::tables($pdo) !== []) {
                            $error = '这个库里已经有表了，换一个空库，或先把旧表清掉。';
                        }
                    } catch (RuntimeException $e) {
                        $error = $e->getMessage();
                    } catch (Throwable $e) {
                        $error = '连不上数据库：' . $e->getMessage();
                    }
                }
            }

            if ($error === '') {
                $state['db'] = $db;
                wizard_save($state);
                header('Location: ?step=site');
                exit;
            }
        }
    }

    $state['db'] = $db;
    render_db($state, $error);
    exit;
}

if ($requestedStep === 'site') {
    if (!isset($state['db'])) {
        header('Location: ?step=db');
        exit;
    }

    $error = '';
    $languages = require $root . '/locales/languages.php';
    $slugs = array_column($languages, 'slug');
    $site = (array)($state['site'] ?? []);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_ok()) {
            $error = '页面过期了，刷新一下再试。';
        } else {
            $site = [
                'name' => post('site_name', 'Nomen'),
                'base_url' => rtrim(post('base_url', guess_base_url()), '/'),
                'copyright' => post('site_copyright'),
                'support_email' => post('support_email'),
                'admin_notify_email' => post('admin_notify_email'),
                'language_default' => post('language_default', 'zh-hans'),
                'language_allow_switch' => post_raw('language_allow_switch') === '1',
            ];

            $scheme = strtolower((string)parse_url($site['base_url'], PHP_URL_SCHEME));
            if ($site['name'] === '' || mb_strlen($site['name']) > 60) {
                $error = '站点名要有内容，最多 60 个字。';
            } elseif (!filter_var($site['base_url'], FILTER_VALIDATE_URL)
                || !in_array($scheme, ['http', 'https'], true)
                || parse_url($site['base_url'], PHP_URL_HOST) === null) {
                $error = '访问地址要写完整，带 http:// 或 https://。';
            } elseif (parse_url($site['base_url'], PHP_URL_QUERY) !== null
                || parse_url($site['base_url'], PHP_URL_FRAGMENT) !== null) {
                $error = '访问地址不要带查询参数或 #。';
            } elseif (!in_array($site['language_default'], $slugs, true)) {
                $error = '这个语言不在列表里。';
            } elseif ($site['support_email'] !== '' && !filter_var($site['support_email'], FILTER_VALIDATE_EMAIL)) {
                $error = '对外支持邮箱格式不对。';
            } elseif ($site['admin_notify_email'] !== '' && !filter_var($site['admin_notify_email'], FILTER_VALIDATE_EMAIL)) {
                $error = '管理员通知邮箱格式不对。';
            }

            if ($error === '') {
                $state['site'] = $site;
                wizard_save($state);
                header('Location: ?step=admin');
                exit;
            }
        }
    }

    $state['site'] = $site;
    render_site($state, $error);
    exit;
}

if ($requestedStep === 'admin') {
    if (!isset($state['site'])) {
        header('Location: ?step=site');
        exit;
    }

    $error = '';
    $admin = (array)($state['admin'] ?? []);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_ok()) {
            $error = '页面过期了，刷新一下再试。';
        } else {
            $username = post('admin_username');
            $email = post('admin_email');
            $password = post_raw('admin_password');
            $confirm = post_raw('admin_password2');

            if ($password === '' && $confirm === '' && isset($state['admin']['password'])) {
                $password = $confirm = (string)$state['admin']['password'];
            }

            if (!preg_match('/^[a-z0-9_]{3,32}$/', $username)) {
                $error = '用户名要 3-32 位小写字母、数字或下划线。';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = '管理员邮箱格式不对。';
            } elseif (EmailPolicy::isBlocked($email)) {
                $error = EmailPolicy::REJECT_MESSAGE;
            } elseif (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
                $error = '密码至少 8 位，且要有字母和数字。';
            } elseif ($password !== $confirm) {
                $error = '两次密码不一致。';
            }

            if ($error === '') {
                $state['admin'] = [
                    'username' => $username,
                    'email' => $email,
                    'password' => $password,
                ];
                wizard_save($state);
                header('Location: ?step=plugins');
                exit;
            }

            $admin = ['username' => $username, 'email' => $email];
        }
    }

    $state['admin'] = $admin;
    render_admin($state, $error);
    exit;
}

if ($requestedStep === 'plugins') {
    if (!isset($state['admin'])) {
        header('Location: ?step=admin');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_ok()) {
            header('Location: ?step=plugins');
            exit;
        }

        $available = Plugins::all();
        $selected = array_values(array_intersect(
            array_map('strval', (array)($_POST['plugins'] ?? [])),
            array_keys($available)
        ));
        $state['plugins'] = $selected;
        $state['features'] = [
            'guardians' => post_raw('feature_guardians') === '1',
            'country' => post_raw('feature_country') === '1',
        ];
        wizard_save($state);
        header('Location: ?step=mail');
        exit;
    }

    render_plugins($state);
    exit;
}

if ($requestedStep === 'mail') {
    if (!isset($state['plugins'])) {
        header('Location: ?step=plugins');
        exit;
    }

    $error = '';
    $smtp = (array)($state['smtp'] ?? []);
    $showForm = (($_GET['form'] ?? '') !== '') || (($smtp['host'] ?? '') !== '');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action');

        if (!csrf_ok()) {
            $error = '页面过期了，刷新一下再试。';
        } elseif ($action === 'skip') {
            $state['smtp'] = [];
            wizard_save($state);
            header('Location: ?step=review');
            exit;
        } elseif ($action === 'configure') {
            header('Location: ?step=mail&form=1');
            exit;
        } else {
            $showForm = true;
            $smtp = [
                'host' => post('smtp_host'),
                'port' => (int)post('smtp_port', '587'),
                'username' => post('smtp_username'),
                'password' => post_raw('smtp_password'),
                'from_email' => post('smtp_from'),
                'encryption' => post('smtp_encryption', 'tls'),
            ];

            if (!in_array($smtp['encryption'], ['tls', 'ssl', ''], true)) {
                $smtp['encryption'] = 'tls';
            }

            if ($smtp['host'] !== '' && ($smtp['port'] < 1 || $smtp['port'] > 65535)) {
                $error = 'SMTP 端口要在 1-65535 之间。';
            } elseif ($smtp['host'] !== '' && !filter_var($smtp['from_email'], FILTER_VALIDATE_EMAIL)) {
                $error = '填了 SMTP 主机就要给一个发件地址。';
            } elseif ($smtp['host'] === '') {
                $smtp = [];
            }

            if ($error === '') {
                $state['smtp'] = $smtp;
                wizard_save($state);
                header('Location: ?step=review');
                exit;
            }
        }
    }

    $state['smtp'] = $smtp;
    if ($showForm) {
        render_mail_form($state, $error);
    } else {
        render_mail_choice($error);
    }
    exit;
}

if ($requestedStep === 'review') {
    foreach (['db', 'site', 'admin', 'plugins', 'smtp'] as $key) {
        if (!array_key_exists($key, $state)) {
            $next = match ($key) {
                'db' => 'db',
                'site' => 'site',
                'admin' => 'admin',
                'plugins' => 'plugins',
                'smtp' => 'mail',
            };
            header('Location: ?step=' . $next);
            exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        render_review($state);
        exit;
    }

    if (!csrf_ok()) {
        render_fail('页面过期了，退回确认页重新提交。');
        exit;
    }

    $db = (array)$state['db'];
    $site = (array)$state['site'];
    $admin = (array)$state['admin'];
    $smtp = (array)($state['smtp'] ?? []);
    $plugins = array_values(array_intersect(
        (array)($state['plugins'] ?? []),
        array_keys(Plugins::all())
    ));
    $features = (array)($state['features'] ?? []);
    $pepper = bin2hex(random_bytes(32));

    try {
        $pdo = connect_database($db);
        if (Database::tables($pdo) !== []) {
            render_fail('这个库里已经有表了（可能有人同时装过两次）。换一个空库再来。');
            exit;
        }

        Database::importSchema($pdo, $pdo->dialect()->schemaFile($root));
        Database::markMigrationsApplied($pdo, $root . '/database/migrations');
        Database::createAdmin($pdo, $admin['username'], $admin['email'], $admin['password'], $pepper);
    } catch (RuntimeException $e) {
        render_fail($e->getMessage());
        exit;
    } catch (Throwable $e) {
        render_fail('安装失败：' . $e->getMessage());
        exit;
    }

    $content = ConfigFile::render([
        'app' => [
            'name' => $site['name'],
            'base_url' => $site['base_url'],
            'copyright' => $site['copyright'] ?? '',
            'support_email' => $site['support_email'] ?? '',
            'admin_notify_email' => $site['admin_notify_email'] ?? '',
            'language_default' => $site['language_default'] ?? 'zh-hans',
            'language_allow_switch' => (bool)($site['language_allow_switch'] ?? true),
            'features' => [
                'guardians' => (bool)($features['guardians'] ?? false),
                'country' => (bool)($features['country'] ?? false),
            ],
        ],
        'pepper' => $pepper,
        'secret' => bin2hex(random_bytes(32)),
        'db' => $db,
        'redis' => ['host' => '127.0.0.1', 'port' => 6379, 'db' => 1, 'password' => ''],
        'smtp' => $smtp,
    ]);

    $bytes = @file_put_contents($configFile, $content);
    $written = $bytes === strlen($content);
    if (!$written && is_file($configFile)) {
        @unlink($configFile);
    }
    if ($written) {
        @chmod($configFile, 0640);
    }

    $pluginsWritten = write_plugins_config($root . '/config/plugins.php', $plugins);
    $pluginsNote = $pluginsWritten
        ? ''
        : 'config/plugins.php 没写进去，装完手动把 enabled 改成：' . implode(', ', $plugins);

    $_SESSION['nomen_install_done'] = [
        'site_name' => $site['name'],
        'base_url' => $site['base_url'],
        'username' => $admin['username'],
        'email' => $admin['email'],
        'written' => $written,
        'content' => $written ? '' : $content,
        'plugins_note' => $pluginsNote,
    ];
    unset($_SESSION['nomen_install']);

    if ($written) {
        session_regenerate_id(true);
    }

    render_done($_SESSION['nomen_install_done']);
    exit;
}

header('Location: ?step=start');
