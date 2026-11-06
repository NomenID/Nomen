<?php
/**
 * 儿童同意年龄（consent-age）的守卫。跑法：
 *
 *     php docs-internal/tests/consent-age-test.php
 *
 * 盯的是这几个我自己会栽的点：
 *   1. 三份配置的码集必须完全一致（countries / consent-age / consent-age-sources）——
 *      少一个码，那个国家就会静默走 default，谁也不知道
 *   2. 来源表里的 age 必须等于年龄表里的值（两处都写着同一个数字，最容易改一处忘一处）
 *   3. 年龄不等于 default 的国家必须有可用来源；kind=statute 必须有条款号 + 官方 https 链接 + 原文引句
 *   4. kind=none / unverified 的 note 与 title 绝不能带核验过程的话（403 / 爬取失败）——
 *      note 是会进邮件、进接口的对外内容
 *   5. 接口 /api/consent-age 的参数校验与返回结构
 *   6. 注册页渲染出来必须是可搜索选择器（不再是 select），且不含预存的年龄表
 *
 * 只读配置 + 反射调接口，不需要数据库（接口本身不碰库）。不写 Redis、不连线上。
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
$_SESSION = [];

// 同意年龄与注册页国家选择属于可选功能，测试按开启状态跑
putenv('FEATURE_GUARDIANS=1');
putenv('FEATURE_COUNTRY=1');
putenv('BRAND_PRIVACY_URL=https://example.com/privacy');

define('PROJ', dirname(__DIR__, 2));

spl_autoload_register(function (string $class) {
    $prefixes = [
        'Nomen\\Src\\'         => PROJ . '/src/',
        'Nomen\\Controllers\\' => PROJ . '/controllers/',
    ];
    foreach ($prefixes as $prefix => $baseDir) {
        if (strpos($class, $prefix) === 0) {
            $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (file_exists($file)) { require $file; return; }
        }
    }
});

// t() 是全局函数不会被自动加载，测试里直接引 I18n（CLI 下 locale 固定 zh-hans，翻译键=中文原文）
require PROJ . '/src/I18n.php';

use Nomen\Src\FamilyHelper;

// 接口的每个 case 丢子进程跑：Response::json() 内部会 exit，父进程里抓不到输出
if (($argv[1] ?? '') === 'child') {
    $_GET = json_decode(base64_decode($argv[2] ?? ''), true) ?: [];
    $_SERVER['REMOTE_ADDR'] = '192.0.2.' . random_int(1, 254);   // TEST-NET，别占真实用户的限流桶
    $api = (new ReflectionClass(\Nomen\Controllers\ApiController::class))->newInstanceWithoutConstructor();
    $m = new ReflectionMethod($api, 'consentAge');
    $m->setAccessible(true);
    $m->invoke($api);
    exit;
}

$fails = 0;
$check = function (string $label, $got, $want) use (&$fails) {
    $ok = $got === $want;
    if (!$ok) { $fails++; }
    printf("[%s] %-52s got=%s\n", $ok ? 'OK ' : 'FAIL', $label, var_export($got, true));
};

$countries = require PROJ . '/config/countries.php';
$agesCfg   = require PROJ . '/config/consent-age.php';
$srcCfg    = require PROJ . '/config/consent-age-sources.php';
$ages      = $agesCfg['ages'];
$sources   = $srcCfg['sources'];
$default   = (int)$agesCfg['default'];

echo "==== 1) 三份配置的码集 ====\n";
$codes       = array_keys($countries); sort($codes);
$ageCodes    = array_keys($ages);      sort($ageCodes);
$sourceCodes = array_keys($sources);   sort($sourceCodes);
$check('国家表码数', count($codes), 246);
$check('年龄表与来源表码数与国家表一致', $ageCodes === $codes && $sourceCodes === $codes, true);
$check('年龄表里没有码走不到的分支（全部显式列出）', count($ages), count($codes));

echo "\n==== 2) 年龄值合法且与来源一致 ====\n";
$badAge = [];
$mismatch = [];
$noSource = [];
foreach ($ages as $code => $age) {
    $age = (int)$age;
    if ($age < 8 || $age > 25) { $badAge[] = $code; }
    $kind = (string)($sources[$code]['kind'] ?? '');
    $want = in_array($kind, ['statute', 'advisory'], true) ? (int)$sources[$code]['age'] : $default;
    if ($age !== $want) { $mismatch[] = $code; }
    if ($age !== $default && !in_array($kind, ['statute', 'advisory'], true)) { $noSource[] = $code; }
}
$check('年龄都在 8-25 之间', implode(',', $badAge), '');
$check('年龄 == 来源表里的年龄', implode(',', $mismatch), '');
$check('非默认年龄的国家都有来源', implode(',', $noSource), '');

echo "\n==== 3) 来源字段完整性 ====\n";
$noProvision = [];
$noQuote = [];
$badUrl = [];
$dirtyNote = [];
foreach ($sources as $code => $e) {
    if (($e['kind'] ?? '') !== 'statute') { continue; }
    // 不用 FILTER_VALIDATE_URL：韩国那条官方链接是韩文路径（law.go.kr/법령/…），
    // 那是真实可用的地址，只是不是 ASCII
    $url = trim((string)($e['url'] ?? ''));
    if (!preg_match('#^https?://\S+$#u', $url)) {
        $badUrl[] = $code;
    }
}
foreach ($sources as $code => $e) {
    $kind = (string)($e['kind'] ?? '');
    $note = (string)($e['note'] ?? '');
    if ($kind === 'statute' && trim((string)($e['quote'] ?? '')) === '') { $noQuote[] = $code; }
    if ($kind === 'unverified') { continue; }   // 未核验的本来就什么都没查到
    if (preg_match('/\b403\b|blocked|fetch(ed|ing)? fail|robot|bot-|refused|cloudflare|captcha/i', $note)) {
        $dirtyNote[] = $code;
    }
}
$check('statute 都有可用链接', implode(',', $badUrl), '');
$check('note 里没有核验过程的话（403/爬取失败）', implode(',', $dirtyNote), '');
echo "  提示：statute 里 " . count($noQuote) . " 个没有原文引句（" . (implode(',', $noQuote) ?: '无') . "），展示时会少一行原文\n";

echo "\n==== 4) FamilyHelper::consentAgeInfo ====\n";
$cn = FamilyHelper::consentAgeInfo('CN');
$check('CN 年龄', $cn['age'], 14);
$check('CN 是法条', $cn['kind'], 'statute');
$check('CN 引用名', $cn['label'], '《中华人民共和国个人信息保护法》第31条');
$check('CN 有原文引句', $cn['quote'] !== '', true);
$check('小写码也认', FamilyHelper::consentAgeInfo('cn')['age'], 14);

$sg = FamilyHelper::consentAgeInfo('SG');
$check('SG 算监管指引', $sg['kind'], 'advisory');
$check('SG 年龄', $sg['age'], 13);

$zz = FamilyHelper::consentAgeInfo('ZZ');
$check('未知码回默认', $zz['age'], 16);
$check('未知码没有引用名（走隐私权政策）', $zz['label'], '');
$check('未知码带隐私权政策链接', $zz['policy_url'], 'https://example.com/privacy');

$th = FamilyHelper::consentAgeInfo('TH');
$check('泰国 = 10（PDPA 原文）', $th['age'], 10);
$br = FamilyHelper::consentAgeInfo('BR');
$check('巴西 = 12（LGPD 儿童定义）', $br['age'], 12);

echo "\n==== 5) 家长邮件里的法律依据块 ====\n";
$statuteBlocks = FamilyHelper::consentAgeEmailBlocks($cn);
$check('法条国家：第一块是 info', $statuteBlocks[0]['type'], 'info');
$check('法条国家：写了法律依据', $statuteBlocks[0]['items'][0]['label'], '法律依据');
$check('法条国家：给了官方链接', in_array('link', array_column($statuteBlocks, 'type'), true), true);
$check('法条国家：给了条文原文', (bool)array_filter($statuteBlocks, fn($b) => str_contains((string)($b['text'] ?? ''), '条文原文')), true);

$noneBlocks = FamilyHelper::consentAgeEmailBlocks($zz);
$check('无立法国家：不出现"未查到"', str_contains(json_encode($noneBlocks, JSON_UNESCAPED_UNICODE), '未查到'), false);
$check('无立法国家：引隐私权政策', str_contains($noneBlocks[0]['items'][0]['value'], '站点隐私政策'), true);
$check('无立法国家：给了政策链接', str_contains((string)($noneBlocks[1]['url'] ?? ''), 'example.com/privacy'), true);

echo "\n==== 6) 接口 /api/consent-age ====\n";
// Response::json() 内部 exit，所以每个 case 丢子进程跑
$runChild = function (array $get): array {
    $arg = base64_encode(json_encode($get));
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' child ' . escapeshellarg($arg);
    $out = shell_exec($cmd);
    return json_decode((string)$out, true) ?: [];
};

$r = $runChild(['country' => 'CN']);
$check('CN: age=14', $r['age'] ?? null, 14);
$check('CN: 带引用名', $r['label'] ?? null, '《中华人民共和国个人信息保护法》第31条');
$check('CN: 带官方链接', str_contains((string)($r['url'] ?? ''), 'cac.gov.cn'), true);
$check('CN: 三语名都在', isset($r['zh'], $r['en']), true);

$r = $runChild(['country' => 'zz']);
$check('小写码: 回 16', $r['age'] ?? null, 16);
$check('未知码: kind=unverified', $r['kind'] ?? null, 'unverified');

$r = $runChild(['country' => 'CHN']);
$check('非法码: 回 invalid_request', $r['error'] ?? null, 'invalid_request');
$r = $runChild([]);
$check('缺参数: 回 invalid_request', $r['error'] ?? null, 'invalid_request');
$r = $runChild(['country' => "C'N"]);
$check('带引号的码也不放行', $r['error'] ?? null, 'invalid_request');

echo "\n==== 7) 注册页渲染（Vue 壳）====\n";
$html = (function () {
    extract(['error' => null, 'old' => []]);
    ob_start();
    require PROJ . '/templates/register.php';
    return ob_get_clean();
})();
$check('没有正文 inline script', str_contains($html, 'window.NOMEN_CONSENT_AGE'), false);
$check('没有预存年龄表', str_contains($html, 'data-consent-overrides'), false);
$check('有 Vue 挂载点', str_contains($html, 'id="register-app"'), true);
$check('props JSON 存在', preg_match('/<script type="application\/json" id="register-props">(.*?)<\/script>/s', $html, $propsMatch), 1);
$props = json_decode($propsMatch[1] ?? '', true);
$check('props 能解析', is_array($props), true);
$check('表单指向接口', $props['urls']['consentAge'] ?? '', '/api/consent-age');
$check('246 个国家（进 props）', is_array($props['countries'] ?? null) ? count($props['countries']) : 0, 246);
$check('国家带三语搜索串', (static function () use ($props): bool {
    foreach ($props['countries'] ?? [] as $c) {
        if (!isset($c['code'], $c['zh'], $c['en'], $c['local'])) return false;
    }
    return (bool)($props['countries'][0]['en'] ?? false);
})(), true);
$check('Vue 入口脚本已挂', preg_match('#/assets/dist/js/register-[A-Za-z0-9_-]+\.js#', $html), 1);
$vueSrc = (string)file_get_contents(PROJ . '/frontend/src/pages/register/RegisterApp.vue');
$check('第五步有来源行（组件里）', str_contains($vueSrc, 'consent-source'), true);

echo "\n==== 8) 旧引用清干净 ====\n";
foreach ([PROJ . '/public/js/register.js', PROJ . '/templates/register.php'] as $file) {
    $body = file_get_contents($file);
    $check(basename($file) . ' 不再引用预存表', str_contains($body, 'data-consent-overrides'), false);
}
$js = file_get_contents(PROJ . '/public/js/register.js');
$check('JS 不再有 consentAgeFor(旧实现)', str_contains($js, 'function consentAgeFor'), false);
$check('JS 会拉接口', str_contains($js, '/api/consent-age') || str_contains($js, 'consentAgeUrl'), true);
$check('JS 有失败兜底（按儿童显示）', str_contains($js, 'applyConsentRule(true, null)'), true);

echo $fails ? "\n有 {$fails} 项没过\n" : "\n全过\n";
exit($fails ? 1 : 0);
