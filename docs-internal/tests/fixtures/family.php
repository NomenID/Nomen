<?php
/**
 * 家庭：孩子账号 + 家长账号的关系。
 *
 * 页面上只用「家庭 / 家长 / 接受邀请 / 邀请中 / 停止监护」，不要出现"家庭组""认领""绑定"这类内部叫法。
 * @var array $user
 * @var array|null $membership           本人的孩子成员行（不在家庭里的孩子为 null）
 * @var array $guardians                 本人所在家庭的家长行
 * @var array $groups                    本人作为家长的家庭
 * @var array $childrenByGroup           上面每个家庭的孩子行
 * @var array $invitations               发给「我这个账号邮箱」的邀请
 * @var array|null $releaseCheck
 * @var array $releaseReasons
 * @var int $consentAge
 * @var int $minDays
 * @var array $relationships
 * @var string $claimPending
 * @var int $resetChild
 * @var string|null $error
 * @var string|null $success
 */
$title = t('家庭');
$extraCss = ['/css/family.css?v=96489965'];

$statusLabels = [
    \Nomen\Src\FamilyHelper::MEMBER_PENDING     => '邀请中',
    \Nomen\Src\FamilyHelper::MEMBER_ACTIVE      => '已加入',
    \Nomen\Src\FamilyHelper::MEMBER_INDEPENDENT => '已停止监护',
    \Nomen\Src\FamilyHelper::MEMBER_REMOVED     => '已退出',
];

$mapGuardian = static fn(array $g): array => [
    'username' => (string)($g['username'] ?: $g['email']),
    'email' => (string)$g['email'],
    'user_id' => (int)($g['user_id'] ?? 0),
    'relationship' => (string)($g['relationship'] ?? 'other'),
];

$groupsOut = [];
foreach ($groups as $group) {
    $children = [];
    foreach ($childrenByGroup[(int)$group['id']] ?? [] as $child) {
        $children[] = [
            'user_id' => (int)$child['user_id'],
            'username' => (string)$child['username'],
            'age' => \Nomen\Src\FamilyHelper::ageOf($child['birth_date'] ?? null),
            'status' => (string)$child['status'],
            'user_status' => (int)($child['user_status'] ?? 1),
        ];
    }
    $groupsOut[] = [
        'id' => (int)$group['id'],
        'guardian_email' => (string)($group['guardian_email'] ?? ''),
        'guardians' => array_values(array_map($mapGuardian, \Nomen\Src\FamilyHelper::guardiansOf((int)$group['id']))),
        'children' => $children,
    ];
}

$props = [
    'csrf' => \Nomen\Src\Csrf::getToken(),
    'user' => [
        'id' => (int)$user['id'],
        'age' => \Nomen\Src\FamilyHelper::ageOf($user['birth_date'] ?? null),
        'hasOwnEmail' => !empty($user['email']),
        'accountAgeOk' => !empty($user['created_at']) && strtotime((string)$user['created_at']) <= strtotime('-' . $minDays . ' days'),
        'claimPendingMasked' => \Nomen\Src\RecoveryHelper::maskEmail($claimPending),
    ],
    'membership' => $membership ? [
        'status' => (string)$membership['status'],
        'label' => t($statusLabels[$membership['status']] ?? $membership['status']),
    ] : null,
    'guardians' => array_values(array_map($mapGuardian, $guardians)),
    'groups' => $groupsOut,
    'childrenByGroup' => array_map(static fn(array $rows): array => array_map(static fn(array $child): array => [
        'user_id' => (int)$child['user_id'],
        'username' => (string)$child['username'],
        'age' => \Nomen\Src\FamilyHelper::ageOf($child['birth_date'] ?? null),
        'status' => (string)$child['status'],
        'user_status' => (int)($child['user_status'] ?? 1),
    ], $rows), $childrenByGroup),
    'invitations' => array_map(static fn(array $invite): array => [
        'email' => (string)$invite['email'],
        'child_names' => (string)($invite['child_names'] ?? ''),
    ], $invitations),
    'releaseCheck' => $releaseCheck ?: [],
    'releaseReasons' => $releaseReasons,
    'consentAge' => (int)$consentAge,
    'minDays' => (int)$minDays,
    'relationships' => $relationships,
    'claimPending' => (string)$claimPending,
    'resetChild' => (int)$resetChild,
    'error' => $error ?: '',
    'success' => $success ?: '',
];

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<div id="family-app" class="container">
    <noscript><p class="form-hint">家庭页需要开启 JavaScript。</p></noscript>
    <script type="application/json" id="family-props"><?= json_encode($props, $jsonFlags) ?></script>
</div>
<?= \Nomen\Src\ViteAssets::entry('src/entries/family.js') ?>
