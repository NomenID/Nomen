<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { apiGet } from '../../app/api'
import { useT } from '../../composables/useT.js'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminBadge from '../components/AdminBadge.vue'
import AdminEmpty from '../components/AdminEmpty.vue'
import AdminAlert from '../components/AdminAlert.vue'
import { copyText } from '../clipboard'

const { t } = useT()
const route = useRoute()
const loading = ref(true)
const error = ref('')
const log = ref(null)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const data = await apiGet(`/api/admin/logs/${route.params.id}`)
    log.value = data.log
  } catch (err) {
    error.value = t('Could not load this log entry')
  } finally {
    loading.value = false
  }
}

async function copy(value, label) {
  const ok = await copyText(value)
  window.Nomen.toast(ok ? t('{:label} copied', { label }) : t('Copy failed, select it manually'), ok ? 'success' : 'error')
}

onMounted(load)
</script>

<template>
  <AdminPage :title="t('Log details')" :description="t('The full record of this action.')">
    <template #actions>
      <router-link class="a-btn a-btn--ghost" to="/admin/logs">{{ t('Back to list') }}</router-link>
    </template>

    <AdminAlert v-if="error" type="danger" :message="error" />
    <div v-else-if="loading" class="a-loading">{{ t('Loading…') }}</div>

    <template v-else-if="log">
      <AdminCard :title="`#${log.unid}`">
        <template #actions>
          <AdminBadge>{{ log.category_label }}</AdminBadge>
          <AdminBadge tone="muted">{{ log.action }}</AdminBadge>
        </template>
        <dl class="a-kv">
          <dt>{{ t('Time') }}</dt><dd>{{ log.created_at }}</dd>
          <dt>{{ t('Actor') }}</dt>
          <dd>
            <router-link v-if="log.actor_url" class="a-link" :to="log.actor_url">{{ log.actor_username || t('System') }}</router-link>
            <span v-else>{{ log.actor_username || t('System') }}</span>
            <AdminBadge tone="muted">{{ log.actor_label }}</AdminBadge>
          </dd>
          <dt>{{ t('Action') }}</dt><dd>{{ log.action_label }} <code class="a-mono">{{ log.action }}</code></dd>
          <dt>{{ t('Target') }}</dt>
          <dd>
            <router-link v-if="log.target_url" class="a-link" :to="log.target_url">{{ log.target_name || log.target_unid }}</router-link>
            <span v-else>{{ log.target_name || log.target_unid || '—' }}</span>
            <span v-if="log.target_type_label" class="a-text--muted">{{ t('({:type})', { type: log.target_type_label }) }}</span>
          </dd>
          <dt>{{ t('Summary') }}</dt><dd>{{ log.summary || '—' }}</dd>
          <dt>IP</dt>
          <dd>
            <span class="a-mono">{{ log.ip_address || '—' }}</span>
            <button v-if="log.ip_address" class="a-btn a-btn--ghost a-btn--sm" type="button" @click="copy(log.ip_address, 'IP')">{{ t('Copy') }}</button>
          </dd>
          <dt>{{ t('Device') }}</dt><dd>{{ log.device_name || '—' }}</dd>
          <dt>User-Agent</dt><dd class="a-sub a-break">{{ log.user_agent || '—' }}</dd>
        </dl>
      </AdminCard>

      <AdminCard :title="t('Changed fields')">
        <AdminEmpty v-if="!log.changes.length" text="This action changed no fields." />
        <div v-else class="a-table-wrap">
          <table class="a-table">
            <thead><tr><th>{{ t('Field') }}</th><th>{{ t('Old value') }}</th><th>{{ t('New value') }}</th></tr></thead>
            <tbody>
              <tr v-for="row in log.changes" :key="row.field">
                <td>{{ row.label }}</td>
                <td class="a-diff-old a-preline">{{ row.old }}</td>
                <td class="a-diff-new a-preline">{{ row.new }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </AdminCard>
    </template>
  </AdminPage>
</template>
