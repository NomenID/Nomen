/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

import { createRouter, createWebHistory } from 'vue-router'
import DashboardPage from './pages/DashboardPage.vue'
import SettingsPage from './pages/SettingsPage.vue'
import FamilyPage from './pages/FamilyPage.vue'
import NotificationsPage from './pages/NotificationsPage.vue'
import NotificationDetailPage from './pages/NotificationDetailPage.vue'
import { useT } from '../composables/useT.js'

const { t } = useT()

export const routes = [
  // dev harness 从 /dev/app.html 启动，把该路径重定向到仪表盘（生产环境不会命中）
  ...(import.meta.env.DEV ? [{ path: '/dev/app.html', redirect: '/dashboard' }] : []),
  { path: '/dashboard', name: 'dashboard', component: DashboardPage, meta: { title: t('Dashboard') } },
  { path: '/settings', name: 'settings', component: SettingsPage, meta: { title: t('Settings') } },
  { path: '/family', name: 'family', component: FamilyPage, meta: { title: t('Family') } },
  { path: '/notifications', name: 'notifications', component: NotificationsPage, meta: { title: t('Messages') } },
  { path: '/notifications/:id(\\d+)', name: 'notification-detail', component: NotificationDetailPage, meta: { title: t('Message detail') } },
]

export function createAppRouter() {
  return createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: () => ({ top: 0 }),
  })
}
