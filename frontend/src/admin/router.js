/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

import { createRouter, createWebHistory } from 'vue-router'
import DashboardPage from './pages/DashboardPage.vue'
import HomePage from './pages/HomePage.vue'
import UsersPage from './pages/UsersPage.vue'
import UserDetailPage from './pages/UserDetailPage.vue'
import AppsPage from './pages/AppsPage.vue'
import AppCreatePage from './pages/AppCreatePage.vue'
import AppDetailPage from './pages/AppDetailPage.vue'
import IdentitiesPage from './pages/IdentitiesPage.vue'
import MountsPage from './pages/MountsPage.vue'
import RecoveryPage from './pages/RecoveryPage.vue'
import RecoveryDetailPage from './pages/RecoveryDetailPage.vue'
import LogsPage from './pages/LogsPage.vue'
import LogDetailPage from './pages/LogDetailPage.vue'
import SettingsPage from './pages/SettingsPage.vue'
import PluginPage from './pages/PluginPage.vue'

export const routes = [
  { path: '/admin', name: 'admin-dashboard', component: DashboardPage, meta: { title: '概览' } },
  { path: '/admin/homepage', name: 'admin-homepage', component: HomePage, meta: { title: '首页' } },
  { path: '/admin/users', name: 'admin-users', component: UsersPage, meta: { title: '用户' } },
  { path: '/admin/users/:id(\\d+)', name: 'admin-user', component: UserDetailPage, meta: { title: '用户详情' } },
  { path: '/admin/apps', name: 'admin-apps', component: AppsPage, meta: { title: '应用' } },
  { path: '/admin/apps/create', name: 'admin-app-create', component: AppCreatePage, meta: { title: '新建应用' } },
  { path: '/admin/apps/:clientId', name: 'admin-app', component: AppDetailPage, meta: { title: '应用详情' } },
  { path: '/admin/identities', name: 'admin-identities', component: IdentitiesPage, meta: { title: '登录方式' } },
  { path: '/admin/mounts', name: 'admin-mounts', component: MountsPage, meta: { title: '接入点' } },
  { path: '/admin/recovery', name: 'admin-recovery', component: RecoveryPage, meta: { title: '恢复工单' } },
  { path: '/admin/recovery/:id(\\d+)', name: 'admin-recovery-detail', component: RecoveryDetailPage, meta: { title: '工单详情' } },
  { path: '/admin/logs', name: 'admin-logs', component: LogsPage, meta: { title: '活动日志' } },
  { path: '/admin/logs/:id(\\d+)', name: 'admin-log-detail', component: LogDetailPage, meta: { title: '日志详情' } },
  { path: '/admin/settings', name: 'admin-settings', component: SettingsPage, meta: { title: '设置' } },
  { path: '/admin/:slug', name: 'admin-plugin', component: PluginPage, meta: { title: '插件页面' } },
  { path: '/admin/:pathMatch(.*)*', redirect: '/admin' },
]

export function createAdminRouter(brandName = 'Nomen') {
  const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: () => ({ top: 0 }),
  })

  router.afterEach((to) => {
    const title = to.meta?.title
    document.title = title ? `${title} - ${brandName} 管理后台` : `${brandName} 管理后台`
  })

  return router
}
