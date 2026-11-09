<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { inject, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiPost } from '../api'
import { toast } from '../../lib/toast.js'
import { usePageData } from '../usePageData.js'
import { useT } from '../../composables/useT.js'

const { t } = useT()
const route = useRoute()
const router = useRouter()
const viewer = inject('viewer')

const { data, loading, reload } = usePageData('notifications', () => {
  const page = Math.max(1, parseInt(route.query.page, 10) || 1)
  return `/api/app/notifications?page=${page}`
})

const markingAll = ref(false)

watch(() => route.query.page, () => {
  if (route.name === 'notifications') reload()
})

async function readAll() {
  if (markingAll.value) return
  markingAll.value = true
  try {
    const res = await apiPost('/notifications/read-all')
    toast(res.message || t('All marked as read'), res.success ? 'success' : 'error')
    if (res.success) {
      data.value = {
        ...data.value,
        unreadCount: 0,
        items: data.value.items.map((item) => ({ ...item, read: true })),
      }
      viewer.unreadCount = 0
    }
  } catch (err) {
    toast(err.message || t('Network error'), 'error')
  } finally {
    markingAll.value = false
  }
}

function goPage(page) {
  router.push({ path: '/notifications', query: { page } })
}
</script>

<template>
  <div v-if="data" class="container">
    <div class="dashboard">
      <div class="dashboard-header">
        <div>
          <h1>{{ t('Messages') }}</h1>
          <p v-if="data.unreadCount > 0" class="text-muted">{{ t('{:count} unread', { count: data.unreadCount }) }}</p>
        </div>
        <button type="button" class="btn btn-secondary" :disabled="data.unreadCount === 0 || markingAll" @click="readAll">{{ t('Mark all as read') }}</button>
      </div>

      <div v-if="!data.items.length" class="dashboard-section">
        <div class="empty-state">
          <div class="empty-state__icon"><span class="icon icon-inbox"></span></div>
          <p>{{ t('No messages') }}</p>
          <p class="text-muted">{{ t('System notices and account alerts show up here') }}</p>
        </div>
      </div>

      <div v-else class="dashboard-section">
        <div class="notif-list">
          <RouterLink
            v-for="item in data.items"
            :key="item.id"
            :to="'/notifications/' + item.id"
            class="notif-item"
            :class="{ 'notif-item--unread': !item.read }"
          >
            <div class="notif-item__main">
              <div class="notif-item__top">
                <span :class="['notif-item__category', 'notif-item__category--' + item.category]">{{ item.categoryLabel }}</span>
                <span class="notif-item__time">{{ item.createdAt }}</span>
              </div>
              <div class="notif-item__title">
                {{ item.title }}
                <span v-if="!item.read" class="notif-item__dot" :title="t('Unread')"></span>
              </div>
              <div class="notif-item__excerpt">{{ item.excerpt }}</div>
            </div>
            <span class="notif-item__arrow">›</span>
          </RouterLink>
        </div>

        <div v-if="data.totalPages > 1" class="notif-pagination">
          <button v-if="data.page > 1" type="button" class="btn btn-sm btn-secondary" @click="goPage(data.page - 1)">{{ t('Previous') }}</button>
          <span class="text-muted">{{ t('Page {:page} / {:total}', { page: data.page, total: data.totalPages }) }}</span>
          <button v-if="data.page < data.totalPages" type="button" class="btn btn-sm btn-secondary" @click="goPage(data.page + 1)">{{ t('Next') }}</button>
        </div>
      </div>
    </div>
  </div>
  <div v-else-if="loading" class="container"><p class="text-muted" style="padding:24px 0">{{ t('Loading...') }}</p></div>
</template>
