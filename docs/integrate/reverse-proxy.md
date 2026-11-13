# 反向代理与 CDN

放在 nginx + Cloudflare 后面是最常见的部署。这一页是「必须配对的那几处」和「出了怪事查哪里」。

## root 指向 public

```nginx
root /www/wwwroot/nomenid/public;
index index.php;
```

**root 必须是 `public/`**，不是项目根。项目根下有 `config/`、`docs/`、`data/`，指错了整个站点的密码和配置
都能被下载。

## 伪静态

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
```

- 少了 `try_files`：只有首页能开，`/login` 全是 404。
- 少了 `$uri/`：目录型地址（`/w/`）会 404。
- 宝塔：站点设置 → 伪静态，贴这一段。

不用伪静态的站点把 `config/installed.php` 的 `app.pretty_urls` 设成 `false`，改用 `index.php?page=`。

## 两个必须放行的路径

```nginx
location ^~ /plugin-assets/ {
    try_files $uri /index.php$is_args$args;
}

location ^~ /.well-known/ {
    try_files $uri /index.php$is_args$args;
}
```

**`/plugin-assets/`**：很多面板的 vhost 自带 `location ~ .*\.(js|css)?$`，会抢在 PHP 前面去磁盘找
`/plugin-assets/xxx/portal.css`，找不到就 404。表现是「页面能开，但插件的样式和脚本全丢」。
`^~` 前缀匹配优先于正则，保证它一定进 PHP。

**`/.well-known/`**：OIDC 的发现文档。不放行的表现是「发现文档 404，其它 OAuth 端点都正常」——接入方
第一步就卡住，而且看着像没实现 OIDC。

## 真实 IP

会话、限流、登录防护都按 IP 算。前面有 CDN 时，PHP 看到的都是 CDN 的 IP，等于全站共用一个限流桶。

```nginx
set_real_ip_from 173.245.48.0/20;   # Cloudflare 网段，按官方列表补全
real_ip_header CF-Connecting-IP;
```

配完在日志里抽查一条，确认记的是访客 IP。**别信 `X-Forwarded-For` 的第一段**，那是客户端可以随便写的，
Cloudflare 会把真实 IP 放在 `CF-Connecting-IP` 里。

限流相关的坑：`login_ip_hard_limit` 是每分钟 20 次。CDN 没透传真实 IP 时，一个办公室的人同时登录就能触发。

## 缓存

| 内容 | 该不该缓存 |
|---|---|
| HTML 页面 | 不缓存（动态，带会话状态） |
| `/css/*` `/js/*` `/assets/*` | 缓存，靠 `?v=` 换版本 |
| `/plugin-assets/*` | 缓存，版本是文件修改时间 |
| `/.well-known/*` | 短缓存（5 分钟）即可 |
| `/oauth/*` `/api/*` | 绝不缓存 |

Cloudflare 默认会缓存扩展名像静态资源的响应。**判断某个地址是缓存还是回源**：

```bash
curl -skI -A 'Mozilla/5.0' https://example.com/css/style.css
# 看 cf-cache-status: HIT / MISS / BYPASS / DYNAMIC
```

`BYPASS` 不是错误，意思是这次没走缓存、回源了；`DYNAMIC` 是动态内容不缓存。

**404 也会被缓存**（源站 404 带 `Cache-Control` 时）。修好之后旧地址还 404，先看 `cf-cache-status`。
绕开的办法是换 `?v=`，让 URL 变掉。

静态资源带 `Set-Cookie`（有些 WAF 会加）时 CF 不缓存，这是面板 WAF 的行为，不是 Nomen 的问题。

## HTTPS

- 全站 HTTPS，HTTP 301 过去。`base_url` 填 `https://`。
- `webauthn_origin` 必须和浏览器地址栏一致，否则生物识别注册会失败（`SecurityError`）。
- OAuth 的 `redirect_uri` 也必须是 https（除了 `localhost`）。

## 子路径部署

**不推荐**，但能跑：

```nginx
location /nomen/ {
    alias /www/wwwroot/nomen/public/;
    try_files $uri $uri/ /nomen/index.php$is_args$args;
}
```

要同时改 `config/brand.php` 的 `base_url` 带上 `/nomen`，否则生成的链接、canonical、OAuth 回调都会指向
根路径。很多地方拼的是绝对路径，子路径下容易漏一处；能上子域名就上子域名。

## 文件权限

```bash
chown -R www:www /www/wwwroot/<站点>
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
```

要写的目录：`data/`、`public/assets/{avatars,recovery,develops}/`、`public/assets/skins/`。
发布包里解出来的文件常常是 666，上线前统一改成 644。

## 面板的常见拦截

- **`.user.ini` 被 `chattr +i`**：`rsync --delete` 会报 23 号错误，部署时排除它。
- **敏感文件规则按结尾拦**：`/license`、`/w/changelog` 这种地址可能被规则命中，页面有内容但状态码 404。
  放行写在站点的 extension conf 里。
- **面板重写 vhost**：在面板里改过站点设置之后，`grep` 一遍上面这几段还在不在。

## 检查清单

- [ ] root 指到 `public/`
- [ ] `try_files` 带 `$uri/`
- [ ] `/plugin-assets/` 和 `/.well-known/` 放行
- [ ] CDN 透传真实 IP
- [ ] HTML 不缓存，静态资源带 `?v=`
- [ ] `base_url` / `webauthn_origin` 和实际地址一致
- [ ] 文件属主 `www:www`，文件 644、目录 755
