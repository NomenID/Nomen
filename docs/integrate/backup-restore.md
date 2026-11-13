# 备份与恢复

备份分两块：**数据库**和**文件**。少一样能恢复，但会丢东西——库没了账号全丢，文件没了头像、证明材料、
站点配置全丢。

## 库里有什么

必须备份的表：`users` / `user_emails` / `user_identities` / `user_biometrics` / `user_totp_secrets` /
`user_recovery_codes` / `oauth_clients` / `site_settings`。

可以重建的：`cache_items`（缓存）、`plugin_migrations`（迁移记录，丢了会重跑迁移）。

## 文件里有什么

| 路径 | 丢了会怎样 |
|---|---|
| `config/installed.php` | 安装信息、DB 凭据、pepper 全丢，站点直接不可用 |
| `.env` | 环境覆盖没了，回落到默认值（可能看起来「配置被重置」） |
| `data/` | OIDC 私钥、备份、缓存文件；**OIDC 私钥丢了等于换密钥**，已发出的 id_token 全部验不过 |
| `public/assets/avatars/` | 用户头像 |
| `public/assets/recovery/` | 恢复工单的证明材料 |
| `public/assets/develops/` | 站点图标、提供方图标 |
| `config/plugins/*.json` | 插件配置（挂载点路径等） |
| `config/skins.php` | 皮肤选择 |

`vendor/`、`public/assets/dist/` 不用备份——发布包里就有。

## 用 CLI

```bash
php bin/nomen backup                  # 默认写到 data/backups/
php bin/nomen backup /tmp/nomen-bak    # 指定目录
```

只导数据库，MySQL 出 `.sql`，SQLite 出 `.sqlite`。文件会提示「里面有全站账号和密码哈希，别放 web 目录」。

恢复：

```bash
php bin/nomen restore /tmp/nomen-bak/nomen-20261113-101500.sql
php bin/nomen restore <文件> --yes      # 不再问确认
```

恢复会**覆盖现有数据**，所以先停掉站点或者至少停掉写入。

## 手工备份（更保险）

CLI 只处理库，主机上更常用的是这一套：

```bash
# 1. 库
mysqldump --single-transaction --quick --default-character-set=utf8mb4 \
  -u<用户> -p<pw> <库名> | gzip > /tmp/nomen-db-$(date +%Y%m%d).sql.gz

# 2. 文件（排除不用备的）
tar czf /tmp/nomen-files-$(date +%Y%m%d).tar.gz \
  -C /www/wwwroot/nomenid \
  --exclude='./vendor' --exclude='./public/assets/dist' --exclude='./data/cache' \
  .
```

- `--single-transaction` 让 InnoDB 在不锁表的情况下导出一致快照，**别去掉**。
- 备份文件**绝不能放在 web root**，`.sql` 会被直接下载。
- 导完立刻看一眼大小：几 KB 的 `.sql` 基本是空的（凭据错、库名错）。

## SQLite 站点

`database/nomen.sqlite` 是**单个文件**，直接拷就行，但要保证没有写入：

```bash
sqlite3 database/nomen.sqlite ".backup /tmp/nomen-$(date +%Y%m%d).sqlite"
```

用 `.backup` 而不是 `cp`——正在写的库 `cp` 出来可能不一致。

## 恢复演练

备份不做演练等于没有备份。**每季度挑一个测试环境跑一遍**：

1. 新建一个空库，导入 `.sql`。
2. 把 `config/installed.php` 的 `db` 段指过去（或者整站复制一份）。
3. 打开首页，确认能开。
4. 用一个已知账号登录一次——**这一步能发现 pepper 有没有对上**。pepper 不对时密码全部验证失败。
5. 打开 `/special/version`，看版本号和备份那天是否一致。

第 4 步是最容易被忽略的：库备了、文件没备，或者 `installed.php` 里的 pepper 和库里密码哈希用的不是同一个，
站能开但谁也登不进去。

## 恢复的顺序

```
1. 停站点（或维护模式）
2. 恢复文件（先不动 config/installed.php，除非它也是从备份里恢复）
3. 恢复数据库
4. 清缓存（data/cache/ 或后台清）
5. php bin/nomen upgrade        # 备份可能比代码旧，跑一遍迁移
6. 起站点，验首页 / 登录 / 版本页
```

第 5 步不能省：备份是上周的，代码是今天的，中间可能有新迁移。`upgrade` 会补上。

## 异地与保留

- 至少留三份：本地、另一台机器、对象存储。
- 保留策略按「最坏情况多久才发现」定，常见是 7 天日备 + 4 周周备 + 12 月月备。
- **备份要加密**。里面有全站账号和密码哈希；密码哈希虽然是哈希，但配合 pepper 泄露就是灾难。
- 备份文件名带日期，别覆盖同一份。

## 检查清单

- [ ] 库和文件都备了
- [ ] `config/installed.php` 和 `.env` 在备份里
- [ ] `data/`（含 OIDC 私钥）在备份里
- [ ] 上传目录在备份里
- [ ] 备份不在 web root
- [ ] 恢复演练过，能登录进去
- [ ] 备份有异地副本
