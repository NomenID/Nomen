# 家庭与监护

给未成年账号设计的：一个家庭组、若干监护人、被监护的账号。**默认关闭**，要用先在
`config/installed.php` 的 `app.features` 打开 `guardians`（或 `FEATURE_GUARDIANS=1`）。

## 打开之后多了什么

| 能力 | 说明 |
|---|---|
| 同意年龄判定 | 注册时按国家判断是否未成年（`config/consent-age.php`） |
| 家长同意 | 未成年账号要么有已验证邮箱，要么家长确认（`consent_at`） |
| 家长邮箱 | 家长的地址可以替孩子收确认信 |
| 家庭页 | `/family`，管成员、邀请、解除关系 |

年龄表按国家，没列到的国家用 `default`（16）。值是产品策略，法规依据写在
`config/consent-age-sources.php`。

## 数据

```
family_groups    家庭组（有 unid）
family_members   成员：谁是谁的监护人、邀请状态、关系是否已解除
```

一个未成年账号属于一个家庭组；一个监护人可以在多个组里。**关系解除不是删行**，是标记时间，
留审计痕迹。

## 页面与接口

| 路径 | 作用 |
|---|---|
| `/family` | 家庭页：成员列表、待处理的邀请 |
| `POST /family/invite-parent` | 邀请家长 |
| `POST /family/accept` | 接受邀请 |
| `POST /family/guardian/add` | 加监护人 |
| `POST /family/child/create` | 建孩子账号 |
| `POST /family/claim`、`/claim/send` | 认领（把孩子账号绑到当前家庭） |
| `POST /family/leave` | 退出家庭组 |
| `POST /family/release` | 解除监护关系 |
| `POST /family/child/reset`、`/reset/send` | 重置孩子的密码 |
| `GET /family/child/status` | 孩子账号状态 |

## 和对验证闸的关系

登录后的「邮箱验证闸」会放行两种情况：

1. `users.email_verified = 1`（自己的邮箱验证过）；
2. `FamilyHelper::guardianConfirmed($userId)` 为真（家长确认过）。

所以孩子账号可以一个邮箱都没有，靠家长那条路进入正常使用。**这也意味着家长确认的账号能跳过邮箱验证**
——这是有意的，否则未成年的孩子没法用。

## 插件里判断监护关系

别自己写 SQL：

```php
use Nomen\Src\FamilyHelper;

if (FamilyHelper::isGuardianOf($guardianId, $childId)) {
    // 允许监护人看孩子的某些数据
}
```

`FamilyHelper` 里还有 `childMembership()`、`guardianConfirmed()`、`createUnclaimedGroup()`。

## 坑

- **功能开关只决定开不开，不改判定结果**：打开之后同意年龄仍按 `consent-age.php` 算，
  不会因为开了功能就放宽。
- **孩子账号的「没有邮箱」是正常状态**：别在插件里假设每个账号都有邮箱。
- **家长邮箱和家长账号不是一回事**：家长可能没有账号，只是一个收信地址。
- **解除监护会立刻生效**：解除之后孩子账号又回到「必须自己验证邮箱」的状态，可能被弹到激活页。
- **同意年龄表要维护**：法规变了要跟着改，改了要重启（PHP 重新读配置文件）。

## 检查清单

- [ ] `features.guardians` 打开了
- [ ] `consent-age.php` 里有目标国家的年龄
- [ ] 家长能收到邀请邮件（SMTP 可用）
- [ ] 孩子账号能被家长确认，之后能正常用
- [ ] 解除监护后行为符合预期
