# 站点健康

后台「站点健康」（`/admin/site-health`）把 PHP、扩展、数据库、迁移、目录权限、定时任务、邮件、
站点地址、pepper、缓存、自动更新、Opcache、磁盘和插件依赖收成一份清单。每条结果分三档：

| 状态 | 含义 |
|---|---|
| `critical` | 已经影响使用，先处理这个 |
| `recommended` | 不影响开门，但建议改 |
| `good` | 正常 |

页面可以随时点「重新检查」；概览页也有一张摘要卡。

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
    ];

    return $tests;
});
```

`id` 只能是小写字母开头的 `a-z0-9._-`，最长 64；`status` 不是三档之一时按 `good` 处理。
`label` / `description` 直接给最终文案，插件自己翻译。

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
