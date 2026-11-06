<?php
/**
 * 一次性迁移：把「未成年的 users.email 就是家长邮箱」这种老账号搬进家庭组。
 *
 * 做法：为每个年龄还没到本地区同意年龄的账号建一个未认领的家庭组，
 * 监护人邮箱取它原来的 email，然后把 users.email 置空。
 *
 * ⚠️ 副作用：迁完之后这些账号不再能用「邮箱验证码」登录，要改用用户名 ——
 * 因为它们本来就没有自己的邮箱，收信走监护人邮箱。
 *
 * 跑法（默认只打印、不动数据）：
 *
 *     php docs-internal/migrations/family-backfill.php
 *     php docs-internal/migrations/family-backfill.php --apply
 *
 * 会先把受影响的 id + email 导出到同目录的 family-backfill-backup-<时间>.csv，
 * 万一要回滚就照那份把 email 填回去（回滚语句在文件末尾打印）。
 */

if (PHP_SAPI !== 'cli') {
    exit("只能在命令行跑\n");
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
spl_autoload_register(function (string $class) {
    $prefixes = [
        'Nomen\\Src\\' => dirname(__DIR__, 2) . '/src/',
    ];
    foreach ($prefixes as $prefix => $baseDir) {
        if (strpos($class, $prefix) === 0) {
            $file = $baseDir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (file_exists($file)) { require $file; return; }
        }
    }
});

use Nomen\Src\FamilyHelper;

$apply = in_array('--apply', $argv, true);

// --exclude=1,2,3：跳过指定账号（内部团队账号、生日明显填错的，别把它们当成未成年建组）
$exclude = [];
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--exclude=')) {
        $exclude = array_values(array_filter(array_map('intval', explode(',', substr($arg, 10)))));
    }
}

$db = \Nomen\Src\Database::getInstance();

$rows = $db->query(
    "SELECT id, username, email, email_verified, country, birth_date, created_at
       FROM users
      WHERE email IS NOT NULL AND email <> '' AND birth_date IS NOT NULL
      ORDER BY id"
)->fetchAll();

$targets = [];
foreach ($rows as $row) {
    $age = FamilyHelper::ageOf((string)$row['birth_date']);
    if ($age === null) {
        continue;
    }
    if ($age >= FamilyHelper::consentAgeFor((string)$row['country'])) {
        continue;
    }
    // 已经在家庭组里的就别重复搬
    if (FamilyHelper::childMembership((int)$row['id']) !== null) {
        continue;
    }
    if (in_array((int)$row['id'], $exclude, true)) {
        continue;
    }
    $row['age'] = $age;
    $targets[] = $row;
}

printf("扫描 %d 个有邮箱的账号，命中 %d 个未成年账号。%s\n\n", count($rows), count($targets),
    $exclude ? '（按 --exclude 跳过：' . implode(', ', $exclude) . '）' : '');
if (!$targets) {
    exit("没有要迁移的账号。\n");
}

foreach ($targets as $row) {
    printf(
        "  #%-4d %-20s %-32s %2d 岁 (%s)  %s\n",
        (int)$row['id'],
        (string)$row['username'],
        (string)$row['email'],
        (int)$row['age'],
        (string)$row['country'],
        empty($row['email_verified']) ? '未验证（组记 pending）' : '已验证（组记 active）'
    );
}

if (!$apply) {
    echo "\n这是预演，没有改任何数据。确认无误后加 --apply 再跑一次。\n";
    exit(0);
}

// 备份里是用户邮箱（个人信息），所以默认写到临时目录、不落仓库
$outArg = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $outArg = substr($arg, 6);
    }
}
$backup = $outArg ?: rtrim(sys_get_temp_dir(), '/\\') . '/family-backfill-backup-' . date('Ymd-His') . '.csv';
$fh = fopen($backup, 'w');
if ($fh === false) {
    exit("写不了备份文件 {$backup}，中止。\n");
}
fputcsv($fh, ['user_id', 'username', 'email']);
foreach ($targets as $row) {
    fputcsv($fh, [(int)$row['id'], (string)$row['username'], (string)$row['email']]);
}
fclose($fh);
printf("\n备份（含用户邮箱，别提交进仓库）已写到 %s\n\n", $backup);

$migrated = 0;
foreach ($targets as $row) {
    $childId = (int)$row['id'];
    $verified = !empty($row['email_verified']);

    try {
        $db->beginTransaction();

        $groupId = FamilyHelper::createUnclaimedGroup((string)$row['email'], 'other', $childId);
        if ($verified) {
            FamilyHelper::recordConsent($childId);
        }

        $db->prepare('UPDATE users SET email = NULL WHERE id = :id')->execute([':id' => $childId]);

        $db->commit();
        $migrated++;
        printf("  已迁移 #%d %s → 家庭组 #%d\n", $childId, (string)$row['username'], $groupId);
    } catch (\Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        printf("  [失败] #%d %s: %s\n", $childId, (string)$row['username'], $e->getMessage());
    }
}

printf("\n迁移完成：%d 个。\n", $migrated);
echo <<<TXT

回滚（照上面那份 CSV 把邮箱填回去，再把家庭组删掉）：
    UPDATE users SET email = '<原邮箱>' WHERE id = <user_id>;
    DELETE FROM family_members WHERE group_id IN (SELECT id FROM family_groups WHERE id IN (<组 id 列表>));
    DELETE FROM family_groups WHERE id IN (<组 id 列表>);

注意：两个孩子的家长如果以前用过两个不同的邮箱，迁移后就是两个独立的家庭组，
需要家长用自己的账号分别认领（他能收到哪个邮箱就能认领哪个）。

TXT;
