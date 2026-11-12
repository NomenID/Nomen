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
import { apiGet, apiPost } from '../../app/api'
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
    error.value = t('Could not load the user list')
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
    disabling ? t('Disable account') : t('Enable account'),
    disabling
      ? t('After disabling, {:name} cannot sign in. Continue?', { name: user.username })
      : t('Allow {:name} to sign in again?', { name: user.username }),
    { confirmText: disabling ? t('Disable') : t('Enable'), cancelText: t('Cancel') },
  )
  if (!ok) return
  busyId.value = user.unid
  try {
    const result = await apiPost('/admin/users/toggle-status', { user_unid: user.unid })
    if (result.success) {
      user.status = result.status
      window.Nomen.toast(result.message, 'success')
    } else {
      window.Nomen.toast(result.message || t('Operation failed'), 'error')
    }
  } finally {
    busyId.value = 0
  }
}

async function toggleAdmin(user) {
  const granting = user.role !== 'admin'
  const ok = await window.Nomen.confirm(
    granting ? t('Grant administrator') : t('Revoke administrator'),
    granting
      ? t('Make {:name} an administrator? They will be able to open the admin panel.', { name: user.username })
      : t('Remove {:name} from administrators?', { name: user.username }),
    { confirmText: granting ? t('Grant') : t('Revoke'), cancelText: t('Cancel') },
  )
  if (!ok) return
  busyId.value = user.unid
  try {
    const result = await apiPost('/admin/users/toggle-admin', { user_unid: user.unid })
    if (result.success) {
      user.role = result.role
      window.Nomen.toast(result.message, 'success')
    } else {
      window.Nomen.toast(result.message || t('Operation failed'), 'error')
    }
  } finally {
    busyId.value = 0
  }
}

onMounted(() => load(1))
</script>

<template>
  <AdminPage :title="t('Users')" :description="t('Search, check status, and manage bans and administrator rights.')">
    <template #actions>
      <span class="a-count" v-if="data">{{ t('{:total} users', { total: data.pagination.total }) }}</span>
    </template>

    <AdminAlert v-if="error" type="danger" :message="error" />

    <AdminCard>
      <form class="a-filters" @submit.prevent="load(1)">
        <input v-model="filters.q" class="a-input" type="search" :placeholder="t('Username, display name or email')">
        <select v-model="filters.filter" class="a-input">
          <option value="">{{ t('All users') }}</option>
          <option value="admin">{{ t('Administrators') }}</option>
          <option value="disabled">{{ t('Disabled') }}</option>
          <option value="unverified">{{ t('Email unverified') }}</option>
        </select>
        <button class="a-btn a-btn--primary" type="submit">{{ t('Filter') }}</button>
        <button class="a-btn a-btn--ghost" type="button" @click="reset">{{ t('Reset') }}</button>
      </form>
    </AdminCard>

    <AdminCard :padded="false">
      <div v-if="loading" class="a-loading">{{ t('Loading…') }}</div>
      <AdminEmpty v-else-if="!data?.users.length" text="No users match these filters." />
      <div v-else class="a-table-wrap">
        <table class="a-table a-table--users">
          <thead>
            <tr>
              <th>{{ t('User') }}</th>
              <th>{{ t('Email') }}</th>
              <th>{{ t('Role / status') }}</th>
              <th>{{ t('Sign-in methods') }}</th>
              <th>{{ t('Created at') }}</th>
              <th>{{ t('Last sign-in') }}</th>
              <th class="a-td-actions">{{ t('Actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="user in data.users" :key="user.unid">
              <td>
                <router-link class="a-link a-link--strong" :to="`/admin/users/${user.unid}`">{{ user.username }}</router-link>
                <div v-if="user.display_name" class="a-sub">{{ user.display_name }}</div>
              </td>
              <td>
                <span>{{ user.email || '—' }}</span>
                <AdminBadge v-if="Number(user.email_verified) === 1" tone="success">{{ t('Verified') }}</AdminBadge>
                <AdminBadge v-else tone="muted">{{ t('Unverified') }}</AdminBadge>
              </td>
              <td>
                <AdminBadge :tone="user.role === 'admin' ? 'primary' : ''">{{ user.role === 'admin' ? t('Administrator') : t('Regular user') }}</AdminBadge>
                <AdminBadge :tone="Number(user.status) === 1 ? 'success' : 'danger'">{{ Number(user.status) === 1 ? t('Normal') : t('Disabled') }}</AdminBadge>
              </td>
              <td>{{ user.login_method_count }}</td>
              <td class="a-nowrap">{{ user.created_at }}</td>
              <td class="a-nowrap">
                <div>{{ user.last_login_at || t('Never signed in') }}</div>
                <div v-if="user.last_login_ip" class="a-sub a-mono">{{ user.last_login_ip }}</div>
              </td>
              <td class="a-td-actions">
                <router-link class="a-btn a-btn--ghost a-btn--sm" :to="`/admin/users/${user.unid}`">{{ t('Details') }}</router-link>
                <button class="a-btn a-btn--ghost a-btn--sm" type="button" :disabled="busyId === user.unid" @click="toggleStatus(user)">
                  {{ Number(user.status) === 1 ? t('Disable') : t('Enable') }}
                </button>
                <button class="a-btn a-btn--ghost a-btn--sm" type="button" :disabled="busyId === user.unid" @click="toggleAdmin(user)">
                  {{ user.role === 'admin' ? t('Revoke administrator') : t('Grant administrator') }}
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
