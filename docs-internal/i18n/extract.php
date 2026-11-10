<?php
/**
 * 多语言目录工具：按「域」扫描 t() 调用，生成 / 校验各域的语言文件。
 *
 * 域和它们的词典目录：
 *   - 核心              locales/                 扫 templates/controllers/src/public/js/frontend/src
 *   - 插件 <slug>       plugins/<slug>/i18n/     扫插件目录里的 php
 *   - 皮肤 <slug>       skins/<slug>/i18n/       扫皮肤目录里的 php
 *
 * 插件/皮肤用到的键如果核心也有（t('登录') 这类通用词），算核心的，不用自己再备一份 ——
 * 运行时 I18n 本来就按「核心优先、扩展补缺」合并词典。只有核心没有的键才要求进自己的 i18n/。
 *
 * 用法（仓库根目录下）：
 *   php docs-internal/i18n/extract.php            生成各域的 en.json（母键=英文原句，en.json 即键的 identity）
 *   php docs-internal/i18n/extract.php --check    只检查不写：代码里的键 vs en.json、
 *                                                 每个域每个语言（含 zh-hans）键是否齐全、占位符是否一致
 *
 * 动态键扫不出 t('...') 字面量的，从这里补（核心域专用）：
 *   - EmailTemplate::render([...]) 里静态文案字段的值（render 内部按字段 tIn）
 *   - $x('...') 的第一参（邮件闭包包装，键同样是英文原句）
 *   - AuthController::LOGIN_METHODS / LOGIN_METHOD_LABELS 的值
 *   - ActivityLog::ACTIONS / CATEGORIES 的值（展示点 t(actionLabel(...)) 动态取）
 *   - FamilyHelper::RELATIONSHIPS / RELEASE_REASONS 的值
 *   - TwoFactorController::getUserFactors 的 label / placeholder（JSON props 给 Vue）
 *   - RecoveryHelper::METHODS / ASSIST_METHODS 的 label 值、ASSIST_LABEL
 *   - config/app.php 的 scopes 描述
 *   - EmailPolicy::REJECT_MESSAGE
 */

require __DIR__ . '/../../src/I18n.php';

use Nomen\Src\I18n;

$root = dirname(__DIR__, 2);

$scanDirs = ['templates', 'controllers', 'src', 'config', 'public/js', 'frontend/src', 'public/nomen-install'];
$scanExts = ['php', 'js', 'vue'];
$scanExcludes = [
    'templates/admin/',          // 后台保持中文
    'controllers/AdminController.php',
    'public/js/admin.js',
    'src/EmailTemplate.php',     // 外壳文案在下面 render 扫描里收（键即字段值）
    'frontend/src/composables/useT.js',
    // 已迁 Vue / 不再由路由渲染的旧模板（留着只为回滚），里面的文案不算键
    'templates/family.php',
    'templates/notifications.php',
    'templates/notification-detail.php',
    'templates/settings.php',
];

/** 递归收要扫描的文件（frontend/src 有 pages/xxx/ 这种子目录，glob 的 * 到不了） */
function scanFiles(string $root, array $dirs, array $exts, array $excludes): array
{
    $files = [];
    foreach ($dirs as $dir) {
        $base = $root . '/' . $dir;
        if (!is_dir($base)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile() || !in_array($file->getExtension(), $exts, true)) {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            $rel = str_replace('\\', '/', substr($path, strlen(str_replace('\\', '/', $root)) + 1));
            if (str_contains($rel, 'node_modules/') || str_contains($rel, 'frontend/dist/')) {
                continue;
            }
            foreach ($excludes as $ex) {
                if (str_contains($rel, $ex)) {
                    continue 2;
                }
            }
            $files[] = $path;
        }
    }
    return $files;
}

