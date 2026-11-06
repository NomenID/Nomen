-- Nomen: multiple addresses per account (max 5), one of them the primary.
--
-- users.email / users.email_verified are kept as a mirror of the primary address,
-- so every existing reader (notifications, login identifier, family fallback)
-- keeps working untouched. Secondary addresses live only in this table.
--
-- No unique key on `email` alone, on purpose: an unverified address must not
-- reserve the name globally (otherwise anyone could park someone else's address
-- in an unverified row and block their registration). Uniqueness across
-- accounts is checked in code, and only among verified rows.

CREATE TABLE IF NOT EXISTS `user_emails` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`    INT UNSIGNED NOT NULL,
  `email`      VARCHAR(255) NOT NULL,
  `is_primary` TINYINT(1)   NOT NULL DEFAULT 0,
  `verified`   TINYINT(1)   NOT NULL DEFAULT 0,
  `verify_token` VARCHAR(64) DEFAULT NULL,
  `verify_token_expires_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `verified_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_email` (`user_id`, `email`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: every existing account's address becomes its (only) primary address.
INSERT IGNORE INTO `user_emails` (`user_id`, `email`, `is_primary`, `verified`, `verified_at`, `created_at`)
SELECT `id`, `email`, 1, `email_verified`, IF(`email_verified` = 1, NOW(), NULL), NOW()
  FROM `users`
 WHERE `email` IS NOT NULL AND `email` <> '';

-- Rollback:
--   DROP TABLE `user_emails`;
