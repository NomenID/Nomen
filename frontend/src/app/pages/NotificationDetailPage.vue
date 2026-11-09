<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { inject, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { apiGet } from '../api'
import { usePageData } from '../usePageData.js'
import { useT } from '../../composables/useT.js'

const { t } = useT()
const route = useRoute()
const viewer = inject('viewer')

// 详情路由带参数：复用 usePageData 的首屏消费，参数变化时手动重取
const initial = inject('initialPage')
const data = ref(initial && initial.name === 'notification-detail' ? initial.data : null)
const loading = ref(!data.value)
const notFound = ref(false)

async function load() {
  loading.value = true
  notFound.value = false
  try {
    const res = await apiGet(`/api/app/notifications/${route.params.id}`)
    data.value = res.page.data
    // 服务端已标记已读，徽标跟着减
    if (viewer) viewer.unreadCount = Math.max(0, (viewer.unreadCount || 0) - 1)
  } catch (err) {
    notFound.value = true
  } finally {
    loading.value = false
  }
}

if (!data.value) load()

watch(() => route.params.id, (id, old) => {
  if (id && id !== old && route.name === 'notification-detail') load()
})
</script>

<template>
  <div v-if="data" class="container">
    <div class="dashboard">
      <div class="notif-detail">
        <RouterLink to="/notifications" class="notif-back">‹ {{ t('Back to messages') }}</RouterLink>

        <div class="notif-detail__head">
          <span :class="['notif-item__category', 'notif-item__category--' + data.notification.category]">{{ data.notification.categoryLabel }}</span>
          <span class="notif-item__time">{{ data.notification.createdAt }}</span>
        </div>

        <h1>{{ data.notification.title }}</h1>

        <div class="notif-detail__body">{{ data.notification.body }}</div>

        <a v-if="data.notification.link" :href="data.notification.link" class="btn btn-primary">{{ data.notification.linkText || t('Take care of it') }}</a>
      </div>
    </div>
  </div>
  <div v-else-if="notFound" class="container"><p class="text-muted" style="padding:24px 0">{{ t('Message not found') }}</p></div>
  <div v-else-if="loading" class="container"><p class="text-muted" style="padding:24px 0">{{ t('Loading...') }}</p></div>
</template>
