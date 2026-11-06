<?php
use Nomen\Src\EmailPolicy;
use Nomen\Src\Installer\ConfigFile;
use Nomen\Src\Installer\Database;

session_start();

$root = dirname(__DIR__, 2);
require $root . '/src/bootstrap.php';

$configFile = $root . '/config/installed.php';

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function page(string $title, string $body, int $step = 0): void
{
    $steps = [1 => '环境检查', 2 => '数据库', 3 => '站点', 4 => '扩展', 5 => '安装'];
    $bar = '';
    foreach ($steps as $n => $label) {
        $state = $step === $n ? ' current' : ($step > $n ? ' done' : '');
        $bar .= '<span class="step' . $state . '">' . $n . '. ' . h($label) . '</span>';
    }

    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . h($title) . ' · Nomen</title><style>'
        . ':root{color-scheme:dark}body{margin:0;background:#0d1117;color:#e6edf3;'
        . 'font:15px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif}'
        . '.wrap{max-width:720px;margin:0 auto;padding:56px 24px 80px}'
        . 'h1{font-size:22px;margin:0 0 8px}h2{font-size:16px;margin:28px 0 12px}'
        . '.muted{color:#8b949e}.steps{display:flex;gap:16px;flex-wrap:wrap;margin:20px 0 32px;font-size:13px}'
        . '.step{color:#6e7681}.step.current{color:#e6edf3}.step.done{color:#7aa2f7}'
        . 'label{display:block;font-size:13px;color:#8b949e;margin:14px 0 6px}'
        . 'input{width:100%;box-sizing:border-box;padding:9px 12px;border-radius:6px;'
        . 'border:1px solid #30363d;background:#0d1117;color:#e6edf3;font:inherit}'
        . 'select{width:100%;box-sizing:border-box;padding:9px 12px;border-radius:6px;'
        . 'border:1px solid #30363d;background:#0d1117;color:#e6edf3;font:inherit}'
        . 'input:focus{outline:2px solid #1f6feb;outline-offset:-1px}'
        . '.row{display:flex;gap:12px}.row>div{flex:1}'
        . 'button{margin-top:24px;padding:9px 18px;border-radius:6px;border:1px solid #30363d;'
        . 'background:#21262d;color:#e6edf3;font:inherit;cursor:pointer}'
        . 'button.primary{background:#238636;border-color:#238636}'
        . '.ok{color:#3fb950}.bad{color:#f85149}'
        . '.note{padding:12px 14px;border:1px solid #30363d;border-radius:6px;margin:16px 0;font-size:14px}'
        . '.err{border-color:#f85149}.warn{border-color:#9e6a03}'
        . 'table{border-collapse:collapse;width:100%;font-size:14px}td{padding:6px 0;border-bottom:1px solid #21262d}'
        . 'code{background:#161b22;padding:1px 5px;border-radius:4px;font-size:13px}'
        . 'textarea{width:100%;height:220px;font:12px/1.5 ui-monospace,Consolas,monospace;'
        . 'background:#0d1117;color:#e6edf3;border:1px solid #30363d;border-radius:6px;padding:10px}'
        . 'a{color:#58a6ff}'
        . '</style></head><body><div class="wrap">'
        . '<h1>Nomen 安装</h1><div class="muted">装完这个向导会给你一份 <code>config/installed.php</code>，放到项目里就能用。</div>'
        . $bar . $body . '</div></body></html>';
}

function fail(string $message): void
{
    page('出错了', '<div class="note err">' . h($message) . '</div><p><a href="?step=1">回到第一步</a></p>', 0);
    exit;
}

