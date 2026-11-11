ALTER TABLE `users`
  ADD COLUMN `unid` char(26) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER `id`,
  ADD UNIQUE KEY `uk_unid` (`unid`);

ALTER TABLE `activity_logs`
  ADD COLUMN `unid` char(26) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER `id`,
  ADD UNIQUE KEY `uk_unid` (`unid`);

ALTER TABLE `account_recovery_requests`
  ADD COLUMN `unid` char(26) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER `id`,
  ADD UNIQUE KEY `uk_unid` (`unid`);

ALTER TABLE `family_groups`
  ADD COLUMN `unid` char(26) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL AFTER `id`,
  ADD UNIQUE KEY `uk_unid` (`unid`);
