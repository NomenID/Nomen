<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

namespace Nomen\Src;

use PDO;

class NotificationHelper
{
    public const TYPE_SYSTEM_IMPORTANT = 'system_important';
    public const TYPE_SYSTEM_GENERAL   = 'system_general';
    public const TYPE_SYSTEM_INTERACT  = 'system_interact';

    public const CATEGORIES = [
        self::TYPE_SYSTEM_IMPORTANT,
        self::TYPE_SYSTEM_GENERAL,
        self::TYPE_SYSTEM_INTERACT,
    ];

    // 偏好表里每类一列，开关只控制投递
    public const SETTING_KEYS = [
        self::TYPE_SYSTEM_IMPORTANT => 'notify_system_important',
        self::TYPE_SYSTEM_GENERAL   => 'notify_system_general',
        self::TYPE_SYSTEM_INTERACT  => 'notify_system_interact',
    ];

    // 默认只投重要通知；一般/互动默认不投，用户可自行开启
    public const DEFAULT_SETTINGS = [
        'notify_system_important' => 1,
        'notify_system_general'   => 0,
        'notify_system_interact'  => 0,
    ];

    public const CATEGORY_LABELS = [
        self::TYPE_SYSTEM_IMPORTANT => 'Important system notice',
        self::TYPE_SYSTEM_GENERAL   => 'General system notice',
        self::TYPE_SYSTEM_INTERACT  => 'System interaction',
    ];

    private static function db(): PDO
    {
        return Database::getInstance();
    }

    public static function getSettings(int $userId): array
    {
        $stmt = self::db()->prepare(
            'SELECT notify_system_important, notify_system_general, notify_system_interact
             FROM user_notification_settings WHERE user_id = :u'
        );
        $stmt->execute([':u' => $userId]);
        $row = $stmt->fetch();

        if ($row) {
            return array_merge(self::DEFAULT_SETTINGS, array_map('intval', $row));
        }

        self::db()->prepare(Database::dialectFor(self::db())->insertIgnore(
            'INSERT IGNORE INTO user_notification_settings (user_id) VALUES (:u)'
        ))->execute([':u' => $userId]);
        return self::DEFAULT_SETTINGS;
    }

    public static function updateSettings(int $userId, array $settings): void
    {
        $sets = [];
        $params = [':u' => $userId];
        foreach (self::SETTING_KEYS as $column) {
            if (!array_key_exists($column, $settings)) continue;
            $sets[] = "$column = :$column";
            $params[":$column"] = (int)(bool)$settings[$column];
        }
        if (!$sets) return;

        self::getSettings($userId);
        self::db()->prepare(
            'UPDATE user_notification_settings SET ' . implode(', ', $sets) . ' WHERE user_id = :u'
        )->execute($params);
    }

