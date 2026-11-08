<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

/**
 * @var array $client
 * @var array $user
 * @var string $scope
 * @var string $state
 * @var array $scopeDescriptions
 * @var array $requestedScopeList
 * @var string $redirectUri
 * @var string $clientId
 * @var string $responseType
 * @var bool $isFirstParty 第一方应用（开发者 Id=1）
 */
$title = t('Authorized apps');

$props = [
    'csrf' => \Nomen\Src\Csrf::getToken(),
    'client' => [
        'name' => (string)$client['name'],
        'developer_name' => (string)($client['developer_name'] ?? ''),
        'icon' => (string)($client['icon'] ?? ''),
        'developer_icon' => (string)($client['developer_icon'] ?? ''),
        'homepage_url' => (string)($client['homepage_url'] ?? ''),
    ],
    'user' => [
        'display_name' => (string)($user['display_name'] ?? $user['username']),
        'username' => (string)$user['username'],
        'avatar_url' => (string)($user['avatar_url'] ?? ''),
    ],
    'scope' => $scope,
    'state' => $state,
    'scopeDescriptions' => $scopeDescriptions,
    'requestedScopeList' => array_values($requestedScopeList),
    'redirectUri' => $redirectUri,
    'clientId' => $clientId,
    'responseType' => $responseType,
    'isFirstParty' => (bool)$isFirstParty,
    'urls' => [
        'authorize' => '/oauth/authorize',
        'deny' => '/oauth/deny',
    ],
];

$denyUrl = '/oauth/deny?client_id=' . urlencode($clientId)
    . '&state=' . urlencode($state)
    . '&redirect_uri=' . urlencode($redirectUri);

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<div id="authorize-app" class="auth-container">
    <noscript>
        <div class="auth-card auth-card--wide">
            <div class="authorize-header">
                <div class="authorize-app-info">
                    <h2><?= htmlspecialchars($client['name']) ?></h2>
                </div>
            </div>
            <div class="authorize-body">
                <p class="authorize-desc"><strong><?= htmlspecialchars($client['name']) ?></strong> <?= t('wants to access your {:sitename} account:') ?></p>
                <form method="POST" action="/oauth/authorize" class="authorize-actions">
                    <?= \Nomen\Src\Csrf::field() ?>
                    <input type="hidden" name="client_id" value="<?= htmlspecialchars($clientId) ?>">
                    <input type="hidden" name="redirect_uri" value="<?= htmlspecialchars($redirectUri) ?>">
                    <input type="hidden" name="scope" value="<?= htmlspecialchars($scope) ?>">
                    <input type="hidden" name="state" value="<?= htmlspecialchars($state) ?>">
                    <input type="hidden" name="response_type" value="<?= htmlspecialchars($responseType) ?>">
                    <input type="hidden" name="action" value="allow">
                    <div class="authorize-buttons">
                        <a href="<?= htmlspecialchars($denyUrl) ?>" class="btn btn-secondary"><?= t('Deny') ?></a>
                        <button type="submit" class="btn btn-primary"><?= t('Authorize') ?></button>
                    </div>
                </form>
            </div>
        </div>
    </noscript>
    <script type="application/json" id="authorize-props"><?= json_encode($props, $jsonFlags) ?></script>
</div>
<?= \Nomen\Src\ViteAssets::entry('src/entries/authorize.js') ?>
