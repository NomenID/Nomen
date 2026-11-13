# 故障排查

按现象查。每条都给「先看什么」和「怎么确认」，不要一上来就重启。

## 站点打不开

先分清是**网络层**还是**应用层**。

```bash
# 1. TCP 通不通（HTTP 端口和 SSH 端口各试一个）
nc -zv example.com 443
nc -zv example.com 22

# 2. HTTP 有没有响应（用 GET，不要用 HEAD）
curl -s -o /dev/null -w '%{http_code}\n' https://example.com/
```

| 结果 | 判断 |
|---|---|
| TCP 都不通 | 机器或网络的事，不是 Nomen |
| TCP 通、HTTP 无响应 | 应用层堵了：PHP-FPM 打满、数据库连不上 |
| HTTP 有响应但是 5xx | 看错误日志，见下面「500」 |

**TCP 通但 SSH 认证卡住**：机器可能被打满了（CPU 抢不到，sshd 自己都响应不了）。
别再重试，隔一两分钟看一次，同时准备从面板 / 控制台进。

## 500

1. 页面上有个 8 位编号（`[3f9a1c7e]`），`grep` 它。
2. 日志里会有异常类型、文件、行号。

```bash
grep '3f9a1c7e' /www/wwwlogs/example.com.error.log
```

常见原因：

| 日志里看到 | 原因 |
|---|---|
| `SQLSTATE` | 数据库连不上 / 库不存在 / 权限 |
| `Class "..." not found` | 插件 autoload 前缀和 namespace 不一致 |
| `Undefined variable` / `null` 上调用方法 | 模板变量少了（皮肤覆盖时最容易） |
| `Allowed memory size exhausted` | 内存不够，看是不是某个查询拉太多 |

临时打开详细报错：`.env` 里 `APP_DEBUG=1`，**验完立刻改回 0**。

## 404

| 现象 | 原因 |
|---|---|
| 只有首页能开，其他全 404 | nginx 少了 `try_files` |
| 目录地址（`/w/`）404 | `try_files` 少了 `$uri/` |
| 插件页面 404 | 插件没启用，或路由没注册（`plugins list` 看状态） |
| `/special/*` 404 | slug 拼错，或插件没加载 |
| 页面有内容但状态 404 | 面板的敏感文件规则按结尾拦了，看 nginx 的拦截规则 |

## 插件样式 / 脚本全丢

现象：页面能开，但插件的 CSS、JS 都没加载（图标错位、交互失效）。

原因：vhost 里有 `location ~ .*\.(js|css)?$` 这样的正则块，抢在 PHP 前面去磁盘找 `/plugin-assets/xxx.css`，
找不到就 404。

```nginx
location ^~ /plugin-assets/ {
    try_files $uri /index.php$is_args$args;
}
```

`^~` 前缀匹配优先于正则。加完 `nginx -t` 再 `reload`。

## 一直 302 到 /activate

账号没有任何已验证的邮箱。

- 自己注册的：去收激活邮件，或者重发。
- 改了邮箱但没验证：`user_emails` 里那条 `verified = 0`。
- 孩子账号：家长确认（`consent_at`）也算通过，检查 `family_members`。

## 一直 302 到 /recovery/secure

账号处于「恢复模式」（安全修复没完成）。

```sql
SELECT id, status, recovery_token_used_at FROM account_recovery_requests
WHERE user_id = <id> ORDER BY id DESC LIMIT 3;
```

修复流程走完（`status = completed`）就会解除。卡住的话看是不是令牌用掉了但没走完。

## 登录不上

| 现象 | 原因 |
|---|---|
| 密码对，提示错误 | pepper 变了（库和 `installed.php` 不是一套） |
| 密码对，反复跳回登录页 | cookie 被清、`session_name` 变了、跨域 |
| 二验输对又跳回来 | `two_factor_pending_ttl` 太短，或者会话存储不可写 |
| 管理员进不了后台 | `users.role` 不是 `admin`；`php bin/nomen user:promote <用户名>` |
| 五种方式全关 | `php bin/nomen auth:reset` |

## 收不到邮件

1. `smtp.host` 是不是空的（空 = 不发信，且**页面照样提示成功**）。
2. 错误日志搜 `SMTP`。
3. 命令行单独试：`swaks ...`（见 [SMTP](../integrate/smtp.md)）。
4. 收件方垃圾箱：查 SPF / DKIM / DMARC。

## 改了内容但页面还是旧的

按这个顺序排：

1. **PHP 层**：opcache 开着且关了时间戳校验 → reload PHP-FPM。
2. **缓存**：`data/cache/` 或 Redis（版本页 60 秒、更新检查 30 分钟）。
3. **CDN**：`curl -skI -A 'Mozilla/5.0' <url>` 看 `cf-cache-status`。`HIT` 就是缓存，加个没用的
   `?v=1` 绕开验证一下。
4. **浏览器**：强刷（Ctrl+F5）。

静态资源（CSS/JS）改了要换 `?v=`，否则浏览器和 CDN 都不会来拿新的。

## 上传失败

| 现象 | 原因 |
|---|---|
| 提示成功但文件没变 | `public/assets/...` 不可写（属主不是 `www`） |
| 413 | nginx 的 `client_max_body_size` 太小 |
| 只有头像失败 | 缺 GD / fileinfo；上传会自动降级成「填链接」 |
| 证明材料上传失败 | 单张 1MB、最多 5 张的上限 |

```bash
chown -R www:www public/assets
chmod -R 775 public/assets/avatars public/assets/recovery public/assets/develops
```

## 突然很慢

```bash
uptime                 # 负载
free -m                # 内存
cat /proc/pressure/cpu /proc/pressure/io /proc/pressure/memory
ps --sort=-rss | head   # 谁在吃内存
```

| 指标 | 正常 | 紧张 |
|---|---|---|
| `pressure/memory` 的 `full avg300` | < 5 | > 10 |
| `pressure/io` 的 `full avg300` | < 10 | > 20 |
| swap 的 `si` / `so` | 0 | 持续非 0 |

**别用 `free` 的 used 或 RSS 汇总判断内存**：共享页会被重复计算。要看真实占用用
`/proc/*/smaps_rollup` 的 `Pss` 累加。

## 磁盘满了

```bash
df -h
du -xh / --max-depth=1 | sort -h | tail
```

先分类再删：一次性垃圾（旧日志、旧备份）、增长型缓存（日志、临时文件）、真数据。
**别对着 GB 级文件做全量校验**，慢盘上会很慢。

日志轮转没配是最常见的「磁盘慢慢满」。

## 排查的基本顺序

1. **只读**：日志、状态码、`df`/`free`/`uptime`。
2. **确认**：能不能复现，是不是所有人都有。
3. **最小改动**：先试 reload，再考虑重启。
4. **回滚预案**：动手前想好怎么退。

「我判断它要崩」不是自己动手的理由——要停服务、改配置、搬数据，先说清楚改什么、为什么、怎么回滚。
