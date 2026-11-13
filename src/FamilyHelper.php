<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

namespace Nomen\Src;

use PDO;

/**
 * 家庭：一个孩子账号 + 至少一个家长账号的关系。
 *
 * 术语对外的说法（界面上别再用内部叫法）：家庭组 = 家庭、认领 = 接受邀请、
 * 待认领 = 邀请中、解除监护 = 停止监护。内部方法名保留了早期的 claim 字样。
 *
 * 关键语义：`users.email` 为 NULL 表示这个孩子没有自己的收信地址，
 * 他的地址就是家长那条 `family_members.email`（contactEmail() 负责回退）。
 *
 * 成员状态只有两种含义：pending = 邀请中（还没人真的加入）、active = 已加入；
 * 之后是 independent（已停止监护）/ removed（已退出）。**「家长点了确认邮件」不改状态**，
 * 只记 consent_at —— 只有家长用自己的账号真的加入了，才叫绑定。
 *
 * 未满 consentAgeFor() 岁的账号，满了以后登录时会自动停止监护。
 */
class FamilyHelper
{
    public const KIND_GUARDIAN = 'guardian';
    public const KIND_CHILD    = 'child';

    public const GROUP_UNCLAIMED = 'unclaimed';
    public const GROUP_ACTIVE    = 'active';
    public const GROUP_RELEASED  = 'released';

    public const MEMBER_PENDING     = 'pending';
    public const MEMBER_ACTIVE      = 'active';
    public const MEMBER_INDEPENDENT = 'independent';
    public const MEMBER_REMOVED     = 'removed';

    /** 满这个岁数且账号创建满 MIN_ACCOUNT_DAYS 天，自动停止监护 */
    public const AUTO_RELEASE_AGE = 18;
    public const MIN_ACCOUNT_DAYS = 180;

    /** 停止监护申请一天最多几次 */
    public const RELEASE_REQUESTS_PER_DAY = 2;

    public const RELATIONSHIPS = [
        'father' => 'Father',
        'mother' => 'Mother',
        'other'  => 'Parent',
    ];

    /** 不能停止监护的原因，给界面用（说人话，别提"组""认领"这些内部词） */
    public const RELEASE_REASONS = [
        'ok'                 => '',
        'not_in_family'      => 'You have not joined any family yet',
        'no_consent'         => 'Your parent has not joined your family yet — there is no supervision to end',
        'no_own_email'       => 'Bind an email of your own first (your contact address is currently a parent’s email)',
        'group_unclaimed'    => 'Your parent has not joined your family yet — there is no supervision to end',
        'no_birth_date'      => 'The account has no date of birth on record, so the age cannot be determined',
        'too_young'          => 'Not yet old enough to end supervision in your region',
        'account_too_new'    => 'The account was created less than 6 months ago',
        'already_independent'=> 'Supervision has already ended',
    ];

    /** @var array<int, ?string> 同一请求内缓存联系邮箱，避免一封信查一次库 */
    private static array $contactCache = [];

    /** @var array{default:int,ages:array<string,int>}|null 同意年龄表，同一请求只读一次 */
    private static ?array $consentAgeConfig = null;

    /** @var array<string,array{zh:string,en:string,local:string}>|null 国家表（含三语名），同一请求只读一次 */
    private static ?array $countryNames = null;

    /** @var array<string,array>|null 逐国法律来源表，只有真要看来源时才载入 */
    private static ?array $consentAgeSources = null;