function post(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function post_raw(string $key): string
{
    return (string)($_POST[$key] ?? '');
}

function rewrite_check_html(): string
{
    // 单引号：$uri / $is_args / $args 是要展示给用户看的 nginx 配置原文，不能让 PHP 插值
    $snippet = 'nginx:

location / {
    try_files $uri $uri/ /index.php$is_args$args;
}

apache（public/.htaccess）:
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]';

    return '<div class="note" id="rewrite-check">伪静态：检测中…</div>'
        . '<div id="rewrite-help" style="display:none"><div class="note err">'
        . '<p>伪静态没生效。除首页外，<code>/login</code>、<code>/special/version</code> 这些地址都会 404。'
        . '加下面任意一段，重启或 reload 后刷新本页：</p>'
        . '<textarea readonly style="height:150px">' . h($snippet) . '</textarea></div></div>'
        . '<script>fetch("/__rewrite_probe").then(function(r){'
        . 'var el=document.getElementById("rewrite-check");'
        . 'if(r.ok){el.textContent="伪静态：可用";el.className="note ok";}'
        . 'else{el.textContent="伪静态：不可用";el.className="note err";'
        . 'document.getElementById("rewrite-help").style.display="block";}'
        . '}).catch(function(){document.getElementById("rewrite-check").textContent="伪静态：检测失败";});</script>';
}

function render_done(string $content, string $username, bool $written, string $pluginsNote = ''): void
{
    $download = '<p><button type="button" id="dl">下载 installed.php</button></p>'
        . '<textarea id="config" readonly>' . h($content) . '</textarea>'
        . '<script>document.getElementById("dl").onclick=function(){'
        . 'var b=new Blob([document.getElementById("config").value],{type:"text/plain"});'
        . 'var a=document.createElement("a");a.href=URL.createObjectURL(b);a.download="installed.php";a.click();};</script>';

    page('装好了', rewrite_check_html() . '<div class="note">数据库导完了，管理员也建好了。</div>'
        . '<div class="note warn"><strong>先删 <code>public/nomen-install/</code> 这个目录，再用别的都行。</strong><br>'
        . '它留着，谁都能重新打开安装向导。删掉之后本页就不会再出现。</div>'
        . ($written
            ? '<p>配置文件已经写到 <code>config/installed.php</code>。如果 PHP 进程和网站不是同一个用户，确认一下网站能读到它。</p>'
            : '<div class="note warn">PHP 进程没有写 <code>config/</code> 的权限。把下面的内容保存成 '
                . '<code>config/installed.php</code> 传上去：</div>' . $download)
        . $pluginsNote
        . '<h2>接下来</h2>'
        . '<p>1. 打开站点，用 <code>' . h($username) . '</code> 登录。</p>', 5);
}

function write_plugins_config(string $file, array $enabled): bool
{
    $list = implode(', ', array_map(
        static fn(string $slug): string => "'" . addslashes($slug) . "'",
        $enabled
    ));
    $content = "<?php\nreturn [\n    // plugins/ 下的目录名。装插件不用改代码，改这里就行。\n"
        . "    'enabled' => [" . $list . "],\n];\n";

    return @file_put_contents($file, $content) !== false;
}

function guess_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

if (is_file($configFile)) {
    page('已经装好了', rewrite_check_html() . '<div class="note">检测到 <code>config/installed.php</code>，这个站点已经装过。'
        . '要重装就先删掉它再刷新。</div>'
        . '<div class="note warn">装完之后请把 <code>public/nomen-install/</code> 删掉或改名，别把安装向导留在网站上。</div>', 0);
    exit;
}

// 上一次已经装完、但配置文件没落地（PHP 没写权限）：直接把内容再给一次
if (!empty($_SESSION['nomen_install_done'])) {
    $done = $_SESSION['nomen_install_done'];
    render_done((string)$done['content'], (string)$done['username'], false);
    exit;
}

$step = (int)($_POST['step'] ?? $_GET['step'] ?? 1);
$wizard = $_SESSION['nomen_install'] ?? ['db' => [], 'site' => [], 'smtp' => []];
$error = '';

