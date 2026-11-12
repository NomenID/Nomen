<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { apiGet, apiPost } from '../../app/api'
import { useT } from '../../composables/useT.js'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminBadge from '../components/AdminBadge.vue'
import AdminEmpty from '../components/AdminEmpty.vue'
import AdminAlert from '../components/AdminAlert.vue'
import { copyText } from '../clipboard'

const { t } = useT()
const loading = ref(true)
const error = ref('')
const data = ref(null)
const paths = reactive({})
const savingKey = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await apiGet('/api/admin/mounts')
    for (const mount of data.value.mounts) {
      paths[mountKey(mount)] = mount.path
    }
  } catch (err) {
    error.value = t('Could not load the endpoints')
  } finally {
    loading.value = false
  }
}

function mountKey(mount) {
  return `${mount.plugin}:${mount.id}`
}

async function save(mount) {
  const key = mountKey(mount)
  savingKey.value = key
  try {
    const result = await apiPost(`/admin/mounts/${encodeURIComponent(mount.plugin)}`, {
      mount_id: mount.id,
      config_key: mount.config,
      path: paths[key],
    })
    window.Nomen.toast(result.message, result.success ? 'success' : 'error')
    if (result.success) {
      mount.path = result.path
      paths[key] = result.path
    }
  } finally {
    savingKey.value = ''
  }
}

async function copy(value, label) {
  const ok = await copyText(value)
  window.Nomen.toast(ok ? t('{:label} copied', { label }) : t('Copy failed, select it manually'), ok ? 'success' : 'error')
}

onMounted(load)
</script>

<template>
  <AdminPage :title="t('Endpoints')" :description="t('The OAuth endpoints this site exposes, plus the public paths plugins are mounted on.')">
    <AdminAlert v-if="error" type="danger" :message="error" />
    <div v-else-if="loading" class="a-loading">{{ t('Loading…') }}</div>

    <template v-else-if="data">
      <AdminCard :title="t('OAuth endpoints')">
        <div class="a-table-wrap">
          <table class="a-table">
            <thead><tr><th>{{ t('Purpose') }}</th><th>{{ t('Path') }}</th><th>{{ t('Full URL') }}</th><th>{{ t('Description') }}</th></tr></thead>
            <tbody>
              <tr v-for="endpoint in data.endpoints" :key="endpoint.path">
                <td>{{ endpoint.label }}</td>
                <td class="a-mono">{{ endpoint.path }}</td>
                <td class="a-mono">
                  {{ data.base }}{{ endpoint.path }}
                  <button class="a-btn a-btn--ghost a-btn--sm" type="button" @click="copy(data.base + endpoint.path, t('URL'))">{{ t('Copy') }}</button>
                </td>
                <td class="a-cell-summary">{{ endpoint.description }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </AdminCard>

      <AdminCard :title="t('Plugin mount points')" :description="t('Mount points are the public paths a plugin serves. Changes apply from the next request.')">
        <AdminEmpty v-if="!data.mounts.length" text="No enabled plugin registers a mount point." />
        <div v-else class="a-table-wrap">
          <table class="a-table">
            <thead><tr><th>{{ t('Plugin') }}</th><th>{{ t('Mount') }}</th><th>{{ t('Path') }}</th><th>{{ t('Access') }}</th><th class="a-td-actions">{{ t('Actions') }}</th></tr></thead>
            <tbody>
              <tr v-for="mount in data.mounts" :key="mountKey(mount)">
                <td>{{ mount.plugin }}</td>
                <td>
                  <strong>{{ mount.title }}</strong>
                  <div v-if="mount.description" class="a-sub">{{ mount.description }}</div>
                </td>
                <td>
                  <input
                    v-if="mount.config"
                    v-model="paths[mountKey(mount)]"
                    class="a-input a-input--inline a-mono"
                    type="text"
                  >
                  <span v-else class="a-mono">{{ mount.path }}</span>
                </td>
                <td><AdminBadge :tone="mount.access === 'admin' ? 'warning' : ''">{{ mount.access === 'admin' ? t('Administrators only') : t('Public') }}</AdminBadge></td>
                <td class="a-td-actions">
                  <button
                    v-if="mount.config"
                    class="a-btn a-btn--ghost a-btn--sm"
                    type="button"
                    :disabled="savingKey === mountKey(mount) || paths[mountKey(mount)] === mount.path"
                    @click="save(mount)"
                  >{{ t('Save path') }}</button>
                  <span v-else class="a-text--muted">{{ t('Cannot be changed') }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </AdminCard>
    </template>
  </AdminPage>
</template>
