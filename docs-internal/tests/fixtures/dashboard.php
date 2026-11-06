<?php
/**
 * @var array $user
 * @var array $authorizations
 * @var array $recentActivity
 * @var bool $showDevPromo
 */
$title = t('仪表盘');

$success = \Nomen\Src\Session::getFlash('success');
$error = \Nomen\Src\Session::getFlash('error');
$redirectedFromHome = (bool)\Nomen\Src\Session::getFlash('redirected_from_home');

$props = [
    'csrf' => \Nomen\Src\Csrf::getToken(),
    'user' => [
        'display_name' => (string)($user['display_name'] ?? $user['username']),
        'username' => (string)$user['username'],
        'avatar_url' => (string)($user['avatar_url'] ?? ''),
    ],
    'authorizations' => array_map(static function (array $auth): array {
        return [
            'client_id' => (string)$auth['client_id'],
            'client_name' => (string)$auth['client_name'],
            'icon' => (string)($auth['icon'] ?? ''),
            'developer_icon' => (string)($auth['developer_icon'] ?? ''),
            'homepage_url' => (string)($auth['homepage_url'] ?? ''),
            'scopes' => (string)$auth['scopes'],
            'updated_at' => date('Y-m-d H:i', strtotime((string)$auth['updated_at'])),
        ];
    }, $authorizations),
    'recentActivity' => array_map(static function (array $item): array {
        $label = t(\Nomen\Src\ActivityLog::actionLabel((string)$item['action']));
        if (($item['target_type'] ?? '') === 'oauth_client' && !empty($item['target_name'])) {
            $label .= '「' . $item['target_name'] . '」';
        }
        return [
            'label' => $label,
            'icon' => \Nomen\Src\ActivityLog::iconFor((string)$item['category']),
            'device_name' => (string)($item['device_name'] ?? ''),
            'created_at' => date('Y-m-d H:i', strtotime((string)$item['created_at'])),
        ];
    }, $recentActivity),
    'showDevPromo' => (bool)$showDevPromo,
    'success' => $success ?: '',
    'error' => $error ?: '',
    'redirectedFromHome' => $redirectedFromHome,
    'urls' => [
        'revokeBase' => '/dashboard/revoke/',
    ],
];

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<div id="dashboard-app" class="container">
    <noscript><p class="form-hint"><?= t('仪表盘需要开启 JavaScript。') ?></p></noscript>
    <script type="application/json" id="dashboard-props"><?= json_encode($props, $jsonFlags) ?></script>
</div>
<?= \Nomen\Src\ViteAssets::entry('src/entries/dashboard.js') ?>