/** 提取 PHP/JS 字符串字面量的内容（支持单双引号与常见转义） */
function unquote(string $raw): ?string
{
    $quote = $raw[0];
    if (strlen($raw) < 2 || $raw[strlen($raw) - 1] !== $quote) {
        return null;
    }
    $body = substr($raw, 1, -1);
    $unescaped = preg_replace_callback('/\\\\(.)/s', function ($m) {
        return match ($m[1]) {
            'n' => "\n", 't' => "\t", 'r' => "\r",
            '0' => "\0", '\\' => '\\', "'" => "'", '"' => '"',
            default => $m[1],
        };
    }, $body);
    return $unescaped;
}

function scanFileForKeys(string $file, array &$keys): void
{
    $content = file_get_contents($file);
    if ($content === false) {
        return;
    }
    // t( / tIn( 后紧跟一个字符串字面量（键）；第二参不管
    if (!preg_match_all('/(?<![A-Za-z0-9_$])(?:nomen_install_)?t(?:In)?\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/', $content, $m)) {
        return;
    }
    foreach ($m[1] as $raw) {
        $key = unquote($raw);
        if ($key === null || $key === '') {
            continue;
        }
        if (!isset($keys[$key])) {
            $keys[$key] = $file;
        }
    }
}

/** 从 PHP 源码里截取 from 起点开始的一对平衡括号内容 */
function balancedBlock(string $code, int $from): ?string
{
    $open = strpos($code, '(', $from);
    if ($open === false) return null;
    $depth = 0;
    $inStr = null;
    $len = strlen($code);
    for ($i = $open; $i < $len; $i++) {
        $ch = $code[$i];
        if ($inStr !== null) {
            if ($ch === '\\') { $i++; continue; }
            if ($ch === $inStr) $inStr = null;
            continue;
        }
        if ($ch === "'" || $ch === '"') { $inStr = $ch; continue; }
        if ($ch === '(') $depth++;
        elseif ($ch === ')') {
            $depth--;
            if ($depth === 0) return substr($code, $open + 1, $i - $open - 1);
        }
    }
    return null;
}

/** collectDynamicKeys 内部登记用 */
function addKey(array &$keys, string $k, string $src): void
{
    if ($k !== '' && !isset($keys[$k])) {
        $keys[$k] = $src;
    }
}

