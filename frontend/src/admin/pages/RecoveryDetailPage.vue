<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { apiGet, apiPost } from '../../app/api'
import { useT } from '../../composables/useT.js'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminBadge from '../components/AdminBadge.vue'
import AdminEmpty from '../components/AdminEmpty.vue'
import AdminAlert from '../components/AdminAlert.vue'

const { t } = useT()
const route = useRoute()
const loading = ref(true)
const error = ref('')
const data = ref(null)
const note = ref('')
const busy = ref('')

const statusTone = {
  pending: 'warning',
  approved: 'primary',
  rejected: 'danger',
  completed: 'success',
}
const statusLabel = {
  pending: 'Pending',
  approved: 'Approved',
  rejected: 'Rejected',
  completed: 'Completed',
}

const requestId = computed(() => String(route.params.id))

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await apiGet(`/api/admin/recovery/${requestId.value}`)
    note.value = data.value.request.admin_note || ''
  } catch (err) {
    error.value = t('The request does not exist or could not be loaded')
  } finally {
    loading.value = false
  }
}

async function act(mode) {
  if (mode === 'reject' && !note.value.trim()) {
    window.Nomen.toast(t('A reason is required when rejecting (it is stored on the request)'), 'error')
    return
  }
  const labels = { approve: t('Approve request'), reject: t('Reject request'), resend: t('Resend link') }
  const ok = await window.Nomen.confirm(
    labels[mode],
    mode === 'reject' ? t('Reject this request?') : t('Continue? The requester will receive an email.'),
    { confirmText: labels[mode], cancelText: t('Cancel') },
  )
  if (!ok) return

  busy.value = mode
  try {
    const result = await apiPost(`/admin/recovery/${mode}`, {
      request_unid: requestId.value,
      admin_note: note.value,
    })
    window.Nomen.toast(result.message, result.success ? 'success' : 'error')
    if (result.success) await load()
  } finally {
    busy.value = ''
  }
}

function matchLabel(value) {
  if (value === null || value === undefined || value === '') return t('No record on the account')
  return Number(value) === 1 ? t('Matches') : t('Does not match')
}

function matchTone(value) {
  if (value === null || value === undefined || value === '') return 'muted'
  return Number(value) === 1 ? 'success' : 'danger'
}

function humanSize(bytes) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

onMounted(load)
</script>

