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
  const confirmed = await window.Nomen.confirm(t('撤销授权'), t('撤销后该应用将无法访问您的信息。'), {
    confirmText: t('撤销'),
    cancelText: t('取消'),
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
    toast(err.message || t('网络错误'), 'error')
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
      :redirected-from-home="data.redirectedFromHome"
      :revoke-handler="revoke"
      :revoking="revoking"
    />
  </div>
</template>