function collectDynamicKeys(string $root): array
{
    $keys = [];

    // 1) EmailTemplate::render([...]) 的静态文案字段（render 内部按字段 tIn，键=字段值）
    $fieldKeys = ['title', 'subtitle', 'preheader', 'footnote', 'text', 'label'];
    foreach (glob($root . '/controllers/*.php') ?: [] as $file) {
        if (str_contains($file, 'AdminController.php')) {
            continue; // 后台保持中文（审核结果邮件那几条已显式走 tIn，会在字面量扫描里被收进来）
        }
        $code = file_get_contents($file);
        if ($code === false) continue;
        $pos = 0;
        while (($p = strpos($code, 'EmailTemplate::render(', $pos)) !== false) {
            $block = balancedBlock($code, $p + strlen('EmailTemplate::render'));
            $pos = $p + 10;
            if ($block === null) continue;
            if (preg_match_all("/'(?:title|subtitle|preheader|footnote|text|label)'\s*=>\s*('(?:[^'\\\\]|\\\\.)*')/", $block, $m)) {
                foreach ($m[1] as $raw) {
                    $k = unquote($raw);
                    if ($k !== null) {
                        addKey($keys, $k, 'EmailTemplate::render@' . basename($file));
                    }
                }
            }
        }
    }

    // 2) AuthController::LOGIN_METHODS / LOGIN_METHOD_LABELS
    $auth = file_get_contents($root . '/controllers/AuthController.php');
    foreach (['LOGIN_METHODS', 'LOGIN_METHOD_LABELS'] as $const) {
        if (preg_match('/' . $const . '\s*=\s*(.*?)\];/s', $auth, $m)) {
            if (preg_match_all("/=>\s*('(?:[^'\\\\]|\\\\.)*')/", $m[1], $labels)) {
                foreach ($labels[1] as $raw) {
                    $k = unquote($raw);
                    if ($k) addKey($keys, $k, 'AuthController::' . $const);
                }
            }
        }
    }

    // 3) ActivityLog::ACTIONS / CATEGORIES 的值（模板里 t(actionLabel(...)) 动态取）
    $al = file_get_contents($root . '/src/ActivityLog.php');
    foreach (['ACTIONS', 'CATEGORIES'] as $const) {
        if (preg_match('/const\s+' . $const . '\s*=\s*\[(.*?)\];/s', $al, $m)) {
            if (preg_match_all("/=>\s*('(?:[^'\\\\]|\\\\.)*')/", $m[1], $labels)) {
                foreach ($labels[1] as $raw) {
                    $k = unquote($raw);
                    if ($k) addKey($keys, $k, 'ActivityLog::' . $const);
                }
            }
        }
    }

    // 4) TwoFactorController::getUserFactors 的 label / placeholder（Vue 输出点包 t）
    $tf = file_get_contents($root . '/controllers/TwoFactorController.php');
    if (preg_match('/function\s+getUserFactors.*?^    \}/ms', $tf, $fnMatch)) {
        $fnBody = $fnMatch[0];
        foreach (['label', 'placeholder'] as $field) {
            if (preg_match_all("/'" . $field . "'\s*=>\s*('(?:[^'\\\\]|\\\\.)*')/", $fnBody, $labels)) {
                foreach ($labels[1] as $raw) {
                    $k = unquote($raw);
                    if ($k) addKey($keys, $k, 'TwoFactorController::getUserFactors');
                }
            }
        }
    }

    // 5) RecoveryHelper::METHODS / ASSIST_METHODS / ASSIST_LABEL
    $rh = file_get_contents($root . '/src/RecoveryHelper.php');
    foreach (['METHODS', 'ASSIST_METHODS'] as $const) {
        if (preg_match('/' . $const . '\s*=\s*\[(.*?)\];/s', $rh, $m)) {
            if (preg_match_all("/'label'\s*=>\s*('(?:[^'\\\\]|\\\\.)*')/", $m[1], $labels)) {
                foreach ($labels[1] as $raw) {
                    $k = unquote($raw);
                    if ($k) addKey($keys, $k, 'RecoveryHelper::' . $const);
                }
            }
        }
    }
    if (preg_match("/ASSIST_LABEL\s*=\s*('(?:[^'\\\\]|\\\\.)*')/", $rh, $m)) {
        $k = unquote($m[1]);
        if ($k) addKey($keys, $k, 'RecoveryHelper::ASSIST_LABEL');
    }

    // 6) config/app.php scopes 描述
    $config = require $root . '/config/app.php';
    foreach (($config['scopes'] ?? []) as $desc) {
        if (is_string($desc) && $desc !== '') {
            addKey($keys, $desc, 'config/app.php scopes');
        }
    }

    // 7) EmailPolicy::REJECT_MESSAGE
    $ep = file_get_contents($root . '/src/EmailPolicy.php');
    if (preg_match("/REJECT_MESSAGE\s*=\s*('(?:[^'\\\\]|\\\\.)*')/", $ep, $m)) {
        $k = unquote($m[1]);
        if ($k) addKey($keys, $k, 'EmailPolicy::REJECT_MESSAGE');
    }

    // 8) Vue 组件 defineProps 里的 label 类默认值：defineProps 会被提升到模块作用域，
    //    那里不能调 t()，所以默认值保持键原文、在展示点 t(labelXxx)。不收集会被当成悬空键。
    foreach (scanFiles($root, ['frontend/src'], ['vue'], []) as $file) {
        $code = file_get_contents($file);
        if ($code === false) continue;
        if (preg_match_all("/label\w*\s*:\s*\{[^}]*default\s*:\s*'((?:[^'\\\\]|\\\\.)*)'/", $code, $m)) {
            foreach ($m[1] as $raw) {
                $k = unquote("'" . $raw . "'");
                if ($k !== null) {
                    addKey($keys, $k, 'vue prop default@' . basename($file));
                }
            }
        }
    }

    // 9) t($var) 的兜底键（模板里 t($labels[...] ?? 'First step verification') 这类，字面量扫描收不到）
    foreach (['First step verification', 'System notice', 'Not set'] as $k) {
        addKey($keys, $k, 'dynamic t() fallback');
    }

    // 10) FamilyHelper::RELATIONSHIPS / RELEASE_REASONS 的值（展示点 t(FamilyHelper::...) 动态取）
    $fh = file_get_contents($root . '/src/FamilyHelper.php');
    foreach (['RELATIONSHIPS', 'RELEASE_REASONS'] as $const) {
        if (preg_match('/' . $const . '\s*=\s*\[(.*?)\];/s', $fh, $m)) {
            if (preg_match_all("/=>\s*('(?:[^'\\\\]|\\\\.)*')/", $m[1], $labels)) {
                foreach ($labels[1] as $raw) {
                    $k = unquote($raw);
                    if ($k) addKey($keys, $k, 'FamilyHelper::' . $const);
                }
            }
        }
    }

    // 11) 邮件闭包 $x('...') 的第一参（控制器里 fn($k) => I18n::tIn(...) 的包装；AdminController 的恢复邮件也走它）
    foreach (scanFiles($root, ['controllers', 'src'], ['php'], []) as $file) {
        $code = file_get_contents($file);
        if ($code === false || !str_contains($code, '$x(')) {
            continue;
        }
        if (preg_match_all("/\\\$x\(\s*('(?:[^'\\\\]|\\\\.)*')/", $code, $m)) {
            foreach ($m[1] as $raw) {
                $k = unquote($raw);
                if ($k) addKey($keys, $k, '$x@' . basename($file));
            }
        }
    }

    return $keys;
}

