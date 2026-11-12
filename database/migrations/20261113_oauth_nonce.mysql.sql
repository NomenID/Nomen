ALTER TABLE `oauth_authorization_codes`
  ADD COLUMN `nonce` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
  COMMENT 'OIDC nonce, echoed in id_token'
  AFTER `code_challenge_method`;
