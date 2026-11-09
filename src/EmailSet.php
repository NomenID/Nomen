<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

namespace Nomen\Src;

use PDO;

/**
 * 一个账号的邮箱集合：最多 MAX 个，其中一个是「首选」。
 *
 * `users.email` / `users.email_verified` 一直是「首选邮箱」的镜像 —— 注册、激活、
 * 设为首选这三条路会同时写两边，所以既有读它们的地方（通知、登录标识、
 * 家庭回退 contactEmail()）一行都不用改。**次要邮箱只存在于这张表里。**
 *
 * 「首选」只可能在已验证的地址上 —— 未验证的地址写进 users.email 会撞上
 * 「有自己的邮箱但没确认」那道激活闸，用户会被锁在激活页里（详见 remove()）。
 *
 * 这张表没有指向 users 的外键，删号时要自己清（见 AuthController::CLAIM_ORPHAN_TABLES）。
 */
class EmailSet
{
    /** 一个账号最多几个邮箱 */
    public const MAX = 5;

    /** 验证链接有效期（秒），与旧的改邮箱流程一致 */
    public const VERIFY_TTL = 86400;

    private static function db(): PDO
    {
        return Database::getInstance();
    }

    /** 该账号的全部邮箱：首选排最前，其次已验证的，最后按加入顺序 */
    public static function all(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $stmt = self::db()->prepare(
            'SELECT id, email, is_primary, verified, verify_token_expires_at, created_at, verified_at
               FROM user_emails
              WHERE user_id = :u
              ORDER BY is_primary DESC, verified DESC, id'
        );
        $stmt->execute([':u' => $userId]);
        return $stmt->fetchAll();
    }

    public static function verified(int $userId): array
    {
        return array_values(array_filter(
            self::all($userId),
            static fn(array $row): bool => !empty($row['verified'])
        ));
    }

    public static function primaryRow(int $userId): ?array
    {
        foreach (self::all($userId) as $row) {
            if (!empty($row['is_primary'])) {
                return $row;
            }
        }
        return null;
    }

    public static function primary(int $userId): ?string
    {
        $row = self::primaryRow($userId);
        return $row === null ? null : (string)$row['email'];
    }

    public static function findRow(int $userId, int $rowId): ?array
    {
        foreach (self::all($userId) as $row) {
            if ((int)$row['id'] === $rowId) {
                return $row;
            }
        }
        return null;
    }

    public static function count(int $userId): int
    {
        return count(self::all($userId));
    }

    public static function hasVerified(int $userId): bool
    {
        return self::verified($userId) !== [];
    }

    /** 这个地址是不是这个账号的（含还没验证的） */
    public static function belongsTo(int $userId, string $email): bool
    {
        $email = EmailPolicy::normalize($email);
        if ($email === '') {
            return false;
        }
        foreach (self::all($userId) as $row) {
            if ((string)$row['email'] === $email) {
                return true;
            }
        }

        $stmt = self::db()->prepare('SELECT email FROM users WHERE id = :u');
        $stmt->execute([':u' => $userId]);
        return EmailPolicy::normalize((string)($stmt->fetchColumn() ?: '')) === $email;
    }

