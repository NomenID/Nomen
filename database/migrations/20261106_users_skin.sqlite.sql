-- users.skin：账号级外观偏好。NULL = 跟随站点默认皮肤。
ALTER TABLE users ADD COLUMN skin TEXT DEFAULT NULL;