/** 键里的占位符集合 */
function placeholders(string $text): array
{
    $names = [];
    if (preg_match_all('/\{:([A-Za-z_][A-Za-z0-9_]*)\}/', $text, $m)) {
        sort($m[1]);
        $names = $m[1];
    }
    return $names;
}

// ---------- 收集 ----------

$localesDir = $root . '/locales';
$coreKeys = [];   // key => 出处
foreach (scanFiles($root, $scanDirs, $scanExts, $scanExcludes) as $file) {
    scanFileForKeys($file, $coreKeys);
}
foreach (collectDynamicKeys($root) as $k => $src) {
    if (!isset($coreKeys[$k])) {
        $coreKeys[$k] = $src;
    }
}

// 插件 / 皮肤各收一份；核心也有的键归核心，剩下的才是自己的
$domains = [
    'core' => ['label' => '核心', 'dir' => $localesDir, 'keys' => $coreKeys],
];
foreach (['plugins' => 'plugin.json', 'skins' => 'skin.json'] as $type => $manifestName) {
    foreach (glob($root . '/' . $type . '/*/' . $manifestName) ?: [] as $manifestFile) {
        $slug = basename(dirname($manifestFile));
        $keys = [];
        foreach (scanFiles($root, [$type . '/' . $slug], $scanExts, []) as $file) {
            scanFileForKeys($file, $keys);
        }
        $domains[$slug] = [
            'label' => ($type === 'plugins' ? '插件 ' : '皮肤 ') . $slug,
            'dir' => dirname($manifestFile) . '/i18n',
            'keys' => array_diff_key($keys, $coreKeys),
        ];
    }
}

$checkOnly = in_array('--check', $argv, true);
$problems = [];

