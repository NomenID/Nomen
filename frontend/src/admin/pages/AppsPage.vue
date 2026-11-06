<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { apiGet, apiPost } from '../../app/api'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminBadge from '../components/AdminBadge.vue'
import AdminEmpty from '../components/AdminEmpty.vue'
import AdminAlert from '../components/AdminAlert.vue'

const router = useRouter()
const loading = ref(true)
const error = ref('')
const apps = ref([])
const busyId = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const data = await apiGet('/api/admin/apps')
    apps.value = data.apps || []
  } catch (err) {
    error.value = '应用列表加载失败'
  } finally {
    loading.value = false
  }
}

async function toggle(app) {
  const enabling = Number(app.status) !== 1
  const ok = await window.Nomen.confirm(
    enabling ? '启用应用' : '停用应用',
    enabling ? `重新允许「${app.name}」登录？` : `停用后「${app.name}」将无法再发起授权，确定继续？`,
    { confirmText: enabling ? '启用' : '停用', cancelText: '取消' },
  )
  if (!ok) return
  busyId.value = app.client_id
  try {
    const result = await apiPost(`/admin/apps/${app.client_id}/toggle`, {})
    window.Nomen.toast(result.message, result.success ? 'success' : 'error')
    if (result.success) app.status = result.status
  } finally {
    busyId.value = ''
  }
}

async function remove(app) {
  const ok = await window.Nomen.confirm(
    '删除应用',
    `删除「${app.name}」后，所有已授权的用户令牌都会失效，且不可恢复。`,
    { confirmText: '删除', cancelText: '取消' },
  )
  if (!ok) return
  busyId.value = app.client_id
  try {
    const result = await apiPost(`/admin/apps/${app.client_id}/delete`, {})
    if (result.success) {
      window.Nomen.toast(result.message, 'success')
      apps.value = apps.value.filter((item) => item.client_id !== app.client_id)
    } else {
      window.Nomen.toast(result.message || '删除失败', 'error')
    }
  } finally {
    busyId.value = ''
  }
}

onMounted(load)
</script>

<template>
  <AdminPage title="应用" description="站点作为 OAuth 提供方时，接入这里的第三方应用。">
    <template #actions>
      <button class="a-btn a-btn--primary" type="button" @click="router.push('/admin/apps/create')">新建应用</button>
    </template>

    <AdminAlert v-if="error" type="danger" :message="error" />

    <AdminCard :padded="false">
      <div v-if="loading" class="a-loading">加载中…</div>
      <AdminEmpty v-else-if="!apps.length" text="还没有应用。新建一个，把 Client ID 和密钥交给接入方。" />
      <div v-else class="a-table-wrap">
        <table class="a-table">
          <thead>
            <tr><th>应用</th><th>Client ID</th><th>类型</th><th>归属</th><th>状态</th><th>创建时间</th><th class="a-td-actions">操作</th></tr>
          </thead>
          <tbody>
            <tr v-for="app in apps" :key="app.client_id">
              <td>
                <div class="a-appcell">
                  <img v-if="app.icon" :src="`/assets/develops/${app.icon}`" alt="" class="a-appcell__icon">
                  <span v-else class="a-appcell__icon a-appcell__icon--text">{{ (app.name || '?').slice(0, 1).toUpperCase() }}</span>
                  <div>
                    <router-link class="a-link a-link--strong" :to="`/admin/apps/${app.client_id}`">{{ app.name }}</router-link>
                    <div v-if="app.description" class="a-sub">{{ app.description }}</div>
                  </div>
                </div>
              </td>
              <td class="a-mono a-nowrap">{{ app.client_id }}</td>
              <td>{{ app.client_type === 'public' ? '公共' : '机密' }}</td>
              <td>{{ app.owner_username || app.developer_name || '—' }}</td>
              <td>
                <AdminBadge :tone="Number(app.status) === 1 ? 'success' : 'danger'">{{ Number(app.status) === 1 ? '在架' : '已停用' }}</AdminBadge>
              </td>
              <td class="a-nowrap">{{ app.created_at }}</td>
              <td class="a-td-actions">
                <router-link class="a-btn a-btn--ghost a-btn--sm" :to="`/admin/apps/${app.client_id}`">详情</router-link>
                <button class="a-btn a-btn--ghost a-btn--sm" type="button" :disabled="busyId === app.client_id" @click="toggle(app)">
                  {{ Number(app.status) === 1 ? '停用' : '启用' }}
                </button>
                <button class="a-btn a-btn--danger a-btn--sm" type="button" :disabled="busyId === app.client_id" @click="remove(app)">删除</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </AdminCard>
  </AdminPage>
</template>
