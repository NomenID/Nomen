CREATE TABLE IF NOT EXISTS identity_providers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL COLLATE NOCASE,
  name TEXT NOT NULL,
  type TEXT NOT NULL DEFAULT 'oauth2',
  client_id TEXT NOT NULL DEFAULT '',
  client_secret TEXT NOT NULL DEFAULT '',
  authorize_url TEXT NOT NULL DEFAULT '',
  token_url TEXT NOT NULL DEFAULT '',
  userinfo_url TEXT NOT NULL DEFAULT '',
  emails_url TEXT NOT NULL DEFAULT '',
  scopes TEXT NOT NULL DEFAULT '',
  subject_field TEXT NOT NULL DEFAULT 'sub',
  email_field TEXT NOT NULL DEFAULT 'email',
  name_field TEXT NOT NULL DEFAULT 'name',
  avatar_field TEXT NOT NULL DEFAULT 'picture',
  email_verified_field TEXT NOT NULL DEFAULT '',
  token_auth TEXT NOT NULL DEFAULT 'post',
  extra_authorize_params TEXT NOT NULL DEFAULT '',
  enabled INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT '',
  updated_at TEXT NOT NULL DEFAULT ''
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_identity_providers_slug ON identity_providers (slug);

CREATE TABLE IF NOT EXISTS user_identities (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  provider_id INTEGER NOT NULL,
  subject TEXT NOT NULL,
  email TEXT DEFAULT NULL,
  display_name TEXT DEFAULT NULL,
  avatar_url TEXT DEFAULT NULL,
  raw TEXT,
  created_at TEXT NOT NULL DEFAULT '',
  updated_at TEXT NOT NULL DEFAULT ''
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_user_identities_provider_subject ON user_identities (provider_id, subject);
CREATE UNIQUE INDEX IF NOT EXISTS idx_user_identities_user_provider ON user_identities (user_id, provider_id);
CREATE INDEX IF NOT EXISTS idx_user_identities_user ON user_identities (user_id);
