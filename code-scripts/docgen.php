#!/usr/bin/env php
<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

$root = dirname(__DIR__);
$args = array_slice($argv, 1);

if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo '用法：php code-scripts/docgen.php [--check] [--only=config,hooks,templates,database]', PHP_EOL, PHP_EOL;
    echo '  从代码生成 docs/ 下的参考手册（配置键 / 钩子 / 模板变量 / 表结构）', PHP_EOL;
    echo '  --check   只比对，不改文件；有差异退出码 1，给 CI 用', PHP_EOL;
    echo '  --only=   只生成其中几类，逗号分隔', PHP_EOL;
    exit(0);
}

$check = in_array('--check', $args, true);
$only = ['config', 'hooks', 'templates', 'database'];
foreach ($args as $arg) {
    if (strpos($arg, '--only=') === 0) {
        $only = array_values(array_filter(array_map('trim', explode(',', substr($arg, 7)))));
    }
}

$docsDir = $root . '/docs';
$metaDir = $root . '/docs-internal/docmeta';
$localPaths = is_file($root . '/paths.local.php') ? require $root . '/paths.local.php' : [];
$pluginsDir = rtrim((string)($localPaths['plugins'] ?? $root . '/plugins'), '/\\');
$skinsDir = rtrim((string)($localPaths['skins'] ?? $root . '/skins'), '/\\');

$pages = [];
$drift = [];
$notes = [];

function rel(string $path): string
{
    global $root;
    return str_replace('\\', '/', substr($path, strlen($root) + 1));
}

function cell(string $text): string
{
    $text = str_replace(["\r\n", "\n", "\r"], ' ', trim($text));
    return str_replace('|', '\\|', $text);
}

function code(string $text): string
{
    return $text === '' ? '—' : '`' . str_replace('`', '\\`', $text) . '`';
}

function textLen(string $text): int
{
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

function readJson(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function pageHeader(string $title, string $source): string
{
    return "# {$title}\n\n"
        . "> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。\n>\n"
        . "> 来源：{$source}\n\n";
}

function joinLines(array $lines): string
{
    $out = '';
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if ($out !== '') {
            $prev = function_exists('mb_substr') ? mb_substr($out, -1, 1, 'UTF-8') : substr($out, -1);
            $next = function_exists('mb_substr') ? mb_substr($line, 0, 1, 'UTF-8') : substr($line, 0, 1);
            $cjk = '/[\p{Han}，。、；：！？）」』】]/u';
            $out .= (preg_match($cjk, $prev) && preg_match($cjk, $next)) ? '' : ' ';
        }
        $out .= $line;
    }
    return $out;
}

function walkFiles(string $dir, array $suffixes): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $found = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $path = str_replace('\\', '/', $file->getPathname());
        if (strpos($path, '/.git/') !== false || strpos($path, '/vendor/') !== false) {
            continue;
        }
        foreach ($suffixes as $suffix) {
            if (substr($path, -strlen($suffix)) === $suffix) {
                $found[] = $path;
                break;
            }
        }
    }
    sort($found);
    return $found;
}

/* ------------------------------------------------------------------ 配置 */

function looksLikeHeading(string $line): bool
{
    return textLen($line) <= 8 && !preg_match('/[：:。，,、；;（）()\s]/u', $line);
}

