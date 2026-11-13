# GeoIP：按 IP 查归属地

注册页按 IP 预填国家、活动日志里显示登录地点。用的是 MaxMind 的 GeoLite2 数据库，**库和 PHP 依赖都要自己装**——
它们不进发布包。

## 装上

随包自带、默认启用，但**没有依赖时它是空转的**：

- `class_exists(\GeoIp2\Database\Reader::class)` 为假 → 只显示「库未安装」，不报错。
- mmdb 文件不存在 → 同上。

所以「装了但什么都没发生」是正常表现，不是坏了。

## 装依赖

```bash
cd /www/wwwroot/<站点>
composer require geoip2/geoip2:^3.4
```

- **别装 2.x**：2.x 在 `names` 为 null 时会抛 `TypeError` 直接 fatal。
- 走国内镜像会快很多：`composer config repos.packagist composer https://mirrors.tencent.com/composer/`。
- **没有 composer 的站**：去 GitHub 下 `geoip2/geoip2`、`maxmind-db/reader`、`maxmind/web-service-common` 三个包
  手工放进 `vendor/`，或者干脆不装这个插件——它不是必需的。

然后下载数据库（要 MaxMind 账号，GeoLite2 免费）：

```bash
mkdir -p data
curl -L "https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-City&license_key=<你的key>&suffix=tar.gz" \
  | tar xz -C /tmp
cp /tmp/GeoIP2-City_*/GeoLite2-City.mmdb data/
```

**放 `data/`**（默认就是这里），别放 `public/`——那是付费数据，不该公开下载。

## 看状态

管理员访问 `/special/geoip`：会列出 PHP 库在不在、mmdb 路径、文件大小、数据库版本和版权日期。

## 它具体改了什么

| 行为 | 说明 |
|---|---|
| 注册页预填国家 | 只影响带 `country` 可选功能的站点（`features.country`） |
| 活动日志显示地点 | 登录记录里多一列国家 / 城市 |
| 不阻塞登录 | 查不到就是空，不会因为库坏了登不进去 |

**它不改账号的国家字段**——用户仍然可以手动改，预填只是省事。

## 坑

- **库要定期更新**。GeoLite2 每周更新，放着不管半年后城市级定位就飘了。写个 cron 每月拉一次。
- **IP 走 CDN 时全是 CDN 的地址**。前面有 Cloudflare 的话，必须配 `real_ip_header CF-Connecting-IP`，
  否则所有访客都显示成 Cloudflare 的机房位置。
- **IPv6**：mmdb 支持，但日志表的 `ip_address` 字段是 `varchar(45)`，别改成 15。
- **隐私**：按 IP 定位在部分司法辖区算个人信息，隐私政策里要提一句。

## 检查清单

- [ ] `composer require geoip2/geoip2:^3.4` 跑过
- [ ] `data/GeoLite2-City.mmdb` 存在且不是 0 字节
- [ ] `/special/geoip` 显示「已就绪」
- [ ] CDN 透传真实 IP
- [ ] 有定期更新库的办法
