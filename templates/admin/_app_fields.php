<?php
/** @var array|null $app */
/** @var array $appScopes */
/** @var array $availableScopes */
/** @var array $redirectUris */
$isPublic = ($app['client_type'] ?? 'confidential') === 'public';
$pkceOn = $isPublic || (int)($app['pkce_required'] ?? 0) === 1;
?>
<div class="form-group">
    <label for="app-name">名称</label>
    <input id="app-name" name="name" required minlength="2" maxlength="128"
           value="<?= htmlspecialchars((string)($app['name'] ?? '')) ?>" autocomplete="off">
</div>

<div class="form-group">
    <label for="app-description">说明</label>
    <textarea id="app-description" name="description" rows="2"><?= htmlspecialchars((string)($app['description'] ?? '')) ?></textarea>
    <span class="form-hint">显示在授权页上，一句话说清这个应用是做什么的。</span>
</div>

<div class="form-group">
    <label for="app-homepage">主页</label>
    <input id="app-homepage" name="homepage" type="url" value="<?= htmlspecialchars((string)($app['homepage_url'] ?? '')) ?>"
           placeholder="https://example.com">
</div>

<div class="form-group">
    <label for="app-redirects">回调地址</label>
    <textarea id="app-redirects" name="redirect_uris" rows="3" required
              placeholder="https://example.com/callback"><?= htmlspecialchars(implode("\n", $redirectUris)) ?></textarea>
    <span class="form-hint">每行一个。授权码只会跳到白名单里的地址，必须和请求里的 redirect_uri 完全一致。</span>
</div>

<div class="form-group">
    <label>权限</label>
    <div class="admin-check-list">
        <?php foreach ($availableScopes as $scope => $label): ?>
            <?php $checked = $scope === 'openid' || in_array($scope, $appScopes, true); ?>
            <label class="admin-check">
                <input type="checkbox" name="scopes[]" value="<?= htmlspecialchars((string)$scope) ?>"
                       <?= $checked ? 'checked' : '' ?> <?= $scope === 'openid' ? 'disabled' : '' ?>>
                <span><?= htmlspecialchars(t((string)$label)) ?></span>
                <code><?= htmlspecialchars((string)$scope) ?></code>
            </label>
        <?php endforeach; ?>
    </div>
    <span class="form-hint">openid 始终包含；用户授权页只会按这里勾选的权限请求。</span>
</div>

<div class="form-group">
    <label>客户端类型</label>
    <div class="admin-check-list">
        <label class="admin-check">
            <input type="radio" name="client_type" value="confidential" <?= $isPublic ? '' : 'checked' ?>>
            <span>机密客户端</span>
            <span class="form-hint">有 Client Secret，适合服务端应用。</span>
        </label>
        <label class="admin-check">
            <input type="radio" name="client_type" value="public" <?= $isPublic ? 'checked' : '' ?>>
            <span>公共客户端</span>
            <span class="form-hint">没有 Secret，适合桌面端和移动端，强制 PKCE。</span>
        </label>
    </div>
</div>

<div class="form-group">
    <label class="admin-check">
        <input type="checkbox" name="pkce_required" value="1" <?= $pkceOn ? 'checked' : '' ?>>
        <span>强制 PKCE</span>
    </label>
</div>

<div class="form-group">
    <label for="app-icon">图标</label>
    <?php if (!empty($app['icon'])): ?>
        <div class="admin-app-icon-preview">
            <img src="<?= htmlspecialchars(\Nomen\Src\IconUpload::url((string)$app['icon'])) ?>" alt="" width="48" height="48">
            <span class="form-hint">当前图标。选新文件会替换。</span>
        </div>
    <?php endif; ?>
    <input id="app-icon" type="file" name="icon" accept="image/png,image/jpeg,image/gif,image/webp">
    <span class="form-hint">PNG / JPEG / GIF / WebP，最大 2MB，会裁成 135×135。</span>
</div>