function parseConfigFile(string $file): array
{
    $sections = [['title' => '配置项', 'items' => []]];
    $pending = [];
    $stack = [];
    $heading = null;
    $forceNew = false;

    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $trim = trim($line);

        if ($trim === '') {
            $pending = [];
            continue;
        }

        if (strpos($trim, '//') === 0) {
            $text = trim(substr($trim, 2));
            if ($text !== '') {
                $pending[] = $text;
            }
            continue;
        }

        if (strpos($trim, '/*') === 0 || strpos($trim, '*') === 0 || strpos($trim, '*/') === 0) {
            continue;
        }

        if ($trim === '],' || $trim === ']') {
            array_pop($stack);
            $pending = [];
            if (!$stack) {
                $forceNew = true;
            }
            continue;
        }

        if (!preg_match("/^'([^']+)'\\s*=>\\s*(.+)$/", $trim, $m)) {
            if ($stack && preg_match("/^'([^']*)',?$/", $trim, $lm)) {
                $sections[count($sections) - 1]['list'][] = $lm[1];
            }
            $pending = [];
            continue;
        }

        if ($pending) {
            if (looksLikeHeading($pending[0])) {
                $heading = array_shift($pending);
            } elseif (preg_match('/^([^\s：:（(]{2,16})[：:](.+)$/u', $pending[0], $hm)) {
                $heading = $hm[1];
                $pending[0] = $hm[2];
            }
        }

        $key = $m[1];
        $expr = $m[2];
        $descLines = $pending;
        $pending = [];

        if (preg_match('#\s//\s*(.+)$#', $expr, $tc)) {
            $descLines[] = trim($tc[1]);
            $expr = substr($expr, 0, (int)strrpos($expr, '//'));
        }
        $expr = trim(rtrim(trim($expr), ','));

        if ($expr === '[') {
            $stack[] = $key;
            $sections[] = [
                'title' => $heading ?? $key,
                'items' => [],
                'intro' => joinLines($descLines),
            ];
            $heading = null;
            $forceNew = false;
            continue;
        }

        if ($heading !== null || $forceNew) {
            $sections[] = ['title' => $heading ?? '其他', 'items' => []];
            $heading = null;
            $forceNew = false;
        }

        $sections[count($sections) - 1]['items'][] = [
            'key' => implode('.', array_merge($stack, [$key])),
            'expr' => $expr,
            'desc' => joinLines($descLines),
        ];
    }

    return array_values(array_filter($sections, static fn($s) => $s['items'] !== [] || !empty($s['list'])));
}

function configDefault(string $expr): string
{
    $expr = trim($expr);
    if ($expr === '') {
        return '—';
    }
    if ($expr === '[]') {
        return '空数组';
    }
    if (preg_match("/^\\\$brand\\['([a-z0-9_]+)'\\]$/", $expr, $m)) {
        return '← brand.' . $m[1];
    }
    if (preg_match("/^\\\$feature\\(/", $expr)) {
        return '关（用 FEATURE_* 或 `installed.php` 打开）';
    }
    if (preg_match('#^[0-9\s*+\-/().]+$#', $expr)) {
        $value = @eval('return ' . $expr . ';');
        if (is_int($value) || is_float($value)) {
            return (string)(int)$value;
        }
    }

    $stripped = preg_replace("/getenv\\('[A-Z0-9_]+'\\)/", '', $expr);
    $stripped = preg_replace("/\\\$[A-Za-z_][A-Za-z0-9_]*(\\[[^\\]]*\\])?/", '', (string)$stripped);

    preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\"|\\b(true|false|null)\\b|\\b(\\d+)\\b/i", (string)$stripped, $found, PREG_SET_ORDER);
    if (!$found) {
        return '—';
    }
    $last = $found[count($found) - 1];
    if (isset($last[4]) && $last[4] !== '') {
        return $last[4];
    }
    if (isset($last[3]) && $last[3] !== '') {
        return $last[3];
    }
    $value = $last[1] !== '' ? $last[1] : ($last[2] ?? '');
    return $value === '' ? "''" : $value;
}

function configEnv(string $expr): string
{
    if (!preg_match_all("/getenv\\('([A-Z0-9_]+)'\\)/", $expr, $m)) {
        return '—';
    }
    return implode(' / ', array_map('code', array_unique($m[1])));
}

