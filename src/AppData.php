<?php
namespace Nomen\Src;

/**
 * 应用区（真 SPA）的数据源：模板首屏和 /api/app/* 端点共用同一份构建逻辑，
 * 两边永远不会漂。返回结构 = 各 Vue 页面组件的 props 契约。
 */
final class AppData
{
    /** 组装 SPA 首屏的完整 props：viewer + csrf + 语言表 + 当前页数据 */
    public static function shell(int $userId, array $page): array
    {
        return [
            'viewer' => self::viewer($userId),
            'brand' => [
                'name'      => (string)Brand::get('name'),
                'logo'      => (string)Brand::get('logo'),
                'url'       => (string)Brand::get('base_url'),
                'copyright' => (string)Brand::get('copyright'),
                'poweredBy' => (bool)Brand::get('powered_by', true),
                'poweredUrl' => \Nomen\Src\Special\Version::PROJECT_URL,
            ],
            'footerHtml' => Footer::html('app'),
            'csrf' => Csrf::getToken(),
            'locales' => array_map(static fn(array $entry): array => [
                'slug' => (string)$entry['slug'],
                'name' => (string)$entry['name'],
                'english' => (string)$entry['english'],
                'code' => (string)$entry['code'],
            ], I18n::registry()),
            'page' => $page,
        ];
    }

    /** 头部 / 导航要用的当前用户信息 */
    public static function viewer(int $userId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT id, username, display_name, avatar, role FROM users WHERE id = :id'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();

        $unreadCount = NotificationHelper::unreadCount($userId);
        $latest = $unreadCount > 0 ? NotificationHelper::latestUnread($userId) : null;

        $viewer = [
            'id' => $userId,
            'username' => (string)($user['username'] ?? ''),
            'displayName' => (string)($user['display_name'] ?? $user['username'] ?? ''),
            'avatarUrl' => \Nomen\Controllers\DashboardController::avatarUrlForUser($userId) ?? '',
            'nav' => Nav::items($userId),
            'isAdmin' => \Nomen\Controllers\AdminController::isAdminUser($userId),
            'unreadCount' => $unreadCount,
            'latestUnread' => $latest ? [
                'id' => (int)$latest['id'],
                'title' => (string)$latest['title'],
                'link' => (string)($latest['link'] ?: ('/notifications/' . (int)$latest['id'])),
            ] : null,
        ];

        return Hooks::filter('viewer.props', $viewer, $userId, $user);
    }

    /** @return array<int, array<string, mixed>> */
    private static function identitiesFor(int $userId): array
    {
        try {
            $stmt = Database::getInstance()->prepare(
                'SELECT ui.id, ui.provider_id, ui.display_name, ui.email, ui.created_at,
                        ip.slug AS provider_slug, ip.name AS provider_name
                 FROM user_identities ui
                 JOIN identity_providers ip ON ip.id = ui.provider_id
                 WHERE ui.user_id = :u
                 ORDER BY ip.sort_order ASC, ip.id ASC'
            );
            $stmt->execute([':u' => $userId]);

            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            return [];
        }
    }

    private static function flash(): array
    {
        return [
            'error' => (string)(Session::getFlash('error') ?: ''),
            'success' => (string)(Session::getFlash('success') ?: ''),
        ];
    }

