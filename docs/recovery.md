# 账号恢复

密码、邮箱、二验全丢了之后的最后一条路。**是人工审核流程，不是自动重置**——这是有意设计，
自动重置等于给社工留门。

## 流程

```
用户填申诉  →  工单 pending  →  管理员审核
                                 ├─ 驳回 → rejected（用户看到统一回复，不透露原因）
                                 └─ 通过 → approved，发一次性恢复链接（7 天）
                                            ↓
                                     用户点链接 → 强制安全修复
                                            ↓
                                     completed（新密码 + 新二验）
```

## 用户侧

入口：`/recovery`。

1. 填账号信息（用户名、可能的邮箱、注册时填过的生日/性别等）。
2. 留联系方式。
3. 上传证明材料（可选，最多 5 张，单张 1MB）。
4. 提交。

提交后到 `/recovery/sent`，**不管工单是否真的存在都显示同样的提示**——防止用来探测账号。

相关接口：

| 路径 | 作用 |
|---|---|
| `POST /recovery` | 提交申诉 |
| `POST /recovery/upload` | 上传证明材料（暂存） |
| `GET /recovery/ticket` | 查自己的工单状态 |
| `GET /recovery/secure` | 凭一次性链接进入的安全修复页 |
| `POST /recovery/secure/finish` | 提交新密码 / 新二验，结束恢复模式 |

## 管理员侧

后台「恢复」页列出工单，能看到：

- 用户填的资料，以及**和库里的比对结果**（生日/性别是否匹配，`birth_date_match` / `gender_match`）。
- 上传的证明材料。
- 这个账号还有哪些可用因素（有没有密码、有没有已验证邮箱、有没有 TOTP）——判断可信度的依据。

审核动作通过 `POST /admin/recovery/{unid}/{approve,reject}` 之类，工单按 UNID 定位，不露自增 id。

通过后系统发一封带一次性链接的邮件，链接里是令牌的哈希，**用一次即作废**。

## 恢复模式

用户点开链接后，账号进入「恢复模式」：

- 除修复所需的页面外，所有 **GET 页面**都被 302 到 `/recovery/secure`。
- POST / AJAX 不拦（修复动作本身就是 POST）。
- 放行名单：`/recovery*`、`/2fa/*`、`/biometric/*`、`/settings/biometrics`、`/settings/emails/verify`、
  `/activate*`、`/logout`、`/captcha`。

在插件里判断这个状态：

```php
if (\Nomen\Src\RecoveryHelper::isActiveFor($userId)) {
    // 这个账号正在强制修复，插件自己的接口要不要拒？
}
```

修复完成后 `completed`，恢复模式解除。

## 数据

`account_recovery_requests`：

| 字段 | 说明 |
|---|---|
| `unid` | 对外编号，后台 URL 用它 |
| `status` | `pending` / `approved` / `rejected` / `completed` |
| `birth_date_match` / `gender_match` | 用户填的和库里的比对结果 |
| `recovery_token_hash` | 一次性链接的令牌哈希 |
| `recovery_token_expires_at` | 7 天 |
| `recovery_token_used_at` | 用掉的时间 |
| `admin_note` / `reviewed_by` / `reviewed_at` | 审核记录 |

证明材料文件落 `public/assets/recovery/`，**只能管理员访问**，别放公开目录。

## 限流

| 项 | 默认 |
|---|---|
| 单 IP 申诉提交 | 5 次 / 小时 |
| 单 IP 上传 | 40 次 / 小时 |
| 暂存图清理 | 2 小时没提交就删 |
| 一次性链接 | 7 天 |

都在 `config/app.php` 的 `recovery_*` 里。

## 坑

- **证明材料目录要能写但不可执行**：`public/assets/recovery/` 里不接受 `.php`。
- **邮件里别带工单详情**：邮件会转发。只发链接。
- **一次性链接被邮件客户端预取**：有些企业邮箱会预取链接导致令牌被用掉。缓解办法是链接先到一个确认页，
  点按钮才真正消费令牌。
- **管理员审核要两人**：单人审核的社工风险高，站点小也就那样，但要知道这个取舍。
- **恢复完成后旧会话没清**：用户可能还有别的浏览器登着，恢复流程结束时要清掉其它会话。

## 检查清单

- [ ] 申诉页能提交，证明材料能上传
- [ ] 工单在后台能看到，比对结果显示正确
- [ ] 通过后邮件能收到，链接能用一次
- [ ] 恢复模式下普通页面确实被拦住
- [ ] 修复完成后恢复模式解除
- [ ] 证明材料目录不公开