// ---------- 第 1 步：环境检查 ----------
if ($step === 1) {
    $required = [
        ['PHP ' . PHP_VERSION, version_compare(PHP_VERSION, '8.1.0', '>='), '需要 8.1 或更高'],
        ['扩展 pdo_mysql 或 pdo_sqlite', extension_loaded('pdo_mysql') || extension_loaded('pdo_sqlite'), '连数据库要用，至少装一个'],
        ['扩展 mbstring', extension_loaded('mbstring'), '多语言和字符串处理要用'],
        ['扩展 openssl', extension_loaded('openssl'), 'HTTPS、SMTP、通行密钥要用'],
        ['database/schema.sql', is_readable($root . '/database/schema.sql'), '文件缺失'],
        ['database/schema.sqlite.sql', is_readable($root . '/database/schema.sqlite.sql'), '文件缺失'],
    ];
    $optional = [
        ['扩展 gd', extension_loaded('gd'), '不会出验证码图片，申诉截图的缩略图也不做'],
        ['扩展 fileinfo', extension_loaded('fileinfo'), '头像和应用图标的上传会关掉'],
        ['扩展 redis', extension_loaded('redis'), '限流和多设备踢出降级'],
        ['config/ 可写', is_writable($root . '/config'), '装完把 installed.php 自己传上去'],
    ];

    $all = true;
    $rows = '';
    foreach ($required as [$label, $pass, $hint]) {
        $rows .= '<tr><td>' . h($label) . '</td><td class="' . ($pass ? 'ok' : 'bad') . '">'
            . ($pass ? '通过' : h($hint)) . '</td></tr>';
        if (!$pass) {
            $all = false;
        }
    }

    $optRows = '';
    foreach ($optional as [$label, $pass, $hint]) {
        $optRows .= '<tr><td>' . h($label) . '</td><td class="' . ($pass ? 'ok' : 'muted') . '">'
            . ($pass ? '有' : h($hint)) . '</td></tr>';
    }

    page('环境检查', rewrite_check_html() . '<h2>必需</h2><table>' . $rows . '</table>'
        . '<h2>可选（缺了也能装，对应功能会关掉）</h2><table>' . $optRows . '</table>'
        . ($all
            ? '<p><form method="get"><input type="hidden" name="step" value="2"><button class="primary" type="submit">下一步：数据库</button></form></p>'
            : '<div class="note err">「必需」里标红的先解决，再刷新本页。</div>'), 1);
    exit;
}

