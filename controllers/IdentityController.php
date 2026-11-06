<?php
namespace Nomen\Controllers;

use Nomen\Src\{ActivityLog, AuthController, Csrf, Database, Hooks, IdentityAuth, IdentityProviders, Response, Session};
use PDO;
use Throwable;

/**
 * 第三方登录：发起授权、处理回调、建号 / 绑定、解绑。
 */
class IdentityController
{
    public function redirect(string $slug): void
    {
        $provider = IdentityProviders::find($slug);
        if ($provider === null || (int)$provider['enabled'] !== 1) {
            Response::error(404, '这个登录方式没有启用');
            return;
        }

        $loggedIn = (bool)Session::get('user_id');
        $link = $loggedIn && ($_GET['link'] ?? '') !== 'no';
        $redirectTo = $link ? '/settings' : $this->safeRedirect((string)($_GET['redirect_to'] ?? '/dashboard'));

        Response::redirect(IdentityAuth::begin($provider, $link, $redirectTo));
    }

    public function callback(string $slug): void
    {
        $provider = IdentityProviders::find($slug);
        $pending = IdentityAuth::pending();

        if ($provider === null || (int)$provider['enabled'] !== 1) {
            $this->fail('这个登录方式没有启用');
            return;
        }
        if ($pending === null || (string)$pending['provider'] !== (string)$provider['slug']) {
            $this->fail('登录状态已失效，请重新发起');
            return;
        }

        $state = (string)($_GET['state'] ?? '');
        if (!hash_equals((string)$pending['state'], $state)) {
            $this->fail('状态校验失败，请重新发起');
            return;
        }
        if (isset($_GET['error'])) {
            $this->fail('第三方登录被取消或拒绝：' . (string)($_GET['error_description'] ?? $_GET['error']));
            return;
        }

        $code = (string)($_GET['code'] ?? '');
        if ($code === '') {
            $this->fail('回调里没有授权码');
            return;
        }

        try {
            $token = IdentityAuth::exchange($provider, $code, (string)$pending['verifier']);
            $profile = IdentityAuth::profile($provider, $token['access_token']);
        } catch (Throwable $e) {
            error_log('[identity] ' . $provider['slug'] . ' 登录失败: ' . $e->getMessage());
            $this->fail('第三方登录失败：' . $e->getMessage());
            return;
        }

        IdentityAuth::clear();

        if (!empty($pending['link'])) {
            $this->linkToCurrentUser($provider, $profile);
            return;
        }

        $user = $this->resolveUser($provider, $profile);
        if ($user === null) {
            return;
        }

        $redirectTo = $this->safeRedirect((string)($pending['redirect'] ?? '/dashboard'));

        if ((int)$user['status'] !== 1) {
            $this->fail('这个账号已被停用');
            return;
        }

        if (AuthController::beginSecondFactorIfNeeded($user, 'social', $redirectTo, false)) {
            Response::redirect('/2fa/verify');
            return;
        }

        Session::regenerate();
        AuthController::finishLogin($user, $redirectTo, false, false, [], 'social');
    }

    public function unlink(): void
    {
        $userId = (int)Session::get('user_id');
        if ($userId <= 0) {
            Response::error(403);
            return;
        }
        if (!Csrf::validate($_POST['_csrf'] ?? null)) {
            Response::json(['success' => false, 'message' => '请求无效，请重试'], 422);
        }

        $providerId = (int)($_POST['provider_id'] ?? 0);
        $db = Database::getInstance();

        $stmt = $db->prepare('SELECT * FROM user_identities WHERE user_id = :u AND provider_id = :p LIMIT 1');
        $stmt->execute([':u' => $userId, ':p' => $providerId]);
        $identity = $stmt->fetch();
        if (!$identity) {
            Response::json(['success' => false, 'message' => '没有绑定这个登录方式'], 404);
        }

        if (!$this->canRemoveIdentity($userId)) {
            Response::json(['success' => false, 'message' => '这是你最后的登录方式，先验证邮箱或绑定另一种方式'], 422);
        }

        $db->prepare('DELETE FROM user_identities WHERE user_id = :u AND provider_id = :p')
            ->execute([':u' => $userId, ':p' => $providerId]);

        ActivityLog::record([
            'action'      => 'identity.unlinked',
            'category'    => ActivityLog::CAT_AUTH,
            'target_type' => 'identity',
            'target_id'   => (string)$providerId,
            'summary'     => '解绑第三方登录方式',
        ]);

        Response::json(['success' => true, 'message' => '已解绑']);
    }

