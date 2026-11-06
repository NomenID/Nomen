-- SQLite 表结构。与 schema.sql 一一对应，改结构时两边一起改。
-- 时间列没有 SQL 层的 ON UPDATE，默认值和自动更新用文件末尾的触发器补。

PRAGMA foreign_keys = OFF;

DROP TABLE IF EXISTS account_recovery_requests;
CREATE TABLE account_recovery_requests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  username TEXT COLLATE NOCASE NOT NULL,
  account_email TEXT COLLATE NOCASE DEFAULT NULL,
  birth_date_input TEXT DEFAULT NULL,
  gender_input TEXT DEFAULT NULL,
  birth_date_match INTEGER DEFAULT NULL,
  gender_match INTEGER DEFAULT NULL,
  description TEXT,
  contact_email TEXT COLLATE NOCASE NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending',
  admin_note TEXT,
  reviewed_by INTEGER DEFAULT NULL,
  reviewed_at TEXT DEFAULT NULL,
  recovery_token_hash TEXT DEFAULT NULL,
  recovery_token_expires_at TEXT DEFAULT NULL,
  recovery_token_used_at TEXT DEFAULT NULL,
  completed_at TEXT DEFAULT NULL,
  admin_email_notify_at TEXT DEFAULT NULL,
  ip_address TEXT DEFAULT NULL,
  user_agent TEXT DEFAULT NULL,
  created_at TEXT NOT NULL DEFAULT '',
  CHECK (status IN ('pending','approved','rejected','completed'))
);
CREATE INDEX idx_account_recovery_requests_user ON account_recovery_requests (user_id);
CREATE INDEX idx_account_recovery_requests_status_created ON account_recovery_requests (status, created_at);
CREATE INDEX idx_account_recovery_requests_token ON account_recovery_requests (recovery_token_hash);

DROP TABLE IF EXISTS activity_logs;
CREATE TABLE activity_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  actor_type TEXT NOT NULL DEFAULT 'user',
  actor_id INTEGER DEFAULT NULL,
  actor_username TEXT DEFAULT NULL,
  action TEXT NOT NULL,
  category TEXT NOT NULL,
  target_type TEXT DEFAULT NULL,
  target_id TEXT DEFAULT NULL,
  target_name TEXT DEFAULT NULL,
  summary TEXT DEFAULT NULL,
  changes TEXT,
  ip_address TEXT DEFAULT NULL,
  user_agent TEXT DEFAULT NULL,
  device_name TEXT DEFAULT NULL,
  created_at TEXT NOT NULL DEFAULT '',
  CHECK (actor_type IN ('user','admin','system'))
);
CREATE INDEX idx_activity_logs_actor ON activity_logs (actor_type, actor_id, created_at);
CREATE INDEX idx_activity_logs_action ON activity_logs (action, created_at);
CREATE INDEX idx_activity_logs_category ON activity_logs (category, created_at);
CREATE INDEX idx_activity_logs_created ON activity_logs (created_at);

DROP TABLE IF EXISTS developers;
CREATE TABLE developers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  icon TEXT DEFAULT NULL,
  api_token_hash TEXT DEFAULT NULL,
  status INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT '',
  updated_at TEXT NOT NULL DEFAULT '',
  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX idx_developers_user ON developers (user_id);

DROP TABLE IF EXISTS family_groups;
CREATE TABLE family_groups (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  status TEXT NOT NULL DEFAULT 'unclaimed',
  created_at TEXT NOT NULL DEFAULT '',
  claimed_at TEXT DEFAULT NULL,
  updated_at TEXT NOT NULL DEFAULT ''
);

