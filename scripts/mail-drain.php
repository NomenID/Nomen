<?php
/**
 * 待发通知邮件的后台补发器：一封一封发，被 SMTP 拒收就等 2 分钟重试，
 * 能发了继续下一封，直到发完或到达最长运行时长（默认 20 小时）。
 *
 * 用法：php scripts/mail-drain.php [最长小时数]
 * 进度靠 notifications.email_sent_at 标记，中断/重跑都只补没发的。
 */

require_once __DIR__ . '/../src/Db/Dialect.php';
require_once __DIR__ . '/../src/Db/Connection.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Email.php';
require_once __DIR__ . '/../src/EmailTemplate.php';
require_once __DIR__ . '/../src/NotificationHelper.php';

use Nomen\Src\Database;
use Nomen\Src\Email;
use Nomen\Src\EmailTemplate;
use Nomen\Src\NotificationHelper;

// 配置只在 Web 入口加载过，CLI 下自己读 .env
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}

const REJECT_WAIT   = 120;   // 普通拒收（限流/临时失败）后等多久重试同一封（秒）
const QUOTA_WAIT    = 1800;  // 配额类拒收（飞书 external recipient exceeds quota）等更久 —— 配额按天重置，试勤无益
const SEND_GAP      = 3;     // 两封成功之间的间隔（秒）
const MAX_PER_MAIL  = 5;     // 同一封连试几次仍失败就先搁置（避免卡在一个坏地址上）
const IDLE_WAIT     = 600;   // 整轮零成功（全局被限流）时等多久再来一轮（秒）

$maxHours = isset($argv[1]) ? max(0, (int)$argv[1]) : 20;
$deadline = time() + $maxHours * 3600;

$lock = fopen(__DIR__ . '/mail-drain.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "已有补发进程在跑，退出\n");
    exit(1);
}

function logLine(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

$db = Database::getInstance();

// 只取邮箱已验证、且该类邮件偏好开着、且地址非空的：未验证或用户关掉的一律不发
$rows = $db->query(
    "SELECT n.id, n.user_id, n.category, n.title, n.body, n.link, u.email
     FROM notifications n JOIN users u ON u.id = n.user_id
     WHERE n.email_sent_at IS NULL AND u.email_verified = 1 AND u.email <> ''
     ORDER BY n.id"
)->fetchAll(PDO::FETCH_ASSOC);

$queue = [];
$muted = 0;
foreach ($rows as $r) {
    $settings = NotificationHelper::getSettings((int)$r['user_id']);
    $key = NotificationHelper::SETTING_KEYS[$r['category']] ?? '';
    if ($key === '' || empty($settings[$key])) {
        $muted++;
        continue;
    }
    $queue[] = ['row' => $r, 'attempts' => 0];
}

logLine('start: pending=' . count($queue) . ' muted(用户关了邮件通知)=' . $muted . ' maxHours=' . $maxHours);

$sent = 0;
$failed = [];
$total = count($queue);

while ($queue && time() < $deadline) {
    $retry = [];
    $progress = false;
    $quotaPaused = false;

    foreach ($queue as $idx => $item) {
        if (time() >= $deadline) {
            $retry[] = $item;
            continue;
        }

        $row = $item['row'];

        // 原子抢票：只有把 NULL 改成时间戳成功的进程才有资格发这封。
        // 多进程/重复启动时，其余进程 rowCount=0 直接跳过，绝不会重复投递。
        $claim = $db->prepare('UPDATE notifications SET email_sent_at = NOW() WHERE id = :id AND email_sent_at IS NULL');
        $claim->execute([':id' => $row['id']]);
        if ($claim->rowCount() !== 1) {
            logLine('skip id=' . $row['id'] . ' (已被其他进程发送)');
            continue;
        }

        $html = EmailTemplate::notification($row['category'], $row['title'], $row['body'], $row['link']);
        $subject = NotificationHelper::deriveSubject($row['title']);

        $res = Email::sendDetailed($row['email'], $subject, $html);

        if ($res['ok']) {
            $sent++;
            $progress = true;
            logLine('sent id=' . $row['id'] . ' (' . $sent . '/' . $total . ')');
            sleep(SEND_GAP);
            continue;
        }

        // 没发出去：把票还回去，让别的批次/以后还能重试
        $db->prepare('UPDATE notifications SET email_sent_at = NULL WHERE id = :id')
           ->execute([':id' => $row['id']]);

        $item['attempts']++;
        $isQuota = stripos($res['error'], 'exceeds quota') !== false;
        logLine('rejected id=' . $row['id'] . ' attempt=' . $item['attempts']
            . ($isQuota ? ' [QUOTA]' : '') . ' code=' . $res['code'] . ' err=' . substr($res['error'], 0, 100));

        // 配额用尽是全账号级的：继续试其他封也是白试，本轮立刻收工去长等
        if ($isQuota) {
            $quotaPaused = true;
            $retry = array_merge($retry, array_slice($queue, $idx));
            break;
        }

        // 已经成功过别的邮件（说明 SMTP 是通的），这封还反复被拒 → 多半是坏地址，别再耗时间
        if ($item['attempts'] >= MAX_PER_MAIL && $progress) {
            $failed[] = $row['id'];
            logLine('skip id=' . $row['id'] . ' (疑似地址无效，留待人工处理)');
            continue;
        }

        if ($item['attempts'] < MAX_PER_MAIL) {
            logLine('wait ' . REJECT_WAIT . 's before retry');
            sleep(REJECT_WAIT);
        }
        $retry[] = $item;
    }

    $queue = $retry;

    if ($quotaPaused) {
        logLine('配额用尽，暂停 ' . QUOTA_WAIT . 's 后再探测（pending=' . count($queue) . '）');
        sleep(QUOTA_WAIT);
        continue;
    }

    if ($queue && !$progress) {
        // 整轮零成功：是全局被限流，不是单封问题 → 重置计数，等久一点再来
        foreach ($queue as &$item) {
            $item['attempts'] = 0;
        }
        unset($item);
        logLine('no progress this round (SMTP 限流中), wait ' . IDLE_WAIT . 's, pending=' . count($queue));
        sleep(IDLE_WAIT);
    }
}

$left = count($queue);
logLine('done: sent=' . $sent . ' pending=' . $left . ' skipped=' . count($failed)
    . ($failed ? ' skippedIds=' . implode(',', $failed) : '')
    . ' elapsed=' . round((time() - ($deadline - $maxHours * 3600)) / 60) . 'min');

flock($lock, LOCK_UN);
fclose($lock);
