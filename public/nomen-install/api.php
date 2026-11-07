<?php
// 安装向导的 JSON 接口。所有写操作校验 CSRF，状态留在 NOMEN_INSTALL 会话里。

require __DIR__ . '/lib.php';

use Nomen\Src\EmailPolicy;
use Nomen\Src\IconUpload;
use Nomen\Src\Installer\ConfigFile;
use Nomen\Src\Installer\Database;
use Nomen\Src\Plugins;

if (nomen_install_is_installed()) {
    nomen_install_json(['error' => '这个站点已经装好了。要重装先删掉 config/installed.php。'], 409);
}

$action = (string)($_GET['action'] ?? '');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$state = nomen_install_state();

if ($method === 'POST' && !nomen_install_csrf_ok()) {
    nomen_install_json(['error' => '页面过期了，刷新一下再试。'], 419);
}

$body = static function (): array {
    $raw = (string)file_get_contents('php://input');
    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
};

$text = static fn(array $data, string $key, string $default = ''): string
    => trim((string)($data[$key] ?? $default));

$rawText = static fn(array $data, string $key): string => (string)($data[$key] ?? '');

try {
    if ($action === 'env') {
        nomen_install_json([
            'ok' => true,
            'env' => nomen_install_env_checks(),
            'upload' => nomen_install_upload_available(),
        ]);
    }

    if ($action === 'db' && $method === 'POST') {
        $data = $body();
        $driver = $text($data, 'driver', 'mysql');
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            $driver = 'mysql';
        }

        $error = '';
        if ($driver === 'sqlite') {
            $db = [
                'driver' => 'sqlite',
                'path' => $text($data, 'path', 'database/nomen.sqlite'),
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
                'host' => $text($data, 'host', '127.0.0.1'),
                'port' => (int)$text($data, 'port', '3306'),
                'name' => $text($data, 'name', 'nomen'),
                'user' => $text($data, 'user', 'nomen'),
                'pass' => $rawText($data, 'pass'),
            ];

            if (!extension_loaded('pdo_mysql')) {
                $error = 'PHP 没装 pdo_mysql，用不了 MySQL / MariaDB。换 SQLite，或装好扩展再回来。';
            } elseif ($db['host'] === '' || $db['name'] === '' || $db['user'] === '') {
                $error = '主机、库名、用户名都要填。';
            } elseif ($db['port'] < 1 || $db['port'] > 65535) {
                $error = '端口要在 1-65535 之间。';
            } else {
                try {
                    $pdo = nomen_install_connect_database($db);
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

        if ($error !== '') {
            nomen_install_json(['error' => $error], 422);
        }

        $state['db'] = $db;
        nomen_install_save_state($state);
        nomen_install_json(['ok' => true, 'step' => 'site', 'state' => nomen_install_public_state($state)]);
    }

    if ($action === 'site' && $method === 'POST') {
        if (!isset($state['db'])) {
            nomen_install_json(['error' => '数据库那一步还没过，刷新页面重新来。', 'step' => 'db'], 409);
        }

        $data = $body();
        $slugs = array_column(nomen_install_languages(), 'slug');
        $site = [
            'name' => $text($data, 'name', 'Nomen'),
            'base_url' => rtrim($text($data, 'base_url', nomen_install_guess_base_url()), '/'),
            'copyright' => $text($data, 'copyright'),
            'support_email' => $text($data, 'support_email'),
            'admin_notify_email' => $text($data, 'admin_notify_email'),
            'logo' => $text($data, 'logo'),
            'language_default' => $text($data, 'language_default', 'zh-hans'),
            'language_allow_switch' => !empty($data['language_allow_switch']),
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
        } elseif ($site['logo'] !== '' && !preg_match('#^(https?://|/)#i', $site['logo'])) {
            $error = '站点图标要填完整 URL 或以 / 开头的站内路径。';
        }

        if (isset($error)) {
            nomen_install_json(['error' => $error], 422);
        }

        $previous = (string)($_SESSION['nomen_install_logo'] ?? '');
        if ($previous !== '' && $previous !== $site['logo']) {
            nomen_install_delete_upload($previous);
            unset($_SESSION['nomen_install_logo']);
        }
        if (nomen_install_is_upload_url($site['logo'])) {
            $_SESSION['nomen_install_logo'] = $site['logo'];
        }

        $state['site'] = $site;
        nomen_install_save_state($state);
        nomen_install_json(['ok' => true, 'step' => 'admin', 'state' => nomen_install_public_state($state)]);
    }

    if ($action === 'upload-logo' && $method === 'POST') {
        if (!isset($state['db'])) {
            nomen_install_json(['error' => '先过数据库那一步再上传头像。', 'step' => 'db'], 409);
        }

        $upload = nomen_install_upload_available();
        if (!$upload['enabled']) {
            nomen_install_json(['error' => $upload['reason']], 422);
        }

        $file = $_FILES['logo'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            $message = match ($code) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '图片太大了，换一张小于 2MB 的。',
                UPLOAD_ERR_NO_FILE => '没收到图片，再选一次。',
                default => '图片上传失败（错误码 ' . $code . '），换一张再试。',
            };
            nomen_install_json(['error' => $message], 422);
        }

        $filename = IconUpload::store($file);
        if ($filename === null) {
            nomen_install_json([
                'error' => '图片处理失败。确认它是 JPG / PNG / GIF / WebP、小于 2MB，服务器也装了 GD 和 fileinfo。',
            ], 422);
        }

        $previous = (string)($_SESSION['nomen_install_logo'] ?? '');
        if ($previous !== '') {
            nomen_install_delete_upload($previous);
        }

        $url = IconUpload::url($filename);
        $_SESSION['nomen_install_logo'] = $url;

        nomen_install_json(['ok' => true, 'url' => $url]);
    }

    if ($action === 'admin' && $method === 'POST') {
        if (!isset($state['site'])) {
            nomen_install_json(['error' => '站点信息那一步还没过，刷新页面重新来。', 'step' => 'site'], 409);
        }

        $data = $body();
        $username = $text($data, 'username');
        $email = $text($data, 'email');
        $password = $rawText($data, 'password');
        $confirm = $rawText($data, 'password2');

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

        if (isset($error)) {
            nomen_install_json(['error' => $error], 422);
        }

        $state['admin'] = [
            'username' => $username,
            'email' => $email,
            'password' => $password,
        ];
        nomen_install_save_state($state);

        nomen_install_json(['ok' => true, 'step' => 'plugins', 'state' => nomen_install_public_state($state)]);
    }

    if ($action === 'plugins' && $method === 'POST') {
        if (!isset($state['admin'])) {
            nomen_install_json(['error' => '管理员账号那一步还没过，刷新页面重新来。', 'step' => 'admin'], 409);
        }

        $data = $body();
        $available = array_keys(Plugins::all());
        $selected = array_values(array_intersect(
            array_map('strval', (array)($data['plugins'] ?? [])),
            $available
        ));
        $features = (array)($data['features'] ?? []);

        $state['plugins'] = $selected;
        $state['features'] = [
            'guardians' => !empty($features['guardians']),
            'country' => !empty($features['country']),
        ];
        nomen_install_save_state($state);

        nomen_install_json(['ok' => true, 'step' => 'mail', 'state' => nomen_install_public_state($state)]);
    }

    if ($action === 'mail' && $method === 'POST') {
        if (!isset($state['plugins'])) {
            nomen_install_json(['error' => '扩展那一步还没过，刷新页面重新来。', 'step' => 'plugins'], 409);
        }

        $data = $body();
        $mode = $text($data, 'mode', 'skip');

        if ($mode === 'skip') {
            $state['smtp'] = [];
            nomen_install_save_state($state);
            nomen_install_json(['ok' => true, 'step' => 'review', 'state' => nomen_install_public_state($state)]);
        }

        $smtp = [
            'host' => $text($data, 'host'),
            'port' => (int)$text($data, 'port', '587'),
            'username' => $text($data, 'username'),
            'password' => $rawText($data, 'password'),
            'from_email' => $text($data, 'from_email'),
            'encryption' => $text($data, 'encryption', 'tls'),
        ];

        if (!in_array($smtp['encryption'], ['tls', 'ssl', ''], true)) {
            $smtp['encryption'] = 'tls';
        }

        if ($smtp['host'] !== '' && ($smtp['port'] < 1 || $smtp['port'] > 65535)) {
            $error = 'SMTP 端口要在 1-65535 之间。';
        } elseif ($smtp['host'] !== '' && !filter_var($smtp['from_email'], FILTER_VALIDATE_EMAIL)) {
            $error = '填了 SMTP 主机就要给一个发件地址。';
        }

        if (isset($error)) {
            nomen_install_json(['error' => $error], 422);
        }

        $state['smtp'] = $smtp['host'] === '' ? [] : $smtp;
        nomen_install_save_state($state);

        nomen_install_json(['ok' => true, 'step' => 'review', 'state' => nomen_install_public_state($state)]);
    }

    if ($action === 'install' && $method === 'POST') {
        foreach (['db', 'site', 'admin', 'plugins', 'smtp'] as $key) {
            if (!array_key_exists($key, $state)) {
                $step = match ($key) {
                    'db' => 'db',
                    'site' => 'site',
                    'admin' => 'admin',
                    'plugins' => 'plugins',
                    'smtp' => 'mail',
                };
                nomen_install_json(['error' => '前面还有没填完的步骤。', 'step' => $step], 409);
            }
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
            $pdo = nomen_install_connect_database($db);
            if (Database::tables($pdo) !== []) {
                nomen_install_json(['error' => '这个库里已经有表了（可能有人同时装过两次）。换一个空库再来。'], 422);
            }

            Database::importSchema($pdo, $pdo->dialect()->schemaFile(nomen_install_root()));
            Database::markMigrationsApplied($pdo, nomen_install_root('database/migrations'));
            Database::createAdmin($pdo, $admin['username'], $admin['email'], $admin['password'], $pepper);
        } catch (RuntimeException $e) {
            nomen_install_json(['error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            nomen_install_json(['error' => '安装失败：' . $e->getMessage()], 422);
        }

        $content = ConfigFile::render([
            'app' => [
                'name' => $site['name'],
                'base_url' => $site['base_url'],
                'copyright' => $site['copyright'] ?? '',
                'support_email' => $site['support_email'] ?? '',
                'admin_notify_email' => $site['admin_notify_email'] ?? '',
                'logo' => $site['logo'] ?? '',
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

        $configFile = nomen_install_config_file();
        $bytes = @file_put_contents($configFile, $content);
        $written = $bytes === strlen($content);
        if (!$written && is_file($configFile)) {
            @unlink($configFile);
        }
        if ($written) {
            @chmod($configFile, 0640);
        }

        $pluginsWritten = nomen_install_write_plugins_config(nomen_install_root('config/plugins.php'), $plugins);
        $pluginsNote = $pluginsWritten
            ? ''
            : 'config/plugins.php 没写进去，装完手动把 enabled 改成：' . implode(', ', $plugins);

        unset($_SESSION['nomen_install'], $_SESSION['nomen_install_logo']);

        $done = [
            'site_name' => $site['name'],
            'base_url' => $site['base_url'],
            'username' => $admin['username'],
            'email' => $admin['email'],
            'written' => $written,
            'content' => $written ? '' : $content,
            'plugins_note' => $pluginsNote,
        ];
        $_SESSION['nomen_install_done'] = $done;

        if ($written) {
            session_regenerate_id(true);
        }

        nomen_install_json(['ok' => true, 'done' => $done]);
    }

    if ($action === 'reset' && $method === 'POST') {
        $uploaded = (string)($_SESSION['nomen_install_logo'] ?? '');
        nomen_install_delete_upload($uploaded);
        unset($_SESSION['nomen_install'], $_SESSION['nomen_install_done'], $_SESSION['nomen_install_logo']);

        nomen_install_json(['ok' => true, 'step' => 'start', 'state' => []]);
    }

    nomen_install_json(['error' => '没有这个操作。'], 404);
} catch (Throwable $e) {
    nomen_install_json(['error' => '服务器处理失败：' . $e->getMessage()], 500);
}

function nomen_install_connect_database(array $db): \Nomen\Src\Db\Connection
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