    private function linkToCurrentUser(array $provider, array $profile): void
    {
        $userId = (int)Session::get('user_id');
        if ($userId <= 0) {
            $this->fail('登录状态已失效，请重新登录后再绑定');
            return;
        }

        $db = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT user_id FROM user_identities WHERE provider_id = :p AND subject = :s LIMIT 1'
        );
        $stmt->execute([':p' => (int)$provider['id'], ':s' => $profile['subject']]);
        $owner = (int)$stmt->fetchColumn();

        if ($owner > 0 && $owner !== $userId) {
            $this->fail('这个第三方账号已经绑定到另一个账号了');
            return;
        }

        $this->saveIdentity($userId, $provider, $profile);

        ActivityLog::record([
            'action'      => 'identity.linked',
            'category'    => ActivityLog::CAT_AUTH,
            'target_type' => 'identity',
            'target_id'   => (string)$provider['id'],
            'target_name' => (string)$provider['name'],
            'summary'     => '绑定第三方登录：' . $provider['name'],
        ]);

        Session::flash('success', '已绑定 ' . $provider['name'] . '，下次可以直接用它登录');
        Response::redirect('/settings');
    }

    /** @return array|null 落库后的用户行；失败时已经输出错误并返回 null */
    private function resolveUser(array $provider, array $profile): ?array
    {
        $db = Database::getInstance();

        $stmt = $db->prepare('SELECT * FROM user_identities WHERE provider_id = :p AND subject = :s LIMIT 1');
        $stmt->execute([':p' => (int)$provider['id'], ':s' => $profile['subject']]);
        $identity = $stmt->fetch();

        if ($identity) {
            $user = $this->userById((int)$identity['user_id']);
            if ($user === null) {
                $this->fail('绑定的账号不存在了');
                return null;
            }
            $this->saveIdentity((int)$user['id'], $provider, $profile);

            return $user;
        }

        // 提供方明确说邮箱已验证，且站内已有同邮箱账号：直接绑定，不建重复账号
        if ($profile['email_verified'] && $profile['email'] !== '') {
            $stmt = $db->prepare('SELECT * FROM users WHERE email = :e LIMIT 1');
            $stmt->execute([':e' => $profile['email']]);
            $user = $stmt->fetch();
            if ($user) {
                $this->saveIdentity((int)$user['id'], $provider, $profile);

                ActivityLog::record([
                    'action'      => 'identity.linked',
                    'category'    => ActivityLog::CAT_AUTH,
                    'actor_type'  => 'user',
                    'actor_id'    => (int)$user['id'],
                    'actor_username' => (string)$user['username'],
                    'target_type' => 'identity',
                    'target_id'   => (string)$provider['id'],
                    'target_name' => (string)$provider['name'],
                    'summary'     => '按已验证邮箱自动绑定：' . $provider['name'],
                ]);

                return $user;
            }
        }

        return $this->createUser($provider, $profile);
    }

    private function createUser(array $provider, array $profile): ?array
    {
        $db = Database::getInstance();
        $config = require dirname(__DIR__) . '/config/app.php';

        $base = $profile['name'] !== '' ? $profile['name'] : ($profile['email'] !== '' ? strstr($profile['email'], '@', true) : $provider['slug'] . '-user');
        $username = $this->uniqueUsername($base);
        $email = $profile['email'] !== '' ? $profile['email'] : null;
        $passwordHash = password_hash(bin2hex(random_bytes(32)) . (string)($config['pepper'] ?? ''), PASSWORD_ARGON2ID);

        $stmt = $db->prepare(
            'INSERT INTO users (username, email, password_hash, display_name, avatar, email_verified, locale)
             VALUES (:u, :e, :p, :d, :a, :v, :l)'
        );
        try {
            $stmt->execute([
                ':u' => $username,
                ':e' => $email,
                ':p' => $passwordHash,
                ':d' => $profile['name'] !== '' ? mb_substr($profile['name'], 0, 64) : $username,
                ':a' => $profile['avatar'] !== '' ? mb_substr($profile['avatar'], 0, 512) : null,
                ':v' => $profile['email_verified'] ? 1 : 0,
                ':l' => null,
            ]);
        } catch (\PDOException $e) {
            error_log('[identity] 创建账号失败: ' . $e->getMessage());
            $this->fail('这个邮箱已经被占用了，请先登录原账号再绑定');
            return null;
        }

        $userId = (int)$db->lastInsertId();
        $this->saveIdentity($userId, $provider, $profile);

        ActivityLog::record([
            'action'      => 'identity.registered',
            'category'    => ActivityLog::CAT_AUTH,
            'target_type' => 'user',
            'target_id'   => (string)$userId,
            'target_name' => $username,
            'summary'     => '通过 ' . $provider['name'] . ' 创建账号',
        ]);

        Hooks::run('user.registered', [$userId, $username, $email]);

        return $this->userById($userId);
    }

    private function saveIdentity(int $userId, array $provider, array $profile): void
    {
        $db = Database::getInstance();
        $dialect = Database::dialectFor($db);

        $now = date('Y-m-d H:i:s');
        $sql = 'INSERT INTO user_identities (user_id, provider_id, subject, email, display_name, avatar_url, raw, created_at, updated_at)
                VALUES (:u, :p, :s, :e, :n, :a, :r, :c, :ua) '
            . $dialect->upsert(
                ['user_id', 'provider_id'],
                'subject = :s2, email = :e2, display_name = :n2, avatar_url = :a2, raw = :r2, updated_at = :u2'
            );
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':u' => $userId,
            ':p' => (int)$provider['id'],
            ':s' => (string)$profile['subject'],
            ':e' => $profile['email'] !== '' ? $profile['email'] : null,
            ':n' => $profile['name'] !== '' ? mb_substr($profile['name'], 0, 191) : null,
            ':a' => $profile['avatar'] !== '' ? mb_substr($profile['avatar'], 0, 500) : null,
            ':r' => json_encode($profile['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':c' => $now,
            ':ua' => $now,
            ':s2' => (string)$profile['subject'],
            ':e2' => $profile['email'] !== '' ? $profile['email'] : null,
            ':n2' => $profile['name'] !== '' ? mb_substr($profile['name'], 0, 191) : null,
            ':a2' => $profile['avatar'] !== '' ? mb_substr($profile['avatar'], 0, 500) : null,
            ':r2' => json_encode($profile['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':u2' => $now,
        ]);
    }

    private function userById(int $id): ?array
    {
        $stmt = Database::getInstance()->prepare(
            'SELECT id, username, email, display_name, avatar, status, role, email_verified, two_factor_policy, locale, skin
             FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function uniqueUsername(string $base): string
    {
        $base = strtolower($base);
        $base = preg_replace('/[^a-z0-9._-]+/', '-', $base) ?? '';
        $base = trim($base, '-._');
        if (strlen($base) < 3) {
            $base = 'user';
        }
        $base = substr($base, 0, 24);

        $db = Database::getInstance();
        $stmt = $db->prepare('SELECT 1 FROM users WHERE username = :u LIMIT 1');

        $candidate = $base;
        for ($i = 0; $i < 20; $i++) {
            $stmt->execute([':u' => $candidate]);
            if (!$stmt->fetchColumn()) {
                return $candidate;
            }
            $candidate = substr($base, 0, 18) . '-' . bin2hex(random_bytes(3));
        }

        return 'user-' . bin2hex(random_bytes(6));
    }

    /** 至少还要有一种能找回账号的方式，才允许解绑 */
    private function canRemoveIdentity(int $userId): bool
    {
        $db = Database::getInstance();

        $stmt = $db->prepare('SELECT COUNT(*) FROM user_identities WHERE user_id = :u');
        $stmt->execute([':u' => $userId]);
        if ((int)$stmt->fetchColumn() > 1) {
            return true;
        }

        $stmt = $db->prepare('SELECT email_verified FROM users WHERE id = :u LIMIT 1');
        $stmt->execute([':u' => $userId]);
        if ((int)$stmt->fetchColumn() === 1) {
            return true;
        }

        $stmt = $db->prepare('SELECT COUNT(*) FROM user_totp_secrets WHERE user_id = :u AND verified = 1');
        $stmt->execute([':u' => $userId]);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function safeRedirect(string $url): string
    {
        return (str_starts_with($url, '/') && !str_contains($url, '://')) ? $url : '/dashboard';
    }

    private function fail(string $message): void
    {
        Session::flash('error', $message);
        $target = Session::get('user_id') ? '/settings' : '/login';
        Response::redirect($target);
    }
}