// ---------- 第 2 步：数据库 ----------
if ($step === 2) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $driver = post('db_driver', 'mysql');
        if (!in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            $driver = 'mysql';
        }

        if ($driver === 'sqlite') {
            $db = [
                'driver' => 'sqlite',
                'path' => post('db_path', 'database/nomen.sqlite'),
            ];

            if ($db['path'] === '') {
                $error = 'SQLite 要填数据库文件路径。';
            } else {
                try {
                    $pdo = Database::connect($db);
                    if (Database::tables($pdo) !== []) {
                        $error = '这个库里已经有表了，换一个空库，或者先把旧表清掉。';
                    }
                } catch (Throwable $e) {
                    $error = '连不上数据库：' . $e->getMessage();
                }
            }
        } else {
            $db = [
                'driver' => $driver === 'mariadb' ? 'mariadb' : 'mysql',
                'host' => post('db_host', '127.0.0.1'),
                'port' => (int)post('db_port', '3306'),
                'name' => post('db_name', 'nomen'),
                'user' => post('db_user', 'nomen'),
                'pass' => post_raw('db_pass'),
            ];

            if ($db['host'] === '' || $db['name'] === '' || $db['user'] === '') {
                $error = '主机、库名、用户名都要填。';
            } else {
                try {
                    $pdo = Database::connect($db, false);
                    $name = str_replace('`', '', $db['name']);
                    try {
                        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    } catch (Throwable $e) {
                        $error = '连上了，但没权限建库。先在面板里建好库和用户，再回来填：'
                            . 'CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
                    }

                    if ($error === '') {
                        $pdo->exec("USE `$name`");
                        if (Database::tables($pdo) !== []) {
                            $error = '这个库里已经有表了，换一个空库，或者先把旧表清掉。';
                        }
                    }
                } catch (Throwable $e) {
                    $error = '连不上数据库：' . $e->getMessage();
                }
            }
        }

        if ($error === '') {
            $_SESSION['nomen_install']['db'] = $db;
            header('Location: ?step=3');
            exit;
        }
    } else {
        $db = $wizard['db'] + [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'name' => 'nomen',
            'user' => 'nomen',
            'pass' => '',
            'path' => 'database/nomen.sqlite',
        ];
    }

    page('数据库', ($error !== '' ? '<div class="note err">' . h($error) . '</div>' : '')
        . '<div class="note warn">库要是不存在，账号得有建库权限；面板建的账号一般没有，先在面板里把库和用户建好。'
        . 'SQLite 不用建库，填一个文件路径就行。</div>'
        . '<form method="post">'
        . '<input type="hidden" name="step" value="2">'
        . '<label>类型</label><select name="db_driver" id="db-driver">'
        . '<option value="mysql"' . (($db['driver'] ?? 'mysql') === 'mysql' ? ' selected' : '') . '>MySQL / MariaDB</option>'
        . '<option value="sqlite"' . (($db['driver'] ?? '') === 'sqlite' ? ' selected' : '') . '>SQLite</option>'
        . '</select>'
        . '<div id="mysql-fields"><div class="row"><div><label>主机</label><input name="db_host" value="' . h($db['host'] ?? '127.0.0.1') . '"></div>'
        . '<div><label>端口</label><input name="db_port" value="' . h($db['port'] ?? 3306) . '"></div></div>'
        . '<label>库名</label><input name="db_name" value="' . h($db['name'] ?? 'nomen') . '">'
        . '<label>用户名</label><input name="db_user" value="' . h($db['user'] ?? 'nomen') . '">'
        . '<label>密码</label><input type="password" name="db_pass" value="' . h($db['pass'] ?? '') . '"></div>'
        . '<div id="sqlite-fields"><label>数据库文件</label>'
        . '<input name="db_path" value="' . h($db['path'] ?? 'database/nomen.sqlite') . '">'
        . '<div class="muted" style="font-size:13px;margin-top:6px">相对路径按项目根算。文件不在就自动建，目录要 PHP 能写。</div></div>'
        . '<button class="primary" type="submit">测试并继续</button>'
        . '</form>'
        . '<script>(function(){var d=document.getElementById("db-driver");'
        . 'function t(){var s=d.value==="sqlite";'
        . 'document.getElementById("mysql-fields").style.display=s?"none":"";'
        . 'document.getElementById("sqlite-fields").style.display=s?"":"none";}'
        . 'd.addEventListener("change",t);t();})();</script>', 2);
    exit;
}

