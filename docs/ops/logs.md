# 日志与监控

出问题时最值钱的是日志。Nomen 的日志分三层：nginx、PHP-FPM、应用。

## 在哪

| 日志 | 位置（宝塔） | 内容 |
|---|---|---|
| nginx 访问 | `/www/wwwlogs/<域名>.log` | 每个请求：IP、UA、状态码、耗时 |
| nginx 错误 | `/www/wwwlogs/<域名>.error.log` | 上游超时、PHP 崩溃 |
| PHP-FPM | 同上（`error_log` 指向它） | `[nomen]` / `[Plugins]` / `[SECURITY]` |
| 应用 | 同上 | `error_log()` 的输出 |

非宝塔：看 `php.ini` 的 `error_log` 和 nginx 的 `access_log` / `error_log` 指令。

## 前缀约定

| 前缀 | 来源 | 该不该管 |
|---|---|---|
| `[nomen]` | 核心报错，500 带 8 位编号 | 要看 |
| `[Plugins]` | 插件加载 / 钩子注册失败 | 要看 |
| `[SECURITY]` | pepper 没配这类 | **必须看** |
| `[Session]` / `[redis]` / `[cache]` | 后端回退 | 偶尔 |
| `[GeoIP]` | 库缺失 / 解析失败 | 忽略 |

```bash
grep -E '\[nomen\]|\[Plugins\]|\[SECURITY\]' <域名>.error.log | tail -30
```

## 用户报错时怎么查

用户给你一个 8 位编号（页面上显示的那个）：

```bash
grep '3f9a1c7e' <域名>.error.log
```

编号是 500 以上错误才有的，4xx 不生成（那是正常拒绝）。

没有编号就给个时间点 + 页面地址：

```bash
grep 'GET /admin/users' <域名>.log | tail -20
grep '07:5[0-9]' <域名>.error.log | tail -20
```

## 访问日志里能看出什么

```bash
# 今天 5xx 的请求
awk '$9 >= 500' <域名>.log | tail -20

# 请求最多的 IP
awk '{print $1}' <域名>.log | sort | uniq -c | sort -rn | head

# 最慢的请求（$request_time 是最后一列，格式看你的 log_format）
awk '{print $NF, $7}' <域名>.log | sort -rn | head
```

**注意 nginx 的 log_format**：默认格式里没有耗时，要自己在 vhost 里加 `$request_time`。

## 别指望日志里的东西

- **PHP 的错误日志可能不开**：`display_errors=Off` 且 `log_errors=Off` 时什么都不写。检查
  `php -i | grep log_errors`。
- **CLI 和 FPM 的日志不是一个地方**：`php bin/nomen` 的错误打到 stderr，不在 nginx 日志里。
- **`error_log()` 写的是 PHP 的 error_log**，不是应用自己的文件。想分离就用 `error_log($msg, 3, '/path')`。

## 监控

外部探针够了就别装一堆 agent。建议至少：

1. **HTTP 探针**：打一个真渲染 PHP 的地址（`/special/version`），1 分钟一次，连续 3 次失败告警。
2. **磁盘**：> 85% 告警。
3. **证书**：到期前 14 天告警。
4. **备份**：文件超过 26 小时没更新告警。

自建 Uptime Kuma 的话，心跳数据在 MySQL 里（不在 SQLite），查最近一次状态：

```sql
SELECT m.name, CONVERT_TZ(h.time,'+00:00','+08:00') AS t,
       CASE h.status WHEN 1 THEN 'UP' WHEN 0 THEN 'DOWN' ELSE h.status END AS st,
       h.ping, h.msg
FROM uptimekuma.heartbeat h
JOIN uptimekuma.monitor m ON m.id = h.monitor_id
WHERE h.id IN (SELECT MAX(id) FROM uptimekuma.heartbeat GROUP BY monitor_id);
```

掉线时先看**时间线**（几点、哪几个站、什么原因），比自己反复采样准。

## 保留与隐私

- 访问日志里有 IP 和 UA，属于个人信息，保留期限写进隐私政策。
- **别在日志里打令牌、密码、邮箱验证码**。调试完记得删 `error_log` 那行。
- 日志压缩后保留 14 天足够定位最近的问题，长期趋势看监控而不是日志。

## 检查清单

- [ ] `log_errors` 是开的，错误有地方落
- [ ] 日志轮转配了，`logrotate -d` 干跑过
- [ ] 500 的编号能在日志里搜到
- [ ] 有 HTTP 探针，打的是动态页
- [ ] 日志里没有敏感值
