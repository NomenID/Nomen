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
  { path: '/dashboard', name: 'dashboard', component: DashboardPage, meta: { title: t('仪表盘') } },
  { path: '/settings', name: 'settings', component: SettingsPage, meta: { title: t('设置') } },
  { path: '/family', name: 'family', component: FamilyPage, meta: { title: t('家庭') } },
  { path: '/notifications', name: 'notifications', component: NotificationsPage, meta: { title: t('消息中心') } },
  { path: '/notifications/:id(\\d+)', name: 'notification-detail', component: NotificationDetailPage, meta: { title: t('消息详情') } },
]

export function createAppRouter() {
  return createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior: () => ({ top: 0 }),
  })
}