// ---------- 第 3 步：站点与管理员 ----------
if ($step === 3) {
    $langs = require $root . '/locales/languages.php';
    $langSlugs = array_column($langs, 'slug');
    $langNames = array_column($langs, 'name', 'slug');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $site = [
            'name' => post('site_name', 'Nomen'),
            'base_url' => rtrim(post('base_url', guess_base_url()), '/'),
            'copyright' => post('site_copyright'),
            'support_email' => post('support_email'),
            'admin_notify_email' => post('admin_notify_email'),
            'language_default' => post('language_default', 'zh-hans'),
            'language_allow_switch' => post_raw('language_allow_switch') === '1',
            'features' => [
                'guardians' => post_raw('feature_guardians') === '1',
                'country' => post_raw('feature_country') === '1',
            ],
        ];
        if (!in_array($site['language_default'], $langSlugs, true)) {
            $site['language_default'] = 'zh-hans';
        }
        $smtp = [
            'host' => post('smtp_host'),
            'port' => (int)post('smtp_port', '587'),
            'username' => post('smtp_username'),
            'password' => post_raw('smtp_password'),
            'from_email' => post('smtp_from'),
            'encryption' => 'tls',
        ];
        $admin = [
            'username' => post('admin_username'),
            'email' => post('admin_email'),
            'password' => post_raw('admin_password'),
            'confirm' => post_raw('admin_password2'),
        ];

        if (!filter_var($site['base_url'], FILTER_VALIDATE_URL)) {
            $error = '访问地址不像个 URL。';
        } elseif (!preg_match('/^[a-z0-9_]{3,32}$/', $admin['username'])) {
            $error = '用户名要 3-32 位小写字母、数字或下划线。';
        } elseif (!filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
            $error = '管理员邮箱格式不对。';
        } elseif (EmailPolicy::isBlocked($admin['email'])) {
            $error = EmailPolicy::REJECT_MESSAGE;
        } elseif ($admin['password'] !== $admin['confirm']) {
            $error = '两次密码不一致。';
        } elseif (strlen($admin['password']) < 8 || !preg_match('/[A-Za-z]/', $admin['password']) || !preg_match('/\d/', $admin['password'])) {
            $error = '密码至少 8 位，且要有字母和数字。';
        }

        if ($error === '') {
            $_SESSION['nomen_install']['site'] = $site;
            $_SESSION['nomen_install']['smtp'] = $smtp;
            $_SESSION['nomen_install']['admin'] = [
                'username' => $admin['username'],
                'email' => $admin['email'],
                'password' => $admin['password'],
            ];
            header('Location: ?step=4');
            exit;
        }
    } else {        $site = ($wizard['site'] ?? []) + ['name' => 'Nomen', 'base_url' => guess_base_url(), 'copyright' => '', 'support_email' => '', 'admin_notify_email' => '', 'language_default' => 'zh-hans', 'language_allow_switch' => true, 'features' => ['guardians' => false, 'country' => false]];
        $admin = $wizard['admin'] ?? ['username' => '', 'email' => '', 'password' => ''];
        $smtp = $wizard['smtp'] ?? ['host' => '', 'port' => 587, 'username' => '', 'password' => '', 'from_email' => ''];
    }

    $langOptions = '';
    foreach ($langs as $lang) {
        $selected = ($site['language_default'] ?? 'zh-hans') === $lang['slug'] ? ' selected' : '';
        $langOptions .= '<option value="' . h($lang['slug']) . '"' . $selected . '>' . h($lang['name'] . ' · ' . $lang['english']) . '</option>';
    }

    page('站点与管理员', ($error !== '' ? '<div class="note err">' . h($error) . '</div>' : '')
        . '<form method="post"><input type="hidden" name="step" value="3">'
        . '<label>站点名</label><input name="site_name" value="' . h($site['name'] ?? 'Nomen') . '">'
        . '<label>访问地址</label><input name="base_url" value="' . h($site['base_url'] ?? guess_base_url()) . '">'
        . '<label>页脚版权（可留空）</label><input name="site_copyright" placeholder="© 2026-Present Example. All rights reserved." value="' . h($site['copyright'] ?? '') . '">'
        . '<div class="row"><div><label>支持邮箱（可留空）</label><input name="support_email" value="' . h($site['support_email'] ?? '') . '"></div>'
        . '<div><label>管理员通知邮箱（可留空）</label><input name="admin_notify_email" value="' . h($site['admin_notify_email'] ?? '') . '"></div></div>'
        . '<label>默认语言（浏览器语言匹配不上时用它）</label><select name="language_default">' . $langOptions . '</select>'
        . '<label style="font-weight:normal;color:#e6edf3;margin-top:14px">'
        . '<input type="checkbox" name="language_allow_switch" value="1"' . (($site['language_allow_switch'] ?? true) ? ' checked' : '') . ' style="width:auto;margin-right:8px">'
        . '允许用户自己切换界面语言（关掉就全站固定默认语言）</label>'
        . '<h2>可选功能（默认关，以后也能改）</h2>'
        . '<label style="font-weight:normal;color:#e6edf3;margin-top:14px">'
        . '<input type="checkbox" name="feature_guardians" value="1"' . (!empty($site['features']['guardians']) ? ' checked' : '') . ' style="width:auto;margin-right:8px">'
        . '家庭与未成年监护（家长同意、家长邮箱、家庭页；不需要就别开）</label>'
        . '<label style="font-weight:normal;color:#e6edf3;margin-top:10px">'
        . '<input type="checkbox" name="feature_country" value="1"' . (!empty($site['features']['country']) ? ' checked' : '') . ' style="width:auto;margin-right:8px">'
        . '注册时填写国家 / 地区（含按 IP 预填，不需要就别开）</label>'
        . '<h2>管理员账号</h2>'
        . '<label>用户名</label><input name="admin_username" value="' . h($admin['username'] ?? '') . '">'
        . '<label>邮箱</label><input name="admin_email" value="' . h($admin['email'] ?? '') . '">'
        . '<div class="row"><div><label>密码（至少 8 位，含字母和数字）</label><input type="password" name="admin_password"></div>'
        . '<div><label>再输一次</label><input type="password" name="admin_password2"></div></div>'
        . '<h2>邮件（可留空，以后在配置文件里补）</h2>'
        . '<div class="row"><div><label>SMTP 主机</label><input name="smtp_host" value="' . h($smtp['host'] ?? '') . '"></div>'
        . '<div><label>端口</label><input name="smtp_port" value="' . h($smtp['port'] ?? 587) . '"></div></div>'
        . '<div class="row"><div><label>SMTP 用户名</label><input name="smtp_username" value="' . h($smtp['username'] ?? '') . '"></div>'
        . '<div><label>SMTP 密码</label><input type="password" name="smtp_password" value="' . h($smtp['password'] ?? '') . '"></div></div>'
        . '<label>发件地址</label><input name="smtp_from" value="' . h($smtp['from_email'] ?? '') . '">'
        . '<button class="primary" type="submit">下一步：扩展</button></form>', 3);
    exit;
}

