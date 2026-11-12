<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { apiGet, apiPost } from '../../app/api'
import { useT } from '../../composables/useT.js'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminBadge from '../components/AdminBadge.vue'
import AdminEmpty from '../components/AdminEmpty.vue'
import AdminAlert from '../components/AdminAlert.vue'

const { t } = useT()
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
    error.value = t('Could not load the app list')
  } finally {
    loading.value = false
  }
}

async function toggle(app) {
  const enabling = Number(app.status) !== 1
  const ok = await window.Nomen.confirm(
    enabling ? t('Enable app') : t('Disable app'),
    enabling
      ? t('Allow {:name} to sign users in again?', { name: app.name })
      : t('After disabling, {:name} can no longer start an authorization. Continue?', { name: app.name }),
    { confirmText: enabling ? t('Enable') : t('Disable'), cancelText: t('Cancel') },
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
    t('Delete app'),
    t('Deleting {:name} revokes every token already issued for it. This cannot be undone.', { name: app.name }),
    { confirmText: t('Delete'), cancelText: t('Cancel') },
  )
  if (!ok) return
  busyId.value = app.client_id
  try {
    const result = await apiPost(`/admin/apps/${app.client_id}/delete`, {})
    if (result.success) {
      window.Nomen.toast(result.message, 'success')
      apps.value = apps.value.filter((item) => item.client_id !== app.client_id)
    } else {
      window.Nomen.toast(result.message || t('Delete failed'), 'error')
    }
  } finally {
    busyId.value = ''
  }
}

onMounted(load)
</script>

<template>
  <AdminPage :title="t('Apps')" :description="t('Third-party applications that sign users in through this site.')">
    <template #actions>
      <button class="a-btn a-btn--primary" type="button" @click="router.push('/admin/apps/create')">{{ t('New app') }}</button>
    </template>

    <AdminAlert v-if="error" type="danger" :message="error" />

    <AdminCard :padded="false">
      <div v-if="loading" class="a-loading">{{ t('Loading…') }}</div>
      <AdminEmpty v-else-if="!apps.length" text="No apps yet. Create one and hand the client ID and secret to the integrator." />
      <div v-else class="a-table-wrap">
        <table class="a-table">
          <thead>
            <tr><th>{{ t('App') }}</th><th>Client ID</th><th>{{ t('Type') }}</th><th>{{ t('Owner') }}</th><th>{{ t('Status') }}</th><th>{{ t('Created at') }}</th><th class="a-td-actions">{{ t('Actions') }}</th></tr>
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
              <td>{{ app.client_type === 'public' ? t('Public') : t('Confidential') }}</td>
              <td>{{ app.owner_username || app.developer_name || '—' }}</td>
              <td>
                <AdminBadge :tone="Number(app.status) === 1 ? 'success' : 'danger'">{{ Number(app.status) === 1 ? t('Enabled') : t('Disabled') }}</AdminBadge>
              </td>
              <td class="a-nowrap">{{ app.created_at }}</td>
              <td class="a-td-actions">
                <router-link class="a-btn a-btn--ghost a-btn--sm" :to="`/admin/apps/${app.client_id}`">{{ t('Details') }}</router-link>
                <button class="a-btn a-btn--ghost a-btn--sm" type="button" :disabled="busyId === app.client_id" @click="toggle(app)">
                  {{ Number(app.status) === 1 ? t('Disable') : t('Enable') }}
                </button>
                <button class="a-btn a-btn--danger a-btn--sm" type="button" :disabled="busyId === app.client_id" @click="remove(app)">{{ t('Delete') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </AdminCard>
  </AdminPage>
</template>
