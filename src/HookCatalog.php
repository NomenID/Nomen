<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

// 本文件由 code-scripts/docgen.php 从 docs-internal/docmeta/hooks.json 生成，别手改。
return array (
  'admin.apps.detail' => 
  array (
    'type' => 'filter',
    'args' => 'app, clientId',
  ),
  'admin.apps.list' => 
  array (
    'type' => 'filter',
    'args' => 'apps',
  ),
  'admin.dashboard.cards' => 
  array (
    'type' => 'filter',
    'args' => 'cards, stats',
  ),
  'admin.dashboard.news' => 
  array (
    'type' => 'filter',
    'args' => 'items, context',
  ),
  'admin.dashboard.site' => 
  array (
    'type' => 'filter',
    'args' => 'site',
  ),
  'admin.logs.rows' => 
  array (
    'type' => 'filter',
    'args' => 'rows',
  ),
  'admin.menu.items' => 
  array (
    'type' => 'filter',
    'args' => 'items',
  ),
  'admin.mounts.list' => 
  array (
    'type' => 'filter',
    'args' => 'mounts',
  ),
  'admin.permissions.grid' => 
  array (
    'type' => 'filter',
    'args' => 'grid',
  ),
  'admin.plugin.page.body' => 
  array (
    'type' => 'filter',
    'args' => 'html, slug, page, tab',
  ),
  'admin.recovery.detail' => 
  array (
    'type' => 'filter',
    'args' => 'payload, request, user',
  ),
  'admin.settings.data' => 
  array (
    'type' => 'filter',
    'args' => 'payload',
  ),
  'admin.settings.saved' => 
  array (
    'type' => 'run',
    'args' => 'policy, backend, adminId, apiEnabled',
  ),
  'admin.shell.props' => 
  array (
    'type' => 'filter',
    'args' => 'props, adminId',
  ),
  'admin.site_health.tests' => 
  array (
    'type' => 'filter',
    'args' => 'tests, context',
  ),
  'admin.user.detail' => 
  array (
    'type' => 'filter',
    'args' => 'payload, userId, user, admin',
  ),
  'admin.user.groups_changed' => 
  array (
    'type' => 'run',
    'args' => 'userId, groups, oldGroups, actorId',
  ),
  'admin.user.role_changed' => 
  array (
    'type' => 'run',
    'args' => 'userId, role, adminId',
  ),
  'admin.user.status_changed' => 
  array (
    'type' => 'run',
    'args' => 'userId, status, adminId',
  ),
  'admin.users.rows' => 
  array (
    'type' => 'filter',
    'args' => 'rows',
  ),
  'api.request' => 
  array (
    'type' => 'filter',
    'args' => 'params',
  ),
  'api.response' => 
  array (
    'type' => 'filter',
    'args' => 'data',
  ),
  'api.siteinfo' => 
  array (
    'type' => 'filter',
    'args' => 'info, siprops, params',
  ),
  'auth.human_check' => 
  array (
    'type' => 'filter',
    'args' => 'check, context',
  ),
  'auth.login.failed' => 
  array (
    'type' => 'run',
    'args' => 'message, userId, context',
  ),
  'auth.login.pre' => 
  array (
    'type' => 'filter',
    'args' => 'screen, context',
  ),
  'auth.notice' => 
  array (
    'type' => 'filter',
    'args' => 'html',
  ),
  'auth.page' => 
  array (
    'type' => 'run',
    'args' => 'page, context',
  ),
  'auth.register.pre' => 
  array (
    'type' => 'filter',
    'args' => 'screen, context',
  ),
  'email.sending' => 
  array (
    'type' => 'filter',
    'args' => 'payload（to / subject / body）, embedded',
  ),
  'email.sent' => 
  array (
    'type' => 'run',
    'args' => 'to, subject, ok, error',
  ),
  'footer.html' => 
  array (
    'type' => 'filter',
    'args' => 'html, layout, brand, links',
  ),
  'footer.links' => 
  array (
    'type' => 'filter',
    'args' => 'links, brand, layout',
  ),
  'gate.check' => 
  array (
    'type' => 'filter',
    'args' => 'allowed, ability, userId, context',
  ),
  'homepage.links' => 
  array (
    'type' => 'filter',
    'args' => 'links',
  ),
  'homepage.sections' => 
  array (
    'type' => 'filter',
    'args' => 'sections, home',
  ),
  'http.headers' => 
  array (
    'type' => 'filter',
    'args' => 'headers, kind',
  ),
  'login.methods' => 
  array (
    'type' => 'filter',
    'args' => 'methods',
  ),
  'meta.robots' => 
  array (
    'type' => 'filter',
    'args' => 'directives, layout, path',
  ),
  'meta.tags' => 
  array (
    'type' => 'filter',
    'args' => 'tags, layout',
  ),
  'nav.items' => 
  array (
    'type' => 'filter',
    'args' => 'items, userId',
  ),
  'notification.created' => 
  array (
    'type' => 'run',
    'args' => 'userId, category, title, body, notificationId',
  ),
  'oauth.authorize.approved' => 
  array (
    'type' => 'run',
    'args' => 'userId, clientId, scope, silent',
  ),
  'oauth.authorize.quick' => 
  array (
    'type' => 'filter',
    'args' => 'null, userId, client, scopes',
  ),
  'oauth.authorize.quick.settings' => 
  array (
    'type' => 'filter',
    'args' => '\'\', userId, client',
  ),
  'oauth.token.issued' => 
  array (
    'type' => 'run',
    'args' => 'userId, clientId, scopes, grantType',
  ),
  'oauth.token.revoked' => 
  array (
    'type' => 'run',
    'args' => 'tokenTypeHint',
  ),
  'oidc.claims' => 
  array (
    'type' => 'filter',
    'args' => 'claims, user, scopes',
  ),
  'request.before' => 
  array (
    'type' => 'run',
    'args' => 'method, path',
  ),
  'response.html' => 
  array (
    'type' => 'filter',
    'args' => 'html, layout',
  ),
  'robots.txt' => 
  array (
    'type' => 'filter',
    'args' => 'body',
  ),
  'route.not_found' => 
  array (
    'type' => 'run',
    'args' => 'method, path',
  ),
  'schedule.tasks' => 
  array (
    'type' => 'filter',
    'args' => 'tasks',
  ),
  'sitemap.xml' => 
  array (
    'type' => 'filter',
    'args' => 'xml',
  ),
  'update.completed' => 
  array (
    'type' => 'run',
    'args' => 'from, to, info',
  ),
  'update.preflight' => 
  array (
    'type' => 'filter',
    'args' => 'reason, offer, current',
  ),
  'user.avatar.changed' => 
  array (
    'type' => 'run',
    'args' => 'userId, oldAvatar, newAvatar',
  ),
  'user.deleted' => 
  array (
    'type' => 'run',
    'args' => 'userId, username, email',
  ),
  'user.email.changed' => 
  array (
    'type' => 'run',
    'args' => 'userId, oldEmail, newEmail',
  ),
  'user.login.succeeded' => 
  array (
    'type' => 'run',
    'args' => 'userId, username',
  ),
  'user.logout' => 
  array (
    'type' => 'run',
    'args' => 'userId, username',
  ),
  'user.password.changed' => 
  array (
    'type' => 'run',
    'args' => 'userId, source',
  ),
  'user.profile.changed' => 
  array (
    'type' => 'run',
    'args' => 'userId, profile, before',
  ),
  'user.registered' => 
  array (
    'type' => 'run',
    'args' => 'userId, username, email（可能为 null）',
  ),
  'userinfo.claims' => 
  array (
    'type' => 'filter',
    'args' => 'claims, user, scopes',
  ),
  'viewer.props' => 
  array (
    'type' => 'filter',
    'args' => 'viewer, userId, user',
  ),
);