    /**
     * 两个「位」各自唯一，互不干扰（2026-10-15 用户定的口径）：
     *
     * - **主邮箱位**唯一 —— 一个地址最多是一个账号的 `users.email`（那个唯一索引就是它）。
     * - **备选位**唯一 —— 一个地址最多是**一个**账号的备选（表上的 uniq_secondary_email）。
     * - 因此同一个地址最多落在 2 个账号上：一个当主、一个当备，这个组合是**允许**的。
     *
     * 于是登录/找回时输入这个地址可能命中 2 个账号 —— 由 candidatesForEmail() 交给用户自己挑。
     */
    public static function primaryTakenByOther(string $email, int $userId): bool
    {
        $email = EmailPolicy::normalize($email);
        if ($email === '') {
            return false;
        }
        $stmt = self::db()->prepare('SELECT id FROM users WHERE email = :e AND id <> :u LIMIT 1');
        $stmt->execute([':e' => $email, ':u' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * 这个地址被别的账号占着「备选」位吗。
     *
     * 只算**已验证**的备选行：未验证的行不占位，否则任何人都能把别人的邮箱塞进自己的
     * 待验证行里、让别人加不成备选（和注册那条「先到先得」不同 —— 主邮箱位有回收支线兜底，
     * 备选位没有，所以宁可让它不占位）。表上的唯一索引用的也是同一个口径。
     */
    public static function secondaryTakenByOther(string $email, int $userId): bool
    {
        $email = EmailPolicy::normalize($email);
        if ($email === '') {
            return false;
        }
        $stmt = self::db()->prepare(
            'SELECT id FROM user_emails WHERE email = :e AND is_primary = 0 AND verified = 1 AND user_id <> :u LIMIT 1'
        );
        $stmt->execute([':e' => $email, ':u' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * 发验证码时可以让用户挑的收信地址：**已验证**的邮箱，首选排最前，已打码。
     *
     * 只列已验证的 —— 没点确认链接的地址还不算「能收码的地址」，跟「登录方式只计已验证邮箱」
     * 一个口径。登录页拿它做「发到哪个邮箱」的选择框（**只给打码值**，
     * 免得把账号绑了哪些地址白送给屏幕前的人）。
     *
     * @return array<int, array{id:int,email:string,masked:string,is_primary:bool}>
     */
    public static function pickableAddresses(int $userId): array
    {
        $out = [];
        foreach (self::verified($userId) as $row) {
            $out[] = [
                'id'         => (int)$row['id'],
                'email'      => (string)$row['email'],
                'masked'     => EmailPolicy::mask((string)$row['email']),
                'is_primary' => !empty($row['is_primary']),
            ];
        }
        return $out;
    }

    /**
     * 这个地址能落到哪几个账号上：主邮箱持有者 + 已验证备选的持有者（最多 2 个）。
     *
     * 登录 / 找回时输入邮箱命中了多个，就必须让用户自己挑 —— 以前是一条 SQL 加 LIMIT 1，
     * 落到哪个账号取决于返回顺序，等于把「用这个邮箱登进哪个号」交给运气。
     *
     * @return array<int, array{id:int,username:string,display_name:string,avatar:?string,status:int,as_primary:bool}>
     */
    public static function candidatesForEmail(string $email): array
    {
        $email = EmailPolicy::normalize($email);
        if ($email === '') {
            return [];
        }

        $db = self::db();
        $out = [];

        $stmt = $db->prepare('SELECT id, username, display_name, avatar, status FROM users WHERE email = :e LIMIT 1');
        $stmt->execute([':e' => $email]);
        $row = $stmt->fetch();
        if ($row) {
            $row['as_primary'] = true;
            $out[] = $row;
        }

        $stmt = $db->prepare(
            'SELECT u.id, u.username, u.display_name, u.avatar, u.status
               FROM user_emails e
               JOIN users u ON u.id = e.user_id
              WHERE e.email = :e AND e.is_primary = 0 AND e.verified = 1
              LIMIT 2'
        );
        $stmt->execute([':e' => $email]);
        foreach ($stmt->fetchAll() as $row) {
            $row['as_primary'] = false;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * 加一个还没验证的邮箱。黑名单与占用由调用方先查（要给出不同的提示）。
     *
     * @return array{id:int, token:string}|null null = 条数已满或参数不合法
     */
    public static function add(int $userId, string $email): ?array
    {
        $email = EmailPolicy::normalize($email);
        if ($userId <= 0 || $email === '' || self::count($userId) >= self::MAX) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $db = self::db();
        $db->prepare(
            'INSERT INTO user_emails (user_id, email, is_primary, verified, verify_token, verify_token_expires_at, created_at)
             VALUES (:u, :e, 0, 0, :t, :exp, NOW())'
        )->execute([
            ':u'   => $userId,
            ':e'   => $email,
            ':t'   => $token,
            ':exp' => date('Y-m-d H:i:s', time() + self::VERIFY_TTL),
        ]);

        return ['id' => (int)$db->lastInsertId(), 'token' => $token];
    }

    /**
     * 验证通过。账号还没有首选邮箱时，这个自动成为首选
     * （第一次绑自己邮箱的孩子走的就是这条：本来就是冲着一个可用地址来的）。
     */
    public static function markVerified(int $userId, int $rowId): bool
    {
        $db = self::db();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'SELECT id, email, verified FROM user_emails WHERE id = :id AND user_id = :u'
                . Database::dialectFor($db)->forUpdate()
            );
            $stmt->execute([':id' => $rowId, ':u' => $userId]);
            $row = $stmt->fetch();
            if (!$row) {
                $db->rollBack();
                return false;
            }
            if (!empty($row['verified'])) {
                $db->commit();
                return true;
            }

            // 验证通过之后它要么占「备选」位、要么（账号还没有首选、且主邮箱位空着时）
            // 直接占「主邮箱」位 —— 两个位的唯一规则是分开的，按它实际会落进哪个位查重。
            // 注意「账号没有首选」不等于「就该升成首选」：这个地址可能已经是别人的主邮箱了，
            // 那种情况它就乖乖当备选（这正是「一主一备」允许的组合）。
            // 锁内再确认一次：点链接的这几分钟里可能被别的账号验证走了。
            $email        = (string)$row['email'];
            $canBePrimary = self::primaryRow($userId) === null
                && !self::primaryTakenByOther($email, $userId);

            if (!$canBePrimary && self::secondaryTakenByOther($email, $userId)) {
                $db->rollBack();
                return false;
            }

            $db->prepare(
                'UPDATE user_emails
                    SET verified = 1, verified_at = NOW(), verify_token = NULL, verify_token_expires_at = NULL
                  WHERE id = :id'
            )->execute([':id' => $rowId]);

            if ($canBePrimary) {
                self::writePrimary($userId, (int)$row['id'], $email);
            }

            $db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[EmailSet] 验证邮箱失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 设为首选。只认已验证的地址（理由见类注释）。
     *
     * 这一步会同时动两个位：目标地址去占「主邮箱」位，**旧首选被降成备选**去占「备选」位，
     * 而备选位是排他的 —— 旧首选如果已经是别的账号的备选，就换不了（它的备选位被自己占着）。
     *
     * @return string 空串 = 成功，否则是给用户看的原因
     */
    public static function setPrimary(int $userId, int $rowId): string
    {
        $row = self::findRow($userId, $rowId);
        if ($row === null || empty($row['verified'])) {
            return t('This address isn\'t verified yet — click the link in the email first');
        }
        if (!empty($row['is_primary'])) {
            return '';
        }
        if (self::primaryTakenByOther((string)$row['email'], $userId)) {
            return t('This address is already another account\'s primary and can\'t become yours');
        }

        $current = self::primaryRow($userId);
        if ($current !== null && self::secondaryTakenByOther((string)$current['email'], $userId)) {
            return t('Can\'t switch: your current primary “{:email}” is already a backup address on another account, and demoting it would collide. Sort that side out first and retry.', ['email' => $current['email']]);
        }

        self::writePrimary($userId, $rowId, (string)$row['email']);
        return '';
    }

    /**
     * 移除一个邮箱。两条底线：至少留一个；删完账号必须还剩至少一个已验证的邮箱
     * （不然收不到验证码和通知，恢复流程的「登录方式 ≥ 3」也会凑不满）。
     * 删掉首选时顺位把最早的已验证邮箱提上来。
     *
     * @return string 空串 = 成功，否则是给用户看的原因
     */
    public static function remove(int $userId, int $rowId): string
    {
        $rows = self::all($userId);
        if (count($rows) <= 1) {
            return t('Keep at least one address');
        }

        $target = self::findRow($userId, $rowId);
        if ($target === null) {
            return t('This address doesn\'t exist');
        }
        if (!empty($target['verified']) && count(self::verified($userId)) <= 1) {
            return t('This is your only verified address — add and verify another one before removing it');
        }

        // 删掉首选时要顺位提一个上来；提上去的那个得能占「主邮箱」位
        // （它的地址可能同时是别的账号的主邮箱，那就提不上去）。先算好再删，免得删完才发现。
        $promote = null;
        if (!empty($target['is_primary'])) {
            foreach ($rows as $candidate) {
                if ((int)$candidate['id'] === $rowId || empty($candidate['verified'])) {
                    continue;
                }
                if (!self::primaryTakenByOther((string)$candidate['email'], $userId)) {
                    $promote = $candidate;
                    break;
                }
            }
            if ($promote === null && !empty($target['verified'])) {
                return t('After removal nothing else could become primary (the remaining verified address is already another account\'s primary). Add and verify a new one first');
            }
        }

        $db = self::db();
        $db->prepare('DELETE FROM user_emails WHERE id = :id AND user_id = :u')
           ->execute([':id' => $rowId, ':u' => $userId]);

        if (!empty($target['is_primary'])) {
            if ($promote !== null) {
                self::writePrimary($userId, (int)$promote['id'], (string)$promote['email']);
            } else {
                $db->prepare('UPDATE users SET email = NULL, email_verified = 0 WHERE id = :u')
                   ->execute([':u' => $userId]);
            }
        }

        return '';
    }

    /**
     * 把 users.email / users.email_verified 同步成「首选」那一行。
     * 注册 / 激活 / 回收占用邮箱那几条路只写 users，不碰这张表，改完喊一声这里。
     */
    public static function syncPrimaryFromUsers(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $db = self::db();
        $stmt = $db->prepare('SELECT email, email_verified FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch() ?: [];

        $email = EmailPolicy::normalize((string)($user['email'] ?? ''));
        if ($email === '') {
            $db->prepare('DELETE FROM user_emails WHERE user_id = :u AND is_primary = 1')
               ->execute([':u' => $userId]);
            return;
        }

        $verified = !empty($user['email_verified']) ? 1 : 0;

        // 同一个地址可能本来就以次要行的身份存在（先加进来再设为首选），别留两条
        $db->prepare('DELETE FROM user_emails WHERE user_id = :u AND is_primary = 1 AND email <> :e')
           ->execute([':u' => $userId, ':e' => $email]);

        $db->prepare(
            'INSERT INTO user_emails (user_id, email, is_primary, verified, verify_token, verify_token_expires_at, created_at, verified_at)
             VALUES (:u, :e, 1, :v, NULL, NULL, NOW(), :va) ' .
            Database::dialectFor($db)->upsert(['user_id', 'email'], 'is_primary = 1')
        )->execute([
            ':u'  => $userId,
            ':e'  => $email,
            ':v'  => $verified,
            ':va' => $verified ? date('Y-m-d H:i:s') : null,
        ]);

        if ($verified) {
            $db->prepare(
                'UPDATE user_emails
                    SET verified = 1, verified_at = COALESCE(verified_at, NOW()),
                        verify_token = NULL, verify_token_expires_at = NULL
                  WHERE user_id = :u AND email = :e'
            )->execute([':u' => $userId, ':e' => $email]);
        }
    }

    /**
     * 发验证码时的收信地址：指定了就用指定的（必须是这个账号已验证的邮箱），
     * 否则首选；都没有再退回家长邮箱（未成年账号没有自己的地址）。
     */
    public static function resolveRecipient(array $user, ?string $requested = null): ?string
    {
        $userId = (int)($user['id'] ?? 0);
        $own    = trim((string)($user['email'] ?? ''));

        $requested = EmailPolicy::normalize((string)$requested);
        if ($requested !== '') {
            foreach (self::verified($userId) as $row) {
                if ((string)$row['email'] === $requested) {
                    return (string)$row['email'];
                }
            }
            if ($own !== '' && EmailPolicy::normalize($own) === $requested) {
                return $own;
            }
        }

        $primary = self::primary($userId);
        if ($primary !== null && $primary !== '') {
            return $primary;
        }
        if ($own !== '') {
            return $own;
        }

        return FamilyHelper::contactEmail($user);
    }

    /** 改写首选：表里只留一行为首选，同时把 users 上的镜像一起改掉 */
    private static function writePrimary(int $userId, int $rowId, string $email): void
    {
        $db = self::db();
        $db->prepare('UPDATE user_emails SET is_primary = 0 WHERE user_id = :u')->execute([':u' => $userId]);
        $db->prepare('UPDATE user_emails SET is_primary = 1, verified = 1 WHERE id = :id AND user_id = :u')
           ->execute([':id' => $rowId, ':u' => $userId]);
        $db->prepare('UPDATE users SET email = :e, email_verified = 1 WHERE id = :u')
           ->execute([':e' => $email, ':u' => $userId]);
    }
}
