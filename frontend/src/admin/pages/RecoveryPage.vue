<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
-->

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { apiGet } from '../../app/api'
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
const filters = reactive({ q: '', status: '' })

const statuses = [
  { value: '', label: '全部' },
  { value: 'pending', label: '待处理' },
  { value: 'approved', label: '已通过' },
  { value: 'rejected', label: '已拒绝' },
  { value: 'completed', label: '已完成' },
]

const statusTone = {
  pending: 'warning',
  approved: 'primary',
  rejected: 'danger',
  completed: 'success',
}

function statusLabel(value) {
  return statuses.find((item) => item.value === value)?.label || value
}

async function load(page = 1) {
  loading.value = true
  error.value = ''
  const params = new URLSearchParams()
  if (filters.q) params.set('q', filters.q)
  if (filters.status) params.set('status', filters.status)
  if (page > 1) params.set('page', String(page))
  try {
    data.value = await apiGet('/api/admin/recovery' + (params.toString() ? '?' + params : ''))
    router.replace({ path: '/admin/recovery', query: Object.fromEntries(params) })
  } catch (err) {
    error.value = '工单列表加载失败'
  } finally {
    loading.value = false
  }
}

function pickStatus(value) {
  filters.status = value
  load(1)
}

function reset() {
  filters.q = ''
  filters.status = ''
  load(1)
}

onMounted(() => load(1))
</script>

<template>
  <AdminPage title="恢复工单" description="用户提交的账号恢复申诉，审核后签发一次性登录链接。">
    <template #actions>
      <span v-if="data" class="a-count">共 {{ data.pagination.total }} 条</span>
    </template>

    <AdminAlert v-if="error" type="danger" :message="error" />

    <AdminCard>
      <div class="a-tabs">
        <button
          v-for="item in statuses"
          :key="item.value"
          class="a-tabs__item"
          :class="{ 'is-active': filters.status === item.value }"
          type="button"
          @click="pickStatus(item.value)"
        >{{ item.label }}</button>
      </div>
      <form class="a-filters" @submit.prevent="load(1)">
        <input v-model="filters.q" class="a-input" type="search" placeholder="用户名、联系邮箱或账号邮箱">
        <button class="a-btn a-btn--primary" type="submit">搜索</button>
        <button class="a-btn a-btn--ghost" type="button" @click="reset">重置</button>
      </form>
    </AdminCard>

    <AdminCard :padded="false">
      <div v-if="loading" class="a-loading">加载中…</div>
      <AdminEmpty v-else-if="!data?.requests.length" text="没有符合条件的工单。" />
      <div v-else class="a-table-wrap">
        <table class="a-table">
          <thead>
            <tr><th>#</th><th>用户</th><th>联系邮箱</th><th>状态</th><th>提交时间</th><th>审核时间</th><th>说明</th></tr>
          </thead>
          <tbody>
            <tr v-for="req in data.requests" :key="req.id">
              <td><router-link class="a-link a-link--strong" :to="`/admin/recovery/${req.id}`">#{{ req.id }}</router-link></td>
              <td>
                <router-link class="a-link" :to="`/admin/users/${req.user_id}`">{{ req.username }}</router-link>
              </td>
              <td>{{ req.contact_email }}</td>
              <td><AdminBadge :tone="statusTone[req.status] || ''">{{ statusLabel(req.status) }}</AdminBadge></td>
              <td class="a-nowrap">{{ req.created_at }}</td>
              <td class="a-nowrap">{{ req.reviewed_at || '—' }}</td>
              <td class="a-cell-summary">{{ req.description }}</td>
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
