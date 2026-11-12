<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { url } from '../lib/url.js'
import { useT } from '../composables/useT.js'

const { t } = useT()

const props = defineProps({
  viewer: { type: Object, default: () => ({}) },
  brand: { type: Object, default: () => ({}) },
  menu: { type: Array, default: () => [] },
})

const route = useRoute()
const sidebarOpen = ref(false)
const currentTheme = ref(document.documentElement.getAttribute('data-theme') || 'dark')

const groups = computed(() => {
  const map = new Map()
  for (const item of props.menu) {
    const name = item.group || t('Other')
    if (!map.has(name)) map.set(name, [])
    map.get(name).push(item)
  }
  return [...map.entries()].map(([name, items]) => ({ name, items }))
})

function isActive(href) {
  const path = route.path
  if (href === '/admin') return path === '/admin'
  return path === href || path.startsWith(href + '/')
}

function toggleTheme() {
  const next = currentTheme.value === 'dark' ? 'light' : 'dark'
  currentTheme.value = next
  document.documentElement.setAttribute('data-theme', next)
  localStorage.setItem('theme', next)
}

async function logout() {
  const ok = await window.Nomen.confirm(t('Sign out'), t('Sign out of this account?'), {
    confirmText: t('Sign out'),
    cancelText: t('Cancel'),
  })
  if (ok) window.location.href = url('/logout')
}

const userInitial = computed(() => {
  const name = props.viewer.displayName || props.viewer.username || ''
  return Array.from(name)[0]?.toUpperCase() || '?'
})
</script>

<template>
  <div class="a-shell" :class="{ 'a-shell--open': sidebarOpen }">
    <div class="a-scrim" @click="sidebarOpen = false"></div>

    <aside class="a-sidebar">
      <a href="/" class="a-brand">
        <img v-if="brand.logo" :src="brand.logo" alt="" width="28" height="28">
        <span class="a-brand__text">
          <strong>{{ brand.name }}</strong>
          <small>{{ t('Admin') }}</small>
        </span>
      </a>

      <nav class="a-nav">
        <div v-for="group in groups" :key="group.name" class="a-nav__group">
          <p class="a-nav__label">{{ group.name }}</p>
          <router-link
            v-for="item in group.items"
            :key="item.key"
            :to="item.href"
            class="a-nav__item"
            :class="{ 'is-active': isActive(item.href) }"
            @click="sidebarOpen = false"
          >{{ item.label }}</router-link>
        </div>
      </nav>

      <div class="a-sidebar__foot">
        <a href="/" class="a-sidebar__link">{{ t('Back to site') }}</a>
        <a href="/special/version" class="a-sidebar__link">{{ t('Version info') }}</a>
      </div>
    </aside>

    <div class="a-main">
      <header class="a-topbar">
        <button class="a-topbar__menu" type="button" :aria-label="t('Menu')" @click="sidebarOpen = !sidebarOpen">
          <span></span><span></span><span></span>
        </button>
        <div class="a-topbar__right">
          <button class="a-iconbtn" type="button" :title="t('Switch theme')" @click="toggleTheme">
            <span :class="['icon', currentTheme === 'dark' ? 'icon-sun' : 'icon-moon']"></span>
          </button>
          <div class="a-user">
            <img v-if="viewer.avatarUrl" :src="viewer.avatarUrl" alt="" class="a-user__avatar">
            <span v-else class="a-user__avatar a-user__avatar--text">{{ userInitial }}</span>
            <span class="a-user__name">{{ viewer.displayName || viewer.username }}</span>
          </div>
          <button class="a-btn a-btn--ghost a-btn--sm" type="button" @click="logout">{{ t('Sign out') }}</button>
        </div>
      </header>

      <main class="a-content">
        <slot />
      </main>
    </div>
  </div>
</template>
