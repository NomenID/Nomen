<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { apiGet, apiPost, apiPostForm } from '../../app/api'
import { useT } from '../../composables/useT.js'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminBadge from '../components/AdminBadge.vue'
import AdminEmpty from '../components/AdminEmpty.vue'
import AdminAlert from '../components/AdminAlert.vue'
import AdminField from '../components/AdminField.vue'
import { copyText } from '../clipboard'

const { t } = useT()
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const data = ref(null)
const editing = ref(false)
const editingHasSecret = ref(false)
const busyId = ref(0)
const methodBusy = ref('')
const logoFile = ref(null)
const logoClear = ref(false)

const methodRows = [
  { key: 'password', label: 'Password sign-in', desc: 'Username or email plus a password. Turning it off also disables password registration.' },
  { key: 'email_code', label: 'Email code sign-in', desc: 'Requires outgoing mail to be configured.' },
  { key: 'biometric', label: 'Biometric (passkey)', desc: 'Device fingerprint, face or Windows Hello.' },
  { key: 'temp_key', label: 'Temp key', desc: 'The /fast-pass fallback channel.' },
  { key: 'oauth', label: 'Third-party sign-in', desc: 'Master switch; each provider can still be turned on or off separately.' },
]

const emptyForm = () => ({
  id: 0,
  slug: '',
  name: '',
  logo: '',
  type: 'oauth2',
  client_id: '',
  client_secret: '',
  authorize_url: '',
  token_url: '',
  userinfo_url: '',
  emails_url: '',
  scopes: '',
  subject_field: 'sub',
  email_field: 'email',
  name_field: 'name',
  avatar_field: 'picture',
  email_verified_field: '',
  token_auth: 'post',
  extra_authorize_params: '',
  enabled: true,
  sort_order: 0,
})

const form = reactive(emptyForm())

const callbackUrl = computed(() => {
  const slug = String(form.slug || '').trim()
  return `${window.location.origin}/auth/${slug || '…'}/callback`
})

const logoPreviewUrl = ref('')

function onLogoChange(event) {
  const file = event.target.files?.[0] || null
  logoFile.value = file
  if (logoPreviewUrl.value) URL.revokeObjectURL(logoPreviewUrl.value)
  logoPreviewUrl.value = file ? URL.createObjectURL(file) : ''
}

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

function startCreate() {
  Object.assign(form, emptyForm())
  logoFile.value = null
  logoClear.value = false
  editing.value = true
}

function startEdit(provider) {
  Object.assign(form, emptyForm(), provider)
  form.client_secret = ''
  form.enabled = Number(provider.enabled) === 1
  editingHasSecret.value = !!provider.has_secret
  logoFile.value = null
  logoClear.value = false
  editing.value = true
}

function applyPreset() {
  const preset = data.value?.presets?.[form.slug]
  if (!preset) return
  Object.assign(form, preset, {
    slug: form.slug,
    client_id: form.client_id,
    client_secret: form.client_secret,
    enabled: true,
    sort_order: form.sort_order,
  })
  form.enabled = Number(preset.enabled ?? 1) === 1
}

