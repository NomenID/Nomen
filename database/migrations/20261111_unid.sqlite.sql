ALTER TABLE users ADD COLUMN unid TEXT DEFAULT NULL;
CREATE UNIQUE INDEX idx_users_unid ON users (unid);

ALTER TABLE activity_logs ADD COLUMN unid TEXT DEFAULT NULL;
CREATE UNIQUE INDEX idx_activity_logs_unid ON activity_logs (unid);

ALTER TABLE account_recovery_requests ADD COLUMN unid TEXT DEFAULT NULL;
CREATE UNIQUE INDEX idx_account_recovery_requests_unid ON account_recovery_requests (unid);

ALTER TABLE family_groups ADD COLUMN unid TEXT DEFAULT NULL;
CREATE UNIQUE INDEX idx_family_groups_unid ON family_groups (unid);
