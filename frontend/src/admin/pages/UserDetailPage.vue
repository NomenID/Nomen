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
const busy = ref(false)

const userId = computed(() => String(route.params.id))

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await apiGet(`/api/admin/users/${userId.value}`)
  } catch (err) {
    error.value = t('The user does not exist or could not be loaded')
  } finally {
    loading.value = false
  }
}

async function toggleStatus() {
  const user = data.value.user
  const disabling = Number(user.status) === 1
  const ok = await window.Nomen.confirm(
    disabling ? t('Disable account') : t('Enable account'),
    disabling
      ? t('After disabling, {:name} cannot sign in. Continue?', { name: user.username })
      : t('Allow {:name} to sign in again?', { name: user.username }),
    { confirmText: disabling ? t('Disable') : t('Enable'), cancelText: t('Cancel') },
  )
  if (!ok) return
  busy.value = true
  try {
    const result = await apiPost('/admin/users/toggle-status', { user_unid: user.unid })
    window.Nomen.toast(result.message, result.success ? 'success' : 'error')
    if (result.success) await load()
  } finally {
    busy.value = false
  }
}

async function toggleAdmin() {
  const user = data.value.user
  const granting = user.role !== 'admin'
  const ok = await window.Nomen.confirm(
    granting ? t('Grant administrator') : t('Revoke administrator'),
    granting
      ? t('Make {:name} an administrator?', { name: user.username })
      : t('Remove {:name} from administrators?', { name: user.username }),
    { confirmText: granting ? t('Grant') : t('Revoke'), cancelText: t('Cancel') },
  )
  if (!ok) return
  busy.value = true
  try {
    const result = await apiPost('/admin/users/toggle-admin', { user_unid: user.unid })
    window.Nomen.toast(result.message, result.success ? 'success' : 'error')
    if (result.success) await load()
  } finally {
    busy.value = false
  }
}

const methodEntries = computed(() => Object.entries(data.value?.methods || {}))
const assistEntries = computed(() => Object.entries(data.value?.assistMethods || {}))

onMounted(load)
</script>

