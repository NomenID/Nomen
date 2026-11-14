# API 发码登录

API 发码登录是随包插件（`plugins/api-code/`），**默认不启用**。它注册一种新登录方式：用户绑定一个
标识符（手机号、邮箱或任何你的 API 能送达的东西），登录时站点把验证码通过你配置的 HTTP 接口发出去，
输码即登录。短信网关、API 发信服务、自建 webhook 都能接。

## 打开

```php
// config/plugins.php
'enabled' => ['open-platform', 'geoip', 'pages', 'search', 'totp-qr', 'api-code'],
```

插件装着但没配发送端点时，登录页**不会出现**这种方式，只占一个 `/api-code` 页面。配置写在
`config/plugins/api-code.json`：

```json
{
    "url": "https://sms.example.com/send",
    "method": "POST",
    "headers": { "Content-Type": "application/json", "X-Token": "你的密钥" },
    "body": "{\"to\":\"{{identifier}}\",\"code\":\"{{code}}\"}",
    "timeout": 5,
    "label": "短信验证码",
    "base_path": "/api-code"
}
```

| 键 | 说明 |
|---|---|
| `url` | **必填**。非空才会在登录页出现这种方式；清空即关闭 |
| `method` | `POST`（默认）或 `GET`。GET 模式下自己把 `{{identifier}}` / `{{code}}` 放进 URL，值会做 URL 编码 |
| `headers` | 原样发送；密钥写在这里，别提交进仓库 |
| `body` | POST 正文模板，`{{identifier}}` 和 `{{code}}` 原样替换；Content-Type 由你在 headers 里声明 |
| `timeout` | 秒，1–15，默认 5 |
| `label` | 登录页显示的名字，默认走词典的「API 验证码」 |
| `base_path` | 插件页地址，默认 `/api-code`，后台「接入点」也能改 |

接口回 2xx 算发送成功，插件不解析网关的响应体。**带请求签名的云厂商 API**（阿里云、腾讯云短信这类
HMAC 签名）不适合这个通用模板——给它们套一层你自己的转发服务即可。

## 绑定

用户登录后访问 `/api-code`：添加标识符 → 收码确认 → 绑定成功。绑定本身也要过一次验证码，
所以只有真的能送达的标识符会被绑上。每个账号最多绑 5 个，第一个自动成为主标识符；
主标识符可换，删除后最早剩下的自动顶上。

一个标识符全局唯一，只指向一个账号，登录时不存在「选账号」的歧义。

## 登录

登录页出现「API 验证码」（或你配置的名字）选项，进入后输标识符 → 收码 → 输码 → 登录。
流程和邮箱验证码登录一致：该走两步验证就走两步验证，未激活账号照样进激活页。

## 安全行为

- 验证码只存哈希，10 分钟有效，输错 5 次作废；重发即替换旧码。
- 发送限流：同一标识符 60 秒一张，同一 IP 每小时 10 张（依赖 Redis，Redis 不可用时放行，与核心口径一致）。
- 登录页不区分「标识符存在与否」，没绑定的标识符也走同一句「已发送」提示，避免账号枚举。
- 发送失败只写日志，界面不暴露网关状态。

## License

Apache-2.0，见插件仓库的 [LICENSE](https://github.com/NomenID/plugin-api-code/LICENSE)。
