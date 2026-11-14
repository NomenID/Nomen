/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

import { createRouter, createWebHistory, createMemoryHistory } from 'vue-router'
import { currentPagePath, installQueryNavigation } from '../lib/url.js'
import { useT } from '../composables/useT.js'
import DashboardPage from './pages/DashboardPage.vue'
import HomePage from './pages/HomePage.vue'
import UsersPage from './pages/UsersPage.vue'
import UserDetailPage from './pages/UserDetailPage.vue'
import AppsPage from './pages/AppsPage.vue'
import AppCreatePage from './pages/AppCreatePage.vue'
import AppDetailPage from './pages/AppDetailPage.vue'
import IdentitiesPage from './pages/IdentitiesPage.vue'
import IdentityFormPage from './pages/IdentityFormPage.vue'
import MountsPage from './pages/MountsPage.vue'
import RecoveryPage from './pages/RecoveryPage.vue'
import RecoveryDetailPage from './pages/RecoveryDetailPage.vue'
import LogsPage from './pages/LogsPage.vue'
import LogDetailPage from './pages/LogDetailPage.vue'
import SettingsPage from './pages/SettingsPage.vue'
import PluginPage from './pages/PluginPage.vue'

// meta.title 存的是词典键，标题在 afterEach 里按当前语言翻
export const routes = [
  { path: '/admin', name: 'admin-dashboard', component: DashboardPage, meta: { title: 'Overview' } },
  { path: '/admin/homepage', name: 'admin-homepage', component: HomePage, meta: { title: 'Home page' } },
  { path: '/admin/users', name: 'admin-users', component: UsersPage, meta: { title: 'Users' } },
  { path: '/admin/users/:id([0-9A-HJKMNP-TV-Z]{26})', name: 'admin-user', component: UserDetailPage, meta: { title: 'User details' } },
  { path: '/admin/apps', name: 'admin-apps', component: AppsPage, meta: { title: 'Apps' } },
  { path: '/admin/apps/create', name: 'admin-app-create', component: AppCreatePage, meta: { title: 'New app' } },
  { path: '/admin/apps/:clientId', name: 'admin-app', component: AppDetailPage, meta: { title: 'App details' } },
  { path: '/admin/identities', name: 'admin-identities', component: IdentitiesPage, meta: { title: 'Sign-in methods' } },
  { path: '/admin/identities/new', name: 'admin-identity-new', component: IdentityFormPage, meta: { title: 'Add sign-in method' } },
  { path: '/admin/identities/:id(\\d+)/edit', name: 'admin-identity-edit', component: IdentityFormPage, meta: { title: 'Edit sign-in method' } },
  { path: '/admin/mounts', name: 'admin-mounts', component: MountsPage, meta: { title: 'Endpoints' } },
  { path: '/admin/recovery', name: 'admin-recovery', component: RecoveryPage, meta: { title: 'Recovery requests' } },
  { path: '/admin/recovery/:id([0-9A-HJKMNP-TV-Z]{26})', name: 'admin-recovery-detail', component: RecoveryDetailPage, meta: { title: 'Request details' } },
  { path: '/admin/logs', name: 'admin-logs', component: LogsPage, meta: { title: 'Activity log' } },
  { path: '/admin/logs/:id([0-9A-HJKMNP-TV-Z]{26})', name: 'admin-log-detail', component: LogDetailPage, meta: { title: 'Log details' } },
  { path: '/admin/settings', name: 'admin-settings', component: SettingsPage, meta: { title: 'Settings' } },
  { path: '/admin/:slug', name: 'admin-plugin', component: PluginPage, meta: { title: 'Plugin page' } },
  { path: '/admin/:pathMatch(.*)*', redirect: '/admin' },
]

export function createAdminRouter(brandName = 'Nomen') {
  const { t } = useT()
  const queryPath = currentPagePath()
  const router = createRouter({
    history: queryPath ? createMemoryHistory() : createWebHistory(),
    routes,
    scrollBehavior: () => ({ top: 0 }),
  })

  if (queryPath) {
    router.push(queryPath)
    installQueryNavigation(router)
  }

  router.afterEach((to) => {
    const panel = `${brandName} ${t('Admin')}`
    document.title = to.meta?.title ? `${t(to.meta.title)} - ${panel}` : panel
  })

  return router
}
