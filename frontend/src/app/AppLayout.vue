<script setup>
import { computed, inject, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { apiPost } from './api'
import { useT } from '../composables/useT.js'

const { t } = useT()
const router = useRouter()
const viewer = inject('viewer')
const brand = inject('brand') || {}
const locales = inject('locales') || []
const currentLocale = window.NOMEN_LOCALE || 'zh-hans'

const drawerOpen = ref(false)
const langOpen = ref(false)
const langQuery = ref('')
const langSearchEl = ref(null)
const currentTheme = ref(document.documentElement.getAttribute('data-theme') || 'dark')

const langBasePath = window.location.pathname
const langHref = (slug) => {
  const query = new URLSearchParams(window.location.search)
  query.set('lang', slug)
  return `${langBasePath}?${query.toString()}`
}

const filteredLocales = computed(() => {
  const q = langQuery.value.trim().toLowerCase()
  if (!q) return locales
  return locales.filter((entry) =>
    `${entry.name} ${entry.english} ${entry.slug}`.toLowerCase().includes(q))
})

const userInitial = computed(() => {
  const name = viewer.displayName || viewer.username || ''
  return Array.from(name)[0]?.toUpperCase() || '?'
})

const navLinks = computed(() => viewer.nav || [])

function toggleDrawer() {
  drawerOpen.value = !drawerOpen.value
}

function closeDrawer() {
  drawerOpen.value = false
}

function setTheme(theme) {
  if (theme !== 'light' && theme !== 'dark') return
  currentTheme.value = theme
  document.documentElement.setAttribute('data-theme', theme)
  localStorage.setItem('theme', theme)
}

function toggleTheme() {
  setTheme(currentTheme.value === 'dark' ? 'light' : 'dark')
}

async function logout() {
  const confirmed = await window.Nomen.confirm(t('退出登录'), t('确定要退出当前账号吗？'), {
    confirmText: t('退出'),
    cancelText: t('取消'),
  })
  if (confirmed) window.location.href = '/logout'
}

function onDrawerNav() {
  closeDrawer()
}

// 新消息飘出提醒：铃铛上的最新未读比本地记的更新时弹一次 toast。
// 点正文 = 标已读 + 跳到消息目标；点叉叉 / 自动消失 = 只记住不重复飘，消息保持未读
const NOTIF_SEEN_KEY = 'nomen_notif_last_seen'

function checkNotifPopup() {
  const latest = viewer.latestUnread
  if (!latest) return
  const lastSeen = parseInt(localStorage.getItem(NOTIF_SEEN_KEY) || '0', 10)
  if (latest.id <= lastSeen) return

  const unread = viewer.unreadCount
  const text = unread > 1
    ? t('你有 {:n} 条新消息\n最新：{:title}', { n: unread, title: latest.title })
    : latest.title
  const toast = window.Nomen.toast(text, 'info', 10000)
  if (!toast) return

  toast.classList.add('nomen-toast--popup')
  toast.setAttribute('role', 'button')

  const remember = () => {
    try { localStorage.setItem(NOTIF_SEEN_KEY, String(latest.id)) } catch (err) { /* 存储不可用就算了 */ }
  }

  const markReadAndGo = () => {
    remember()
    apiPost('/notifications/read', { id: latest.id }).finally(() => {
      router.push(latest.link)
    })
  }

  toast.addEventListener('click', (event) => {
    if (event.target.closest('.nomen-toast__close')) return
    markReadAndGo()
  })
}

function onKeydown(event) {
  if (event.key === 'Escape' && drawerOpen.value) closeDrawer()
}

onMounted(() => {
  checkNotifPopup()
  document.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown)
})

function closeLang() {
  langOpen.value = false
  langQuery.value = ''
}

function onDocClick(event) {
  if (!event.target.closest('.lang-picker')) closeLang()
  if (!event.target.closest('.mobile-menu-drawer') && !event.target.closest('.mobile-menu-btn')) closeDrawer()
}

function openLang() {
  langOpen.value = true
  setTimeout(() => langSearchEl.value?.focus(), 30)
}

onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))
</script>

