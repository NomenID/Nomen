-- Nomen: family groups -- guardian consent for minor accounts.
-- Apply before deploying the matching code.

CREATE TABLE IF NOT EXISTS `family_groups` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `status`     VARCHAR(16)  NOT NULL DEFAULT 'unclaimed',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `claimed_at` DATETIME DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Guardians and children share this table. A guardian row exists before the
-- guardian has an account at all (user_id IS NULL until claimed), which is what
-- lets a child register first and the parent show up later.
CREATE TABLE IF NOT EXISTS `family_members` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id`            INT UNSIGNED NOT NULL,
  `kind`                VARCHAR(8)   NOT NULL,
  `user_id`             INT UNSIGNED DEFAULT NULL,
  `email`               VARCHAR(255) DEFAULT NULL,
  `relationship`        VARCHAR(16)  DEFAULT NULL,
  `status`              VARCHAR(16)  NOT NULL,
  `consent_at`          DATETIME     DEFAULT NULL,
  `release_requested_at` DATETIME    DEFAULT NULL,
  `independent_at`      DATETIME     DEFAULT NULL,
  `independent_reason`  VARCHAR(16)  DEFAULT NULL,
  `left_at`             DATETIME     DEFAULT NULL,
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_group` (`group_id`),
  KEY `idx_user_kind` (`user_id`, `kind`),
  KEY `idx_guardian_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A minor has no email of its own; the guardian address lives on the member row
-- above. The unique index stays and still holds: MySQL allows many NULLs in a
-- unique index, so several children can share one guardian address.
ALTER TABLE `users` MODIFY `email` VARCHAR(255) COLLATE utf8mb4_unicode_ci NULL;

-- Rollback:
--   DELETE FROM users WHERE email IS NULL;   -- only after reviewing what is there
--   ALTER TABLE `users` MODIFY `email` VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL;
--   DROP TABLE `family_members`;
--   DROP TABLE `family_groups`;
