# 日常运维

按频率列。每一项都能用一条命令确认，不靠记忆。

## 每天（或告警触发）

```bash
# 站点活着
curl -s -o /dev/null -w '%{http_code}\n' https://example.com/

# 错误日志有没有新的
tail -50 /www/wwwlogs/example.com.error.log | grep -E '\[nomen\]|\[Plugins\]|\[SECURITY\]'

# 磁盘
df -h /
```

## 每周

```bash
# 备份有没有真的生成（不是看脚本退出码，是看文件大小）
ls -lh /backup/nomen-*.sql.gz | tail -3

# 数据库大小
mysql -e "SELECT table_schema, ROUND(SUM(data_length+index_length)/1024/1024) AS mb
          FROM information_schema.tables WHERE table_schema='<库>' GROUP BY 1;"

# 日志目录
du -sh /www/wwwlogs/
```

备份的判定标准是「能恢复」，不是「文件存在」。至少每月演练一次（见
[备份与恢复](../integrate/backup-restore.md)）。

## 每月

| 项 | 怎么查 |
|---|---|
| 证书有效期 | `echo \| openssl s_client -connect example.com:443 2>/dev/null \| openssl x509 -noout -dates` |
| GeoIP 库更新 | 重下 mmdb（GeoLite2 每周更新，不更新城市定位会飘） |
| 活动日志增长 | `SELECT COUNT(*) FROM activity_logs;`，太大就按时间归档 |
| 依赖安全 | `composer audit`（有 composer 的话） |
| 慢查询 | `slow_query_log` 里看看有没有新出现的 |

## 每季

- **恢复演练**：拿备份在测试环境恢复一次，能登录才算过。
- **权限复查**：`SELECT id, unid, username, role FROM users WHERE role='admin';` 该撤的撤。
- **token 清理**：过期的 `oauth_access_tokens` / `oauth_refresh_tokens` 可以删。
- **插件更新**：`php bin/nomen plugins list` 看有没有被版本要求跳过的。

## 日志轮转

不配轮转，日志迟早把盘吃满。

```
/www/wwwlogs/*.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    create 640 www www
}
```

PHP-FPM 的错误日志和 nginx 的日志都要覆盖。**改完 `logrotate -d` 干跑一次**确认匹配到了文件。

## 监控要盯什么

| 指标 | 正常 | 告警阈值 |
|---|---|---|
| HTTP 状态码 | 2xx/3xx | 5xx 连续出现 |
| 响应时间 | < 500ms | > 2s 持续 1 分钟 |
| 内存压力 `full avg300` | < 5 | > 10 |
| IO 压力 `full avg300` | < 10 | > 20 |
| 磁盘使用 | < 80% | > 90% |
| 数据库连接数 | 稳定 | 接近上限 |

外部探针建议打一个**会真渲染 PHP 的地址**（比如 `/special/version`），比打静态首页更能反映实际状态。

## 更新程序

```bash
git fetch && git log --oneline HEAD..origin/main   # 先看改了什么
# 备份 → 替换文件 → php bin/nomen upgrade → plugins migrate → 验证
```

完整流程和排除项见 [安装 / 升级](../install.md) 和
[兼容与升级](../internals/compatibility.md)。

## 季节性的事

- 开学 / 活动前看一眼容量，别等活动当天扩。
- 域名和证书到期时间记在日历里。
- 换了运维（包括换自己三个月后的记忆）之前，把「哪台机器、哪个目录、备份在哪」写下来。
