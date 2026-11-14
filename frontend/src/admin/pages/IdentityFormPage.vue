<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiGet, apiPostForm } from '../../app/api'
import { useT } from '../../composables/useT.js'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminAlert from '../components/AdminAlert.vue'
import AdminField from '../components/AdminField.vue'
import { copyText } from '../clipboard'

const { t } = useT()
const route = useRoute()
const router = useRouter()
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const presets = ref({})
const editingId = ref(0)
const editingHasSecret = ref(false)
const logoFile = ref(null)
const logoClear = ref(false)
const logoPreviewUrl = ref('')

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
const isCreate = computed(() => editingId.value === 0)

const callbackUrl = computed(() => {
  const slug = String(form.slug || '').trim()
  return `${window.location.origin}/auth/${slug || '…'}/callback`
})

function onLogoChange(event) {
  const file = event.target.files?.[0] || null
  logoFile.value = file
  if (logoPreviewUrl.value) URL.revokeObjectURL(logoPreviewUrl.value)
  logoPreviewUrl.value = file ? URL.createObjectURL(file) : ''
}

function applyPreset() {
  const preset = presets.value[form.slug]
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

async function load() {
  loading.value = true
  error.value = ''
  try {
    const data = await apiGet('/api/admin/identities')
    presets.value = data.presets || {}
    const id = Number(route.params.id || 0)
    if (id > 0) {
      const provider = (data.providers || []).find((row) => Number(row.id) === id)
      if (!provider) throw new Error('not found')
      Object.assign(form, emptyForm(), provider)
      form.client_secret = ''
      form.enabled = Number(provider.enabled) === 1
      editingHasSecret.value = !!provider.has_secret
      editingId.value = id
    }
  } catch (err) {
    error.value = t('Could not load the sign-in methods')
  } finally {
    loading.value = false
  }
}

async function save() {
  error.value = ''
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
      router.push('/admin/identities')
    } else {
      error.value = result.message || t('Save failed')
    }
  } finally {
    saving.value = false
  }
}

function back() {
  router.push('/admin/identities')
}

async function copy(value, label) {
  const ok = await copyText(value)
  window.Nomen.toast(ok ? t('{:label} copied', { label }) : t('Copy failed, select it manually'), ok ? 'success' : 'error')
}

onMounted(load)
</script>

<template>
  <AdminPage
    :title="isCreate ? t('Add sign-in method') : t('Edit sign-in method')"
    :description="t('Let users sign in with GitHub, Google, Microsoft, CaelLabID or any other standard OAuth2 provider.')"
  >
    <template #actions>
      <button class="a-btn a-btn--ghost" type="button" @click="back">{{ t('Cancel') }}</button>
    </template>

    <AdminAlert v-if="error" type="danger" :message="error" />
    <div v-else-if="loading" class="a-loading">{{ t('Loading…') }}</div>

    <template v-else>
      <form class="a-form" @submit.prevent="save">
        <AdminCard :title="t('Basics')">
          <div class="a-form__row">
            <AdminField v-if="isCreate" :label="t('Preset')" :hint="t('Pick a preset to fill in the URLs and fields; you can still change them')">
              <select v-model="form.slug" class="a-input" @change="applyPreset">
                <option value="">{{ t('Custom OAuth2') }}</option>
                <option v-for="(preset, key) in presets" :key="key" :value="key">{{ preset.name }}</option>
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
        </AdminCard>

        <AdminCard :title="t('Connection')">
          <div class="a-form__row">
            <AdminField label="Client ID">
              <input v-model="form.client_id" class="a-input a-mono" type="text" required>
            </AdminField>
            <AdminField label="Client Secret" :hint="!isCreate && editingHasSecret ? t('Already saved; leave it empty to keep it') : t('Public clients can leave it empty')">
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
        </AdminCard>

        <AdminCard :title="t('Field mapping')">
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
        </AdminCard>

        <AdminCard :title="t('Options')">
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
        </AdminCard>

        <div class="a-form__actions">
          <button class="a-btn a-btn--primary" type="submit" :disabled="saving">{{ saving ? t('Saving…') : t('Save') }}</button>
          <button class="a-btn a-btn--ghost" type="button" @click="back">{{ t('Cancel') }}</button>
        </div>
      </form>
    </template>
  </AdminPage>
</template>