    private static function db(): PDO
    {
        return Database::getInstance();
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    public static function ageOf(?string $birthDate): ?int
    {
        if (!$birthDate) {
            return null;
        }
        try {
            $birth = new \DateTime($birthDate);
        } catch (\Throwable $e) {
            return null;
        }

        $today = new \DateTime('today');
        if ($birth > $today) {
            return 0;
        }
        return $today->diff($birth)->y;
    }

    /** 各国同意年龄表（config/consent-age.php），首次调用时载入并缓存 */
    public static function consentAgeTable(): array
    {
        if (self::$consentAgeConfig === null) {
            $file = __DIR__ . '/../config/consent-age.php';
            self::$consentAgeConfig = is_file($file) ? require $file : ['default' => 16, 'ages' => []];
        }
        return self::$consentAgeConfig;
    }

    public static function consentAgeFor(?string $country): int
    {
        $config  = self::consentAgeTable();
        $default = (int)($config['default'] ?? 16);
        $code    = strtoupper(trim((string)$country));
        if ($code === '') {
            return $default;
        }
        return (int)($config['ages'][$code] ?? $default);
    }

    /** 国家/地区表：码 → {zh, en, local}（config/countries.php），首次调用时载入并缓存 */
    public static function countries(): array
    {
        if (self::$countryNames === null) {
            $file = __DIR__ . '/../config/countries.php';
            self::$countryNames = is_file($file) ? require $file : [];
        }
        return self::$countryNames;
    }

    /** 认不出来就原样回显国家码，别让邮件里出现空白 */
    public static function countryName(?string $code): string
    {
        $code = strtoupper(trim((string)$code));
        if ($code === '') {
            return t('Not provided');
        }
        $entry = self::countries()[$code] ?? null;
        if ($entry === null) {
            return $code;
        }
        return is_array($entry) ? (string)$entry['zh'] : (string)$entry;
    }

    /** 逐国法律来源表（config/consent-age-sources.php），首次调用时载入并缓存 */
    public static function consentAgeSources(): array
    {
        if (self::$consentAgeSources === null) {
            $file = __DIR__ . '/../config/consent-age-sources.php';
            $config = is_file($file) ? require $file : [];
            self::$consentAgeSources = $config['sources'] ?? [];
        }
        return self::$consentAgeSources;
    }

    /** 引用名：中文法条名加书名号（后面直接跟条款号，不加空格），外文原名用空格分隔 */
    private static function sourceLabel(array $source): string
    {
        $title = trim((string)($source['title'] ?? ''));
        if ($title === '') {
            return '';
        }
        $provision = trim((string)($source['provision'] ?? ''));
        if (preg_match('/\p{Han}/u', $title)) {
            return "《{$title}》{$provision}";
        }
        return $provision === '' ? $title : "{$title} {$provision}";
    }

    /**
     * 一个国家/地区的儿童年龄 + 法律来源，注册页接口和两封家长邮件都从这里取。
     *
     * kind：statute 有法条 / advisory 只有监管指引（SG HK TW MY PH MO JP）/ none 该法没有年龄条款
     * / unverified 没核到。后两种对外只能引《虚舟实验室隐私权政策》，不能写"未查到"。
     */
    public static function consentAgeInfo(?string $code): array
    {
        $code      = strtoupper(trim((string)$code));
        $entry     = self::countries()[$code] ?? null;
        $source    = self::consentAgeSources()[$code] ?? [];
        $kind      = (string)($source['kind'] ?? 'unverified');
        $hasSource = in_array($kind, ['statute', 'advisory'], true);

        return [
            'country'    => $code,
            'zh'         => is_array($entry) ? (string)($entry['zh'] ?? $code) : (string)($entry ?: $code),
            'en'         => is_array($entry) ? (string)($entry['en'] ?? $code) : (string)($entry ?: $code),
            'age'        => self::consentAgeFor($code),
            'kind'       => $kind,
            'label'      => $hasSource ? self::sourceLabel($source) : '',
            'quote'      => $hasSource ? (string)($source['quote'] ?? '') : '',
            'url'        => $hasSource ? (string)($source['url'] ?? '') : '',
            'note'       => $hasSource ? (string)($source['note'] ?? '') : '',
            'policy_url' => EmailTemplate::privacyUrl(),
        ];
    }

    /**
     * 家长邮件里的「法律依据」块。有法条就写法条名、原文引句、官方链接；
     * 没有法定年龄的国家不能写"未查到"，统一引站点隐私政策。
     */
    public static function consentAgeEmailBlocks(array $info, ?string $locale = null): array
    {
        $x = fn(string $k, array $p = []): string => I18n::tIn($locale ?? I18n::locale(), $k, $p);

        if (in_array($info['kind'], ['statute', 'advisory'], true)) {
            $label = (string)$info['label'] . ($info['kind'] === 'advisory' ? $x('(local regulatory guidance)') : '');
            $blocks = [['type' => 'info', 'items' => [['label' => $x('Legal basis'), 'value' => $label]]]];

            $note = trim((string)$info['note']);
            if ($note !== '' && !str_contains($note, 'pending official verification')) {
                $blocks[] = ['type' => 'paragraph', 'muted' => true, 'text' => $note];
            }
            if (trim((string)$info['quote']) !== '') {
                $blocks[] = ['type' => 'paragraph', 'muted' => true, 'text' => $x('Quoted provision: {:quote}', ['quote' => $info['quote']])];
            }
            if (trim((string)$info['url']) !== '') {
                $blocks[] = ['type' => 'link', 'label' => $x('Official source'), 'url' => $info['url']];
            }
            return $blocks;
        }

        $blocks = [
            ['type' => 'info', 'items' => [[
                'label' => $x('Legal basis'),
                'value' => $x('Under the site privacy policy, this region is handled as {:age}.', ['age' => $info['age']]),
            ]]],
        ];

        if (trim((string)$info['policy_url']) !== '') {
            $blocks[] = ['type' => 'link', 'label' => $x('Privacy policy'), 'url' => (string)$info['policy_url']];
        }

        return $blocks;
    }

    /** 注册时填的性别，给邮件用；选项和叫法都走 config/genders.php */
    public static function genderLabel(?string $gender, ?string $locale = null): string
    {
        $value = (string)$gender;
        if ($value === '') {
            return I18n::tIn($locale ?? I18n::locale(), 'Not provided');
        }

        return Gender::label($value, $locale);
    }

    /** 邮件里怎么称呼这个孩子：填了男女就用他/她，其余用 TA */
    public static function genderPronoun(?string $gender, ?string $locale = null): string
    {
        $x = fn(string $k): string => I18n::tIn($locale ?? I18n::locale(), $k);
        return match ((string)$gender) {
            'female' => $x('She'),
            'male'   => $x('He'),
            default  => 'TA',
        };
    }

    /** 没出生日期记录时按成年处理 —— 与既有激活流程的判断一致 */
    public static function isMinorRow(array $user): bool
    {
        if (!Features::guardians()) {
            return false;
        }

        $age = self::ageOf($user['birth_date'] ?? null);
        if ($age === null) {
            return false;
        }
        return $age < self::consentAgeFor($user['country'] ?? null);
    }

    // ========== 收信地址 ==========

    /**
     * 账号的联系邮箱：自己的邮箱优先，未成年没邮箱就落到监护人邮箱上。
     *
     * 「自己的邮箱」= 首选邮箱（users.email）。账号没有首选、但有别的已验证邮箱时
     * 也该发到那个上面 —— 有一种合法状态是「一个邮箱既是 A 的主邮箱、又是 B 的备选」，
     * 这时候 B 可以只有备选、没有首选（EmailSet 里那两个位各自唯一）。
     * 没有它的话那种账号的通知邮件会静默发不出去。
     */
    public static function contactEmail(array $user): ?string
    {
        $own = trim((string)($user['email'] ?? ''));
        if ($own !== '') {
            return $own;
        }

        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        $verified = EmailSet::verified($userId);
        if ($verified !== []) {
            return (string)$verified[0]['email'];
        }

        return self::guardianEmailFor($userId);
    }

    /**
     * 家长那条地址确认过没有。
     *
     * 两种都算：家长点过确认邮件（recordConsent）、家长用自己的账号接受了邀请
     * （claimByGuardianEmail）—— 它们都记 consent_at。
     * 账号激活闸用它判断「没有自己邮箱的孩子是否至少有一个地址验证通过」。
     */
    public static function guardianConfirmed(int $childUserId): bool
    {
        if ($childUserId <= 0) {
            return false;
        }

        $stmt = self::db()->prepare(
            'SELECT 1 FROM family_members c
               JOIN family_members g ON g.group_id = c.group_id AND g.kind = :gk
              WHERE c.user_id = :uid AND c.kind = :ck
                AND c.status <> :cremoved AND g.status <> :gremoved
                AND g.consent_at IS NOT NULL
              LIMIT 1'
        );
        $stmt->execute([
            ':gk'       => self::KIND_GUARDIAN,
            ':uid'      => $childUserId,
            ':ck'       => self::KIND_CHILD,
            ':cremoved' => self::MEMBER_REMOVED,
            ':gremoved' => self::MEMBER_REMOVED,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /** 该孩子所在组的监护人邮箱；优先已认领的监护人，其次最早那条 */
    public static function guardianEmailFor(int $childUserId): ?string
    {
        if (array_key_exists($childUserId, self::$contactCache)) {
            return self::$contactCache[$childUserId];
        }

        $stmt = self::db()->prepare(
            'SELECT g.email FROM family_members c
               JOIN family_members g ON g.group_id = c.group_id AND g.kind = :gk
              WHERE c.user_id = :uid AND c.kind = :ck AND c.status <> :removed
                AND g.status <> :gremoved AND g.email IS NOT NULL AND g.email <> \'\'
              ORDER BY (g.user_id IS NULL), g.id
              LIMIT 1'
        );
        $stmt->execute([
            ':gk'       => self::KIND_GUARDIAN,
            ':ck'       => self::KIND_CHILD,
            ':uid'      => $childUserId,
            ':removed'  => self::MEMBER_REMOVED,
            ':gremoved' => self::MEMBER_REMOVED,
        ]);
        $email = $stmt->fetchColumn();

        return self::$contactCache[$childUserId] = ($email === false ? null : (string)$email);
    }

    // ========== 查询 ==========

    public static function childMembership(int $childUserId): ?array
    {
        $stmt = self::db()->prepare(
            'SELECT m.*, g.status AS group_status, g.claimed_at, g.unid AS group_unid
               FROM family_members m
               JOIN family_groups g ON g.id = m.group_id
              WHERE m.user_id = :uid AND m.kind = :ck
              ORDER BY m.id DESC
              LIMIT 1'
        );
        $stmt->execute([':uid' => $childUserId, ':ck' => self::KIND_CHILD]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function groupById(int $groupId): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM family_groups WHERE id = :g');
        $stmt->execute([':g' => $groupId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function guardiansOf(int $groupId): array
    {
        $stmt = self::db()->prepare(
            'SELECT m.*, u.username, u.display_name, u.avatar
               FROM family_members m
               LEFT JOIN users u ON u.id = m.user_id
              WHERE m.group_id = :g AND m.kind = :k
              ORDER BY m.id'
        );
        $stmt->execute([':g' => $groupId, ':k' => self::KIND_GUARDIAN]);
        return $stmt->fetchAll();
    }

    public static function childrenOf(int $groupId): array
    {
        $stmt = self::db()->prepare(
            'SELECT m.*, u.username, u.display_name, u.avatar, u.email AS own_email,
                    u.email_verified, u.status AS user_status, u.birth_date, u.country, u.created_at
               FROM family_members m
               LEFT JOIN users u ON u.id = m.user_id
              WHERE m.group_id = :g AND m.kind = :k
              ORDER BY m.id'
        );
        $stmt->execute([':g' => $groupId, ':k' => self::KIND_CHILD]);
        return $stmt->fetchAll();
    }

    /** 该用户名下作为监护人的所有组 */
    public static function groupsOfGuardian(int $guardianUserId): array
    {
        $stmt = self::db()->prepare(
            'SELECT g.*, m.id AS member_id, m.relationship, m.email AS guardian_email,
                    (SELECT COUNT(*) FROM family_members c
                      WHERE c.group_id = g.id AND c.kind = :ck AND c.status <> :removed) AS child_count
               FROM family_members m
               JOIN family_groups g ON g.id = m.group_id
              WHERE m.user_id = :uid AND m.kind = :gk AND m.status <> :gremoved
              ORDER BY g.id'
        );
        $stmt->execute([
            ':uid'      => $guardianUserId,
            ':gk'       => self::KIND_GUARDIAN,
            ':ck'       => self::KIND_CHILD,
            ':removed'  => self::MEMBER_REMOVED,
            ':gremoved' => self::MEMBER_REMOVED,
        ]);
        return $stmt->fetchAll();
    }

    /** 该监护人账号能不能管这个孩子（同组、孩子没独立也没被剔除） */
    public static function childOfGuardian(int $guardianUserId, int $childUserId): ?array
    {
        $stmt = self::db()->prepare(
            'SELECT c.*
               FROM family_members g
               JOIN family_members c ON c.group_id = g.group_id AND c.kind = :ck
              WHERE g.user_id = :gid AND g.kind = :gk AND g.status <> :gremoved
                AND c.user_id = :cid AND c.status IN (:pending, :active)
              LIMIT 1'
        );
        $stmt->execute([
            ':gid'      => $guardianUserId,
            ':gk'       => self::KIND_GUARDIAN,
            ':ck'       => self::KIND_CHILD,
            ':gremoved' => self::MEMBER_REMOVED,
            ':cid'      => $childUserId,
            ':pending'  => self::MEMBER_PENDING,
            ':active'   => self::MEMBER_ACTIVE,
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** 该孩子所在组里已经有账号的监护人（通知用） */
    public static function guardianAccountsOfChild(int $childUserId): array
    {
        $membership = self::childMembership($childUserId);
        if (!$membership) {
            return [];
        }

        $out = [];
        foreach (self::guardiansOf((int)$membership['group_id']) as $guardian) {
            if (!empty($guardian['user_id'])) {
                $out[] = $guardian;
            }
        }
        return $out;
    }

    /** 这条家长邮箱收到的邀请：还没被任何账号接受的 */
    public static function claimableGroupsForEmail(string $email): array
    {
        $stmt = self::db()->prepare(
            'SELECT g.id, g.created_at, m.relationship, m.email,
                    (SELECT COUNT(*) FROM family_members c
                      WHERE c.group_id = g.id AND c.kind = :ck AND c.status <> :removed) AS child_count
               FROM family_members m
               JOIN family_groups g ON g.id = m.group_id
              WHERE m.kind = :gk AND m.email = :e AND m.user_id IS NULL AND g.status = :unclaimed
              ORDER BY g.id'
        );
        $stmt->execute([
            ':gk'        => self::KIND_GUARDIAN,
            ':ck'        => self::KIND_CHILD,
            ':removed'   => self::MEMBER_REMOVED,
            ':e'         => self::normalizeEmail($email),
            ':unclaimed' => self::GROUP_UNCLAIMED,
        ]);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return [];
        }

        // GROUP_CONCAT 不是四个引擎都一个写法，孩子的用户名单独查一次再拼
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = self::db()->prepare(
            "SELECT c.group_id, u.username
               FROM family_members c
               JOIN users u ON u.id = c.user_id
              WHERE c.group_id IN ({$in}) AND c.kind = ? AND c.status <> ?
              ORDER BY u.username"
        );
        $stmt->execute([...$ids, self::KIND_CHILD, self::MEMBER_REMOVED]);

        $names = [];
        foreach ($stmt->fetchAll() as $row) {
            $names[(int)$row['group_id']][] = (string)$row['username'];
        }
        foreach ($rows as &$row) {
            $row['child_names'] = implode(',', $names[(int)$row['id']] ?? []);
        }

        return $rows;
    }

    /** 这个监护人邮箱是否已经有一个没作废的家庭组（用于挡住「第二个孩子自己注册」） */
    public static function existingGroupForGuardianEmail(string $email): ?array
    {
        $stmt = self::db()->prepare(
            'SELECT g.id, g.status
               FROM family_members m
               JOIN family_groups g ON g.id = m.group_id
              WHERE m.kind = :gk AND m.email = :e AND g.status <> :released
              ORDER BY g.id
              LIMIT 1'
        );
        $stmt->execute([
            ':gk'       => self::KIND_GUARDIAN,
            ':e'        => self::normalizeEmail($email),
            ':released' => self::GROUP_RELEASED,
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function isClaimed(int $groupId): bool
    {
        $stmt = self::db()->prepare(
            'SELECT COUNT(*) FROM family_members
              WHERE group_id = :g AND kind = :k AND user_id IS NOT NULL AND status <> :removed'
        );
        $stmt->execute([':g' => $groupId, ':k' => self::KIND_GUARDIAN, ':removed' => self::MEMBER_REMOVED]);
        return (int)$stmt->fetchColumn() > 0;
    }

    // ========== 写入 ==========

    /** 孩子自主注册时建组：一条监护人成员（还没有账号）+ 一条孩子成员 */
    public static function createUnclaimedGroup(string $guardianEmail, string $relationship, int $childUserId): int
    {
        $db = self::db();
        // 迁移脚本会把它套在自己的事务里，那时不能再开一个
        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }
        try {
            $db->prepare('INSERT INTO family_groups (unid, status) VALUES (:unid, :st)')
               ->execute([':unid' => Unid::generate(), ':st' => self::GROUP_UNCLAIMED]);
            $groupId = (int)$db->lastInsertId();

            $db->prepare(
                'INSERT INTO family_members (group_id, kind, user_id, email, relationship, status)
                 VALUES (:g, :k, NULL, :e, :r, :st)'
            )->execute([
                ':g'  => $groupId,
                ':k'  => self::KIND_GUARDIAN,
                ':e'  => self::normalizeEmail($guardianEmail),
                ':r'  => isset(self::RELATIONSHIPS[$relationship]) ? $relationship : 'other',
                ':st' => self::MEMBER_PENDING,
            ]);

            self::addChildMember($groupId, $childUserId, self::MEMBER_PENDING);
            if ($ownTransaction) {
                $db->commit();
            }
            return $groupId;
        } catch (\Throwable $e) {
            if ($ownTransaction) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function addChildMember(int $groupId, int $childUserId, string $status = self::MEMBER_PENDING, bool $consented = false): int
    {
        $stmt = self::db()->prepare(
            'INSERT INTO family_members (group_id, kind, user_id, email, status, consent_at)
             VALUES (:g, :k, :u, NULL, :st, ' . ($consented ? 'NOW()' : 'NULL') . ')'
        );
        $stmt->execute([':g' => $groupId, ':k' => self::KIND_CHILD, ':u' => $childUserId, ':st' => $status]);
        return (int)self::db()->lastInsertId();
    }

    /** 加一位还没账号的监护人（就是「把妈妈也加进来」） */
    public static function addPendingGuardian(int $groupId, string $email, string $relationship): int
    {
        $stmt = self::db()->prepare(
            'INSERT INTO family_members (group_id, kind, user_id, email, relationship, status)
             VALUES (:g, :k, NULL, :e, :r, :st)'
        );
        $stmt->execute([
            ':g'  => $groupId,
            ':k'  => self::KIND_GUARDIAN,
            ':e'  => self::normalizeEmail($email),
            ':r'  => isset(self::RELATIONSHIPS[$relationship]) ? $relationship : 'other',
            ':st' => self::MEMBER_PENDING,
        ]);
        return (int)self::db()->lastInsertId();
    }

    /**
     * 家长在确认邮件里点了确认：只记审计时间，**不动成员状态**。
     * 状态只由「家长用自己的账号真的加入」决定（claimByGuardianEmail）。
     */
    public static function recordConsent(int $childUserId): void
    {
        $membership = self::childMembership($childUserId);
        if (!$membership) {
            return;
        }

        self::db()->prepare(
            'UPDATE family_members SET consent_at = COALESCE(consent_at, NOW())
              WHERE group_id = :g AND kind IN (:c, :k)'
        )->execute([
            ':g' => $membership['group_id'],
            ':c' => self::KIND_CHILD,
            ':k' => self::KIND_GUARDIAN,
        ]);
    }

    /**
     * 凭已确认的家长邮箱把账号接进家庭（对外叫「接受邀请」）。
     * 邮箱必须先由调用方验证过（验证码，或账号自己的邮箱就是它）—— 这里只负责落库。
     *
     * @return int 接进去的家庭数
     */
    public static function claimByGuardianEmail(string $email, int $guardianUserId): int
    {
        $db    = self::db();
        $email = self::normalizeEmail($email);

        $stmt = $db->prepare(
            'SELECT group_id FROM family_members
              WHERE kind = :k AND email = :e AND user_id IS NULL'
        );
        $stmt->execute([':k' => self::KIND_GUARDIAN, ':e' => $email]);
        $groupIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$groupIds) {
            return 0;
        }

        $db->prepare(
            'UPDATE family_members SET user_id = :uid, status = :st, consent_at = COALESCE(consent_at, NOW())
              WHERE kind = :k AND email = :e AND user_id IS NULL'
        )->execute([':uid' => $guardianUserId, ':st' => self::MEMBER_ACTIVE, ':k' => self::KIND_GUARDIAN, ':e' => $email]);

        foreach ($groupIds as $groupId) {
            // 家长真的加入了，组里的孩子才算「已加入家庭」
            $db->prepare(
                'UPDATE family_members SET status = :st
                  WHERE group_id = :g AND kind = :k AND status = :pending'
            )->execute([
                ':st'      => self::MEMBER_ACTIVE,
                ':g'       => (int)$groupId,
                ':k'       => self::KIND_CHILD,
                ':pending' => self::MEMBER_PENDING,
            ]);

            $db->prepare(
                'UPDATE family_groups SET status = :st, claimed_at = COALESCE(claimed_at, NOW())
                  WHERE id = :g AND status = :unclaimed'
            )->execute([':st' => self::GROUP_ACTIVE, ':g' => (int)$groupId, ':unclaimed' => self::GROUP_UNCLAIMED]);
        }

        return count($groupIds);
    }

    /**
     * 家长点了确认邮件时顺手把「已经有账号、且账号邮箱就是那条家长邮箱」的人接进家庭。
     * 点确认本身就证明了控制那个邮箱，所以不用再走一遍验证码。
     *
     * @return int 接进去的家庭数
     */
    public static function linkExistingGuardianAccounts(int $childUserId): int
    {
        $membership = self::childMembership($childUserId);
        if (!$membership) {
            return 0;
        }

        $linked = 0;
        foreach (self::guardiansOf((int)$membership['group_id']) as $guardian) {
            if (!empty($guardian['user_id']) || empty($guardian['email'])) {
                continue;
            }
            $stmt = self::db()->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
            $stmt->execute([':e' => $guardian['email']]);
            $userId = (int)($stmt->fetchColumn() ?: 0);
            if ($userId > 0) {
                $linked += self::claimByGuardianEmail((string)$guardian['email'], $userId);
            }
        }
        return $linked;
    }

    /**
     * 孩子主动邀请一位家长（填家长邮箱）。没有家庭就新建一个，已经有就在里面再加一条家长邀请。
     *
     * @return int 邀请到的家庭数
     */
    public static function inviteGuardianForChild(int $childUserId, string $guardianEmail, string $relationship = 'other'): int
    {
        $membership = self::childMembership($childUserId);
        if (!$membership) {
            self::createUnclaimedGroup($guardianEmail, $relationship, $childUserId);
            return 1;
        }

        $email = self::normalizeEmail($guardianEmail);
        $stmt = self::db()->prepare(
            'SELECT COUNT(*) FROM family_members
              WHERE group_id = :g AND kind = :k AND email = :e AND user_id IS NULL'
        );
        $stmt->execute([':g' => $membership['group_id'], ':k' => self::KIND_GUARDIAN, ':e' => $email]);
        if ((int)$stmt->fetchColumn() > 0) {
            return 0;   // 同一地址已经邀请过了
        }

        self::addPendingGuardian((int)$membership['group_id'], $email, $relationship);
        return 1;
    }

    /** 组里还有没有没作废的孩子；没有就把组标成已结束 */
    public static function refreshGroupStatus(int $groupId): void
    {
        $db = self::db();

        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM family_members
              WHERE group_id = :g AND kind = :k AND status IN (:pending, :active)'
        );
        $stmt->execute([
            ':g'       => $groupId,
            ':k'       => self::KIND_CHILD,
            ':pending' => self::MEMBER_PENDING,
            ':active'  => self::MEMBER_ACTIVE,
        ]);
        $remaining = (int)$stmt->fetchColumn();

        if ($remaining === 0) {
            $db->prepare('UPDATE family_groups SET status = :st WHERE id = :g')
               ->execute([':st' => self::GROUP_RELEASED, ':g' => $groupId]);
            return;
        }

        if (self::isClaimed($groupId)) {
            $db->prepare('UPDATE family_groups SET status = :st WHERE id = :g AND status = :unclaimed')
               ->execute([':st' => self::GROUP_ACTIVE, ':g' => $groupId, ':unclaimed' => self::GROUP_UNCLAIMED]);
        }
    }

    public static function markIndependent(int $childUserId, string $reason): void
    {
        $membership = self::childMembership($childUserId);
        if (!$membership || $membership['status'] === self::MEMBER_INDEPENDENT) {
            return;
        }

        self::db()->prepare(
            'UPDATE family_members SET status = :st, independent_at = NOW(), independent_reason = :r WHERE id = :id'
        )->execute([':st' => self::MEMBER_INDEPENDENT, ':r' => $reason, ':id' => $membership['id']]);

        self::refreshGroupStatus((int)$membership['group_id']);
    }

    /** 孩子退组 / 被监护人移出 */
    public static function removeChildMember(int $childUserId): void
    {
        $membership = self::childMembership($childUserId);
        if (!$membership) {
            return;
        }

        self::db()->prepare(
            'UPDATE family_members SET status = :st, left_at = NOW() WHERE id = :id'
        )->execute([':st' => self::MEMBER_REMOVED, ':id' => $membership['id']]);

        self::refreshGroupStatus((int)$membership['group_id']);
    }

    public static function markReleaseRequested(int $childUserId): void
    {
        self::db()->prepare(
            'UPDATE family_members SET release_requested_at = NOW()
              WHERE user_id = :u AND kind = :k'
        )->execute([':u' => $childUserId, ':k' => self::KIND_CHILD]);
    }

    // ========== 独立判定 ==========

    /**
     * 这个账号现在能不能解除监护。
     *
     * @return array{ok:bool, reason:string, age?:int, need?:int}
     */
    public static function canRelease(array $user): array
    {
        $userId = (int)($user['id'] ?? 0);
        $membership = self::childMembership($userId);
        if (!$membership) {
            return ['ok' => false, 'reason' => 'not_in_family'];
        }
        if ($membership['status'] === self::MEMBER_INDEPENDENT) {
            return ['ok' => false, 'reason' => 'already_independent'];
        }
        if ($membership['status'] !== self::MEMBER_ACTIVE) {
            return ['ok' => false, 'reason' => 'no_consent'];
        }
        if (!self::isClaimed((int)$membership['group_id'])) {
            return ['ok' => false, 'reason' => 'group_unclaimed'];
        }
        // 有自己的邮箱才放行：解除监护后监护人邮箱就不再兜底了，
        // 没有邮箱的账号会连找回都做不了
        if (empty($user['email'])) {
            return ['ok' => false, 'reason' => 'no_own_email'];
        }

        $age  = self::ageOf($user['birth_date'] ?? null);
        $need = self::consentAgeFor($user['country'] ?? null);
        if ($age === null) {
            return ['ok' => false, 'reason' => 'no_birth_date'];
        }
        if ($age < $need) {
            return ['ok' => false, 'reason' => 'too_young', 'age' => $age, 'need' => $need];
        }
        if (strtotime((string)($user['created_at'] ?? '')) > strtotime('-' . self::MIN_ACCOUNT_DAYS . ' days')) {
            return ['ok' => false, 'reason' => 'account_too_new'];
        }

        return ['ok' => true, 'reason' => 'ok', 'age' => $age, 'need' => $need];
    }

    /**
     * 登录时惰性判一次：满 18 岁且账号满 6 个月的未成年账号，自动停止监护。
     * 还没被接受的邀请（pending）到这时候直接作废 —— 别留一条能对成年人行使家长权力的邀请。
     * 不建定时任务，晚一两天生效没关系。
     *
     * @return string|null null = 没触发，'auto' = 自动停止监护，'expired' = 邀请作废
     */
    public static function syncDueAutonomy(int $userId): ?string
    {
        $membership = self::childMembership($userId);
        if (!$membership
            || !in_array($membership['status'], [self::MEMBER_ACTIVE, self::MEMBER_PENDING], true)) {
            return null;
        }

        $stmt = self::db()->prepare('SELECT birth_date, country, created_at FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch() ?: [];

        $age = self::ageOf($user['birth_date'] ?? null);
        if ($age === null || $age < self::AUTO_RELEASE_AGE) {
            return null;
        }
        if (strtotime((string)($user['created_at'] ?? '')) > strtotime('-' . self::MIN_ACCOUNT_DAYS . ' days')) {
            return null;
        }

        if ($membership['status'] === self::MEMBER_PENDING) {
            self::removeChildMember($userId);
            return 'expired';
        }

        self::markIndependent($userId, 'auto');
        return 'auto';
    }
}
