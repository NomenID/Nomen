<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { apiGet, apiPost } from '../../app/api'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminBadge from '../components/AdminBadge.vue'
import AdminEmpty from '../components/AdminEmpty.vue'
import AdminAlert from '../components/AdminAlert.vue'
import AdminPagination from '../components/AdminPagination.vue'

const router = useRouter()
const loading = ref(true)
const error = ref('')
const data = ref(null)
const busyId = ref(0)
const filters = reactive({ q: '', filter: '' })

async function load(page = 1) {
  loading.value = true
  error.value = ''
  const params = new URLSearchParams()
  if (filters.q) params.set('q', filters.q)
  if (filters.filter) params.set('filter', filters.filter)
  if (page > 1) params.set('page', String(page))
  try {
    data.value = await apiGet('/api/admin/users' + (params.toString() ? '?' + params : ''))
    router.replace({ path: '/admin/users', query: Object.fromEntries(params) })
  } catch (err) {
    error.value = '用户列表加载失败'
  } finally {
    loading.value = false
  }
}

function reset() {
  filters.q = ''
  filters.filter = ''
  load(1)
}

async function toggleStatus(user) {
  const disabling = Number(user.status) === 1
  const ok = await window.Nomen.confirm(
    disabling ? '禁用账户' : '启用账户',
    disabling ? `禁用后 ${user.username} 将无法登录，确定继续？` : `重新允许 ${user.username} 登录？`,
    { confirmText: disabling ? '禁用' : '启用', cancelText: '取消' },
  )
  if (!ok) return
  busyId.value = user.id
  try {
    const result = await apiPost('/admin/users/toggle-status', { user_id: user.id })
    if (result.success) {
      user.status = result.status
      window.Nomen.toast(result.message, 'success')
    } else {
      window.Nomen.toast(result.message || '操作失败', 'error')
    }
  } finally {
    busyId.value = 0
  }
}

async function toggleAdmin(user) {
  const granting = user.role !== 'admin'
  const ok = await window.Nomen.confirm(
    granting ? '设为管理员' : '撤销管理员',
    granting ? `把 ${user.username} 设为管理员？对方将能进入管理后台。` : `撤销 ${user.username} 的管理员身份？`,
    { confirmText: granting ? '设为管理员' : '撤销', cancelText: '取消' },
  )
  if (!ok) return
  busyId.value = user.id
  try {
    const result = await apiPost('/admin/users/toggle-admin', { user_id: user.id })
    if (result.success) {
      user.role = result.role
      window.Nomen.toast(result.message, 'success')
    } else {
      window.Nomen.toast(result.message || '操作失败', 'error')
    }
  } finally {
    busyId.value = 0
  }
}

onMounted(() => load(1))
</script>

<template>
  <AdminPage title="用户" description="搜索、查看状态，处理禁用与管理员权限。">
    <template #actions>
      <span class="a-count" v-if="data">共 {{ data.pagination.total }} 人</span>
    </template>

    <AdminAlert v-if="error" type="danger" :message="error" />

    <AdminCard>
      <form class="a-filters" @submit.prevent="load(1)">
        <input v-model="filters.q" class="a-input" type="search" placeholder="用户名、昵称或邮箱">
        <select v-model="filters.filter" class="a-input">
          <option value="">全部用户</option>
          <option value="admin">管理员</option>
          <option value="disabled">已禁用</option>
          <option value="unverified">邮箱未验证</option>
        </select>
        <button class="a-btn a-btn--primary" type="submit">筛选</button>
        <button class="a-btn a-btn--ghost" type="button" @click="reset">重置</button>
      </form>
    </AdminCard>

    <AdminCard :padded="false">
      <div v-if="loading" class="a-loading">加载中…</div>
      <AdminEmpty v-else-if="!data?.users.length" text="没有符合条件的用户。" />
      <div v-else class="a-table-wrap">
        <table class="a-table a-table--users">
          <thead>
            <tr>
              <th>用户</th>
              <th>邮箱</th>
              <th>角色 / 状态</th>
              <th>登录方式</th>
              <th>创建时间</th>
              <th>最近登录</th>
              <th class="a-td-actions">操作</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="user in data.users" :key="user.id">
              <td>
                <router-link class="a-link a-link--strong" :to="`/admin/users/${user.id}`">{{ user.username }}</router-link>
                <div v-if="user.display_name" class="a-sub">{{ user.display_name }}</div>
              </td>
              <td>
                <span>{{ user.email || '—' }}</span>
                <AdminBadge v-if="Number(user.email_verified) === 1" tone="success">已验证</AdminBadge>
                <AdminBadge v-else tone="muted">未验证</AdminBadge>
              </td>
              <td>
                <AdminBadge :tone="user.role === 'admin' ? 'primary' : ''">{{ user.role === 'admin' ? '管理员' : '普通用户' }}</AdminBadge>
                <AdminBadge :tone="Number(user.status) === 1 ? 'success' : 'danger'">{{ Number(user.status) === 1 ? '正常' : '已禁用' }}</AdminBadge>
              </td>
              <td>{{ user.login_method_count }}</td>
              <td class="a-nowrap">{{ user.created_at }}</td>
              <td class="a-nowrap">
                <div>{{ user.last_login_at || '从未登录' }}</div>
                <div v-if="user.last_login_ip" class="a-sub a-mono">{{ user.last_login_ip }}</div>
              </td>
              <td class="a-td-actions">
                <router-link class="a-btn a-btn--ghost a-btn--sm" :to="`/admin/users/${user.id}`">详情</router-link>
                <button class="a-btn a-btn--ghost a-btn--sm" type="button" :disabled="busyId === user.id" @click="toggleStatus(user)">
                  {{ Number(user.status) === 1 ? '禁用' : '启用' }}
                </button>
                <button class="a-btn a-btn--ghost a-btn--sm" type="button" :disabled="busyId === user.id" @click="toggleAdmin(user)">
                  {{ user.role === 'admin' ? '撤销管理员' : '设为管理员' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </AdminCard>

    <AdminPagination
      v-if="data"
      :page="data.pagination.page"
      :pages="data.pagination.pages"
      :total="data.pagination.total"
      @change="load"
    />
  </AdminPage>
</template>
