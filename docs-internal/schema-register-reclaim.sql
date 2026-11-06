-- Nomen: reclaiming an email that is held by an unverified account.
-- Apply before deploying the matching code.

CREATE TABLE IF NOT EXISTS `pending_registrations` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`         VARCHAR(255) NOT NULL,
  `username`      VARCHAR(32)  NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `country`       CHAR(2)      DEFAULT NULL,
  `birth_date`    DATE         DEFAULT NULL,
  `gender`        VARCHAR(30)  DEFAULT NULL,
  `is_under_16`   TINYINT(1)   NOT NULL DEFAULT 0,
  `code_hash`     CHAR(128)    NOT NULL,
  `attempts`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_sent_at`  DATETIME     DEFAULT NULL,
  `expires_at`    DATETIME     NOT NULL,
  `ip_address`    VARCHAR(45)  DEFAULT NULL,
  `user_agent`    VARCHAR(500) DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_email` (`email`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
