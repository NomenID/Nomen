# 开放平台

给第三方应用一个自助入口：开发者注册、建 OAuth 应用、改回调地址和 scope。**不装也能在后台建应用**，
核心的 `OAuthClients` 不依赖插件。

## 开在哪

`config/plugins/open-platform.json`：

```json
{"base_path": "/open"}
```

后台「接入点」能改。默认地址 `/open`。

## 开发者能做什么

| 地址 | 干什么 |
|---|---|
| `/open` | 门户首页 |
| `/open/dashboard` | 我的应用 |
| `/open/apps/create` | 新建应用 |
| `/open/apps/{clientId}` | 详情、改配置、重置 secret、删除 |
| `/open/settings` | 开发者资料 |

管理员在后台「应用」页能看到全部应用，在「开发者」页能启停开发者账号。

## 停用之后

应用还在、还能授权，只是开发者看不到管理界面了。核心的授权和令牌流程完全不受影响。

## 详细说明

数据表、路由清单、坑：见 [官方插件 / Open Platform](plugins/open-platform.md)。
接入方怎么用这些应用拿身份：见 [把 Nomen 当身份提供方接入](integrate/oidc-client.md)。