function buildConfig(): void
{
    global $root, $pages, $drift;

    $skip = ['countries.php', 'genders.php', 'consent-age-sources.php', 'installed.php', 'home-nomenid.php'];
    $files = glob($root . '/config/*.php') ?: [];
    sort($files);

    $configMeta = readJson($GLOBALS['metaDir'] . '/config.json');
    $overrides = $configMeta['keys'] ?? [];
    $fieldDocs = [];
    $seen = [];

    $index = ["# 配置参考\n\n", "每个配置文件一页。读配置时的优先级是 **`.env` → `config/installed.php` → 文件里的字面量**，"
        . "表里的「默认值」指的是最后那一档；「环境变量」列有值的，说明 `.env` 可以覆盖。\n\n",
        "| 文件 | 配置项 |\n|---|---|\n"];
    $total = 0;

    foreach ($files as $file) {
        $name = basename($file);
        if (in_array($name, $skip, true)) {
            continue;
        }
        $sections = parseConfigFile($file);
        $count = 0;
        foreach ($sections as $section) {
            $count += count($section['items']) + count($section['list'] ?? []);
        }
        if ($count === 0) {
            continue;
        }

        $out = pageHeader('config/' . $name, '`config/' . $name . '`');
        $fieldDocs = $configMeta['fields'][$name] ?? [];
        $sectionTitles = $configMeta['sectionTitles'][$name] ?? [];
        $sectionIntros = $configMeta['sectionIntros'][$name] ?? [];
        if (!empty($configMeta['files'][$name])) {
            $out .= $configMeta['files'][$name] . "\n\n";
        }
        $out .= "共 {$count} 项。\n\n";

        foreach ($sections as $section) {
            $title = $sectionTitles[$section['title']] ?? $section['title'];
            $out .= '## ' . $title . "\n\n";
            $intro = $sectionIntros[$section['title']] ?? ($section['intro'] ?? '');
            if ($intro !== '') {
                $out .= $intro . "\n\n";
            }
            if (!empty($section['list'])) {
                foreach ($section['list'] as $value) {
                    $out .= '- `' . $value . "`\n";
                }
                $out .= "\n";
                continue;
            }
            $uniform = array_unique(array_map(static fn($i) => configDefault($i['expr']), $section['items']));
            $rows = [];
            foreach ($section['items'] as $item) {
                $default = configDefault($item['expr']);
                // quickbuild 号只是部署记录，文档里保留开发线版本号
                if ($name === 'app.php' && $item['key'] === 'version') {
                    $default = (string)preg_replace('/\+qb\.\d+$/', '', $default);
                }
                $desc = $item['desc'];
                if ($desc === '' && isset($overrides[$name . '.' . $item['key']])) {
                    $desc = (string)$overrides[$name . '.' . $item['key']];
                }
                if ($desc === '') {
                    $leaf = substr($item['key'], (int)strrpos('.' . $item['key'], '.'));
                    $desc = (string)($fieldDocs[$leaf] ?? '');
                }
                if ($desc === '' && strpos($default, '← brand.') === 0) {
                    $desc = '取自 `config/brand.php` 的 `' . preg_replace('/^← brand\./', '', $default) . '`';
                }
                $seen[$name . '.' . $item['key']] = true;
                $rows[] = ['key' => $item['key'], 'env' => configEnv($item['expr']), 'default' => $default, 'desc' => $desc];
            }

            if (count($uniform) === 1 && count($rows) > 1 && !array_filter(array_column($rows, 'desc'))) {
                $out .= implode('、', array_map(static fn($r) => preg_replace('/^.*\./', '', $r['key']), $rows)) . "\n\n";
                continue;
            }
            if (count($rows) > 40) {
                $groups = [];
                foreach ($rows as $row) {
                    $groups[$row['default']][] = preg_replace('/^.*\./', '', $row['key']);
                }
                ksort($groups, SORT_NATURAL);
                $out .= '本组共 ' . count($rows) . " 项，按取值归组；逐项写在 `config/{$name}` 里。\n\n";
                $out .= "| 默认值 | 键 |\n|---|---|\n";
                foreach ($groups as $value => $keys) {
                    $out .= '| ' . cell((string)$value) . ' | ' . implode(', ', $keys) . " |\n";
                }
                $out .= "\n";
                continue;
            }
            $out .= "| 键 | 环境变量 | 默认值 | 说明 |\n|---|---|---|---|\n";
            foreach ($rows as $row) {
                $out .= '| ' . code($row['key'])
                    . ' | ' . $row['env']
                    . ' | ' . cell($row['default'])
                    . ' | ' . (cell($row['desc']) ?: '—') . " |\n";
            }
            $out .= "\n";
        }

        $pages['config/' . str_replace('.php', '.md', $name)] = $out;
        $index[] = '| [' . $name . '](' . str_replace('.php', '.md', $name) . ') | ' . $count . " |\n";
        $total += $count;
    }

    foreach (array_keys($overrides) as $key) {
        if (!isset($seen[$key])) {
            $drift[] = "config.json 里的 {$key} 在配置里找不到";
        }
    }

    $index[] = "\n数据类文件（`countries.php` / `genders.php` / `consent-age-sources.php`）是列表常量，不是可调项，直接在 `config/` 里看；"
        . "`home-nomenid.php` 只给官方站用，不进发布包。\n";
    $pages['config/index.md'] = implode('', $index);
    $GLOBALS['notes'][] = "配置：{$total} 项";
}

/* ------------------------------------------------------------------ 钩子 */

