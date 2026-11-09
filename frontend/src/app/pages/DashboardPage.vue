<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
-->

<script setup>
import { inject, ref } from 'vue'
import { useRouter } from 'vue-router'
import DashboardApp from '../../pages/dashboard/DashboardApp.vue'
import { apiPost } from '../api'
import { toast } from '../../lib/toast.js'
import { usePageData } from '../usePageData.js'
import { useT } from '../../composables/useT.js'

const { t } = useT()
const { data, loading } = usePageData('dashboard')
const router = useRouter()
const csrf = inject('csrf')
const viewer = inject('viewer')
const initial = inject('initialPage')

// 撤销授权：确认弹窗 + JSON 动作，成功后原地更新列表
const revoking = ref(false)

async function revoke(auth) {
  const confirmed = await window.Nomen.confirm(t('Revoke authorization'), t('After revoking, this app can no longer access your information.'), {
    confirmText: t('Revoke'),
    cancelText: t('Cancel'),
    danger: true,
  })
  if (!confirmed) return
  if (revoking.value) return
  revoking.value = true

  try {
    const res = await apiPost(`/dashboard/revoke/${auth.client_id}`)
    toast(res.message, res.success ? 'success' : 'error')
    if (res.success && res.data?.authorizations) {
      data.value = { ...data.value, authorizations: res.data.authorizations }
    }
  } catch (err) {
    toast(err.message || t('Network error'), 'error')
  } finally {
    revoking.value = false
  }
}

// 首页重定向过来的提示只显示一次
if (initial?.name === 'dashboard' && initial.data?.redirectedFromHome) {
  initial.consumed = true
}

void router; void viewer; void csrf; void loading
</script>

<template>
  <div v-if="data" class="container">
    <DashboardApp
      :csrf="csrf"
      :user="data.user"
      :authorizations="data.authorizations"
      :recent-activity="data.recentActivity"
      :success="data.success"
      :error="data.error"
      :revoke-handler="revoke"
      :revoking="revoking"
    />
  </div>
</template>
