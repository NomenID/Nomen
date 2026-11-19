<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed, onMounted, ref } from 'vue'
import { apiGet, apiPost } from '../../app/api'
import { useT } from '../../composables/useT.js'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminBadge from '../components/AdminBadge.vue'
import AdminAlert from '../components/AdminAlert.vue'

const { t } = useT()
const loading = ref(true)
const error = ref('')
const data = ref(null)
const busy = ref('')

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

async function save(action, id) {
  busy.value = id
  error.value = ''
  try {
    const result = await apiPost(`/admin/site-health/${action}`, { id })
    if (result.success) {
      await load()
    } else {
      error.value = result.message || t('Could not save the ignored checks.')
    }
  } catch (err) {
    error.value = t('Could not save the ignored checks.')
  } finally {
    busy.value = ''
  }
}

function ignore(id) {
  return save('ignore', id)
}

function restore(id) {
  return save('unignore', id)
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

const visibleGroups = computed(() => groups.filter((group) => items(group.status).length))

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

      <AdminCard v-for="group in visibleGroups" :key="group.status" :title="t(group.title)">
        <div class="a-health-list">
          <details v-for="test in items(group.status)" :key="test.id" class="a-health-item">
            <summary class="a-health-item__summary">
              <span class="a-health-item__main">
                <strong>{{ test.label }}</strong>
                <span class="a-mono a-text--muted">{{ test.id }}</span>
              </span>
              <span class="a-health-item__hint">{{ test.description }}</span>
              <AdminBadge :tone="tone(test.status)">{{ statusText(test.status) }}</AdminBadge>
            </summary>
            <div class="a-health-item__body">
              <p class="a-text">{{ test.description }}</p>
              <p v-if="test.value" class="a-mono a-text--muted">{{ test.value }}</p>
              <p v-if="test.links && test.links.length" class="a-text a-text--muted">
                {{ t('References:') }}
                <template v-for="(link, index) in test.links" :key="link.url">
                  <span v-if="index > 0"> · </span>
                  <a class="a-link" :href="link.url" target="_blank" rel="noopener noreferrer">{{ link.label }}</a>
                </template>
              </p>
              <div class="a-health-item__actions">
                <button
                  class="a-btn a-btn--ghost a-btn--sm"
                  type="button"
                  :disabled="busy === test.id"
                  @click="ignore(test.id)"
                >
                  {{ t('Ignore this check') }}
                </button>
              </div>
            </div>
          </details>
        </div>
      </AdminCard>

      <AdminCard v-if="data.ignored && data.ignored.length" :title="t('Ignored checks')">
        <ul class="a-health-ignored">
          <li v-for="item in data.ignored" :key="item.id">
            <span class="a-health-ignored__label">
              <strong>{{ item.label }}</strong>
              <span class="a-mono a-text--muted">{{ item.id }}</span>
            </span>
            <button
              class="a-btn a-btn--ghost a-btn--sm"
              type="button"
              :disabled="busy === item.id"
              @click="restore(item.id)"
            >
              {{ t('Restore') }}
            </button>
          </li>
        </ul>
      </AdminCard>
    </template>
  </AdminPage>
</template>