<template>
  <AdminPage :title="t('Request #{:id}', { id: requestId })" :description="t('Check the details, the evidence and the account timeline.')">
    <template #actions>
      <router-link class="a-btn a-btn--ghost" to="/admin/recovery">{{ t('Back to list') }}</router-link>
    </template>

    <AdminAlert v-if="error" type="danger" :message="error" />
    <div v-else-if="loading" class="a-loading">{{ t('Loading…') }}</div>

    <template v-else-if="data">
      <div class="a-profile">
        <div class="a-profile__avatar">#</div>
        <div>
          <h2>{{ data.request.username }}</h2>
          <p class="a-text a-text--muted">{{ t('Submitted at {:time} · contact email {:email}', { time: data.request.created_at, email: data.request.contact_email }) }}</p>
          <div class="a-badges">
            <AdminBadge :tone="statusTone[data.request.status] || ''">{{ t(statusLabel[data.request.status] || data.request.status) }}</AdminBadge>
            <AdminBadge v-if="data.request.reviewed_at" tone="muted">{{ t('Reviewed at {:time}', { time: data.request.reviewed_at }) }}</AdminBadge>
          </div>
        </div>
      </div>

      <div class="a-grid a-grid--2">
        <AdminCard :title="t('Request details')">
          <dl class="a-kv">
            <dt>{{ t('Account') }}</dt><dd><router-link v-if="data.user.unid" class="a-link" :to="`/admin/users/${data.user.unid}`">{{ data.request.username }}</router-link><span v-else>{{ data.request.username }}</span></dd>
            <dt>{{ t('Account email') }}</dt><dd>{{ data.request.account_email || '—' }}</dd>
            <dt>{{ t('Contact email') }}</dt><dd>{{ data.request.contact_email }}</dd>
            <dt>{{ t('Date of birth') }}</dt>
            <dd>{{ data.request.birth_date_input || '—' }} <AdminBadge :tone="matchTone(data.request.birth_date_match)">{{ matchLabel(data.request.birth_date_match) }}</AdminBadge></dd>
            <dt>{{ t('Gender') }}</dt>
            <dd>{{ data.request.gender_input || '—' }} <AdminBadge :tone="matchTone(data.request.gender_match)">{{ matchLabel(data.request.gender_match) }}</AdminBadge></dd>
            <dt>{{ t('Submitted from IP') }}</dt><dd class="a-mono">{{ data.request.ip_address || '—' }}</dd>
            <dt>{{ t('User agent') }}</dt><dd class="a-sub">{{ data.request.user_agent || '—' }}</dd>
          </dl>
          <p class="a-text a-text--muted">{{ t('What the requester wrote') }}</p>
          <p class="a-text a-preline">{{ data.request.description || t('(left blank)') }}</p>
        </AdminCard>

        <AdminCard :title="t('Account details')">
          <dl class="a-kv">
            <dt>{{ t('Username') }}</dt><dd>{{ data.user.username || '—' }}</dd>
            <dt>{{ t('Email') }}</dt><dd>{{ data.user.email || '—' }} <AdminBadge :tone="Number(data.user.email_verified) === 1 ? 'success' : 'muted'">{{ Number(data.user.email_verified) === 1 ? t('Verified') : t('Unverified') }}</AdminBadge></dd>
            <dt>{{ t('Status') }}</dt><dd><AdminBadge :tone="Number(data.user.status) === 1 ? 'success' : 'danger'">{{ Number(data.user.status) === 1 ? t('Normal') : t('Disabled') }}</AdminBadge></dd>
            <dt>{{ t('Registered at') }}</dt><dd>{{ data.user.created_at || '—' }}</dd>
            <dt>{{ t('Last sign-in') }}</dt><dd>{{ data.user.last_login_at || t('Never signed in') }}</dd>
            <dt>{{ t('Link lifetime') }}</dt><dd>{{ t('{:days} days', { days: data.linkTtlDays }) }}</dd>
            <template v-if="data.request.recovery_token_expires_at">
              <dt>{{ t('Current link') }}</dt><dd>{{ data.request.recovery_token_expires_at }}<template v-if="data.request.recovery_token_used_at"> {{ t('(already used)') }}</template></dd>
            </template>
          </dl>
        </AdminCard>
      </div>

      <AdminCard :title="t('Review')">
        <label class="a-field">
          <span class="a-field__label">{{ t('Administrator note') }}</span>
          <textarea v-model="note" class="a-input a-textarea" rows="3" :placeholder="t('Required when rejecting (not sent to the user)')"></textarea>
        </label>
        <div class="a-form__actions">
          <button class="a-btn a-btn--primary" type="button" :disabled="!!busy" @click="act('approve')">{{ busy === 'approve' ? t('Working…') : t('Approve request') }}</button>
          <button class="a-btn a-btn--ghost" type="button" :disabled="!!busy" @click="act('resend')">{{ busy === 'resend' ? t('Working…') : t('Resend link') }}</button>
          <button class="a-btn a-btn--danger" type="button" :disabled="!!busy" @click="act('reject')">{{ busy === 'reject' ? t('Working…') : t('Reject request') }}</button>
        </div>
      </AdminCard>

      <AdminCard :title="t('Evidence')">
        <AdminEmpty v-if="!data.attachments.length" text="No evidence was uploaded." />
        <div v-else class="a-attachments">
          <a v-for="file in data.attachments" :key="file.name" :href="file.url" target="_blank" rel="noopener" class="a-attachment">
            <img :src="file.url" :alt="file.name" loading="lazy">
            <span>{{ file.seq }} · {{ humanSize(file.size) }} · {{ file.mime }}</span>
          </a>
        </div>
      </AdminCard>

      <AdminCard :title="t('Recent activity on the account')">
        <AdminEmpty v-if="!data.recentLogs.length" text="No activity was recorded." />
        <div v-else class="a-table-wrap">
          <table class="a-table">
            <thead><tr><th>{{ t('Time') }}</th><th>{{ t('Category') }}</th><th>{{ t('Action') }}</th><th>{{ t('Summary') }}</th><th>{{ t('Device') }}</th></tr></thead>
            <tbody>
              <tr v-for="log in data.recentLogs" :key="log.unid">
                <td class="a-nowrap"><router-link class="a-link" :to="`/admin/logs/${log.unid}`">{{ log.created_at }}</router-link></td>
                <td>{{ log.category_label }}</td>
                <td>{{ log.action_label }}</td>
                <td class="a-cell-summary">{{ log.summary }}</td>
                <td>{{ log.device_name || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </AdminCard>
    </template>
  </AdminPage>
</template>