async function save() {
  saving.value = true
  try {
    const fd = new FormData()
    for (const [key, value] of Object.entries(form)) {
      if (key === 'logo') continue
      fd.set(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value ?? ''))
    }
    if (logoFile.value) {
      fd.set('logo', logoFile.value)
    } else if (logoClear.value) {
      fd.set('logo_clear', '1')
    } else {
      fd.set('logo', form.logo || '')
    }
    const result = await apiPostForm('/admin/identities/save', fd)
    if (result.success) {
      window.Nomen.toast(result.message, 'success')
      editing.value = false
      await load()
    } else {
      error.value = result.message || t('Save failed')
    }
  } finally {
    saving.value = false
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

onMounted(load)
</script>

<template>
  <AdminPage :title="t('Sign-in methods')" :description="t('Let users sign in with GitHub, Google, Microsoft, CaelLabID or any other standard OAuth2 provider.')">
    <template #actions>
      <button class="a-btn a-btn--primary" type="button" @click="startCreate">{{ t('Add sign-in method') }}</button>
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

      <AdminCard v-if="editing" :title="form.id > 0 ? t('Edit sign-in method') : t('Add sign-in method')">
        <form class="a-form" @submit.prevent="save">
          <div class="a-form__row">
            <AdminField v-if="form.id === 0" :label="t('Preset')" :hint="t('Pick a preset to fill in the URLs and fields; you can still change them')">
              <select v-model="form.slug" class="a-input" @change="applyPreset">
                <option value="">{{ t('Custom OAuth2') }}</option>
                <option v-for="(preset, key) in data.presets" :key="key" :value="key">{{ preset.name }}</option>
              </select>
            </AdminField>
            <AdminField :label="t('Slug')" :hint="t('Used in the callback URL; lowercase letters, digits and hyphens only')">
              <input v-model="form.slug" class="a-input a-mono" type="text" maxlength="32" required>
            </AdminField>
            <AdminField :label="t('Display name')">
              <input v-model="form.name" class="a-input" type="text" maxlength="64" required>
            </AdminField>
          </div>

          <div class="a-form__row">
            <AdminField :label="t('Callback URL')" :hint="t('In the provider OAuth app settings, paste this address into the redirect URI field exactly, character for character')">
              <div class="a-kv">
                <span class="a-mono a-break">{{ callbackUrl }}</span>
                <button v-if="form.slug" class="a-btn a-btn--ghost a-btn--sm" type="button" @click="copy(callbackUrl, t('Callback URL'))">{{ t('Copy') }}</button>
              </div>
            </AdminField>
            <AdminField
              :label="t('Icon')"
              :hint="form.logo ? t('Current: {:value}; pick a new file to replace it, or check the box to clear it and fall back to the initial letter', { value: form.logo }) : t('Optional; PNG / JPG / WebP, square works best. Preset providers ship their own icons.')"
            >
              <div class="a-kv">
                <img v-if="logoPreviewUrl || form.logo" :src="logoPreviewUrl || form.logo" alt="" class="a-provider-logo a-provider-logo--lg">
                <input class="a-input a-input--file" type="file" accept="image/png,image/jpeg,image/webp" @change="onLogoChange">
              </div>
              <label v-if="form.logo" class="a-check" style="margin-top:8px">
                <input v-model="logoClear" type="checkbox">
                <span>{{ t('Clear the icon') }}</span>
              </label>
            </AdminField>
          </div>

          <div class="a-form__row">
            <AdminField label="Client ID">
              <input v-model="form.client_id" class="a-input a-mono" type="text" required>
            </AdminField>
            <AdminField label="Client Secret" :hint="form.id > 0 && editingHasSecret ? t('Already saved; leave it empty to keep it') : t('Public clients can leave it empty')">
              <input v-model="form.client_secret" class="a-input a-mono" type="password" autocomplete="new-password">
            </AdminField>
          </div>

          <div class="a-form__row">
            <AdminField :label="t('Authorization URL')">
              <input v-model="form.authorize_url" class="a-input a-mono" type="url" required>
            </AdminField>
            <AdminField :label="t('Token URL')">
              <input v-model="form.token_url" class="a-input a-mono" type="url" required>
            </AdminField>
          </div>

          <div class="a-form__row">
            <AdminField :label="t('User info URL')">
              <input v-model="form.userinfo_url" class="a-input a-mono" type="url" required>
            </AdminField>
            <AdminField :label="t('Email list URL')" :hint="t('Optional. GitHub needs it to confirm an email is verified')">
              <input v-model="form.emails_url" class="a-input a-mono" type="url">
            </AdminField>
          </div>

          <div class="a-form__row">
            <AdminField label="Scope" :hint="t('As the provider requires; separate several with spaces')">
              <input v-model="form.scopes" class="a-input a-mono" type="text">
            </AdminField>
            <AdminField :label="t('Token request style')">
              <select v-model="form.token_auth" class="a-input">
                <option value="post">{{ t('client_id / secret in the form body') }}</option>
                <option value="basic">{{ t('HTTP Basic authentication') }}</option>
              </select>
            </AdminField>
          </div>

          <div class="a-form__row">
            <AdminField :label="t('Subject field')" :hint="t('Field name in the user info JSON; a.b paths are supported')">
              <input v-model="form.subject_field" class="a-input a-mono" type="text">
            </AdminField>
            <AdminField :label="t('Email field')">
              <input v-model="form.email_field" class="a-input a-mono" type="text">
            </AdminField>
          </div>

          <div class="a-form__row">
            <AdminField :label="t('Name field')">
              <input v-model="form.name_field" class="a-input a-mono" type="text">
            </AdminField>
            <AdminField :label="t('Avatar field')">
              <input v-model="form.avatar_field" class="a-input a-mono" type="text">
            </AdminField>
          </div>

          <div class="a-form__row">
            <AdminField :label="t('Email verified field')" :hint="t('Leave empty to distrust the verification state the provider reports (accounts with the same email are not linked automatically)')">
              <input v-model="form.email_verified_field" class="a-input a-mono" type="text">
            </AdminField>
            <AdminField :label="t('Extra authorization parameters')" :hint="t('Query string format, for example prompt=consent')">
              <input v-model="form.extra_authorize_params" class="a-input a-mono" type="text">
            </AdminField>
          </div>

          <div class="a-form__row">
            <AdminField :label="t('Sort order')">
              <input v-model.number="form.sort_order" class="a-input" type="number" step="1">
            </AdminField>
            <AdminField :label="t('Status')">
              <label class="a-check">
                <input v-model="form.enabled" type="checkbox">
                <span>{{ t('Show on the sign-in page') }}</span>
              </label>
            </AdminField>
          </div>

          <div class="a-form__actions">
            <button class="a-btn a-btn--primary" type="submit" :disabled="saving">{{ saving ? t('Saving…') : t('Save') }}</button>
            <button class="a-btn a-btn--ghost" type="button" @click="editing = false">{{ t('Cancel') }}</button>
          </div>
        </form>
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
                  <button class="a-btn a-btn--ghost a-btn--sm" type="button" @click="startEdit(provider)">{{ t('Edit') }}</button>
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
