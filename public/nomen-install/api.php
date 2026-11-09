<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

// 安装向导的 JSON 接口。所有写操作校验 CSRF，状态留在 NOMEN_INSTALL 会话里。

require __DIR__ . '/lib.php';

use Nomen\Src\EmailPolicy;
use Nomen\Src\IconUpload;
use Nomen\Src\Installer\ConfigFile;
use Nomen\Src\Installer\Database;
use Nomen\Src\Plugins;

if (nomen_install_is_installed()) {
    nomen_install_json(['error' => nomen_install_t('This site is already installed. Delete config/installed.php to reinstall.')], 409);
}

$action = (string)($_GET['action'] ?? '');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$state = nomen_install_state();

if ($method === 'POST' && !nomen_install_csrf_ok()) {
    nomen_install_json(['error' => nomen_install_t('This page has expired. Refresh the page and try again.')], 419);
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

    if ($action === 'i18n' && $method === 'GET') {
        $set = (string)($_GET['set'] ?? '');
        if ($set !== '' && nomen_install_lang_valid($set)) {
            nomen_install_set_lang($set);
        }
        $lang = $set !== '' && nomen_install_lang_valid($set) ? $set : nomen_install_lang();
        $dictionary = [];
        if ($lang !== 'en') {
            $file = nomen_install_root('locales/' . $lang . '.json');
            $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
            if (is_array($data)) {
                $dictionary = $data;
            }
        }
        nomen_install_json(['ok' => true, 'lang' => $lang, 'dictionary' => $dictionary]);
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
                $error = nomen_install_t('PHP is missing pdo_sqlite, so SQLite is unavailable. Pick another driver, or install the extension and come back.');
            } elseif ($db['path'] === '') {
                $error = nomen_install_t('SQLite needs a database file path.');
            } else {
                try {
                    $pdo = Database::connect($db, false);
                    if (Database::tables($pdo) !== []) {
                        $error = nomen_install_t('This database file already has tables. Use an empty file, or clear the old tables first.');
                    }
                } catch (RuntimeException $e) {
                    $error = $e->getMessage();
                } catch (Throwable $e) {
                    $error = nomen_install_t('Could not connect to the database: {:reason}', ['reason' => $e->getMessage()]);
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
                $error = nomen_install_t('PHP is missing pdo_mysql, so MySQL / MariaDB is unavailable. Use SQLite, or install the extension and come back.');
            } elseif ($db['host'] === '' || $db['name'] === '' || $db['user'] === '') {
                $error = nomen_install_t('Host, database name and username are all required.');
            } elseif ($db['port'] < 1 || $db['port'] > 65535) {
                $error = nomen_install_t('The port must be between 1 and 65535.');
            } else {
                try {
                    $pdo = nomen_install_connect_database($db);
                    if (Database::tables($pdo) !== []) {
                        $error = nomen_install_t('This database already has tables. Use an empty one, or clear the old tables first.');
                    }
                } catch (RuntimeException $e) {
                    $error = $e->getMessage();
                } catch (Throwable $e) {
                    $error = nomen_install_t('Could not connect to the database: {:reason}', ['reason' => $e->getMessage()]);
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
            nomen_install_json(['error' => nomen_install_t('The database step is not finished yet. Refresh the page and start over.'), 'step' => 'db'], 409);
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
            $error = nomen_install_t('The site name is required, at most 60 characters.');
        } elseif (!filter_var($site['base_url'], FILTER_VALIDATE_URL)
            || !in_array($scheme, ['http', 'https'], true)
            || parse_url($site['base_url'], PHP_URL_HOST) === null) {
            $error = nomen_install_t('The base URL must be complete, with http:// or https://.');
        } elseif (parse_url($site['base_url'], PHP_URL_QUERY) !== null
            || parse_url($site['base_url'], PHP_URL_FRAGMENT) !== null) {
            $error = nomen_install_t('The base URL must not contain a query string or a # fragment.');
        } elseif (!in_array($site['language_default'], $slugs, true)) {
            $error = nomen_install_t('That language is not in the list.');
        } elseif ($site['support_email'] !== '' && !filter_var($site['support_email'], FILTER_VALIDATE_EMAIL)) {
            $error = nomen_install_t('The support email address is not valid.');
        } elseif ($site['admin_notify_email'] !== '' && !filter_var($site['admin_notify_email'], FILTER_VALIDATE_EMAIL)) {
            $error = nomen_install_t('The admin notification email address is not valid.');
        } elseif ($site['logo'] !== '' && !preg_match('#^(https?://|/)#i', $site['logo'])) {
            $error = nomen_install_t('The site logo must be a full URL or a site path starting with /.');
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
            nomen_install_json(['error' => nomen_install_t('Finish the database step before uploading an avatar.'), 'step' => 'db'], 409);
        }

        $upload = nomen_install_upload_available();
        if (!$upload['enabled']) {
            nomen_install_json(['error' => $upload['reason']], 422);
        }

        $file = $_FILES['logo'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            $message = match ($code) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => nomen_install_t('The image is too large. Use one under 2MB.'),
                UPLOAD_ERR_NO_FILE => nomen_install_t('No image was received. Pick one and try again.'),
                default => nomen_install_t('The image upload failed (error code {:code}). Try another image.', ['code' => $code]),
            };
            nomen_install_json(['error' => $message], 422);
        }

        $filename = IconUpload::store($file);
        if ($filename === null) {
            nomen_install_json([
                'error' => nomen_install_t('Could not process the image. Make sure it is a JPG / PNG / GIF / WebP under 2MB and the server has GD and fileinfo.'),
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
            nomen_install_json(['error' => nomen_install_t('The site step is not finished yet. Refresh the page and start over.'), 'step' => 'site'], 409);
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
            $error = nomen_install_t('The username must be 3-32 lowercase letters, digits or underscores.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = nomen_install_t('The admin email address is not valid.');
        } elseif (EmailPolicy::isBlocked($email)) {
            $error = nomen_install_t(EmailPolicy::REJECT_MESSAGE);
        } elseif (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            $error = nomen_install_t('The password must be at least 8 characters and contain letters and digits.');
        } elseif ($password !== $confirm) {
            $error = nomen_install_t('The two passwords do not match.');
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
            nomen_install_json(['error' => nomen_install_t('The admin step is not finished yet. Refresh the page and start over.'), 'step' => 'admin'], 409);
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
            nomen_install_json(['error' => nomen_install_t('The plugins step is not finished yet. Refresh the page and start over.'), 'step' => 'plugins'], 409);
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
            $error = nomen_install_t('The SMTP port must be between 1 and 65535.');
        } elseif ($smtp['host'] !== '' && !filter_var($smtp['from_email'], FILTER_VALIDATE_EMAIL)) {
            $error = nomen_install_t('A from address is required when an SMTP host is set.');
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
                nomen_install_json(['error' => nomen_install_t('Some earlier steps are not finished yet.'), 'step' => $step], 409);
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
                nomen_install_json(['error' => nomen_install_t('This database already has tables (two installs may have raced). Use an empty database and try again.')], 422);
            }

            Database::importSchema($pdo, $pdo->dialect()->schemaFile(nomen_install_root()));
            Database::markMigrationsApplied($pdo, nomen_install_root('database/migrations'));
            Database::createAdmin($pdo, $admin['username'], $admin['email'], $admin['password'], $pepper);
        } catch (RuntimeException $e) {
            nomen_install_json(['error' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            nomen_install_json(['error' => nomen_install_t('Installation failed: {:reason}', ['reason' => $e->getMessage()])], 422);
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
            : nomen_install_t('config/plugins.php could not be written. After installing, set enabled to: {:list}', ['list' => implode(', ', $plugins)]);

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

    nomen_install_json(['error' => nomen_install_t('No such action.')], 404);
} catch (Throwable $e) {
    nomen_install_json(['error' => nomen_install_t('The server could not process the request: {:reason}', ['reason' => $e->getMessage()])], 500);
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
        throw new RuntimeException(nomen_install_t('Connected, but this account has no permission to create databases. Create the database and user in your hosting panel first, then come back.'));
    }

    return Database::connect($db, true);
}
