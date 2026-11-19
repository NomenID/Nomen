# 安全策略

这一页是「出厂设置里哪些是安全相关的、别乱动」。部署层面的（nginx、权限、备份）在
[反向代理](integrate/reverse-proxy.md) 和 [备份恢复](integrate/backup-restore.md)。

## pepper

密码是 `password_hash($password . $pepper, PASSWORD_ARGON2ID)`：**Argon2id，pepper 直接拼在明文后面**。

- 存在 `.env` 的 `APP_PEPPER`（或 `installed.php` 的 `pepper`）。
- **有用户之后绝不能改**：改了所有密码都验不过，而且无法回滚。
- 老数据里的无 pepper 哈希会在一次成功登录后就地升级成带 pepper 的 Argon2id。
- 没配时启动会往日志写 `[SECURITY] APP_PEPPER 未配置`。

## CSRF

所有 POST 表单都带 token，`Csrf::field()` 输出，`Csrf::verify()` 校验。

- 插件自己的 POST 处理函数要自己调 `Csrf::verify()`，路由层不做。
- 站点 API 的写模块也要自己校验（核心只管读权限）。
- 后台的表单用 `data-admin-ajax` 时，token 由外层接管，但**表单里仍然要有 `Csrf::field()`**。

## 登录防护

| 配置 | 默认 | 作用 |
|---|---|---|
| `login_captcha_threshold` | 5 | 单 IP 每分钟登录尝试超过这个值才要验证码 |
| `login_ip_hard_limit` | 20 | 单 IP 每分钟硬上限，超了直接拒 |
| `login_failed_method_ttl` | 900 | 「刚才这个方式没成功」的提醒保留多久 |
| `two_factor_pending_ttl` | 600 | 二验待验证会话的有效期 |

限流按 **IP** 算。前面有 CDN 时必须透传真实 IP，否则一个办公室共用一个限流桶。

## 接口限流

| 端点 | 限制 |
|---|---|
| `POST /oauth/token` | 30 次 / 分钟 / IP |
| `GET /api/userinfo` | 60 次 / 分钟 / IP |
| `GET /api/token-info` | 30 次 / 分钟 / IP |
| `GET /api/version` | 60 次 / 分钟 / IP |
| `GET /auth/<slug>/redirect` | 120 次 / 分钟 / IP |

超了回 429，带 `Retry-After`。

## 会话

| 项 | 值 |
|---|---|
| 未登录访客 | `session_lifetime`（2 小时） |
| 登录后滑动有效期 | `login_ttl`（30 天，每次访问续期） |
| 登录后硬上限 | `login_max_ttl`（90 天，从登录时刻算） |
| 公共设备 | 浏览器会话 cookie，关浏览器即失效 |

PHP 的 `gc_maxlifetime` 提到 90 天（PHP 只能全局设），所以匿名会话的服务端数据也留 90 天。

## 令牌

- access / refresh token **只存哈希**，明文只在签发那一次返回。
- 授权码 10 分钟、access 1 小时、refresh 180 天。
- refresh 一次旧的立刻失效（轮换）。
- `sub` 按应用生成，应用之间无法串号。

## 响应头

`public/index.php` 里全局设置：

```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
Content-Security-Policy: frame-ancestors 'none'; base-uri 'self'; object-src 'none'
```

HSTS 交给 nginx 统一加，代码里不重复。**别把 CSP 收紧到禁止内联脚本**——布局里有防闪的内联脚本，
会白屏。

## 上传

- 头像 135×135、2MB 上限，落 `public/assets/avatars/`。
- 证明材料落 `public/assets/recovery/`，单张 1MB、最多 5 张。
- 插件静态资源只发白名单类型（css/js/图片/字体/json），路径不能跳出 `assets/`。
- **上传目录不能执行 PHP**：`public/assets/*` 下的 `.php` 应该由 nginx 拒绝执行。

## 调试开关

`.env` 的 `APP_DEBUG`：开着时 500 页面带异常类型、文件行号和调用栈。

**线上永远关着。** 交付前检查：

```bash
grep -i '^APP_DEBUG' .env
```

## 插件和皮肤

插件跑的是全权限 PHP，皮肤模板也是直接 `require`。**装第三方扩展等于把站点代码权限给它**，
只装信得过的。核心的几个不变量（密码、二防、恢复审核）**不做成可改结果的钩子**。

## 密码策略

没有强制的复杂度规则（长度、字符种类）——这类规则实际收益低，还催生 `Password1!`。
真正起作用的是：

- Argon2id + pepper；
- 登录限流 + 验证码；
- 可选的两步验证；
- 账号恢复流程要人工审核。

要加自己的规则，在 `handleRegister` 里加校验，别改 `password_hash` 的调用方式。

## 自查清单

- [ ] `APP_PEPPER` 是随机的、备份过、从没改过
- [ ] `APP_DEBUG` 线上关闭
- [ ] CDN 透传真实 IP（限流才有意义）
- [ ] 上传目录不执行 PHP
- [ ] 装了插件 / 皮肤的都是信得过的
- [ ] 两步验证按需开启，且 SMTP 可用
- [ ] 备份文件不在 web root
