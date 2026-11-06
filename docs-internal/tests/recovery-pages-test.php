<?php
/**
 * 恢复流程页面的渲染守卫。跑法：
 *
 *     php docs-internal/tests/recovery-pages-test.php
 *
 * 盯的是几个我在这几页上栽过 / 差点栽的坑：
 *   1. 邮箱必须打码 —— 申诉页第 3 步、以及提交后的「申诉已提交」页
 *      （2026-10-09 用户发现：第 3 步打了码，sent 页又把完整地址打出来了，前面白打）
 *   2. `.auth-container` 只能是单子元素（它是 flex 居中容器，多一个子元素整个版式就崩）
 *   3. 用户可控内容必须转义
 *
 * 只渲染模板本体，不套 layout（layout 要 session / 数据库）。
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
$_SESSION = [];

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

use Nomen\Src\RecoveryHelper;

$fails = 0;
$check = function (string $label, $got, $want) use (&$fails) {
    $ok = $got === $want;
    if (!$ok) { $fails++; }
    printf("[%s] %-52s got=%s\n", $ok ? 'OK ' : 'FAIL', $label, var_export($got, true));
};

function renderTemplate(string $template, array $data): string
{
    extract($data);
    ob_start();
    require PROJ . '/templates/' . $template . '.php';
    return ob_get_clean();
}

$longEmail = 'red_team_13@caellab.com';   // 用户截图里露出来的那个
$masked    = RecoveryHelper::maskEmail($longEmail);
// 本地部分（red_team_13，11 位）只留首位，其余打星；域名也打掉（2026-10-15 起）
// —— 别手数星号，按长度算
$expectMasked = 'r' . str_repeat('*', mb_strlen('red_team_13') - 1) . '@c*.com';
$check('打码函数本身：本地部分只留首位，域名也打掉', $masked, $expectMasked);

// ---------------- 申诉已提交页 ----------------
echo "\n==== /recovery/sent ====\n";
$sent = renderTemplate('recovery-sent', ['contact' => $longEmail]);
$check('sent 页含打码后的地址', str_contains($sent, $masked), true);
$check('sent 页不再出现完整地址', str_contains($sent, $longEmail), false);
$check('sent 页不再露出完整域名', str_contains($sent, '@caellab.com'), false);
$check('sent 页仍是单子元素（flex 居中）',
    substr_count($sent, 'class="auth-container"') === 1 && substr_count($sent, 'class="auth-card"') === 1, true);

// 换个短地址：打码后仍应可读且不完整
$short = 'a@b.com';
$sentShort = renderTemplate('recovery-sent', ['contact' => $short]);
$check('本地部分仅 1 位时也只剩域名首字', str_contains($sentShort, 'a@b*.com'), true);

// contact 为空时不该留一行空的提示
$sentEmpty = renderTemplate('recovery-sent', ['contact' => null]);
$check('contact 为空时不出提示行', str_contains($sentEmpty, '审核结果与恢复链接都会发送至'), false);

// ---------------- 申诉表单第 3 步 ----------------
echo "\n==== /recovery 第 3 步 ====\n";
$form = renderTemplate('recovery', [
    'ctx' => ['user_id' => 4, 'username' => 'yunyun', 'email' => $longEmail, 'email_verified' => 1],
    'genders' => ['female' => '女性', 'male' => '男性', 'non-binary' => '非二元性别', 'prefer-not-to-say' => '我宁愿不说'],
    'error' => null,
    'old' => ['current_step' => '3'],
    'attachMax' => 5,
    'attachMaxLabel' => '1 MB',
]);
$check('第 3 步「当前账号邮箱」也是打码的', str_contains($form, $masked), true);
$check('第 3 步不出现完整账号邮箱', str_contains($form, $longEmail), false);

// ---------------- 用户可控内容必须转义 ----------------
echo "\n==== 转义 ====\n";
$evil = '<script>alert(1)</script>';
$evilForm = renderTemplate('recovery', [
    'ctx' => ['user_id' => 4, 'username' => $evil, 'email' => 'x@y.com', 'email_verified' => 1],
    'genders' => ['male' => '男性'],
    'error' => null,
    'old' => ['current_step' => '2', 'description' => $evil . ' 我常在这台电脑登录，注册邮箱 a***@b.com'],
    'attachMax' => 5,
    'attachMaxLabel' => '1 MB',
]);
$check('用户名里的脚本被转义', str_contains($evilForm, '<script>alert(1)</script>'), false);
// 描述现在进 props JSON（JSON_HEX_TAG 把 < > 转成 \u003C \u003E），原样 <script> 不许出现
$check('描述里的脚本被转义', str_contains($evilForm, '<script>alert(1)</script>'), false);
$check('描述进了 props JSON（已转义）', substr_count($evilForm, '\u003Cscript\u003E'), 2);

// ---------------- 打码函数的边界 ----------------
// 2026-10-15 换成新打码：本地留首字、**域名也打掉**（1*********@q*.com）。
// 只打本地部分的话域名会把账号暴露得差不多。星号数 = 位数（用户明确说不管这个）。
echo "\n==== maskEmail 边界 ====\n";
$check('无 @ 时原样返回', RecoveryHelper::maskEmail('nope'), 'nope');
$check('null -> 空串', RecoveryHelper::maskEmail(null), '');
$check('@ 开头时不崩', RecoveryHelper::maskEmail('@x.com'), '@x.com');
$check('常见 QQ 邮箱', RecoveryHelper::maskEmail('10000@qq.com'), '1****@q*.com');
$check('多段本地部分全打码', RecoveryHelper::maskEmail('a.b.c@x.com'), 'a****@x*.com');
$check('本地只剩一个字符', RecoveryHelper::maskEmail('a@b.com'), 'a@b*.com');
$check('本地部分为空时不崩', RecoveryHelper::maskEmail('@'), '@');
$check('单段域名', RecoveryHelper::maskEmail('x@localhost'), 'x@l*');

echo $fails === 0 ? "\nALL PASS\n" : "\n{$fails} FAILED\n";
exit($fails === 0 ? 0 : 1);
