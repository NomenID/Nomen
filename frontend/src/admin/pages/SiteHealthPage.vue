<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { onMounted, ref } from 'vue'
import { apiGet } from '../../app/api'
import { useT } from '../../composables/useT.js'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminBadge from '../components/AdminBadge.vue'
import AdminAlert from '../components/AdminAlert.vue'

const { t } = useT()
const loading = ref(true)
const error = ref('')
const data = ref(null)

const groups = [
  { status: 'critical', title: 'Critical' },
  { status: 'recommended', title: 'Recommended' },
  { status: 'good', title: 'Good' },
]

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await apiGet('/api/admin/site-health')
  } catch (err) {
    error.value = t('Could not run the site health checks')
  } finally {
    loading.value = false
  }
}

function tone(status) {
  if (status === 'critical') return 'danger'
  if (status === 'recommended') return 'warning'
  return 'success'
}

function statusText(status) {
  if (status === 'critical') return t('Critical')
  if (status === 'recommended') return t('Recommended')
  return t('Good')
}

function items(status) {
  return (data.value?.tests || []).filter((test) => test.status === status)
}

function formatted(ts) {
  return ts ? new Date(ts * 1000).toLocaleString() : ''
}

onMounted(load)
</script>

<template>
  <AdminPage :title="t('Site Health')" :description="t('Checks the server, database, and configuration. Plugins can add their own tests.')">
    <template #actions>
      <button class="a-btn" type="button" :disabled="loading" @click="load">
        {{ loading ? t('Checking…') : t('Run tests again') }}
      </button>
    </template>

    <AdminAlert v-if="error" type="danger" :message="error" />
    <div v-else-if="loading" class="a-loading">{{ t('Loading…') }}</div>

    <template v-else-if="data">
      <AdminCard :title="t('Site Health')">
        <p class="a-text">
          {{ t('Overall status:') }}
          <AdminBadge :tone="tone(data.status)">{{ statusText(data.status) }}</AdminBadge>
          <span class="a-text--muted"> · {{ t('Last checked:') }} {{ formatted(data.generatedAt) }}</span>
        </p>
      </AdminCard>

      <AdminCard v-for="group in groups" :key="group.status" :title="t(group.title)">
        <p v-if="!items(group.status).length" class="a-text--muted">{{ t('No issues in this group.') }}</p>
        <div v-else class="a-table-wrap">
          <table class="a-table">
            <thead>
              <tr>
                <th>{{ t('Test') }}</th>
                <th>{{ t('Status') }}</th>
                <th>{{ t('Details') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="test in items(group.status)" :key="test.id">
                <td>
                  <strong>{{ test.label }}</strong>
                  <div class="a-mono a-text--muted">{{ test.id }}</div>
                </td>
                <td><AdminBadge :tone="tone(test.status)">{{ statusText(test.status) }}</AdminBadge></td>
                <td>
                  {{ test.description }}
                  <div v-if="test.value" class="a-mono a-text--muted">{{ test.value }}</div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </AdminCard>
    </template>
  </AdminPage>
</template>
