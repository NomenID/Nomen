# config/email-policy.php

> 本页由 `code-scripts/docgen.php` 生成，改代码后重新生成即可；手改会被覆盖。
>
> 来源：`config/email-policy.php`

邮箱域名黑名单。注册和换邮箱时按这里拦截，拦下来统一回同一句文案，不按域名区分理由——分开说等于告诉对方只差一个域名就过了。

共 11 项。

## reserved_domains

保留域（RFC 2606）：DNS 上永远收不到信，只可能是编的或者拿来占位的

- `example.com`
- `example.net`
- `example.org`
- `example.edu`

## 保留后缀

整段顶级域 / 特殊用途后缀（RFC 2606 / 6761 / 7686）

- `test`
- `example`
- `invalid`
- `localhost`
- `local`
- `onion`

## 其他

| 键 | 环境变量 | 默认值 | 说明 |
|---|---|---|---|
| `blocked_domains` | — | 空数组 | 以后要额外拦的域名往这里加，文案还是那一句 |