DROP TABLE IF EXISTS family_members;
CREATE TABLE family_members (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  group_id INTEGER NOT NULL,
  kind TEXT NOT NULL,
  user_id INTEGER DEFAULT NULL,
  email TEXT COLLATE NOCASE DEFAULT NULL,
  relationship TEXT DEFAULT NULL,
  status TEXT NOT NULL,
  consent_at TEXT DEFAULT NULL,
  release_requested_at TEXT DEFAULT NULL,
  independent_at TEXT DEFAULT NULL,
  independent_reason TEXT DEFAULT NULL,
  left_at TEXT DEFAULT NULL,
  created_at TEXT NOT NULL DEFAULT '',
  updated_at TEXT NOT NULL DEFAULT ''
);
CREATE INDEX idx_family_members_group ON family_members (group_id);
CREATE INDEX idx_family_members_user_kind ON family_members (user_id, kind);
CREATE INDEX idx_family_members_guardian_email ON family_members (email);

DROP TABLE IF EXISTS notifications;
CREATE TABLE notifications (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  category TEXT NOT NULL,
  title TEXT NOT NULL,
  body TEXT NOT NULL,
  link TEXT DEFAULT NULL,
  link_text TEXT DEFAULT NULL,
  dedupe_key TEXT DEFAULT NULL,
  email_sent_at TEXT DEFAULT NULL,
  read_at TEXT DEFAULT NULL,
  created_at TEXT NOT NULL DEFAULT ''
);
CREATE UNIQUE INDEX idx_notifications_user_dedupe ON notifications (user_id, dedupe_key);
CREATE INDEX idx_notifications_user_read ON notifications (user_id, read_at);