// ---------- 第 4 步：扩展 ----------
if ($step === 4) {
    $available = \Nomen\Src\Plugins::all();
    $defaults = ['open-platform', 'pages'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $selected = array_values(array_intersect(
            array_map('strval', (array)($_POST['plugins'] ?? [])),
            array_keys($available)
        ));
        $_SESSION['nomen_install']['plugins'] = $selected;
        header('Location: ?step=5');
        exit;
    }

    $selected = $wizard['plugins'] ?? $defaults;
    $selected = array_values(array_intersect((array)$selected, array_keys($available)));

    $rows = '';
    foreach ($available as $slug => $manifest) {
        $checked = in_array($slug, $selected, true) ? ' checked' : '';
        $rows .= '<tr><td style="width:28px">'
            . '<input type="checkbox" name="plugins[]" value="' . h($slug) . '"' . $checked . ' style="width:auto">'
            . '</td><td><strong>' . h($manifest['name'] ?? $slug) . '</strong>'
            . '<div class="muted" style="font-size:13px">' . h($manifest['description'] ?? '') . '</div></td>'
            . '<td class="muted" style="white-space:nowrap">' . h($manifest['version'] ?? '') . '</td></tr>';
    }

    page('扩展', '<div class="note">装完以后还能在 <code>config/plugins.php</code> 里改，后台也能看到各插件的状态。</div>'
        . '<form method="post"><input type="hidden" name="step" value="4">'
        . '<table>' . $rows . '</table>'
        . '<button class="primary" type="submit">下一步：确认</button></form>', 4);
    exit;
}

