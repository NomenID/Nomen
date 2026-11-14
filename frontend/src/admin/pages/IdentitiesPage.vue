<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { apiGet, apiPost } from '../../app/api'
import { useT } from '../../composables/useT.js'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminBadge from '../components/AdminBadge.vue'
import AdminEmpty from '../components/AdminEmpty.vue'
import AdminAlert from '../components/AdminAlert.vue'
import { copyText } from '../clipboard'

const { t } = useT()
const router = useRouter()
const loading = ref(true)
const error = ref('')
const data = ref(null)
const busyId = ref(0)
const methodBusy = ref('')
const defaultBusy = ref(false)

const methodRows = [
  { key: 'password', label: 'Password sign-in', desc: 'Username or email plus a password. Turning it off also disables password registration.', defaultable: true },
  { key: 'email_code', label: 'Email code sign-in', desc: 'Requires outgoing mail to be configured.', defaultable: true },
  { key: 'biometric', label: 'Biometric (passkey)', desc: 'Device fingerprint, face or Windows Hello.', defaultable: true },
  { key: 'temp_key', label: 'Temp key', desc: 'The /fast-pass fallback channel.', defaultable: false },
  { key: 'oauth', label: 'Third-party sign-in', desc: 'Master switch; each provider can still be turned on or off separately.', defaultable: true },
]

const defaultOptions = computed(() => {
  if (!data.value) return []
  const options = []
  for (const row of methodRows) {
    if (!row.defaultable) continue
    const on = !!data.value.methods[row.key]
    const usable = row.key === 'oauth' ? on && data.value.providers_enabled > 0 : on
    options.push({
      id: row.key,
      label: t(row.label),
      desc: t(row.desc),
      usable,
      by: '',
    })
  }
  for (const extra of data.value.extra_methods || []) {
    options.push({
      id: extra.id,
      label: extra.label,
      desc: t('Turn it on or off under Plugins.'),
      usable: true,
      by: extra.by,
    })
  }
  return options
})

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await apiGet('/api/admin/identities')
  } catch (err) {
    error.value = t('Could not load the sign-in methods')
  } finally {
    loading.value = false
  }
}

async function setDefault(option) {
  if (!option.usable || data.value.default_method === option.id || defaultBusy.value) return
  defaultBusy.value = true
  try {
    const result = await apiPost('/admin/identities/default-method', { method: option.id })
    if (result.success) {
      data.value.default_method = result.default_method
      window.Nomen.toast(result.message, 'success')
    } else {
      window.Nomen.toast(result.message || t('Operation failed'), 'error')
    }
  } finally {
    defaultBusy.value = false
  }
}

async function toggleMethod(row) {
  methodBusy.value = row.key
  try {
    const result = await apiPost('/admin/identities/toggle-method', {
      method: row.key,
      on: data.value.methods[row.key] ? '0' : '1',
    })
    if (result.success) {
      data.value.methods = result.methods
      window.Nomen.toast(result.message, 'success')
    } else {
      window.Nomen.toast(result.message || t('Operation failed'), 'error')
    }
  } finally {
    methodBusy.value = ''
  }
}

async function toggle(provider) {
  busyId.value = provider.id
  try {
    const result = await apiPost('/admin/identities/toggle', { id: provider.id })
    if (result.success) {
      provider.enabled = result.enabled
      window.Nomen.toast(result.message, 'success')
    } else {
      window.Nomen.toast(result.message || t('Operation failed'), 'error')
    }
  } finally {
    busyId.value = 0
  }
}

async function remove(provider) {
  const ok = await window.Nomen.confirm(
    t('Delete sign-in method'),
    t('After deleting {:name}, users who bound it can no longer sign in with it.', { name: provider.name }),
    { confirmText: t('Delete'), cancelText: t('Cancel') },
  )
  if (!ok) return
  busyId.value = provider.id
  try {
    const result = await apiPost('/admin/identities/delete', { id: provider.id })
    if (result.success) {
      window.Nomen.toast(result.message, 'success')
      await load()
    } else {
      window.Nomen.toast(result.message || t('Delete failed'), 'error')
    }
  } finally {
    busyId.value = 0
  }
}

async function copy(value, label) {
  const ok = await copyText(value)
  window.Nomen.toast(ok ? t('{:label} copied', { label }) : t('Copy failed, select it manually'), ok ? 'success' : 'error')
}

onMounted(load)
</script>