if ($checkOnly) {
    foreach ($domains as $id => $domain) {
        if ($domain['keys'] === []) {
            continue; // 没有自己的键（比如只有清单标题的插件）就不要求有词典目录
        }
        $domainKeys = $domain['keys'];

        if ($id === 'core') {
            $existing = is_file($domain['dir'] . '/en.json')
                ? (json_decode((string)file_get_contents($domain['dir'] . '/en.json'), true) ?: [])
                : [];
            foreach (array_diff(array_keys($existing), array_keys($domainKeys)) as $k) {
                $problems[] = "[悬] 核心en 有、代码里扫不到（改文案了？确认后删）: {$k}";
            }
            foreach (array_diff(array_keys($domainKeys), array_keys($existing)) as $k) {
                $problems[] = "[缺] 代码里有、核心en 没有: {$k}  ({$domainKeys[$k]})";
            }
        }

        foreach (I18n::slugs() as $slug) {
            $file = $domain['dir'] . '/' . $slug . '.json';
            if (!is_file($file)) {
                $problems[] = "[{$domain['label']} 缺文件] locales 词典缺 {$slug}.json";
                continue;
            }
            $data = json_decode((string)file_get_contents($file), true);
            if (!is_array($data)) {
                $problems[] = "[{$domain['label']} 坏JSON] {$slug}.json";
                continue;
            }
            foreach ($domainKeys as $k => $src) {
                if (!isset($data[$k]) || !is_string($data[$k]) || $data[$k] === '') {
                    $problems[] = "[{$domain['label']} {$slug} 缺条目] {$k}";
                    continue;
                }
                $need = placeholders($k);
                $have = placeholders($data[$k]);
                if ($need !== $have) {
                    $problems[] = "[{$domain['label']} {$slug} 占位符不一致] {$k}  要 " . implode(',', $need) . ' 有 ' . implode(',', $have);
                }
            }
        }
    }

    if ($problems) {
        echo "发现 " . count($problems) . " 个问题：\n" . implode("\n", $problems) . "\n";
        exit(1);
    }
    $total = 0;
    foreach ($domains as $domain) {
        $total += count($domain['keys']);
    }
    echo '检查通过：' . count($domains) . ' 个域、' . $total . " 个键，每个语言文件都齐全。\n";
    exit(0);
}

// ---------- 生成 ----------

foreach ($domains as $id => $domain) {
    if ($domain['keys'] === []) {
        continue;
    }
    // en 是母键语言：en.json 即键的 identity 映射，其它语言（含 zh-hans）都是要维护的翻译
    $zhFile = $domain['dir'] . '/en.json';
    if (!is_dir($domain['dir'])) {
        mkdir($domain['dir'], 0775, true);
    }
    $existing = is_file($zhFile) ? (json_decode((string)file_get_contents($zhFile), true) ?: []) : [];

    $added = array_diff_key($domain['keys'], $existing);
    $merged = $existing;
    foreach (array_keys($added) as $k) {
        $merged[$k] = $k;
    }
    ksort($merged, SORT_STRING);
    file_put_contents(
        $zhFile,
        json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
    );

    $stale = array_diff(array_keys($existing), array_keys($domain['keys']));
    $label = $domain['label'];
    $path = str_replace('\\', '/', substr($zhFile, strlen($root) + 1));
    echo "{$path} 已生成：" . count($merged) . " 个键（新增 " . count($added) . "，悬空 " . count($stale) . "）。\n";
    if ($added) {
        echo "  新增键：\n    " . implode("\n    ", array_slice(array_keys($added), 0, 50)) . (count($added) > 50 ? "\n    …" : '') . "\n";
    }
    if ($stale) {
        echo "  悬空键（代码里扫不到，确认后从这个域的所有语言文件里删）：\n    " . implode("\n    ", array_slice(array_values($stale), 0, 50)) . (count($stale) > 50 ? "\n    …" : '') . "\n";
    }
    if ($id === 'core' && ($added || $stale)) {
        echo "  其它语言文件（含 zh-hans）要跟上：新键补翻译、悬空键确认后全删。\n";
    }
}