    private static function userRow(int $userId): array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT id, username, display_name, email, email_verified, avatar, bio, birth_date,
                    gender, country, quick_login, two_factor_policy, status, role, created_at
               FROM users WHERE id = :id'
        );
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if (!$user) {
            Session::destroy();
            Response::redirect('/login');
        }
        return $user;
    }

    public static function dashboard(int $userId): array
    {
        $user = self::userRow($userId);
        $authorizations = \Nomen\Controllers\DashboardController::authorizationListFor($userId);
        $recentActivity = \Nomen\Controllers\DashboardController::activityFeedFor($userId);

        $flash = self::flash();

        return [
            'page' => 'dashboard',
            'data' => [
                'user' => [
                    'display_name' => (string)($user['display_name'] ?? $user['username']),
                    'username' => (string)$user['username'],
                    'avatar_url' => (string)(\Nomen\Controllers\DashboardController::avatarUrlForUser($userId) ?? ''),
                ],
                'authorizations' => $authorizations,
                'recentActivity' => $recentActivity,
                'success' => $flash['success'],
                'error' => $flash['error'],
            ],
        ];
    }

    public static function settings(int $userId): array
    {
        $user = self::userRow($userId);
        $emails = EmailSet::all($userId);
        $flash = self::flash();

        $genderMap = ['female' => '女性', 'male' => '男性', 'non-binary' => '非二元性别', 'prefer-not-to-say' => '我宁愿不说'];
        $genderDisplay = $genderMap[$user['gender'] ?? ''] ?? ($user['gender'] ?: '未设置');

        $verifiedEmails = array_values(array_filter($emails, static fn(array $row): bool => !empty($row['verified'])));

        $locationLabel = static function (array $row): string {
            $meta = 'IP: ' . ($row['ip_address'] ?: t('未知'));
            if (!empty($row['location']) && $row['location'] !== '未知位置' && $row['location'] !== '本地') {
                $meta .= t(' · 地点: ') . $row['location'];
            }
            return $meta;
        };

        $activeTokens = array_map(static function (array $token) use ($locationLabel): array {
            return [
                'deviceId' => (string)$token['device_id'],
                'deviceName' => (string)($token['device_name'] ?: t('未知设备')),
                'isCurrent' => $token['device_id'] === ($_COOKIE['caellab_device_id'] ?? ''),
                'meta' => $locationLabel($token)
                    . t(' · 首次: ') . date('Y-m-d H:i', strtotime((string)$token['first_seen_at']))
                    . t(' · 最近: ') . date('Y-m-d H:i', strtotime((string)$token['last_seen_at'])),
            ];
        }, \Nomen\Controllers\DashboardController::activeTokensFor($userId));

        $currentDeviceId = $_COOKIE['caellab_device_id'] ?? '';
        $trustedDevices = array_map(static function (array $device) use ($locationLabel, $currentDeviceId): array {
            $meta = $locationLabel($device)
                . t(' · 首次: ') . date('Y-m-d H:i', strtotime((string)$device['first_seen_at']))
                . t(' · 最近: ') . date('Y-m-d H:i', strtotime((string)$device['last_seen_at']));
            if (!empty($device['trusted_at'])) {
                $meta .= t(' · 信任至: ') . date('Y-m-d H:i', strtotime($device['trusted_at'] . ' +90 days'));
            }
            return [
                'deviceId' => (string)$device['device_id'],
                'deviceName' => (string)($device['device_name'] ?: t('未知设备')),
                'isCurrent' => $device['device_id'] === $currentDeviceId,
                'meta' => $meta,
            ];
        }, \Nomen\Controllers\DashboardController::trustedDevicesFor($userId));

        return [
            'page' => 'settings',
            'data' => [
                'features' => Features::all(),
                'user' => [
                    'username' => (string)$user['username'],
                    'email' => (string)($user['email'] ?? ''),
                    'displayName' => (string)($user['display_name'] ?? ''),
                    'bio' => (string)($user['bio'] ?? ''),
                    'avatarUrl' => (string)(\Nomen\Controllers\DashboardController::avatarUrlForUser($userId) ?? ''),
                    'birthDateDisplay' => $user['birth_date'] ? date(t('Y年m月d日'), strtotime((string)$user['birth_date'])) : t('未设置'),
                    'genderDisplay' => t($genderDisplay),
                    'countryDisplay' => (string)($user['country'] ?: t('未设置')),
                    'createdAtDisplay' => date('Y-m-d H:i', strtotime((string)$user['created_at'])),
                    'twoFactorPolicy' => (string)($user['two_factor_policy'] ?? 'new_device'),
                    'quickLogin' => !isset($user['quick_login']) || (int)$user['quick_login'] === 1,
                    'contactVerified' => !empty($user['email_verified'])
                        || EmailSet::hasVerified($userId)
                        || (Features::guardians() && FamilyHelper::guardianConfirmed($userId)),
                    'assistLabel' => t(RecoveryHelper::ASSIST_LABEL),
                    'error' => $flash['error'],
                    'success' => $flash['success'],
                ],
                'emails' => array_map(static fn(array $row): array => [
                    'id' => (int)$row['id'],
                    'email' => (string)$row['email'],
                    'is_primary' => !empty($row['is_primary']),
                    'verified' => !empty($row['verified']),
                ], $emails),
                'maxEmails' => EmailSet::MAX,
                'verifiedEmails' => array_map(static fn(array $row): array => [
                    'email' => (string)$row['email'],
                    'is_primary' => !empty($row['is_primary']),
                ], $verifiedEmails),
                'notifSettings' => NotificationHelper::getSettings($userId),
                'locales' => I18n::switchingAllowed() ? array_map(static fn(array $entry): array => [
                    'slug' => (string)$entry['slug'],
                    'name' => (string)$entry['name'],
                    'english' => (string)$entry['english'],
                ], I18n::registry()) : [],
                'currentLocale' => I18n::locale(),
                'skins' => array_map(static fn(array $entry): array => [
                    'slug' => (string)$entry['slug'],
                    'name' => (string)$entry['name'],
                    'description' => t((string)($entry['description'] ?? '')),
                ], array_values(Skins::available())),
                'currentSkin' => Skins::active(),
                'activeTokens' => $activeTokens,
                'trustedDevices' => $trustedDevices,
                'identities' => self::identitiesFor($userId),
                'identityProviders' => IdentityProviders::publicList(),
                'urls' => [
                    'avatar' => '/settings/avatar',
                    'profile' => '/settings/profile',
                    'password' => '/settings/password',
                    'sendResetCode' => '/settings/password/send-code',
                    'resetCode' => '/settings/password/reset-code',
                    'revokeDevice' => '/settings/devices/revoke',
                    'tempKeys' => [
                        'list' => '/settings/temp-keys',
                        'create' => '/settings/temp-keys',
                        'remove' => '/settings/temp-keys/delete',
                        'generate' => '/settings/temp-keys/generate',
                    ],
                ],
            ],
        ];
    }

    public static function family(int $userId): array
    {
        $user = \Nomen\Controllers\FamilyController::userDataFor($userId);

        $membership = FamilyHelper::childMembership($userId);
        $groups = FamilyHelper::groupsOfGuardian($userId);

        $childrenByGroup = [];
        foreach ($groups as $group) {
            $childrenByGroup[(int)$group['id']] = FamilyHelper::childrenOf((int)$group['id']);
        }

        $invitations = !empty($user['email'])
            ? FamilyHelper::claimableGroupsForEmail((string)$user['email'])
            : [];

        $mapGuardian = static fn(array $g): array => [
            'username' => (string)($g['username'] ?: $g['email']),
            'email' => (string)$g['email'],
            'user_id' => (int)($g['user_id'] ?? 0),
            'relationship' => (string)($g['relationship'] ?? 'other'),
        ];

        $mapChild = static fn(array $child): array => [
            'user_id' => (int)$child['user_id'],
            'username' => (string)$child['username'],
            'age' => FamilyHelper::ageOf($child['birth_date'] ?? null),
            'status' => (string)$child['status'],
            'user_status' => (int)($child['user_status'] ?? 1),
        ];

        $groupsOut = [];
        foreach ($groups as $group) {
            $groupsOut[] = [
                'id' => (int)$group['id'],
                'guardian_email' => (string)($group['guardian_email'] ?? ''),
                'guardians' => array_values(array_map($mapGuardian, FamilyHelper::guardiansOf((int)$group['id']))),
                'children' => array_map($mapChild, $childrenByGroup[(int)$group['id']] ?? []),
            ];
        }

        $statusLabels = [
            FamilyHelper::MEMBER_PENDING => '邀请中',
            FamilyHelper::MEMBER_ACTIVE => '已加入',
            FamilyHelper::MEMBER_INDEPENDENT => '已停止监护',
            FamilyHelper::MEMBER_REMOVED => '已退出',
        ];

        $flash = self::flash();

        return [
            'page' => 'family',
            'data' => [
                'user' => [
                    'id' => $userId,
                    'age' => FamilyHelper::ageOf($user['birth_date'] ?? null),
                    'hasOwnEmail' => !empty($user['email']),
                    'accountAgeOk' => !empty($user['created_at']) && strtotime((string)$user['created_at']) <= strtotime('-' . FamilyHelper::MIN_ACCOUNT_DAYS . ' days'),
                    'claimPendingMasked' => RecoveryHelper::maskEmail((string)Session::get('family_claim_email', '')),
                ],
                'membership' => $membership ? [
                    'status' => (string)$membership['status'],
                    'label' => t($statusLabels[$membership['status']] ?? $membership['status']),
                ] : null,
                'guardians' => $membership ? array_values(array_map($mapGuardian, FamilyHelper::guardiansOf((int)$membership['group_id']))) : [],
                'groups' => $groupsOut,
                'childrenByGroup' => array_map(
                    static fn(array $rows): array => array_map($mapChild, $rows),
                    $childrenByGroup
                ),
                'invitations' => array_map(static fn(array $invite): array => [
                    'email' => (string)$invite['email'],
                    'child_names' => (string)($invite['child_names'] ?? ''),
                ], $invitations),
                'releaseCheck' => $membership ? (FamilyHelper::canRelease($user) ?: []) : [],
                'releaseReasons' => FamilyHelper::RELEASE_REASONS,
                'consentAge' => FamilyHelper::consentAgeFor($user['country'] ?? null),
                'minDays' => FamilyHelper::MIN_ACCOUNT_DAYS,
                'relationships' => FamilyHelper::RELATIONSHIPS,
                'claimPending' => (string)Session::get('family_claim_email', ''),
                'resetChild' => (int)Session::get('family_reset_child', 0),
                'error' => $flash['error'],
                'success' => $flash['success'],
            ],
        ];
    }

    public static function notifications(int $userId, int $page = 1): array
    {
        $page = max(1, $page);
        $perPage = 20;

        ['items' => $items, 'total' => $total] = NotificationHelper::listFor($userId, $page, $perPage);
        $totalPages = max(1, (int)ceil($total / $perPage));

        $flash = self::flash();

        return [
            'page' => 'notifications',
            'data' => [
                'items' => array_map(static function (array $item): array {
                    $plain = mb_substr(strip_tags((string)$item['body']), 0, 80, 'UTF-8');
                    return [
                        'id' => (int)$item['id'],
                        'category' => (string)$item['category'],
                        'categoryLabel' => t(NotificationHelper::CATEGORY_LABELS[$item['category']] ?? '系统通知'),
                        'title' => (string)$item['title'],
                        'excerpt' => $plain . (mb_strlen(strip_tags((string)$item['body']), 'UTF-8') > 80 ? '…' : ''),
                        'read' => !empty($item['read_at']),
                        'createdAt' => date('Y-m-d H:i', strtotime((string)$item['created_at'])),
                    ];
                }, $items),
                'page' => $page,
                'totalPages' => $totalPages,
                'total' => (int)$total,
                'unreadCount' => NotificationHelper::unreadCount($userId),
                'error' => $flash['error'],
                'success' => $flash['success'],
            ],
        ];
    }

    public static function notificationDetail(int $userId, int $id): array
    {
        $notification = NotificationHelper::get($userId, $id);
        if (!$notification) {
            return [];
        }

        NotificationHelper::markRead($userId, $id);
        $notification['read_at'] = $notification['read_at'] ?: date('Y-m-d H:i:s');

        return [
            'page' => 'notification-detail',
            'data' => [
                'notification' => [
                    'id' => (int)$notification['id'],
                    'category' => (string)$notification['category'],
                    'categoryLabel' => t(NotificationHelper::CATEGORY_LABELS[$notification['category']] ?? '系统通知'),
                    'title' => (string)$notification['title'],
                    'body' => (string)$notification['body'],
                    'link' => (string)($notification['link'] ?? ''),
                    'linkText' => (string)($notification['link_text'] ?? ''),
                    'createdAt' => date('Y-m-d H:i', strtotime((string)$notification['created_at'])),
                ],
            ],
        ];
    }
}
