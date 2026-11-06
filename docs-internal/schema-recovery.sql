-- Nomen account recovery + activity log schema
-- Apply before deploying the matching code.

CREATE TABLE IF NOT EXISTS `account_recovery_requests` (
  `id`                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`                    INT UNSIGNED NOT NULL,
  `username`                   VARCHAR(64)  NOT NULL,
  `account_email`              VARCHAR(255) DEFAULT NULL,
  `birth_date_input`           DATE         DEFAULT NULL,
  `gender_input`               VARCHAR(32)  DEFAULT NULL,
  `birth_date_match`           TINYINT(1)   DEFAULT NULL,
  `gender_match`               TINYINT(1)   DEFAULT NULL,
  `description`                TEXT,
  `contact_email`              VARCHAR(255) NOT NULL,
  `status`                     ENUM('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
  `admin_note`                 TEXT,
  `reviewed_by`                INT UNSIGNED DEFAULT NULL,
  `reviewed_at`                DATETIME     DEFAULT NULL,
  `recovery_token_hash`        CHAR(128)    DEFAULT NULL,
  `recovery_token_expires_at`  DATETIME     DEFAULT NULL,
  `recovery_token_used_at`     DATETIME     DEFAULT NULL,
  `completed_at`               DATETIME     DEFAULT NULL,
  `ip_address`                 VARCHAR(45)  DEFAULT NULL,
  `user_agent`                 VARCHAR(500) DEFAULT NULL,
  `created_at`                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status_created` (`status`, `created_at`),
  KEY `idx_token` (`recovery_token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_type`     ENUM('user','admin','system') NOT NULL DEFAULT 'user',
  `actor_id`       INT UNSIGNED DEFAULT NULL,
  `actor_username` VARCHAR(64)  DEFAULT NULL,
  `action`         VARCHAR(64)  NOT NULL,
  `category`       VARCHAR(32)  NOT NULL,
  `target_type`    VARCHAR(32)  DEFAULT NULL,
  `target_id`      VARCHAR(64)  DEFAULT NULL,
  `target_name`    VARCHAR(255) DEFAULT NULL,
  `summary`        VARCHAR(255) DEFAULT NULL,
  `changes`        TEXT,
  `ip_address`     VARCHAR(45)  DEFAULT NULL,
  `user_agent`     VARCHAR(500) DEFAULT NULL,
  `device_name`    VARCHAR(100) DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_actor` (`actor_type`, `actor_id`, `created_at`),
  KEY `idx_action` (`action`, `created_at`),
  KEY `idx_category` (`category`, `created_at`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2026-10-09: 管理员「新工单」提醒节流用。窗口内新提交的工单不发信，
-- 而是等下一个窗口开启时随汇总一起发出（见 config 的 admin_notify_window）。
ALTER TABLE `account_recovery_requests`
  ADD COLUMN `admin_email_notify_at` DATETIME DEFAULT NULL AFTER `completed_at`;
