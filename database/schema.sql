
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `account_recovery_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_recovery_requests` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `username` varchar(64) NOT NULL,
  `account_email` varchar(255) DEFAULT NULL,
  `birth_date_input` date DEFAULT NULL,
  `gender_input` varchar(32) DEFAULT NULL,
  `birth_date_match` tinyint(1) DEFAULT NULL,
  `gender_match` tinyint(1) DEFAULT NULL,
  `description` text,
  `contact_email` varchar(255) NOT NULL,
  `status` enum('pending','approved','rejected','completed') NOT NULL DEFAULT 'pending',
  `admin_note` text,
  `reviewed_by` int unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `recovery_token_hash` char(128) DEFAULT NULL,
  `recovery_token_expires_at` datetime DEFAULT NULL,
  `recovery_token_used_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `admin_email_notify_at` datetime DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_status_created` (`status`,`created_at`),
  KEY `idx_token` (`recovery_token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `actor_type` enum('user','admin','system') NOT NULL DEFAULT 'user',
  `actor_id` int unsigned DEFAULT NULL,
  `actor_username` varchar(64) DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `category` varchar(32) NOT NULL,
  `target_type` varchar(32) DEFAULT NULL,
  `target_id` varchar(64) DEFAULT NULL,
  `target_name` varchar(255) DEFAULT NULL,
  `summary` varchar(255) DEFAULT NULL,
  `changes` mediumtext,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(500) DEFAULT NULL,
  `device_name` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_actor` (`actor_type`,`actor_id`,`created_at`),
  KEY `idx_action` (`action`,`created_at`),
  KEY `idx_category` (`category`,`created_at`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `developers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `developers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `name` varchar(128) COLLATE utf8mb4_general_ci NOT NULL,
  `icon` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `api_token_hash` varchar(128) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user` (`user_id`),
  CONSTRAINT `developers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `family_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `family_groups` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unclaimed',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `claimed_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `family_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `family_members` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int unsigned NOT NULL,
  `kind` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `relationship` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `consent_at` datetime DEFAULT NULL,
  `release_requested_at` datetime DEFAULT NULL,
  `independent_at` datetime DEFAULT NULL,
  `independent_reason` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `left_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_group` (`group_id`),
  KEY `idx_user_kind` (`user_id`,`kind`),
  KEY `idx_guardian_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `category` varchar(20) NOT NULL,
  `title` varchar(190) NOT NULL,
  `body` text NOT NULL,
  `link` varchar(500) DEFAULT NULL,
  `link_text` varchar(50) DEFAULT NULL,
  `dedupe_key` varchar(64) DEFAULT NULL,
  `email_sent_at` datetime DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_dedupe` (`user_id`,`dedupe_key`),
  KEY `idx_user_read` (`user_id`,`read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_access_tokens` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `token_hash` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'SHA-256 hash of the token',
  `client_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int unsigned NOT NULL,
  `scopes` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `revoked` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_token_hash` (`token_hash`),
  KEY `idx_expires` (`expires_at`),
  KEY `client_id` (`client_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `oauth_access_tokens_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `oauth_clients` (`client_id`) ON DELETE CASCADE,
  CONSTRAINT `oauth_access_tokens_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_authorization_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_authorization_codes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int unsigned NOT NULL,
  `redirect_uri` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scopes` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code_challenge` varchar(256) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'PKCE code_challenge',
  `code_challenge_method` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'plain or S256',
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_code` (`code`),
  KEY `idx_expires` (`expires_at`),
  KEY `client_id` (`client_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `oauth_authorization_codes_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `oauth_clients` (`client_id`) ON DELETE CASCADE,
  CONSTRAINT `oauth_authorization_codes_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_clients` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `client_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_secret` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'confidential' COMMENT 'confidential=使用 Client Secret, public=公共客户端(免密钥,强制PKCE)',
  `pkce_required` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1=强制该应用使用 PKCE',
  `name` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `icon` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `homepage_url` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo_url` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `redirect_uris` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'JSON array of redirect URIs',
  `scopes` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'openid profile email' COMMENT 'Space-separated allowed scopes',
  `owner_id` int unsigned DEFAULT NULL,
  `developer_id` int unsigned DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1=active, 0=disabled',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_id` (`client_id`),
  KEY `idx_client_id` (`client_id`),
  KEY `owner_id` (`owner_id`),
  KEY `idx_developer_id` (`developer_id`),
  CONSTRAINT `oauth_clients_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `oauth_clients_ibfk_2` FOREIGN KEY (`developer_id`) REFERENCES `developers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `oauth_refresh_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `oauth_refresh_tokens` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `token_hash` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'SHA-256 hash of the token',
  `access_token_id` int unsigned NOT NULL,
  `client_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int unsigned NOT NULL,
  `scopes` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `revoked` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_token_hash` (`token_hash`),
  KEY `access_token_id` (`access_token_id`),
  KEY `client_id` (`client_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `oauth_refresh_tokens_ibfk_1` FOREIGN KEY (`access_token_id`) REFERENCES `oauth_access_tokens` (`id`) ON DELETE CASCADE,
  CONSTRAINT `oauth_refresh_tokens_ibfk_2` FOREIGN KEY (`client_id`) REFERENCES `oauth_clients` (`client_id`) ON DELETE CASCADE,
  CONSTRAINT `oauth_refresh_tokens_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pending_registrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pending_registrations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `country` char(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `gender` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_under_16` tinyint(1) NOT NULL DEFAULT '0',
  `code_hash` char(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL DEFAULT '0',
  `last_sent_at` datetime DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_email` (`email`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `plugin_migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `plugin_migrations` (
  `plugin` varchar(64) NOT NULL,
  `filename` varchar(191) NOT NULL,
  `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`plugin`,`filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_app_subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_app_subjects` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `client_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_client` (`user_id`,`client_id`),
  UNIQUE KEY `uq_subject` (`subject_id`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `user_app_subjects_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_app_subjects_ibfk_2` FOREIGN KEY (`client_id`) REFERENCES `oauth_clients` (`client_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_authorizations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_authorizations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `client_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scopes` varchar(512) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_client` (`user_id`,`client_id`),
  KEY `client_id` (`client_id`),
  CONSTRAINT `user_authorizations_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_authorizations_ibfk_2` FOREIGN KEY (`client_id`) REFERENCES `oauth_clients` (`client_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_biometrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_biometrics` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `credential_id` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `public_key` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `sign_count` int DEFAULT '0',
  `transports` text COLLATE utf8mb4_unicode_ci,
  `device_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `revoked` tinyint DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `user_biometrics_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_devices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `device_id` varchar(64) NOT NULL,
  `device_name` varchar(200) NOT NULL DEFAULT '',
  `ip_address` varchar(45) NOT NULL DEFAULT '',
  `user_agent` text,
  `first_seen_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `trusted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_device` (`user_id`,`device_id`),
  CONSTRAINT `user_devices_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_emails`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_emails` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `verified` tinyint(1) NOT NULL DEFAULT '0',
  `verify_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `verify_token_expires_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `verified_at` datetime DEFAULT NULL,
  `secondary_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS (if(((`is_primary` = 1) or (`verified` = 0)),NULL,`email`)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_email` (`user_id`,`email`),
  UNIQUE KEY `uniq_secondary_email` (`secondary_email`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_notification_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_notification_settings` (
  `user_id` int unsigned NOT NULL,
  `notify_system_important` tinyint NOT NULL DEFAULT '1',
  `notify_system_general` tinyint NOT NULL DEFAULT '0',
  `notify_system_interact` tinyint NOT NULL DEFAULT '0',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_recovery_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_recovery_codes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `batch_id` varchar(32) NOT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_user_used` (`user_id`,`used_at`),
  KEY `idx_batch` (`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_temp_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_temp_keys` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `key_hash` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `label` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_temp_keys_user` (`user_id`),
  KEY `idx_user_temp_keys_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_totp_secrets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_totp_secrets` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `secret` varchar(64) NOT NULL,
  `verified` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `oauth_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `username` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` char(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `gender` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `locale` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `skin` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `display_name` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `avatar` varchar(512) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bio` text COLLATE utf8mb4_unicode_ci,
  `email_verified` tinyint(1) NOT NULL DEFAULT '0',
  `email_verify_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_verify_token_expires_at` datetime DEFAULT NULL,
  `email_verify_last_sent_at` datetime DEFAULT NULL,
  `pending_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pending_email_token` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pending_email_token_expires_at` datetime DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1=active, 0=disabled, -1=banned',
  `role` enum('user','admin') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
  `two_factor_policy` enum('every_login','new_device') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'new_device' COMMENT '两步验证强度: every_login=每次登录都验证; new_device=仅新设备首次验证',
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `password_reset_code` varchar(6) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_reset_code_expires_at` datetime DEFAULT NULL,
  `quick_login` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uk_oauth_id` (`oauth_id`),
  KEY `idx_email` (`email`),
  KEY `idx_username` (`username`),
  KEY `idx_email_verify_token` (`email_verify_token`),
  KEY `idx_pending_email_token` (`pending_email_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `app_meta`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_meta` (
  `k` varchar(64) NOT NULL,
  `v` text NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `site_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `site_settings` (
  `k` varchar(64) NOT NULL,
  `v` text NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_items` (
  `k` varchar(191) NOT NULL,
  `v` mediumtext NOT NULL,
  `expires_at` int unsigned NOT NULL,
  PRIMARY KEY (`k`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `identity_providers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `identity_providers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(32) NOT NULL,
  `name` varchar(64) NOT NULL,
  `type` varchar(16) NOT NULL DEFAULT 'oauth2',
  `client_id` varchar(255) NOT NULL DEFAULT '',
  `client_secret` varchar(255) NOT NULL DEFAULT '',
  `authorize_url` varchar(500) NOT NULL DEFAULT '',
  `token_url` varchar(500) NOT NULL DEFAULT '',
  `userinfo_url` varchar(500) NOT NULL DEFAULT '',
  `emails_url` varchar(500) NOT NULL DEFAULT '',
  `scopes` varchar(500) NOT NULL DEFAULT '',
  `subject_field` varchar(64) NOT NULL DEFAULT 'sub',
  `email_field` varchar(64) NOT NULL DEFAULT 'email',
  `name_field` varchar(64) NOT NULL DEFAULT 'name',
  `avatar_field` varchar(64) NOT NULL DEFAULT 'picture',
  `email_verified_field` varchar(64) NOT NULL DEFAULT '',
  `token_auth` varchar(8) NOT NULL DEFAULT 'post',
  `extra_authorize_params` varchar(500) NOT NULL DEFAULT '',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_identity_providers_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_identities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_identities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `provider_id` int unsigned NOT NULL,
  `subject` varchar(191) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `display_name` varchar(191) DEFAULT NULL,
  `avatar_url` varchar(500) DEFAULT NULL,
  `raw` mediumtext,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_identities_provider_subject` (`provider_id`, `subject`),
  UNIQUE KEY `uk_user_identities_user_provider` (`user_id`, `provider_id`),
  KEY `idx_user_identities_user` (`user_id`),
  CONSTRAINT `user_identities_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_identities_provider_fk` FOREIGN KEY (`provider_id`) REFERENCES `identity_providers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