<template>
  <header class="header">
    <div class="header-inner">
      <RouterLink to="/dashboard" class="logo">
        <img v-if="brand.logo" :src="brand.logo" alt="" class="logo-icon" width="32" height="32">
        <span class="logo-text">{{ brand.name || 'Nomen' }}</span>
      </RouterLink>
      <button
        class="mobile-menu-btn"
        :class="{ active: drawerOpen }"
        :aria-label="t('菜单')"
        :aria-expanded="drawerOpen"
        aria-controls="mobile-menu-drawer"
        @click.stop="toggleDrawer"
      >
        <span></span><span></span><span></span>
      </button>
      <nav class="nav" id="mainNav">
        <template v-for="link in navLinks" :key="link.key">
          <RouterLink
            v-if="link.key === 'notifications'"
            :to="link.href"
            class="nav-notif"
            :title="link.label"
            :aria-label="link.label"
          >
            <span class="icon icon-bell"></span>
            <span v-if="viewer.unreadCount > 0" class="nav-notif__count">{{ viewer.unreadCount > 99 ? '99+' : viewer.unreadCount }}</span>
          </RouterLink>
          <RouterLink v-else-if="link.spa" :to="link.href" class="nav-link">{{ link.label }}</RouterLink>
          <a v-else :href="link.href" class="nav-link">{{ link.label }}</a>
        </template>
        <span class="nav-user">
          <img v-if="viewer.avatarUrl" :src="viewer.avatarUrl" alt="" class="nav-avatar">
          {{ viewer.username }}
        </span>
        <button class="nav-link nav-link--danger" @click="logout">{{ t('退出') }}</button>
        <div class="lang-picker" :class="{ 'is-open': langOpen }" v-if="locales.length">
          <button
            class="lang-trigger"
            type="button"
            :aria-expanded="langOpen"
            aria-haspopup="true"
            @click.stop="langOpen ? closeLang() : openLang()"
          >
            <span class="icon icon-globe" aria-hidden="true"></span>
            <span class="lang-trigger-name">{{ (locales.find((l) => l.slug === currentLocale) || {}).name || currentLocale }}</span>
            <svg class="lang-chevron" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
          </button>
          <div class="lang-panel" :hidden="!langOpen">
            <div class="lang-search">
              <input ref="langSearchEl" v-model="langQuery" type="search" :placeholder="t('搜索语言')" autocomplete="off" :aria-label="t('搜索语言')">
            </div>
            <ul class="lang-list" :aria-label="t('选择语言')">
              <li v-for="entry in filteredLocales" :key="entry.slug" :aria-current="entry.slug === currentLocale || undefined">
                <a
                  :href="langHref(entry.slug)"
                  class="lang-option"
                  :class="{ 'is-active': entry.slug === currentLocale }"
                  :hreflang="entry.slug"
                  :lang="entry.slug"
                >
                  <span class="lang-option-name" :lang="entry.slug">{{ entry.name }}</span>
                  <span class="lang-option-meta">{{ entry.english }} · {{ entry.slug }}</span>
                  <span v-if="entry.slug === currentLocale" class="icon icon-check lang-option-check" aria-hidden="true"></span>
                </a>
              </li>
            </ul>
            <p v-if="!filteredLocales.length" class="lang-empty">{{ t('没有找到语言') }}</p>
          </div>
        </div>
        <button class="theme-toggle" :title="t('切换主题')" :aria-label="t('切换亮色/暗色主题')" @click="toggleTheme">
          <span class="icon icon-sun"></span>
          <span class="icon icon-moon"></span>
        </button>
      </nav>
    </div>
  </header>

  <main class="main main--app">
    <slot />
  </main>

  <footer class="footer">
    <div class="footer-inner">
      <span v-if="brand.copyright">{{ brand.copyright }}</span>
      <span v-else>&copy; {{ new Date().getFullYear() }} {{ brand.name || 'Nomen' }}</span>
      <a v-if="brand.poweredBy !== false" class="footer-powered" href="/special/version">Powered by Nomen</a>
    </div>
  </footer>

  <!-- 移动端抽屉：必须挂在 header 之外（fixed 定位不能被 header 的 backdrop-filter 困住） -->
  <Teleport to="body">
    <div class="mobile-menu-overlay" :class="{ show: drawerOpen }" @click="closeDrawer"></div>
    <div class="mobile-menu-drawer" :class="{ show: drawerOpen }">
      <div class="mobile-menu-header">
        <div class="mobile-menu-user">
          <img v-if="viewer.avatarUrl" :src="viewer.avatarUrl" alt="" class="mobile-menu-avatar">
          <span v-else class="mobile-menu-avatar">{{ userInitial }}</span>
          <span class="mobile-menu-name">{{ viewer.username }}</span>
        </div>
      </div>
      <nav class="mobile-menu-nav">
        <template v-for="link in navLinks" :key="link.key">
          <RouterLink v-if="link.spa" :to="link.href" class="mobile-menu-link" @click="onDrawerNav">
            <span :class="['icon', 'icon-' + (link.icon || 'file')]" aria-hidden="true"></span>
            <span>{{ link.label }}</span>
            <span v-if="link.key === 'notifications' && viewer.unreadCount > 0" class="mobile-menu-badge">{{ viewer.unreadCount > 99 ? '99+' : viewer.unreadCount }}</span>
          </RouterLink>
          <a v-else :href="link.href" class="mobile-menu-link" @click="onDrawerNav">
            <span :class="['icon', 'icon-' + (link.icon || 'file')]" aria-hidden="true"></span>
            <span>{{ link.label }}</span>
          </a>
        </template>
        <button type="button" class="mobile-menu-link mobile-menu-danger" @click="logout">
          <span class="icon icon-logout" aria-hidden="true"></span>
          <span>{{ t('退出登录') }}</span>
        </button>

        <div class="mobile-menu-divider"></div>
        <div class="mobile-theme-section">
          <span class="mobile-theme-label">{{ t('主题') }}</span>
          <div class="mobile-theme-options">
            <button
              type="button"
              class="mobile-theme-btn"
              :class="{ active: currentTheme === 'light' }"
              :title="t('亮色')"
              :aria-label="t('亮色主题')"
              @click="setTheme('light')"
            ><span class="icon icon-sun"></span></button>
            <button
              type="button"
              class="mobile-theme-btn"
              :class="{ active: currentTheme === 'dark' }"
              :title="t('暗色')"
              :aria-label="t('暗色主题')"
              @click="setTheme('dark')"
            ><span class="icon icon-moon"></span></button>
          </div>
        </div>

        <div class="mobile-menu-divider"></div>
        <div class="mobile-lang-section">
          <span class="mobile-theme-label">{{ t('语言') }}</span>
          <div class="mobile-lang-options">
            <a
              v-for="entry in locales"
              :key="entry.slug"
              :href="langHref(entry.slug)"
              class="mobile-lang-btn"
              :class="{ 'is-active': entry.slug === currentLocale }"
              :lang="entry.slug"
            >{{ entry.name }}</a>
          </div>
        </div>
      </nav>
    </div>
  </Teleport>
</template>