    /**
     * 投递一条系统通知：站内始终写入，邮件按「邮件通知偏好」推送（重要类默认开）。
     * dedupe_key 撞唯一键视为已投递过，直接跳过（不重复发信）。
     */
    public static function notify(int $userId, string $category, string $title, string $body, array $opts = []): bool
    {
        if (!in_array($category, self::CATEGORIES, true)) {
            return false;
        }

        try {
            $stmt = self::db()->prepare(
                'INSERT INTO notifications (user_id, category, title, body, link, link_text, dedupe_key)
                 VALUES (:u, :c, :t, :b, :l, :lt, :d)'
            );
            $stmt->execute([
                ':u'  => (int)$userId,
                ':c'  => $category,
                ':t'  => $title,
                ':b'  => $body,
                ':l'  => $opts['link'] ?? null,
                ':lt' => $opts['link_text'] ?? null,
                ':d'  => $opts['dedupe_key'] ?? null,
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }

        $notificationId = (int)self::db()->lastInsertId();

        Hooks::run('notification.created', [
            (int)$userId,
            $category,
            $title,
            $body,
            $notificationId,
        ]);

        if ($opts['email'] ?? true) {
            self::emailNotification((int)$userId, $category, $title, $body, $opts['link'] ?? null, $notificationId);
        }
        return true;
    }

    /**
     * 给一条已存在的通知补发邮件（用于历史消息同步），发完写 email_sent_at 保证幂等
     */
    public static function emailExisting(array $notification): bool
    {
        $userId = (int)$notification['user_id'];
        $sent = self::emailNotification(
            $userId,
            $notification['category'],
            $notification['title'],
            $notification['body'],
            $notification['link'] ?? null,
            (int)$notification['id']
        );
        return $sent;
    }

    /**
     * @return bool 是否真的投递了邮件（被偏好关闭/无邮箱/已标记都返回 false）
     */
    private static function emailNotification(int $userId, string $category, string $title, string $body, ?string $link, int $notificationId): bool
    {
        $settings = self::getSettings($userId);
        if (empty($settings[self::SETTING_KEYS[$category] ?? ''])) {
            return false;
        }

        $stmt = self::db()->prepare('SELECT id, email, email_verified FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $user = $stmt->fetch();
        if (!$user || empty($user['email_verified'])) {
            return false;
        }

        // 未成年账号没有自己的邮箱，通知发到监护人邮箱
        $to = FamilyHelper::contactEmail($user);
        if ($to === null) {
            return false;
        }

        // 外壳/按钮按收件人语言出；通知正文是存库内容（中文数据），保持原样
        $html = EmailTemplate::notification($category, $title, $body, $link, I18n::emailLocale((int)$user['id']));
        $subject = self::deriveSubject($title);

        if (PHP_SAPI === 'cli') {
            $ok = Email::send($to, $subject, $html);
        } else {
            // Web 请求：响应发完再发信，别拖慢页面
            register_shutdown_function(function () use ($to, $subject, $html) {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                Email::send($to, $subject, $html);
            });
            $ok = true;
        }

        if ($ok && $notificationId > 0) {
            self::db()->prepare('UPDATE notifications SET email_sent_at = NOW() WHERE id = :id')
                ->execute([':id' => $notificationId]);
        }
        return $ok;
    }

    /**
     * 通知标题常常是一整段话，取首句做邮件主题，避免主题被客户端截断
     */
    public static function deriveSubject(string $title): string
    {
        $title = trim($title);
        $prefix = (string)Brand::get('name', 'Nomen') . ' · ';
        if (mb_strlen($title, 'UTF-8') <= 48) {
            return $prefix . $title;
        }

        $firstClause = preg_split('/[，。；,;]/u', $title)[0] ?? $title;
        if (mb_strlen($firstClause, 'UTF-8') > 48) {
            $firstClause = mb_substr($firstClause, 0, 46, 'UTF-8') . '…';
        }
        return $prefix . $firstClause;
    }

    public static function unreadCount(int $userId): int
    {
        $stmt = self::db()->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :u AND read_at IS NULL'
        );
        $stmt->execute([':u' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    public static function latestUnread(int $userId): ?array
    {
        $stmt = self::db()->prepare(
            'SELECT id, title, link FROM notifications
             WHERE user_id = :u AND read_at IS NULL
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':u' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @return array{items: array, total: int}
     */
    public static function listFor(int $userId, int $page, int $perPage = 20): array
    {
        $page = max(1, $page);

        $stmt = self::db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :u');
        $stmt->execute([':u' => $userId]);
        $total = (int)$stmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt = self::db()->prepare(
            'SELECT id, category, title, body, link, link_text, read_at, created_at
             FROM notifications WHERE user_id = :u
             ORDER BY created_at DESC, id DESC LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll();

        return ['items' => $items, 'total' => $total];
    }

    public static function get(int $userId, int $id): ?array
    {
        $stmt = self::db()->prepare(
            'SELECT id, category, title, body, link, link_text, read_at, created_at
             FROM notifications WHERE user_id = :u AND id = :id'
        );
        $stmt->execute([':u' => $userId, ':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function markRead(int $userId, int $id): void
    {
        self::db()->prepare(
            'UPDATE notifications SET read_at = NOW() WHERE user_id = :u AND id = :id AND read_at IS NULL'
        )->execute([':u' => $userId, ':id' => $id]);
    }

    public static function markAllRead(int $userId): void
    {
        self::db()->prepare(
            'UPDATE notifications SET read_at = NOW() WHERE user_id = :u AND read_at IS NULL'
        )->execute([':u' => $userId]);
    }
}