<template>
  <AdminPage :title="t('Sign-in methods')" :description="t('Let users sign in with GitHub, Google, Microsoft, CaelLabID or any other standard OAuth2 provider.')">
    <template #actions>
      <button class="a-btn a-btn--primary" type="button" @click="router.push('/admin/identities/new')">{{ t('Add sign-in method') }}</button>
    </template>

    <AdminAlert v-if="error" type="danger" :message="error" />
    <div v-else-if="loading" class="a-loading">{{ t('Loading…') }}</div>

    <template v-else-if="data">
      <AdminCard
        :title="t('Sign-in switches')"
        :description="t('Master switches for the five kinds of sign-in. Turning all of them off is refused; if you do get locked out, run php bin/nomen auth:reset on the server to turn everything back on.')"
      >
        <div class="a-table-wrap">
          <table class="a-table">
            <thead><tr><th>{{ t('Method') }}</th><th>{{ t('Description') }}</th><th>{{ t('Status') }}</th><th class="a-td-actions">{{ t('Actions') }}</th></tr></thead>
            <tbody>
              <tr v-for="row in methodRows" :key="row.key">
                <td class="a-strong">{{ t(row.label) }}</td>
                <td>{{ t(row.desc) }}</td>
                <td>
                  <AdminBadge :tone="data.methods[row.key] ? 'success' : 'muted'">{{ data.methods[row.key] ? t('On') : t('Off') }}</AdminBadge>
                </td>
                <td class="a-td-actions">
                  <button class="a-btn a-btn--ghost a-btn--sm" type="button" :disabled="methodBusy === row.key" @click="toggleMethod(row)">
                    {{ data.methods[row.key] ? t('Turn off') : t('Turn on') }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="a-text a-text--muted" style="margin-top:10px">{{ t('The third-party buttons also need at least one enabled provider; each provider can be turned on or off below.') }}</p>
      </AdminCard>

      <AdminCard
        :title="t('Default sign-in method')"
        :description="t('What the sign-in page shows first. Devices that have signed in before still start with the method they used last time.')"
      >
        <div class="a-radios">
          <label
            v-for="option in defaultOptions"
            :key="option.id"
            class="a-radio"
            :class="{ 'is-active': data.default_method === option.id }"
          >
            <input
              type="radio"
              name="default-method"
              :value="option.id"
              :checked="data.default_method === option.id"
              :disabled="!option.usable || defaultBusy"
              @change="setDefault(option)"
            >
            <span>
              <strong>{{ option.label }}</strong>
              <span class="a-sub a-block">
                {{ option.by ? t('Provided by the plugin {:name}.', { name: option.by }) + ' ' + option.desc : option.desc }}
              </span>
              <span v-if="!option.usable" class="a-sub a-block">{{ t('Turn it on above to make it the default.') }}</span>
            </span>
            <AdminBadge v-if="data.default_method === option.id" tone="success">{{ t('Default') }}</AdminBadge>
          </label>
        </div>
        <p v-if="(data.extra_methods || []).length" class="a-text a-text--muted" style="margin-top:10px">
          {{ t('Sign-in methods registered by plugins are listed here too; the plugin page controls whether they are on.') }}
        </p>
      </AdminCard>

      <AdminCard :padded="false">
        <AdminEmpty v-if="!data.providers.length" text="No third-party sign-in is configured yet." />
        <div v-else class="a-table-wrap">
          <table class="a-table">
            <thead><tr><th>{{ t('Name') }}</th><th>{{ t('Slug') }}</th><th>{{ t('Type') }}</th><th>{{ t('Callback URL') }}</th><th>{{ t('Status') }}</th><th class="a-td-actions">{{ t('Actions') }}</th></tr></thead>
            <tbody>
              <tr v-for="provider in data.providers" :key="provider.id">
                <td class="a-strong">
                  <span class="a-appcell">
                    <img v-if="provider.logo" :src="provider.logo" alt="" class="a-provider-logo">
                    <span>{{ provider.name }}</span>
                  </span>
                </td>
                <td class="a-mono">{{ provider.slug }}</td>
                <td>{{ provider.type }}</td>
                <td>
                  <span class="a-mono a-break">{{ provider.callback_url }}</span>
                  <button class="a-btn a-btn--ghost a-btn--sm" type="button" @click="copy(provider.callback_url, t('Callback URL'))">{{ t('Copy') }}</button>
                </td>
                <td>
                  <AdminBadge :tone="Number(provider.enabled) === 1 ? 'success' : 'muted'">{{ Number(provider.enabled) === 1 ? t('Enabled') : t('Disabled') }}</AdminBadge>
                </td>
                <td class="a-td-actions">
                  <button class="a-btn a-btn--ghost a-btn--sm" type="button" @click="router.push(`/admin/identities/${provider.id}/edit`)">{{ t('Edit') }}</button>
                  <button class="a-btn a-btn--ghost a-btn--sm" type="button" :disabled="busyId === provider.id" @click="toggle(provider)">
                    {{ Number(provider.enabled) === 1 ? t('Disable') : t('Enable') }}
                  </button>
                  <button class="a-btn a-btn--danger a-btn--sm" type="button" :disabled="busyId === provider.id" @click="remove(provider)">{{ t('Delete') }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </AdminCard>

      <AdminCard :title="t('How to fill in the callback URL')">
        <p class="a-text">{{ t('Paste each callback URL into the provider OAuth app settings exactly, without adding or dropping a slash.') }}</p>
        <p class="a-text a-text--muted">{{ t('The CaelLabID callback allowlist requires no .php suffix; this program routes without suffixes anyway.') }}</p>
      </AdminCard>
    </template>
  </AdminPage>
</template>