DROP TABLE IF EXISTS oauth_access_tokens;
CREATE TABLE oauth_access_tokens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  token_hash TEXT NOT NULL,
  client_id TEXT NOT NULL,
  user_id INTEGER NOT NULL,
  scopes TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  revoked INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT '',
  FOREIGN KEY (client_id) REFERENCES oauth_clients (client_id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX idx_oauth_access_tokens_token_hash ON oauth_access_tokens (token_hash);
CREATE INDEX idx_oauth_access_tokens_token_hash_2 ON oauth_access_tokens (token_hash);
CREATE INDEX idx_oauth_access_tokens_expires ON oauth_access_tokens (expires_at);
CREATE INDEX idx_oauth_access_tokens_client_id ON oauth_access_tokens (client_id);
CREATE INDEX idx_oauth_access_tokens_user_id ON oauth_access_tokens (user_id);

DROP TABLE IF EXISTS oauth_authorization_codes;
CREATE TABLE oauth_authorization_codes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL,
  client_id TEXT NOT NULL,
  user_id INTEGER NOT NULL,
  redirect_uri TEXT NOT NULL,
  scopes TEXT NOT NULL,
  code_challenge TEXT DEFAULT NULL,
  code_challenge_method TEXT DEFAULT NULL,
  expires_at TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT '',
  FOREIGN KEY (client_id) REFERENCES oauth_clients (client_id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX idx_oauth_authorization_codes_code ON oauth_authorization_codes (code);
CREATE INDEX idx_oauth_authorization_codes_code_2 ON oauth_authorization_codes (code);
CREATE INDEX idx_oauth_authorization_codes_expires ON oauth_authorization_codes (expires_at);
CREATE INDEX idx_oauth_authorization_codes_client_id ON oauth_authorization_codes (client_id);
CREATE INDEX idx_oauth_authorization_codes_user_id ON oauth_authorization_codes (user_id);

DROP TABLE IF EXISTS oauth_clients;
CREATE TABLE oauth_clients (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  client_id TEXT COLLATE NOCASE NOT NULL,
  client_secret TEXT NOT NULL,
  client_type TEXT NOT NULL DEFAULT 'confidential',
  pkce_required INTEGER NOT NULL DEFAULT 0,
  name TEXT NOT NULL,
  icon TEXT DEFAULT NULL,
  description TEXT,
  homepage_url TEXT DEFAULT NULL,
  logo_url TEXT DEFAULT NULL,
  redirect_uris TEXT NOT NULL,
  scopes TEXT NOT NULL DEFAULT 'openid profile email',
  owner_id INTEGER DEFAULT NULL,
  developer_id INTEGER DEFAULT NULL,
  status INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT '',
  updated_at TEXT NOT NULL DEFAULT '',
  FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE SET NULL,
  FOREIGN KEY (developer_id) REFERENCES developers (id) ON DELETE SET NULL
);
CREATE UNIQUE INDEX idx_oauth_clients_client_id ON oauth_clients (client_id);
CREATE INDEX idx_oauth_clients_client_id_2 ON oauth_clients (client_id);
CREATE INDEX idx_oauth_clients_owner_id ON oauth_clients (owner_id);
CREATE INDEX idx_oauth_clients_developer_id ON oauth_clients (developer_id);

DROP TABLE IF EXISTS oauth_refresh_tokens;
CREATE TABLE oauth_refresh_tokens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  token_hash TEXT NOT NULL,
  access_token_id INTEGER NOT NULL,
  client_id TEXT NOT NULL,
  user_id INTEGER NOT NULL,
  scopes TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  revoked INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT '',
  FOREIGN KEY (access_token_id) REFERENCES oauth_access_tokens (id) ON DELETE CASCADE,
  FOREIGN KEY (client_id) REFERENCES oauth_clients (client_id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX idx_oauth_refresh_tokens_token_hash ON oauth_refresh_tokens (token_hash);
CREATE INDEX idx_oauth_refresh_tokens_token_hash_2 ON oauth_refresh_tokens (token_hash);
CREATE INDEX idx_oauth_refresh_tokens_access_token_id ON oauth_refresh_tokens (access_token_id);
CREATE INDEX idx_oauth_refresh_tokens_client_id ON oauth_refresh_tokens (client_id);
CREATE INDEX idx_oauth_refresh_tokens_user_id ON oauth_refresh_tokens (user_id);

DROP TABLE IF EXISTS pending_registrations;
CREATE TABLE pending_registrations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT COLLATE NOCASE NOT NULL,
  username TEXT COLLATE NOCASE NOT NULL,
  password_hash TEXT NOT NULL,
  country TEXT DEFAULT NULL,
  birth_date TEXT DEFAULT NULL,
  gender TEXT DEFAULT NULL,
  is_under_16 INTEGER NOT NULL DEFAULT 0,
  code_hash TEXT NOT NULL,
  attempts INTEGER NOT NULL DEFAULT 0,
  last_sent_at TEXT DEFAULT NULL,
  expires_at TEXT NOT NULL,
  ip_address TEXT DEFAULT NULL,
  user_agent TEXT DEFAULT NULL,
  created_at TEXT NOT NULL DEFAULT '',
  updated_at TEXT NOT NULL DEFAULT ''
);
CREATE UNIQUE INDEX idx_pending_registrations_email ON pending_registrations (email);
CREATE INDEX idx_pending_registrations_expires ON pending_registrations (expires_at);

DROP TABLE IF EXISTS plugin_migrations;
CREATE TABLE plugin_migrations (
  plugin TEXT NOT NULL,
  filename TEXT NOT NULL,
  applied_at TEXT NOT NULL DEFAULT '',
  PRIMARY KEY (plugin, filename)
);

DROP TABLE IF EXISTS user_app_subjects;
CREATE TABLE user_app_subjects (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  client_id TEXT COLLATE NOCASE NOT NULL,
  subject_id TEXT NOT NULL,
  created_at TEXT DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  FOREIGN KEY (client_id) REFERENCES oauth_clients (client_id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX idx_user_app_subjects_user_client ON user_app_subjects (user_id, client_id);
CREATE UNIQUE INDEX idx_user_app_subjects_subject ON user_app_subjects (subject_id);
CREATE INDEX idx_user_app_subjects_client_id ON user_app_subjects (client_id);

DROP TABLE IF EXISTS user_authorizations;
CREATE TABLE user_authorizations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  client_id TEXT COLLATE NOCASE NOT NULL,
  scopes TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT '',
  updated_at TEXT NOT NULL DEFAULT '',
  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  FOREIGN KEY (client_id) REFERENCES oauth_clients (client_id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX idx_user_authorizations_user_client ON user_authorizations (user_id, client_id);
CREATE INDEX idx_user_authorizations_client_id ON user_authorizations (client_id);

DROP TABLE IF EXISTS user_biometrics;
CREATE TABLE user_biometrics (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  credential_id TEXT NOT NULL,
  public_key TEXT NOT NULL,
  sign_count INTEGER DEFAULT 0,
  transports TEXT,
  device_type TEXT DEFAULT NULL,
  revoked INTEGER DEFAULT 0,
  created_at TEXT DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
CREATE INDEX idx_user_biometrics_user_id ON user_biometrics (user_id);

DROP TABLE IF EXISTS user_devices;
CREATE TABLE user_devices (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  device_id TEXT NOT NULL,
  device_name TEXT NOT NULL DEFAULT '',
  ip_address TEXT NOT NULL DEFAULT '',
  user_agent TEXT,
  first_seen_at TEXT DEFAULT NULL,
  last_seen_at TEXT DEFAULT NULL,
  trusted_at TEXT DEFAULT NULL,
  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX idx_user_devices_device ON user_devices (user_id, device_id);

DROP TABLE IF EXISTS user_emails;
CREATE TABLE user_emails (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  email TEXT COLLATE NOCASE NOT NULL,
  is_primary INTEGER NOT NULL DEFAULT 0,
  verified INTEGER NOT NULL DEFAULT 0,
  verify_token TEXT DEFAULT NULL,
  verify_token_expires_at TEXT DEFAULT NULL,
  created_at TEXT NOT NULL DEFAULT '',
  verified_at TEXT DEFAULT NULL,
  secondary_email TEXT GENERATED ALWAYS AS (CASE WHEN is_primary = 1 OR verified = 0 THEN NULL ELSE email END) STORED
);
CREATE UNIQUE INDEX idx_user_emails_user_email ON user_emails (user_id, email);
CREATE UNIQUE INDEX idx_user_emails_secondary_email ON user_emails (secondary_email);
CREATE INDEX idx_user_emails_email ON user_emails (email);

DROP TABLE IF EXISTS user_notification_settings;
CREATE TABLE user_notification_settings (
  user_id INTEGER NOT NULL,
  notify_system_important INTEGER NOT NULL DEFAULT 1,
  notify_system_general INTEGER NOT NULL DEFAULT 0,
  notify_system_interact INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT NOT NULL DEFAULT '',
  PRIMARY KEY (user_id)
);

DROP TABLE IF EXISTS user_recovery_codes;
CREATE TABLE user_recovery_codes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  code_hash TEXT NOT NULL,
  batch_id TEXT NOT NULL,
  used_at TEXT DEFAULT NULL,
  created_at TEXT DEFAULT NULL
);
CREATE INDEX idx_user_recovery_codes_user ON user_recovery_codes (user_id);
CREATE INDEX idx_user_recovery_codes_user_used ON user_recovery_codes (user_id, used_at);
CREATE INDEX idx_user_recovery_codes_batch ON user_recovery_codes (batch_id);

DROP TABLE IF EXISTS user_temp_keys;
CREATE TABLE user_temp_keys (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  key_hash TEXT NOT NULL,
  label TEXT DEFAULT NULL,
  expires_at TEXT NOT NULL,
  last_used_at TEXT DEFAULT NULL,
  created_at TEXT DEFAULT NULL
);
CREATE INDEX idx_user_temp_keys_user_temp_keys_user ON user_temp_keys (user_id);
CREATE INDEX idx_user_temp_keys_user_temp_keys_expires ON user_temp_keys (expires_at);

DROP TABLE IF EXISTS user_totp_secrets;
CREATE TABLE user_totp_secrets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  secret TEXT NOT NULL,
  verified INTEGER NOT NULL DEFAULT 0,
  created_at TEXT DEFAULT NULL,
  updated_at TEXT DEFAULT NULL
);
CREATE UNIQUE INDEX idx_user_totp_secrets_user ON user_totp_secrets (user_id);

DROP TABLE IF EXISTS users;
CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  oauth_id TEXT COLLATE NOCASE DEFAULT NULL,
  username TEXT COLLATE NOCASE NOT NULL,
  email TEXT COLLATE NOCASE DEFAULT NULL,
  country TEXT DEFAULT NULL,
  birth_date TEXT DEFAULT NULL,
  gender TEXT DEFAULT NULL,
  locale TEXT DEFAULT NULL,
  skin TEXT DEFAULT NULL,
  password_hash TEXT NOT NULL DEFAULT '',
  display_name TEXT DEFAULT NULL,
  avatar TEXT DEFAULT NULL,
  bio TEXT,
  email_verified INTEGER NOT NULL DEFAULT 0,
  email_verify_token TEXT DEFAULT NULL,
  email_verify_token_expires_at TEXT DEFAULT NULL,
  email_verify_last_sent_at TEXT DEFAULT NULL,
  pending_email TEXT COLLATE NOCASE DEFAULT NULL,
  pending_email_token TEXT DEFAULT NULL,
  pending_email_token_expires_at TEXT DEFAULT NULL,
  status INTEGER NOT NULL DEFAULT 1,
  role TEXT NOT NULL DEFAULT 'user',
  two_factor_policy TEXT NOT NULL DEFAULT 'new_device',
  last_login_at TEXT DEFAULT NULL,
  last_login_ip TEXT DEFAULT NULL,
  created_at TEXT NOT NULL DEFAULT '',
  updated_at TEXT NOT NULL DEFAULT '',
  password_reset_code TEXT DEFAULT NULL,
  password_reset_code_expires_at TEXT DEFAULT NULL,
  quick_login INTEGER NOT NULL DEFAULT 1,
  CHECK (role IN ('user','admin')),
  CHECK (two_factor_policy IN ('every_login','new_device'))
);
CREATE UNIQUE INDEX idx_users_username ON users (username);
CREATE UNIQUE INDEX idx_users_email ON users (email);
CREATE UNIQUE INDEX idx_users_oauth_id ON users (oauth_id);
CREATE INDEX idx_users_email_2 ON users (email);
CREATE INDEX idx_users_username_2 ON users (username);
CREATE INDEX idx_users_email_verify_token ON users (email_verify_token);
CREATE INDEX idx_users_pending_email_token ON users (pending_email_token);

-- 时间列默认值 / 自动更新（等价于 MySQL 的 DEFAULT CURRENT_TIMESTAMP、ON UPDATE）
CREATE TRIGGER account_recovery_requests__insert_defaults AFTER INSERT ON account_recovery_requests
BEGIN
  UPDATE account_recovery_requests SET
    created_at = CASE WHEN NEW.created_at IS NULL OR NEW.created_at = '' THEN datetime('now','localtime') ELSE NEW.created_at END
  WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER activity_logs__insert_defaults AFTER INSERT ON activity_logs
BEGIN
  UPDATE activity_logs SET
    created_at = CASE WHEN NEW.created_at IS NULL OR NEW.created_at = '' THEN datetime('now','localtime') ELSE NEW.created_at END
  WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER developers__insert_defaults AFTER INSERT ON developers
BEGIN
  UPDATE developers SET
    created_at = CASE WHEN NEW.created_at IS NULL OR NEW.created_at = '' THEN datetime('now','localtime') ELSE NEW.created_at END,
    updated_at = CASE WHEN NEW.updated_at IS NULL OR NEW.updated_at = '' THEN datetime('now','localtime') ELSE NEW.updated_at END
  WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER developers__updated_at AFTER UPDATE ON developers
WHEN NEW.updated_at = OLD.updated_at
BEGIN
  UPDATE developers SET updated_at = datetime('now','localtime') WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER family_groups__insert_defaults AFTER INSERT ON family_groups
BEGIN
  UPDATE family_groups SET
    created_at = CASE WHEN NEW.created_at IS NULL OR NEW.created_at = '' THEN datetime('now','localtime') ELSE NEW.created_at END,
    updated_at = CASE WHEN NEW.updated_at IS NULL OR NEW.updated_at = '' THEN datetime('now','localtime') ELSE NEW.updated_at END
  WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER family_groups__updated_at AFTER UPDATE ON family_groups
WHEN NEW.updated_at = OLD.updated_at
BEGIN
  UPDATE family_groups SET updated_at = datetime('now','localtime') WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER family_members__insert_defaults AFTER INSERT ON family_members
BEGIN
  UPDATE family_members SET
    created_at = CASE WHEN NEW.created_at IS NULL OR NEW.created_at = '' THEN datetime('now','localtime') ELSE NEW.created_at END,
    updated_at = CASE WHEN NEW.updated_at IS NULL OR NEW.updated_at = '' THEN datetime('now','localtime') ELSE NEW.updated_at END
  WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER family_members__updated_at AFTER UPDATE ON family_members
WHEN NEW.updated_at = OLD.updated_at
BEGIN
  UPDATE family_members SET updated_at = datetime('now','localtime') WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER notifications__insert_defaults AFTER INSERT ON notifications
BEGIN
  UPDATE notifications SET
    created_at = CASE WHEN NEW.created_at IS NULL OR NEW.created_at = '' THEN datetime('now','localtime') ELSE NEW.created_at END
  WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER oauth_access_tokens__insert_defaults AFTER INSERT ON oauth_access_tokens
BEGIN
  UPDATE oauth_access_tokens SET
    created_at = CASE WHEN NEW.created_at IS NULL OR NEW.created_at = '' THEN datetime('now','localtime') ELSE NEW.created_at END
  WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER oauth_authorization_codes__insert_defaults AFTER INSERT ON oauth_authorization_codes
BEGIN
  UPDATE oauth_authorization_codes SET
    created_at = CASE WHEN NEW.created_at IS NULL OR NEW.created_at = '' THEN datetime('now','localtime') ELSE NEW.created_at END
  WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER oauth_clients__insert_defaults AFTER INSERT ON oauth_clients
BEGIN
  UPDATE oauth_clients SET
    created_at = CASE WHEN NEW.created_at IS NULL OR NEW.created_at = '' THEN datetime('now','localtime') ELSE NEW.created_at END,
    updated_at = CASE WHEN NEW.updated_at IS NULL OR NEW.updated_at = '' THEN datetime('now','localtime') ELSE NEW.updated_at END
  WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER oauth_clients__updated_at AFTER UPDATE ON oauth_clients
WHEN NEW.updated_at = OLD.updated_at
BEGIN
  UPDATE oauth_clients SET updated_at = datetime('now','localtime') WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER oauth_refresh_tokens__insert_defaults AFTER INSERT ON oauth_refresh_tokens
BEGIN
  UPDATE oauth_refresh_tokens SET
    created_at = CASE WHEN NEW.created_at IS NULL OR NEW.created_at = '' THEN datetime('now','localtime') ELSE NEW.created_at END
  WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER pending_registrations__insert_defaults AFTER INSERT ON pending_registrations
BEGIN
  UPDATE pending_registrations SET
    created_at = CASE WHEN NEW.created_at IS NULL OR NEW.created_at = '' THEN datetime('now','localtime') ELSE NEW.created_at END,
    updated_at = CASE WHEN NEW.updated_at IS NULL OR NEW.updated_at = '' THEN datetime('now','localtime') ELSE NEW.updated_at END
  WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER pending_registrations__updated_at AFTER UPDATE ON pending_registrations
WHEN NEW.updated_at = OLD.updated_at
BEGIN
  UPDATE pending_registrations SET updated_at = datetime('now','localtime') WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER user_app_subjects__insert_defaults AFTER INSERT ON user_app_subjects
BEGIN
  UPDATE user_app_subjects SET
    created_at = CASE WHEN NEW.created_at IS NULL OR NEW.created_at = '' THEN datetime('now','localtime') ELSE NEW.created_at END
  WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER user_authorizations__insert_defaults AFTER INSERT ON user_authorizations
BEGIN
  UPDATE user_authorizations SET
    created_at = CASE WHEN NEW.created_at IS NULL OR NEW.created_at = '' THEN datetime('now','localtime') ELSE NEW.created_at END,
    updated_at = CASE WHEN NEW.updated_at IS NULL OR NEW.updated_at = '' THEN datetime('now','localtime') ELSE NEW.updated_at END
  WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER user_authorizations__updated_at AFTER UPDATE ON user_authorizations
WHEN NEW.updated_at = OLD.updated_at
BEGIN
  UPDATE user_authorizations SET updated_at = datetime('now','localtime') WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER user_biometrics__insert_defaults AFTER INSERT ON user_biometrics
BEGIN
  UPDATE user_biometrics SET
    created_at = CASE WHEN NEW.created_at IS NULL OR NEW.created_at = '' THEN datetime('now','localtime') ELSE NEW.created_at END
  WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER user_emails__insert_defaults AFTER INSERT ON user_emails
BEGIN
  UPDATE user_emails SET
    created_at = CASE WHEN NEW.created_at IS NULL OR NEW.created_at = '' THEN datetime('now','localtime') ELSE NEW.created_at END
  WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER user_notification_settings__insert_defaults AFTER INSERT ON user_notification_settings
BEGIN
  UPDATE user_notification_settings SET
    updated_at = CASE WHEN NEW.updated_at IS NULL OR NEW.updated_at = '' THEN datetime('now','localtime') ELSE NEW.updated_at END
  WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER user_notification_settings__updated_at AFTER UPDATE ON user_notification_settings
WHEN NEW.updated_at = OLD.updated_at
BEGIN
  UPDATE user_notification_settings SET updated_at = datetime('now','localtime') WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER user_recovery_codes__insert_defaults AFTER INSERT ON user_recovery_codes
BEGIN
  UPDATE user_recovery_codes SET
    created_at = CASE WHEN NEW.created_at IS NULL OR NEW.created_at = '' THEN datetime('now','localtime') ELSE NEW.created_at END
  WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER user_temp_keys__insert_defaults AFTER INSERT ON user_temp_keys
BEGIN
  UPDATE user_temp_keys SET
    created_at = CASE WHEN NEW.created_at IS NULL OR NEW.created_at = '' THEN datetime('now','localtime') ELSE NEW.created_at END
  WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER user_totp_secrets__insert_defaults AFTER INSERT ON user_totp_secrets
BEGIN
  UPDATE user_totp_secrets SET
    created_at = CASE WHEN NEW.created_at IS NULL OR NEW.created_at = '' THEN datetime('now','localtime') ELSE NEW.created_at END,
    updated_at = CASE WHEN NEW.updated_at IS NULL OR NEW.updated_at = '' THEN datetime('now','localtime') ELSE NEW.updated_at END
  WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER user_totp_secrets__updated_at AFTER UPDATE ON user_totp_secrets
WHEN NEW.updated_at = OLD.updated_at
BEGIN
  UPDATE user_totp_secrets SET updated_at = datetime('now','localtime') WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER users__insert_defaults AFTER INSERT ON users
BEGIN
  UPDATE users SET
    created_at = CASE WHEN NEW.created_at IS NULL OR NEW.created_at = '' THEN datetime('now','localtime') ELSE NEW.created_at END,
    updated_at = CASE WHEN NEW.updated_at IS NULL OR NEW.updated_at = '' THEN datetime('now','localtime') ELSE NEW.updated_at END
  WHERE rowid = NEW.rowid;
END;

CREATE TRIGGER users__updated_at AFTER UPDATE ON users
WHEN NEW.updated_at = OLD.updated_at
BEGIN
  UPDATE users SET updated_at = datetime('now','localtime') WHERE rowid = NEW.rowid;
END;

PRAGMA foreign_keys = ON;
