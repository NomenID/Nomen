<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { apiGet } from '../../app/api'
import { useT } from '../../composables/useT.js'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminBadge from '../components/AdminBadge.vue'
import AdminEmpty from '../components/AdminEmpty.vue'
import AdminAlert from '../components/AdminAlert.vue'
import AdminPagination from '../components/AdminPagination.vue'

const { t } = useT()
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
    error.value = t('Could not load the log')
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
  <AdminPage :title="t('Activity log')" :description="t('Audit trail of user and administrator actions.')">
    <template #actions>
      <a class="a-btn a-btn--ghost" :href="exportUrl()">{{ t('Export CSV') }}</a>
      <span v-if="data" class="a-count">{{ t('{:total} entries', { total: data.pagination.total }) }}</span>
    </template>

    <AdminAlert v-if="error" type="danger" :message="error" />

    <AdminCard>
      <form class="a-filters a-filters--wrap" @submit.prevent="load(1)">
        <select v-model="filters.category" class="a-input">
          <option value="">{{ t('All categories') }}</option>
          <option v-for="(label, key) in data?.categories || {}" :key="key" :value="key">{{ label }}</option>
        </select>
        <select v-model="filters.action" class="a-input">
          <option value="">{{ t('All actions') }}</option>
          <option v-for="(label, key) in actionOptions" :key="key" :value="key">{{ label }}</option>
        </select>
        <select v-model="filters.actor_type" class="a-input">
          <option value="">{{ t('All actor types') }}</option>
          <option value="user">{{ t('User') }}</option>
          <option value="admin">{{ t('Administrator') }}</option>
          <option value="system">{{ t('System') }}</option>
        </select>
        <input v-model="filters.actor" class="a-input" type="text" :placeholder="t('Actor')">
        <input v-model="filters.target" class="a-input" type="text" :placeholder="t('Target')">
        <input v-model="filters.q" class="a-input" type="search" :placeholder="t('Keyword')">
        <input v-model="filters.from" class="a-input" type="date" :title="t('Start date')">
        <input v-model="filters.to" class="a-input" type="date" :title="t('End date')">
        <select v-model="filters.dir" class="a-input">
          <option value="">{{ t('Newest first') }}</option>
          <option value="newer">{{ t('Oldest first') }}</option>
        </select>
        <button class="a-btn a-btn--primary" type="submit">{{ t('Filter') }}</button>
        <button class="a-btn a-btn--ghost" type="button" @click="reset">{{ t('Reset') }}</button>
      </form>
    </AdminCard>

    <AdminCard :padded="false">
      <div v-if="loading" class="a-loading">{{ t('Loading…') }}</div>
      <AdminEmpty v-else-if="!data?.logs.length" text="No log entries match these filters." />
      <div v-else class="a-table-wrap">
        <table class="a-table">
          <thead><tr><th>{{ t('Time') }}</th><th>{{ t('Actor') }}</th><th>{{ t('Category') }}</th><th>{{ t('Action') }}</th><th>{{ t('Target') }}</th><th>{{ t('Summary') }}</th></tr></thead>
          <tbody>
            <tr v-for="log in data.logs" :key="log.unid">
              <td class="a-nowrap"><router-link class="a-link" :to="`/admin/logs/${log.unid}`">{{ log.created_at }}</router-link></td>
              <td>
                <router-link v-if="log.actor_url" class="a-link" :to="log.actor_url">{{ log.actor_username || t('System') }}</router-link>
                <span v-else>{{ log.actor_username || t('System') }}</span>
                <AdminBadge tone="muted">{{ log.actor_label }}</AdminBadge>
              </td>
              <td>{{ log.category_label }}</td>
              <td>{{ log.action_label }}</td>
              <td>
                <router-link v-if="log.target_url" class="a-link" :to="log.target_url">{{ log.target_name || log.target_unid }}</router-link>
                <span v-else>{{ log.target_name || log.target_unid || '—' }}</span>
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
