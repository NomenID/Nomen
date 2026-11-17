<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed, nextTick, provide, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import BaseAlert from '../../components/BaseAlert.vue'
import { toast } from '../../lib/toast.js'
import { useT } from '../../composables/useT.js'
import { usePageData } from '../usePageData.js'

const { t } = useT()
const route = useRoute()
const router = useRouter()

const { data } = usePageData('settings')

// 子页共用同一份数据：切标签不重新请求
provide('settingsData', data)

const navItems = computed(() => [
  { to: '/settings/profile', label: t('Profile'), icon: 'user' },
  { to: '/settings/security', label: t('Security'), icon: 'shield' },
  { to: '/settings/access', label: t('Sign-in and devices'), icon: 'monitor' },
  { to: '/settings/prefs', label: t('Preferences'), icon: 'sliders' },
])

const isActive = (path) => route.path === path || (path === '/settings/profile' && route.path === '/settings')

// 老地址 /settings#sec-xxx 与服务端跳转都靠这张表落到对应子页
const SECTION_PAGES = {
  'sec-avatar': '/settings/profile',
  'sec-profile': '/settings/profile',
  'sec-account': '/settings/profile',
  'sec-family': '/settings/profile',
  'sec-emails': '/settings/security',
  'sec-password': '/settings/security',
  'sec-reset-code': '/settings/security',
  'sec-2fa': '/settings/security',
  'sec-totp': '/settings/security',
  'sec-recovery': '/settings/security',
  'sec-biometric': '/settings/security',
  'sec-identities': '/settings/access',
  'sec-active-devices': '/settings/access',
  'sec-temp-keys': '/settings/access',
  'sec-devices': '/settings/access',
  'sec-locale': '/settings/prefs',
  'sec-skin': '/settings/prefs',
  'sec-notifications': '/settings/prefs',
}

function hashSection() {
  const raw = window.location.hash.replace(/^#/, '')
  return raw ? decodeURIComponent(raw) : ''
}

function flashSection() {
  const id = hashSection()
  if (!id) return
  const target = document.getElementById(id)
  if (!target || !target.classList.contains('settings-section')) return
  target.classList.add('settings-section--flash')
  target.scrollIntoView({ behavior: 'smooth', block: 'start' })
  setTimeout(() => target.scrollIntoView({ behavior: 'smooth', block: 'start' }), 300)
}

async function resolveHash() {
  const id = hashSection()
  const page = SECTION_PAGES[id]
  if (id && page && route.path !== page) {
    await router.replace(page + '#' + id)
  }
  await nextTick()
  flashSection()
}

// 数据就绪后再定位锚点：DOM 里要先有子页，否则 getElementById 是空的
let flashed = false
watch(
  data,
  (value) => {
    if (!value) return
    resolveHash()

    // 服务端 flash（OAuth 绑定、无 JS 表单）改成 toast：跳转后已经滚到区块，顶部提示看不见
    if (flashed) return
    flashed = true
    if (value.user?.success) toast(value.user.success, 'success', 6000)
    else if (value.user?.error) toast(value.user.error, 'error', 8000)
  },
  { immediate: true },
)
</script>

<template>
  <div class="container settings">
    <h1>{{ t('Account settings') }}</h1>

    <template v-if="data">
      <BaseAlert v-if="data.user.error" type="error" :message="data.user.error" />
      <BaseAlert v-if="data.user.success" type="success" :message="data.user.success" />

      <div class="settings-layout">
        <nav class="settings-nav">
          <RouterLink
            v-for="item in navItems"
            :key="item.to"
            :to="item.to"
            class="settings-nav__link"
            :class="{ 'is-active': isActive(item.to) }"
          >
            <span :class="['icon', 'icon-' + item.icon]" aria-hidden="true"></span>
            <span>{{ item.label }}</span>
          </RouterLink>
        </nav>

        <div class="settings-content">
          <RouterView v-slot="{ Component }">
            <component :is="Component" v-if="Component" />
          </RouterView>
        </div>
      </div>
    </template>
  </div>
</template>