<template>
  <AdminPage :title="data ? t('User · {:name}', { name: data.user.username }) : t('User details')" :description="t('Account details, sign-in methods, authorizations and recent activity.')">
    <template #actions>
      <router-link class="a-btn a-btn--ghost" to="/admin/users">{{ t('Back to list') }}</router-link>
      <template v-if="data">
        <button class="a-btn a-btn--ghost" type="button" :disabled="busy" @click="toggleStatus">
          {{ Number(data.user.status) === 1 ? t('Disable account') : t('Enable account') }}
        </button>
        <button class="a-btn a-btn--ghost" type="button" :disabled="busy || data.isSelf" @click="toggleAdmin">
          {{ data.user.role === 'admin' ? t('Revoke administrator') : t('Grant administrator') }}
        </button>
      </template>
    </template>

    <AdminAlert v-if="error" type="danger" :message="error" />
    <div v-else-if="loading" class="a-loading">{{ t('Loading…') }}</div>

    <template v-else-if="data">
      <div class="a-profile">
        <div class="a-profile__avatar">{{ (data.user.username || '?').slice(0, 1).toUpperCase() }}</div>
        <div>
          <h2>{{ data.user.display_name || data.user.username }}</h2>
          <p class="a-text a-text--muted">@{{ data.user.username }} · UNID {{ data.user.unid }}</p>
          <div class="a-badges">
            <AdminBadge :tone="data.user.role === 'admin' ? 'primary' : ''">{{ data.user.role === 'admin' ? t('Administrator') : t('Regular user') }}</AdminBadge>
            <AdminBadge :tone="Number(data.user.status) === 1 ? 'success' : 'danger'">{{ Number(data.user.status) === 1 ? t('Normal') : t('Disabled') }}</AdminBadge>
            <AdminBadge :tone="Number(data.user.email_verified) === 1 ? 'success' : 'muted'">
              {{ Number(data.user.email_verified) === 1 ? t('Email verified') : t('Email unverified') }}
            </AdminBadge>
          </div>
        </div>
      </div>

      <div class="a-grid a-grid--2">
        <AdminCard :title="t('Account details')">
          <dl class="a-kv">
            <dt>{{ t('Email') }}</dt><dd>{{ data.user.email || '—' }}</dd>
            <dt>{{ t('Country / Region') }}</dt><dd>{{ data.user.country || '—' }}</dd>
            <dt>{{ t('Date of birth') }}</dt><dd>{{ data.user.birth_date || '—' }}</dd>
            <dt>{{ t('Gender') }}</dt><dd>{{ data.user.gender || '—' }}</dd>
            <dt>{{ t('Two-step verification') }}</dt><dd>{{ data.user.two_factor_policy || 'off' }}</dd>
            <dt>{{ t('Registered at') }}</dt><dd>{{ data.user.created_at }}</dd>
            <dt>{{ t('Last sign-in') }}</dt><dd>{{ data.user.last_login_at || t('Never signed in') }}<template v-if="data.user.last_login_ip"> · <span class="a-mono">{{ data.user.last_login_ip }}</span></template></dd>
          </dl>
        </AdminCard>

        <AdminCard :title="t('Sign-in methods')">
          <dl class="a-kv">
            <template v-for="[key, method] in methodEntries" :key="key">
              <dt>{{ method.label }}</dt>
              <dd>
                <AdminBadge :tone="method.present ? 'success' : 'muted'">{{ method.present ? t('Set up') : t('Not set') }}</AdminBadge>
              </dd>
            </template>
          </dl>
          <template v-if="assistEntries.length">
            <p class="a-text a-text--muted">{{ t('Quick assist verification methods') }}</p>
            <dl class="a-kv">
              <template v-for="[key, method] in assistEntries" :key="key">
                <dt>{{ method.label }}</dt>
                <dd>
                  <AdminBadge :tone="method.present ? 'success' : 'muted'">
                    {{ method.present ? (method.on || t('Bound')) : (method.off || t('Not bound')) }}
                  </AdminBadge>
                </dd>
              </template>
            </dl>
          </template>
        </AdminCard>
      </div>

      <AdminCard :title="t('Authorized apps')">
        <AdminEmpty v-if="!data.authorizations.length" text="No app has been authorized." />
        <div v-else class="a-table-wrap">
          <table class="a-table">
            <thead><tr><th>{{ t('App') }}</th><th>Client ID</th><th>{{ t('Scopes') }}</th><th>{{ t('Authorized at') }}</th></tr></thead>
            <tbody>
              <tr v-for="auth in data.authorizations" :key="auth.client_id + auth.created_at">
                <td>{{ auth.client_name || t('(deleted app)') }}</td>
                <td class="a-mono">{{ auth.client_id }}</td>
                <td>{{ auth.scopes }}</td>
                <td class="a-nowrap">{{ auth.created_at }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </AdminCard>

      <AdminCard :title="t('Account recovery history')">
        <AdminEmpty v-if="!data.recoveryHistory.length" text="No recovery requests." />
        <div v-else class="a-table-wrap">
          <table class="a-table">
            <thead><tr><th>#</th><th>{{ t('Status') }}</th><th>{{ t('Contact email') }}</th><th>{{ t('Submitted at') }}</th><th>{{ t('Reviewed at') }}</th></tr></thead>
            <tbody>
              <tr v-for="req in data.recoveryHistory" :key="req.unid">
                <td><router-link class="a-link" :to="`/admin/recovery/${req.unid}`">#{{ req.unid }}</router-link></td>
                <td><AdminBadge>{{ req.status }}</AdminBadge></td>
                <td>{{ req.contact_email }}</td>
                <td class="a-nowrap">{{ req.created_at }}</td>
                <td class="a-nowrap">{{ req.reviewed_at || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </AdminCard>

      <AdminCard v-if="data.membership || data.ownedGroups.length" :title="t('Family / guardianship')">
        <dl v-if="data.membership" class="a-kv">
          <dt>{{ t('Family') }}</dt><dd>#{{ data.membership.group_unid }} ({{ data.membership.status }})</dd>
          <dt>{{ t('Guardian') }}</dt><dd>{{ (data.familyGuardians || []).map(g => g.email || g.username || g.id).join(', ') || '—' }}</dd>
        </dl>
        <div v-if="data.ownedGroups.length">
          <p class="a-text a-text--muted">{{ t('Families where this user is the guardian:') }}</p>
          <ul class="a-list">
            <li v-for="group in data.ownedGroups" :key="group.id">
              #{{ group.unid }} · {{ group.status }}
              <span v-if="data.ownedGroupsChildren[group.id]">
                · {{ t('{:count} children', { count: data.ownedGroupsChildren[group.id].length }) }}
              </span>
            </li>
          </ul>
        </div>
      </AdminCard>

      <AdminCard :title="t('Recent activity')">
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