function buildHooks(): void
{
    global $root, $pluginsDir, $skinsDir, $pages, $drift;

    $meta = readJson($GLOBALS['metaDir'] . '/hooks.json');
    if (!$meta) {
        $drift[] = 'docs-internal/docmeta/hooks.json 不存在，钩子页无法生成';
        return;
    }

    $sites = [];
    // 只扫核心仓自己的目录：插件和皮肤是独立仓库，扫进去会让生成的页面随本地 clone 情况变化，
    // CI 上没有那些仓就对不上了。扩展侧触发的钩子在 sidecar 里用 owner 标注。
    $dirs = [$root . '/src', $root . '/templates', $root . '/public', $root . '/controllers'];
    foreach ($dirs as $dir) {
        foreach (walkFiles($dir, ['.php']) as $file) {
            $source = file_get_contents($file) ?: '';
            if (!preg_match_all('/Hooks::(emit|filter|run)\(\s*[\'"]([a-z0-9_.]+)[\'"]/', $source, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($m[2] as $i => $hit) {
                $offset = $hit[1];
                $line = substr_count(substr($source, 0, $offset), "\n") + 1;
                $sites[$hit[0]][] = ['kind' => $m[1][$i][0], 'file' => rel($file), 'line' => $line];
            }
        }
    }

    $domains = [
        'auth' => ['name' => '登录与账号', 'match' => ['user.', 'auth.']],
        'oauth' => ['name' => 'OAuth 与 OIDC', 'match' => ['oauth.', 'oidc.', 'userinfo.']],
        'render' => ['name' => '页面渲染与导航', 'match' => ['layout.', 'nav.', 'footer.', 'meta.', 'viewer.']],
        'home' => ['name' => '首页', 'match' => ['homepage.']],
        'admin' => ['name' => '后台', 'match' => ['admin.']],
        'content' => ['name' => '内容与站点 API', 'match' => ['pages.', 'api.']],
        'notify' => ['name' => '邮件与通知', 'match' => ['email.', 'notification.']],
    ];

    $grouped = [];
    foreach ($meta as $hook => $info) {
        $domain = 'content';
        foreach ($domains as $slug => $rule) {
            foreach ($rule['match'] as $prefix) {
                if (strpos($hook, $prefix) === 0) {
                    $domain = $slug;
                    break 2;
                }
            }
        }
        $grouped[$domain][$hook] = $info;
    }

    $missing = array_values(array_diff(array_keys($meta), array_keys($sites)));
    $undocumented = array_values(array_diff(array_keys($sites), array_keys($meta)));
    $extensionOwned = [];
    $realMissing = [];
    foreach ($missing as $hook) {
        if ((string)($meta[$hook]['owner'] ?? '') !== '') {
            $extensionOwned[] = $hook;
        } else {
            $realMissing[] = $hook;
        }
    }
    if ($realMissing) {
        $drift[] = '代码里已经没有这些钩子的触发点：' . implode('、', $realMissing) . '（要么删描述，要么补触发点，要么标 owner）';
    }
    if ($undocumented) {
        $drift[] = '代码里有触发点但 hooks.json 没写：' . implode('、', $undocumented);
    }
    $ownerMap = [];
    foreach ($meta as $hook => $info) {
        if ((string)($info['owner'] ?? '') !== '') {
            $ownerMap[$hook] = (string)$info['owner'];
        }
    }

    $index = ["# 钩子参考\n\n",
        "钩子的说明写在 `docs-internal/docmeta/hooks.json`，本页由它和代码里的触发点一起生成。"
        . "`--check` 会双向核对：代码里查不到的钩子、以及代码里有但没登记钩子的，都会报错。\n\n",
        "| 钩子 | 类型 | 触发点 |\n|---|---|---|\n"];

    $total = 0;
    foreach ($domains as $slug => $rule) {
        if (empty($grouped[$slug])) {
            continue;
        }
        $hooks = $grouped[$slug];
        ksort($hooks);
        $out = pageHeader('钩子：' . $rule['name'], '`docs-internal/docmeta/hooks.json` 和代码里的 `Hooks::filter` / `Hooks::emit` 调用点');
        $out .= "## 本组钩子\n\n| 钩子 | 类型 | 参数 | 时机 |\n|---|---|---|---|\n";
        foreach ($hooks as $hook => $info) {
            $out .= '| ' . code($hook) . ' | ' . cell($info['type'] ?? '—')
                . ' | ' . (cell($info['args'] ?? '') ?: '—')
                . ' | ' . (cell($info['when'] ?? '') ?: '—') . " |\n";
            $index[] = '| [' . $hook . '](' . $slug . '.md) | '
                . cell($info['type'] ?? '—') . ' | '
                . (isset($sites[$hook]) ? count($sites[$hook]) . ' 处'
                    : (isset($ownerMap[$hook]) ? '扩展侧' : '**0 处**')) . " |\n";
        }

        $out .= "\n## 触发点\n\n";
        foreach ($hooks as $hook => $info) {
            $out .= '### ' . $hook . "\n\n";
            $out .= '- 类型：' . ($info['type'] ?? '—') . "\n";
            if (!empty($info['args'])) {
                $out .= '- 参数：' . $info['args'] . "\n";
            }
            if (!empty($info['when'])) {
                $out .= '- 时机：' . $info['when'] . "\n";
            }
            if (!empty($info['note'])) {
                $out .= '- 注意：' . $info['note'] . "\n";
            }
            if (empty($sites[$hook])) {
                if (isset($ownerMap[$hook])) {
                    $out .= '- 触发点：' . $ownerMap[$hook] . " 那边触发，源码在它自己的仓库里\n\n";
                } else {
                    $out .= "- 触发点：**代码里没有**（登记过但没人触发）\n\n";
                }
                continue;
            }
            $out .= "- 触发点：\n";
            $byFile = [];
            foreach ($sites[$hook] as $site) {
                $byFile[$site['file']] = $byFile[$site['file']] ?? ['kind' => $site['kind'], 'n' => 0];
                $byFile[$site['file']]['n']++;
            }
            foreach ($byFile as $file => $info) {
                $out .= '  - ' . code($file) . '（' . $info['kind'] . ($info['n'] > 1 ? '，' . $info['n'] . ' 处' : '') . "）\n";
            }
            $out .= "\n";
        }

        $pages['hooks/' . $slug . '.md'] = $out;
        $total += count($hooks);
    }

    $pages['hooks/index.md'] = implode('', $index);
    $GLOBALS['notes'][] = "钩子：{$total} 个";
}

/* ------------------------------------------------------------ 模板变量 */

function buildTemplates(): void
{
    global $root, $pluginsDir, $skinsDir, $pages, $drift;

    $meta = readJson($GLOBALS['metaDir'] . '/templates.json');
    if (!$meta) {
        $drift[] = 'docs-internal/docmeta/templates.json 不存在，模板页无法生成';
        return;
    }

    $vars = [];
    // 同上：只扫核心仓自己的目录，插件渲染的核心模板不算进来，保证本地和 CI 生成同一份
    $dirs = [$root . '/src', $root . '/controllers', $root . '/public'];
    foreach ($dirs as $dir) {
        foreach (walkFiles($dir, ['.php']) as $file) {
            $source = file_get_contents($file) ?: '';
            $offset = 0;
            while (($pos = strpos($source, 'Response::view', $offset)) !== false) {
                if (!preg_match('/^Response::view(Partial)?\(/', substr($source, $pos, 24), $pm)) {
                    $offset = $pos + 8;
                    continue;
                }
                $start = $pos + strlen('Response::view(') + (($pm[1] ?? '') === 'Partial' ? 7 : 0);
                $depth = 1;
                $quote = null;
                $i = $start;
                $len = strlen($source);
                for (; $i < $len; $i++) {
                    $ch = $source[$i];
                    if ($quote !== null) {
                        if ($ch === '\\') {
                            $i++;
                        } elseif ($ch === $quote) {
                            $quote = null;
                        }
                        continue;
                    }
                    if ($ch === "'" || $ch === '"') {
                        $quote = $ch;
                    } elseif ($ch === '(' || $ch === '[') {
                        $depth++;
                    } elseif ($ch === ')' || $ch === ']') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    }
                }
                $call = substr($source, $start, $i - $start);
                $offset = $i;
                if (!preg_match("/^\\s*'([^']+)'/", $call, $tm)) {
                    continue;
                }
                $view = $tm[1];
                $rest = ltrim(substr($call, strlen($tm[0])), " \t\n\r,");
                $keys = [];
                $raw = '';
                if (strpos($rest, '[') === 0) {
                    if (preg_match_all("/'([A-Za-z_][A-Za-z0-9_]*)'\\s*=>/", $rest, $km)) {
                        $keys = array_values(array_unique($km[1]));
                    }
                } elseif (preg_match('/^compact\(([^)]*)\)/', $rest, $cm)) {
                    if (preg_match_all("/'([A-Za-z_][A-Za-z0-9_]*)'|\"([A-Za-z_][A-Za-z0-9_]*)\"|\\b([A-Za-z_][A-Za-z0-9_]*)\\b/", $cm[1], $km, PREG_SET_ORDER)) {
                        foreach ($km as $hit) {
                            $name = $hit[1] ?? '';
                            if ($name === '') {
                                $name = ($hit[2] ?? '') !== '' ? $hit[2] : ($hit[3] ?? '');
                            }
                            if ($name !== '') {
                                $keys[] = $name;
                            }
                        }
                        $keys = array_values(array_unique($keys));
                    }
                } else {
                    $raw = trim(preg_split('/,(?![^\[]*\])/', $rest, 2)[0] ?? '');
                }
                foreach ($keys as $key) {
                    $vars[$view][$key] = true;
                }
                if ($raw !== '') {
                    $vars['#raw'][$view] = true;
                    $vars['#rawText'][$view] = $raw;
                }
                if (!isset($vars[$view])) {
                    $vars[$view] = [];
                }
            }
        }
    }

    $groups = [
        'auth' => ['title' => '登录与注册页', 'items' => ['login', 'register', 'register-verify', 'activate', '2fa-verify', 'authorize', 'fast-pass']],
        'recovery' => ['title' => '账号恢复页', 'items' => ['recovery', 'recovery-family', 'recovery-secure', 'recovery-sent']],
        'shell' => ['title' => '页面外壳', 'items' => ['app-shell', 'admin-shell', 'special']],
        'home' => ['title' => '首页', 'items' => ['home', 'home-page', 'home-custom']],
        'partial' => ['title' => '公共片段', 'items' => ['_email_list', '_lang_picker', '_mobile_menu', '_site_sky']],
    ];

    $known = [];
    foreach ($groups as $group) {
        foreach ($group['items'] as $item) {
            $known[] = $item;
        }
    }
    foreach (array_keys($meta) as $name) {
        if (!in_array($name, $known, true)) {
            $drift[] = "templates.json 里的 {$name} 不在分组表里（改 code-scripts/docgen.php 的 \$groups）";
        }
    }

    $index = ["# 模板参考\n\n",
        "核心模板在 `templates/`，皮肤可以覆盖同名文件（`skins/<slug>/templates/`）。"
        . "变量列只统计**核心代码**里的 `Response::view('<模板>', [...])` 调用点：插件调用同一个模板时会传它自己的变量，"
        . "那些不算在内。列表里少一个键，页面上就会变成 undefined。\n\n",
        "| 分组 | 模板 |\n|---|---|\n"];

    $total = 0;
    foreach ($groups as $slug => $group) {
        $out = pageHeader('模板：' . $group['title'], '代码里的 `Response::view()` 调用点和 `docs-internal/docmeta/templates.json`');
        foreach ($group['items'] as $name) {
            if (!isset($meta[$name])) {
                continue;
            }
            $out .= "### {$name}\n\n";
            $out .= (string)($meta[$name]['desc'] ?? '') . "\n\n";
            if (!empty($meta[$name]['file'])) {
                $out .= '- 文件：' . code($meta[$name]['file']) . "\n";
            }
            $keys = array_keys($vars[$name] ?? []);
            if ($keys) {
                $out .= '- 变量：' . implode('、', array_map('code', $keys)) . "\n";
            } elseif (!empty($vars['#rawText'][$name])) {
                $out .= '- 变量：由 `' . $vars['#rawText'][$name] . "` 整体传入（键名运行期才知道）\n";
            } else {
                $out .= "- 变量：无（由 `include` 直接引入，或渲染方用 `\$props` 之类整体传入）\n";
            }
            if (!empty($meta[$name]['vars'])) {
                foreach ($meta[$name]['vars'] as $var => $meaning) {
                    $out .= "  - `{$var}`：{$meaning}\n";
                }
            }
            if (!empty($meta[$name]['note'])) {
                $out .= '- 注意：' . $meta[$name]['note'] . "\n";
            }
            $out .= "\n";
            $index[] = '| ' . $group['title'] . ' | [' . $name . '](' . $slug . ".md) |\n";
            $total++;
        }
        $pages['templates/' . $slug . '.md'] = $out;
    }

    $pages['templates/index.md'] = implode('', $index);
    $GLOBALS['notes'][] = "模板：{$total} 个";
}

/* -------------------------------------------------------------- 表结构 */

function columnHint(string $col): string
{
    $hints = [
        'id' => '自增主键',
        'unid' => '对外编号（26 位 ULID，界面上只露这个）',
        'created_at' => '创建时间',
        'updated_at' => '最后修改时间',
        'user_id' => '所属账号（`users.id`）',
        'client_id' => '所属应用（`oauth_clients.client_id`）',
        'developer_id' => '所属开发者（`developers.id`）',
        'ip_address' => '客户端 IP',
        'user_agent' => '客户端 UA',
        'status' => '状态标记',
        'password_hash' => '密码哈希（掺了 `config/app.php` 的 pepper）',
    ];
    if (isset($hints[$col])) {
        return $hints[$col];
    }
    if (substr($col, -3) === '_at') {
        return '时间';
    }
    return '';
}

function buildDatabase(): void
{
    global $root, $pages, $drift;

    $meta = readJson($GLOBALS['metaDir'] . '/tables.json');
    if (!$meta) {
        $drift[] = 'docs-internal/docmeta/tables.json 不存在，表结构页无法生成';
        return;
    }

    $sql = (string)file_get_contents($root . '/database/schema.sql');
    if (!preg_match_all('/CREATE TABLE `([a-z0-9_]+)` \((.*?)\n\) ENGINE/s', $sql, $matches, PREG_SET_ORDER)) {
        $drift[] = 'database/schema.sql 里没解析到建表语句';
        return;
    }

    $tables = [];
    foreach ($matches as $match) {
        $name = $match[1];
        $body = $match[2];
        $columns = [];
        $indexes = [];
        foreach (explode("\n", $body) as $line) {
            $line = trim(rtrim(trim($line), ','));
            if ($line === '') {
                continue;
            }
            if (preg_match('/^`([a-z0-9_]+)` (.+)$/', $line, $cm)) {
                $type = preg_split('/\s+/', $cm[2])[0];
                $type = preg_replace_callback('/^[a-z]+/i', static fn($tm) => strtoupper($tm[0]), $type);
                $columns[$cm[1]] = [
                    'type' => strtoupper($type),
                    'null' => stripos($cm[2], 'NOT NULL') === false,
                    'default' => preg_match("/DEFAULT '([^']*)'/", $cm[2], $dm) ? $dm[1]
                        : (preg_match('/DEFAULT (CURRENT_TIMESTAMP|[0-9.]+|NULL)/i', $cm[2], $dm) ? $dm[1] : '—'),
                    'raw' => $cm[2],
                ];
                continue;
            }
            if (preg_match('/^(PRIMARY KEY|UNIQUE KEY `([a-z0-9_]+)`|KEY `([a-z0-9_]+)`) \(([^)]+)\)/', $line, $im)) {
                $cols = array_map(static fn($c) => trim($c, ' `'), explode(',', $im[4]));
                $indexes[] = [
                    'kind' => $im[1] === 'PRIMARY KEY' ? '主键' : ($im[2] !== '' ? '唯一' : '普通'),
                    'name' => $im[2] !== '' ? $im[2] : ($im[3] !== '' ? $im[3] : 'PRIMARY'),
                    'cols' => $cols,
                ];
            }
        }
        $tables[$name] = ['columns' => $columns, 'indexes' => $indexes];
    }

    $missing = array_values(array_diff(array_keys($tables), array_keys($meta)));
    $stale = array_values(array_diff(array_keys($meta), array_keys($tables)));
    if ($missing) {
        $drift[] = 'schema.sql 里有表没写说明：' . implode('、', $missing);
    }
    if ($stale) {
        $drift[] = 'tables.json 里这些表已经不在 schema.sql：' . implode('、', $stale);
    }

    $groups = [
        'account' => ['title' => '账号与身份', 'items' => ['users', 'user_emails', 'pending_registrations', 'user_devices', 'user_biometrics', 'user_totp_secrets', 'user_recovery_codes', 'user_temp_keys']],
        'oauth' => ['title' => 'OAuth 与开放平台', 'items' => ['oauth_clients', 'oauth_authorization_codes', 'oauth_access_tokens', 'oauth_refresh_tokens', 'developers', 'user_authorizations', 'user_app_subjects']],
        'identity' => ['title' => '第三方登录', 'items' => ['identity_providers', 'user_identities']],
        'recovery' => ['title' => '账号恢复与家庭', 'items' => ['account_recovery_requests', 'family_groups', 'family_members']],
        'site' => ['title' => '站点与内容', 'items' => ['site_settings', 'app_meta', 'activity_logs', 'notifications', 'user_notification_settings', 'cache_items', 'plugin_migrations']],
    ];

    $known = [];
    foreach ($groups as $group) {
        foreach ($group['items'] as $item) {
            $known[] = $item;
        }
    }
    foreach (array_keys($meta) as $name) {
        if (!in_array($name, $known, true)) {
            $drift[] = "tables.json 里的 {$name} 不在分组表里（改 code-scripts/docgen.php 的 \$groups）";
        }
    }

    $index = ["# 数据结构\n\n",
        "表结构以 `database/schema.sql` 为准，本页由它生成。SQLite 版本在 `database/schema.sqlite.sql`，"
        . "升级用 `database/migrations/` 下的增量脚本（`php bin/nomen upgrade` 执行）。\n\n",
        "| 分组 | 表 | 说明 |\n|---|---|---|\n"];

    foreach ($groups as $slug => $group) {
        $out = pageHeader('数据表：' . $group['title'], '`database/schema.sql` 和 `docs-internal/docmeta/tables.json`');
        $out .= "## {$group['title']}\n\n";
        foreach ($group['items'] as $name) {
            if (!isset($tables[$name])) {
                continue;
            }
            $table = $tables[$name];
            $info = $meta[$name] ?? [];
            foreach (array_keys($info['columns'] ?? []) as $col) {
                if (!isset($table['columns'][$col])) {
                    $drift[] = "tables.json 给 {$name} 写的字段 {$col} 在 schema.sql 里不存在";
                }
            }
            $out .= "### {$name}\n\n";
            $out .= (string)($info['desc'] ?? '') . "\n\n";
            if (!empty($info['note'])) {
                $out .= '> ' . $info['note'] . "\n\n";
            }
            $out .= "| 字段 | 类型 | 可空 | 默认 | 说明 |\n|---|---|---|---|---|\n";
            foreach ($table['columns'] as $col => $def) {
                $desc = $info['columns'][$col] ?? columnHint($col);
                $default = $def['default'] === '' ? "''" : $def['default'];
                $out .= '| ' . code($col) . ' | ' . cell($def['type'])
                    . ' | ' . ($def['null'] ? '是' : '否')
                    . ' | ' . cell($default)
                    . ' | ' . (cell($desc) ?: '—') . " |\n";
            }
            if ($table['indexes']) {
                $out .= "\n索引：";
                $parts = [];
                foreach ($table['indexes'] as $indexDef) {
                    $parts[] = $indexDef['kind'] . ' `' . $indexDef['name'] . '`（' . implode(', ', $indexDef['cols']) . '）';
                }
                $out .= implode('；', $parts) . "\n";
            }
            $out .= "\n";
            $index[] = '| ' . $group['title'] . ' | [' . $name . '](' . $slug . '.md) | ' . cell((string)($info['desc'] ?? '')) . " |\n";
        }
        $pages['database/' . $slug . '.md'] = $out;
    }

    $pages['database/index.md'] = implode('', $index);
    $GLOBALS['notes'][] = '表：' . count($tables) . ' 张';
}

/* ---------------------------------------------------------------- 执行 */

$builders = [
    'config' => 'buildConfig',
    'hooks' => 'buildHooks',
    'templates' => 'buildTemplates',
    'database' => 'buildDatabase',
];
foreach ($builders as $key => $fn) {
    if (in_array($key, $only, true)) {
        $fn();
    }
}

$changed = [];
foreach ($pages as $relPath => $content) {
    $target = $docsDir . '/' . $relPath;
    $current = is_file($target) ? (string)file_get_contents($target) : null;
    if ($current === $content) {
        continue;
    }
    $changed[] = $relPath;
    if (!$check) {
        $dir = dirname($target);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($target, $content);
    }
}

foreach ($GLOBALS['notes'] as $note) {
    echo $note, PHP_EOL;
}

if ($drift) {
    echo PHP_EOL, '对不上：', PHP_EOL;
    foreach ($drift as $line) {
        echo '  - ', $line, PHP_EOL;
    }
}

if ($changed) {
    if ($check) {
        echo PHP_EOL, '有 ', count($changed), ' 个页面和代码不一致：', PHP_EOL;
        foreach ($changed as $relPath) {
            echo '  - docs/', $relPath, PHP_EOL;
        }
    } else {
        echo PHP_EOL, '已更新 ', count($changed), ' 个页面：', PHP_EOL;
        foreach ($changed as $relPath) {
            echo '  - docs/', $relPath, PHP_EOL;
        }
    }
} else {
    echo PHP_EOL, '文档已是最新。', PHP_EOL;
}

exit($check && ($changed || $drift) ? 1 : 0);