// ---------- 第 5 步：执行 ----------
if ($step === 5) {
    $db = $wizard['db'] ?? [];
    $site = $wizard['site'] ?? [];
    $admin = $wizard['admin'] ?? [];
    $smtp = $wizard['smtp'] ?? [];
    $plugins = array_values(array_intersect(
        (array)($wizard['plugins'] ?? []),
        array_keys(\Nomen\Src\Plugins::all())
    ));

    if ($db === [] || $site === [] || $admin === []) {
        header('Location: ?step=1');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $dbLabel = ($db['driver'] ?? 'mysql') === 'sqlite'
            ? 'SQLite · ' . $db['path']
            : $db['user'] . '@' . $db['host'] . '/' . $db['name'];

        $pluginNames = [];
        foreach ($plugins as $slug) {
            $pluginNames[] = (string)(\Nomen\Src\Plugins::all()[$slug]['name'] ?? $slug);
        }

        page('确认', '<table>'
            . '<tr><td>站点名</td><td>' . h($site['name']) . '</td></tr>'
            . '<tr><td>访问地址</td><td>' . h($site['base_url']) . '</td></tr>'
            . '<tr><td>数据库</td><td>' . h($dbLabel) . '</td></tr>'
            . '<tr><td>默认语言</td><td>' . h(($site['language_default'] ?? 'zh-hans')
                . (($site['language_allow_switch'] ?? true) ? '（允许用户切换）' : '（不允许切换）')) . '</td></tr>'
            . '<tr><td>可选功能</td><td>' . h(implode('、', array_filter([
                !empty($site['features']['guardians']) ? '家庭与未成年监护' : '',
                !empty($site['features']['country']) ? '注册时填写国家 / 地区' : '',
            ])) ?: '都不开启') . '</td></tr>'
            . '<tr><td>扩展</td><td>' . h($pluginNames ? implode('、', $pluginNames) : '不启用') . '</td></tr>'
            . '<tr><td>管理员</td><td>' . h($admin['username'] . ' <' . $admin['email'] . '>') . '</td></tr>'
            . '</table>'
            . '<p class="muted">页脚会显示 Powered by Nomen，链到 /special/version。这是 GPLv3 第 7 条 (b) 款的署名条款，见 NOTICE。</p>'
            . '<form method="post"><input type="hidden" name="step" value="5">'
            . '<button class="primary" type="submit">开始安装</button></form>', 5);
        exit;
    }

    $pepper = bin2hex(random_bytes(32));

    try {
        $pdo = Database::connect($db, true);
        if (Database::tables($pdo) !== []) {
            fail('这个库里已经有表了（可能有人同时装了两次），换一个空库重来。');
        }

        Database::importSchema($pdo, $pdo->dialect()->schemaFile($root));
        Database::markMigrationsApplied($pdo, $root . '/database/migrations');
        Database::createAdmin($pdo, $admin['username'], $admin['email'], $admin['password'], $pepper);
    } catch (Throwable $e) {
        fail('安装失败：' . $e->getMessage() . '。如果表已经建了一半，先把库清空再试。');
    }

    $content = ConfigFile::render([
        'app' => [
            'name' => $site['name'],
            'base_url' => $site['base_url'],
            'copyright' => $site['copyright'] ?? '',
            'support_email' => $site['support_email'],
            'admin_notify_email' => $site['admin_notify_email'],
            'language_default' => $site['language_default'] ?? 'zh-hans',
            'language_allow_switch' => (bool)($site['language_allow_switch'] ?? true),
            'features' => [
                'guardians' => (bool)($site['features']['guardians'] ?? false),
                'country' => (bool)($site['features']['country'] ?? false),
            ],
        ],
        'pepper' => $pepper,
        'secret' => bin2hex(random_bytes(32)),
        'db' => $db,
        'redis' => ['host' => '127.0.0.1', 'port' => 6379, 'db' => 1, 'password' => ''],
        'smtp' => $smtp,
    ]);

    $written = @file_put_contents($configFile, $content) !== false;
    if ($written) {
        @chmod($configFile, 0640);
    }

    $pluginsWritten = write_plugins_config($root . '/config/plugins.php', $plugins);
    $pluginsNote = $pluginsWritten
        ? ''
        : '<div class="note warn"><code>config/plugins.php</code> 没写进去。装完手动把 '
            . '<code>enabled</code> 改成 <code>' . h(implode(', ', $plugins)) . '</code>。</div>';

    $_SESSION['nomen_install_done'] = ['content' => $content, 'username' => $admin['username']];
    unset($_SESSION['nomen_install']);

    render_done($content, $admin['username'], $written, $pluginsNote);
    exit;
}

header('Location: ?step=1');
