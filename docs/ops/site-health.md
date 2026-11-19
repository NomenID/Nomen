# 站点健康

后台「站点健康」（`/admin/site-health`）把 PHP、扩展、数据库、迁移、目录权限、定时任务、邮件、
站点地址、缓存、自动更新、Opcache、磁盘和插件依赖收成一份清单。PHP 版本那一项同时检查 PHP2Shell
（CVE-2026-109618 / CVE-2026-109619），受影响时给出升级或关站的提醒和参考链接。
每条结果分三档：

| 状态 | 含义 |
|---|---|
| `critical` | 已经影响使用，先处理这个 |
| `recommended` | 不影响开门，但建议改 |
| `good` | 正常 |

页面可以随时点「重新检查」；概览页也有一张摘要卡。每条检查默认收起，展开后能看到完整说明、
参考链接和「忽略这一项」。

## 忽略检查项

被忽略的检查不参与总体状态，也不出现在分组列表里，只留在页面底部「已忽略的检查」中，可以随时恢复。
忽略规则存在 `site_settings` 的 `health.ignored`（JSON 数组）。插件被停用后，它的检查项已经跑不到，
但仍会留在忽略列表里，方便恢复。

忽略只停止提示，不会改变任何东西。PHP 版本、数据库迁移这类检查，忽略之后问题本身还在。

## 插件加检查项

用 `admin.site_health.tests` 过滤器追加，格式：

```php
Hooks::add('admin.site_health.tests', function (array $tests): array {
    $tests[] = [
        'id'          => 'myplugin.queue',
        'label'       => t('Queue worker'),
        'status'      => 'recommended',
        'description' => t('The queue is not running.'),
        'value'       => '0 jobs',
        'links'       => [                      // 可选：最多 6 条，只收 http(s)
            ['label' => t('Queue worker docs'), 'url' => 'https://example.com/queue'],
        ],
    ];

    return $tests;
});
```

`id` 只能是小写字母开头的 `a-z0-9._-`，最长 64；`status` 不是三档之一时按 `good` 处理。
`label` / `description` 直接给最终文案，插件自己翻译。`links` 会在展开后的条目里显示。

## 概览页卡片

概览页的「站点健康」「Redis」「插件」「新闻」「活动」可以上下移动，顺序按管理员保存，
存在 `users.dashboard_order`；顶部数字统计固定在原地。插件可以往新闻卡里加条目：

```php
Hooks::add('admin.dashboard.news', function (array $items): array {
    $items[] = [
        'id'       => 'myplugin-1',
        'title'    => 'My plugin 2.0 is out',
        'date'     => '2026-11-19',
        'url'      => 'https://example.com/plugin',
        'priority' => 'normal',
        'summary'  => 'New queue worker and faster imports.',
    ];

    return $items;
});
```
