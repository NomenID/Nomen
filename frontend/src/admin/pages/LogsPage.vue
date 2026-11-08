<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
-->

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
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
const filters = reactive({
  category: '',
  action: '',
  actor_type: '',
  actor: '',
  target: '',
  q: '',
  from: '',
  to: '',
  dir: '',
})

const actionOptions = computed(() => {
  const all = data.value?.actions || {}
  if (!filters.category) return all
  const out = {}
  for (const [key, label] of Object.entries(all)) {
    if (key.startsWith(filters.category + '.')) out[key] = label
  }
  return out
})

function buildParams(page = 1) {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(filters)) {
    if (value) params.set(key === 'q' ? 'q' : key, value)
  }
  if (page > 1) params.set('page', String(page))
  return params
}

async function load(page = 1) {
  loading.value = true
  error.value = ''
  const params = buildParams(page)
  try {
    data.value = await apiGet('/api/admin/logs' + (params.toString() ? '?' + params : ''))
    router.replace({ path: '/admin/logs', query: Object.fromEntries(params) })
  } catch (err) {
    error.value = '日志加载失败'
  } finally {
    loading.value = false
  }
}

function reset() {
  for (const key of Object.keys(filters)) filters[key] = ''
  load(1)
}

function exportUrl() {
  const params = buildParams(1)
  return '/admin/logs/export' + (params.toString() ? '?' + params : '')
}

onMounted(() => load(1))
</script>

<template>
  <AdminPage title="活动日志" description="全站用户与管理员操作的审计记录。">
    <template #actions>
      <a class="a-btn a-btn--ghost" :href="exportUrl()">导出 CSV</a>
      <span v-if="data" class="a-count">共 {{ data.pagination.total }} 条</span>
    </template>

    <AdminAlert v-if="error" type="danger" :message="error" />

    <AdminCard>
      <form class="a-filters a-filters--wrap" @submit.prevent="load(1)">
        <select v-model="filters.category" class="a-input">
          <option value="">全部分类</option>
          <option v-for="(label, key) in data?.categories || {}" :key="key" :value="key">{{ label }}</option>
        </select>
        <select v-model="filters.action" class="a-input">
          <option value="">全部动作</option>
          <option v-for="(label, key) in actionOptions" :key="key" :value="key">{{ label }}</option>
        </select>
        <select v-model="filters.actor_type" class="a-input">
          <option value="">全部操作者类型</option>
          <option value="user">用户</option>
          <option value="admin">管理员</option>
          <option value="system">系统</option>
        </select>
        <input v-model="filters.actor" class="a-input" type="text" placeholder="操作者">
        <input v-model="filters.target" class="a-input" type="text" placeholder="目标">
        <input v-model="filters.q" class="a-input" type="search" placeholder="关键词">
        <input v-model="filters.from" class="a-input" type="date" title="起始日期">
        <input v-model="filters.to" class="a-input" type="date" title="结束日期">
        <select v-model="filters.dir" class="a-input">
          <option value="">从新到旧</option>
          <option value="newer">从旧到新</option>
        </select>
        <button class="a-btn a-btn--primary" type="submit">筛选</button>
        <button class="a-btn a-btn--ghost" type="button" @click="reset">重置</button>
      </form>
    </AdminCard>

    <AdminCard :padded="false">
      <div v-if="loading" class="a-loading">加载中…</div>
      <AdminEmpty v-else-if="!data?.logs.length" text="没有符合条件的日志。" />
      <div v-else class="a-table-wrap">
        <table class="a-table">
          <thead><tr><th>时间</th><th>操作者</th><th>分类</th><th>动作</th><th>目标</th><th>说明</th></tr></thead>
          <tbody>
            <tr v-for="log in data.logs" :key="log.id">
              <td class="a-nowrap"><router-link class="a-link" :to="`/admin/logs/${log.id}`">{{ log.created_at }}</router-link></td>
              <td>
                <router-link v-if="log.actor_url" class="a-link" :to="log.actor_url">{{ log.actor_username || '系统' }}</router-link>
                <span v-else>{{ log.actor_username || '系统' }}</span>
                <AdminBadge tone="muted">{{ log.actor_label }}</AdminBadge>
              </td>
              <td>{{ log.category_label }}</td>
              <td>{{ log.action_label }}</td>
              <td>
                <router-link v-if="log.target_url" class="a-link" :to="log.target_url">{{ log.target_name || log.target_id }}</router-link>
                <span v-else>{{ log.target_name || log.target_id || '—' }}</span>
              </td>
              <td class="a-cell-summary">{{ log.summary }}</td>
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
