<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { onMounted, reactive, ref } from 'vue'
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
const filters = reactive({ q: '', status: '' })

const statuses = [
  { value: '', label: 'All' },
  { value: 'pending', label: 'Pending' },
  { value: 'approved', label: 'Approved' },
  { value: 'rejected', label: 'Rejected' },
  { value: 'completed', label: 'Completed' },
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
    error.value = t('Could not load the request list')
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
  <AdminPage :title="t('Recovery requests')" :description="t('Account recovery requests submitted by users. After review a one-time sign-in link is issued.')">
    <template #actions>
      <span v-if="data" class="a-count">{{ t('{:total} in total', { total: data.pagination.total }) }}</span>
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
        >{{ t(item.label) }}</button>
      </div>
      <form class="a-filters" @submit.prevent="load(1)">
        <input v-model="filters.q" class="a-input" type="search" :placeholder="t('Username, contact email or account email')">
        <button class="a-btn a-btn--primary" type="submit">{{ t('Search') }}</button>
        <button class="a-btn a-btn--ghost" type="button" @click="reset">{{ t('Reset') }}</button>
      </form>
    </AdminCard>

    <AdminCard :padded="false">
      <div v-if="loading" class="a-loading">{{ t('Loading…') }}</div>
      <AdminEmpty v-else-if="!data?.requests.length" text="No requests match these filters." />
      <div v-else class="a-table-wrap">
        <table class="a-table">
          <thead>
            <tr><th>#</th><th>{{ t('User') }}</th><th>{{ t('Contact email') }}</th><th>{{ t('Status') }}</th><th>{{ t('Submitted at') }}</th><th>{{ t('Reviewed at') }}</th><th>{{ t('Description') }}</th></tr>
          </thead>
          <tbody>
            <tr v-for="req in data.requests" :key="req.unid">
              <td><router-link class="a-link a-link--strong" :to="`/admin/recovery/${req.unid}`">#{{ req.unid }}</router-link></td>
              <td>
                <router-link class="a-link" :to="`/admin/users/${req.user_unid}`">{{ req.username }}</router-link>
              </td>
              <td>{{ req.contact_email }}</td>
              <td><AdminBadge :tone="statusTone[req.status] || ''">{{ t(statusLabel(req.status)) }}</AdminBadge></td>
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
