# Quick Central Login：第一方免确认

第一方应用（`developer_id = 1`）的授权请求直接发码，不弹确认页；回调地址上带一个 `spicallogin` 标记，
接入方据此提示「快速登录成功」。

**不随发布包分发**，需要的话自己 clone。这个插件本质是「CaelLabID 那套中心登录」的实现，
别的站装了没有意义。

## 装上

```bash
git clone https://github.com/NomenID/plugin-quick-central-login plugins/quick-central-login
```

```php
'enabled' => ['open-platform', 'geoip', 'pages', 'totp-qr', 'quick-central-login'],
```

要求核心 **≥ 1.3.2**（这个版本起核心才有 `oauth.authorize.quick` 钩子）。

## 它做什么

1. 挂 `oauth.authorize.quick`：判定这个请求是不是第一方应用 + 用户没关掉快速登录。
2. 是的话返回一个标记字符串，核心跳过确认页直接发 code，回调带上 `spicallogin=<标记>`。
3. 在 `/special/quick-login` 给用户一个开关页。

用户偏好存在插件自己的表 `quick_login_prefs` 里，**没有记录 = 开启**（默认开）。

## 用户看到什么

- 开着：从第一方应用点登录，直接回来，页面不闪。
- 关了：正常弹确认页。
- 用户能在 `/special/quick-login` 改。

## 接入方要做的

看到回调里带 `spicallogin` 就提示一句「快速登录成功」——因为这条路上**可能连授权页都没渲染过**，
用户无感回来，不提示会以为没生效。

```php
$quick = isset($_GET['spicallogin']);
```

**只判参数在不在，绝不比对值**：值是个产品标记（`caellab-launch-plan-sister-project-quick-login`），
会改，而且别的实例不一样。

## 坑

- **`developer_id = 1` 是硬编码的第一方判断**。站点自己的应用如果不是 id 1，要先在后台把 `developer_id`
  改对，否则永远走确认流程。
- **核心移除了内置快速登录**（1.3.2 起）。老版本核心内置这个能力，插件和它不要同时开。
- **跳确认页是安全默认**。别为了让流程「顺」把这个插件装在所有站上——它的前提是「这个应用就是本站自己的」。
- **用户关掉之后** `quick_login_prefs` 里会有一行 `0`，删掉那行等于重新打开。

## 检查清单

- [ ] 核心 ≥ 1.3.2
- [ ] 第一方应用的 `developer_id` 是 1
- [ ] `/special/quick-login` 能开，开关能保存
- [ ] 从第一方应用登录：不弹确认页，回调带 `spicallogin`
- [ ] 关掉之后会弹确认页
