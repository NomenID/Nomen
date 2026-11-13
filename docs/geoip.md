# GeoIP

按 IP 查国家与地点，用在两处：注册页预填国家（要开 `country` 功能）、活动日志显示登录地点。

**依赖和数据库都不进发布包**，要自己装。没装时功能静默降级，页面照常。

## 三步

```bash
# 1. 装库（别装 2.x，names 为 null 时会 fatal）
composer require geoip2/geoip2:^3.4

# 2. 下 mmdb（要 MaxMind 账号，GeoLite2 免费）
curl -L "https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-City&license_key=<key>&suffix=tar.gz" \
  | tar xz -C /tmp
cp /tmp/GeoIP2-City_*/GeoLite2-City.mmdb data/

# 3. 看状态
# 管理员打开 /special/geoip
```

插件默认在 `enabled` 里。mmdb 放 `data/`，**不要放 `public/`**。

## 降级行为

| 情况 | 表现 |
|---|---|
| 插件没启用 | 不预填、日志显示「未知位置」 |
| `geoip2` 没装 | 同上，`/special/geoip` 显示缺库 |
| mmdb 缺失 / 损坏 | 同上，日志里有 `[GeoIP]` |
| 内网 / 本地 IP | 「本地」或「未知位置」 |

**不影响注册和登录**，只是少一个便利功能。

## 详细说明

依赖版本、mmdb 更新、CDN 下的真实 IP、隐私提示：见 [官方插件 / GeoIP](plugins/geoip.md)。
