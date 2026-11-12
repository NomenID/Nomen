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
import AdminAlert from '../components/AdminAlert.vue'
import AdminBadge from '../components/AdminBadge.vue'
import AdminEmpty from '../components/AdminEmpty.vue'

const { t } = useT()
const loading = ref(true)
const error = ref('')
const data = ref(null)
const versionInfo = ref(null)

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await apiGet('/api/admin/dashboard')
  } catch (err) {
    error.value = t('Could not load the overview. Refresh and try again.')
  } finally {
    loading.value = false
  }
}

async function loadVersion() {
  try {
    versionInfo.value = await apiGet('/api/admin/version')
  } catch {
    // 拉不到更新源就只显示当前版本
  }
}

onMounted(() => {
  load()
  loadVersion()
})
</script>

<template>
  <AdminPage :title="t('Overview')" :description="t('Site status, open items and recent activity.')">
    <AdminAlert v-if="error" type="danger" :message="error" />
    <div v-else-if="loading" class="a-loading">{{ t('Loading…') }}</div>

    <template v-else-if="data">
      <AdminAlert v-if="data.upgradePending" type="warning">
        {{ t('The code is at {:code} but the database records {:db}. Run {:cmd} in the project root; this notice disappears once it finishes.', { code: data.upgradePending.code, db: data.upgradePending.db || t('Not recorded'), cmd: 'php bin/nomen upgrade' }) }}
      </AdminAlert>

      <div class="a-statgrid">
        <div v-for="card in data.cards" :key="card.label" class="a-stat" :class="{ 'a-stat--alert': card.alert }">
          <div class="a-stat__value">{{ card.value }}</div>
          <div class="a-stat__label">{{ card.label }}</div>
          <div class="a-stat__hint">{{ card.hint }}</div>
        </div>
      </div>

      <div class="a-grid a-grid--2">
        <AdminCard title="Redis">
          <template #actions>
            <AdminBadge v-if="!data.redis.ok" tone="danger">{{ t('Unavailable') }}</AdminBadge>
            <AdminBadge v-else-if="data.redis.warning" tone="warning">{{ t('Memory is tight') }}</AdminBadge>
            <AdminBadge v-else tone="success">{{ t('Normal') }}</AdminBadge>
          </template>

          <dl v-if="!data.redis.ok" class="a-kv">
            <dt>{{ t('Error') }}</dt><dd>{{ data.redis.error }}</dd>
            <dt>{{ t('Impact') }}</dt><dd>{{ t('Rate limiting will silently let everything through and sign-in state may be lost. Check the Redis process and the memory on the machine first.') }}</dd>
          </dl>
          <dl v-else class="a-kv">
            <dt>{{ t('Version') }}</dt><dd>{{ data.redis.version }}</dd>
            <dt>{{ t('Memory') }}</dt><dd>{{ data.redis.used }} / {{ data.redis.max }}<template v-if="data.redis.percent !== null">{{ t('({:percent}%)', { percent: data.redis.percent }) }}</template></dd>
            <dt>{{ t('Eviction policy') }}</dt><dd>{{ data.redis.policy }}<template v-if="data.redis.policy === 'noeviction'">{{ t('(no eviction; writes fail once it is full)') }}</template></dd>
            <dt>{{ t('Keys in the current database') }}</dt><dd>{{ data.redis.keys }}</dd>
            <dt>{{ t('Session index') }}</dt><dd>{{ data.redis.index ? t('Filled') : t('Not filled; revoking sessions on other devices will scan the whole database') }}</dd>
          </dl>
        </AdminCard>

        <AdminCard :title="t('Plugin')">
          <template #actions>
            <a class="a-link" href="/special/plugins">{{ t('Details') }}</a>
          </template>
          <p class="a-text">
            {{ t('{:loaded} enabled, {:bundled} bundled with the release: {:list}', { loaded: data.plugins.loaded, bundled: data.plugins.bundled.length, list: data.plugins.bundled.join(', ') }) }}
          </p>
          <AdminAlert v-if="data.plugins.missingExt.length" type="warning">
            {{ t('Missing PHP extensions: {:list}. Related features will degrade.', { list: data.plugins.missingExt.join(', ') }) }}
          </AdminAlert>
          <p class="a-text a-text--muted">
            {{ t('Current version {:version}', { version: data.site?.version || t('Unknown') }) }}
            <template v-if="versionInfo?.latest">
              · {{ t('Latest release') }}
              <a class="a-link" :href="versionInfo.latest.url" target="_blank" rel="noopener">{{ versionInfo.latest.version }}</a>
              <AdminBadge v-if="versionInfo.updateAvailable" tone="warning">{{ t('Update available') }}</AdminBadge>
            </template>
          </p>
        </AdminCard>
      </div>

      <AdminCard :title="t('Recent activity')">
        <template #actions>
          <router-link class="a-link" to="/admin/logs">{{ t('View all') }}</router-link>
        </template>

        <AdminEmpty v-if="!data.recentLogs.length" text="Nothing has been recorded yet." />
        <div v-else class="a-table-wrap">
          <table class="a-table">
            <thead>
              <tr><th>{{ t('Time') }}</th><th>{{ t('Actor') }}</th><th>{{ t('Category') }}</th><th>{{ t('Action') }}</th><th>{{ t('Summary') }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="log in data.recentLogs" :key="log.unid">
                <td class="a-nowrap"><router-link class="a-link" :to="`/admin/logs/${log.unid}`">{{ log.created_at }}</router-link></td>
                <td>
                  <router-link v-if="log.actor_url" class="a-link" :to="log.actor_url">{{ log.actor_username || t('System') }}</router-link>
                  <span v-else>{{ log.actor_username || t('System') }}</span>
                  <AdminBadge tone="muted">{{ log.actor_label }}</AdminBadge>
                </td>
                <td>{{ log.category_label }}</td>
                <td>{{ log.action_label }}</td>
                <td class="a-cell-summary">{{ log.summary }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </AdminCard>
    </template>
  </AdminPage>
</template>
