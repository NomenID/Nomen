<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
-->

<script setup>
import { computed, reactive, ref } from 'vue'
import StepHead from '../StepHead.vue'
import { t } from '../i18n'
import { go, submit, uploadLogo, wizard } from '../wizard'

const saved = wizard.state.site || {}

const form = reactive({
  name: saved.name || 'Nomen',
  base_url: saved.base_url || wizard.baseUrl || '',
  copyright: saved.copyright || '',
  support_email: saved.support_email || '',
  admin_notify_email: saved.admin_notify_email || '',
  logo: saved.logo || '',
  language_default: saved.language_default || wizard.lang || 'en',
  language_allow_switch: saved.language_allow_switch !== false,
})

const optionalOpen = computed(() =>
  form.copyright !== '' || form.support_email !== '' || form.admin_notify_email !== ''
)

const fileInput = ref(null)
const uploading = ref(false)
const uploadError = ref('')
const preview = computed(() => form.logo || wizard.defaultLogo)

const uploadReady = computed(() => wizard.upload?.enabled === true)
const uploadHint = computed(() => uploadReady.value
  ? t('JPG / PNG / GIF / WebP, up to 2MB, cropped to a square automatically.')
  : (wizard.upload?.reason || t('This server cannot receive image uploads right now — paste an image URL instead.')))

function pickFile() {
  if (uploading.value || !uploadReady.value) return
  fileInput.value?.click()
}

async function onFile(event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return

  uploading.value = true
  uploadError.value = ''
  try {
    form.logo = await uploadLogo(file)
  } catch (err) {
    uploadError.value = err.message
  } finally {
    uploading.value = false
  }
}

function useDefault() {
  form.logo = ''
  uploadError.value = ''
}

async function save() {
  await submit('site', { ...form })
}
</script>

<template>
  <StepHead icon="site" :title="t('Site details')"
    :lead="t('These appear on the site and can be changed after installing.')" />

  <form @submit.prevent="save">
    <div class="fields">
      <div class="field">
        <label for="site_name">{{ t('Site name') }}</label>
        <input id="site_name" v-model="form.name" class="input" maxlength="60" required>
        <div class="hint">{{ t('Shown on the sign-in page, in page titles and in emails.') }}</div>
      </div>

      <div class="field">
        <label for="base_url">{{ t('Base URL') }}</label>
        <input id="base_url" v-model="form.base_url" class="input" type="url" required>
        <div class="hint">{{ t('Include the protocol, e.g. https://id.example.com. Used for links and emails after installing.') }}</div>
      </div>

      <div class="field">
        <span class="field-label">{{ t('Site icon') }}</span>
        <div class="avatar">
          <div class="avatar__preview" :class="{ 'is-busy': uploading }">
            <img :src="preview" alt="" width="72" height="72">
            <span v-if="uploading" class="avatar__spinner" aria-hidden="true"></span>
          </div>
          <div class="avatar__body">
            <div class="avatar__actions">
              <input ref="fileInput" class="upload-input" type="file"
                accept="image/jpeg,image/png,image/gif,image/webp" @change="onFile">
              <button class="btn btn-ghost btn-sm" type="button" :disabled="uploading || !uploadReady"
                @click="pickFile">{{ t('Upload an image') }}</button>
              <button class="btn btn-ghost btn-sm" type="button" :disabled="uploading || form.logo === ''"
                @click="useDefault">{{ t('Use the default icon') }}</button>
            </div>
            <p class="avatar__hint">{{ uploadHint }}</p>
            <p v-if="uploadError" class="avatar__error" role="alert">{{ uploadError }}</p>
          </div>
        </div>
      </div>

      <div class="field">
        <label for="language_default">{{ t('Default language') }}</label>
        <select id="language_default" v-model="form.language_default" class="input">
          <option v-for="language in wizard.languages" :key="language.slug" :value="language.slug">
            {{ language.name }} · {{ language.english }}
          </option>
        </select>
        <div class="hint">{{ t('Used when the visitor\'s browser language does not match.') }}</div>
      </div>

      <div class="field">
        <label class="switch">
          <input v-model="form.language_allow_switch" type="checkbox">
          <span class="switch__track"></span>
          <span>{{ t('Let visitors switch the interface language') }}</span>
        </label>
      </div>
      <div class="field">
        <label for="site_logo">{{ t('Icon URL (optional)') }}</label>
        <input id="site_logo" v-model="form.logo" class="input" maxlength="500"
          placeholder="https://example.com/icon.png">
        <div class="hint">{{ t('If you would rather not upload, paste a full URL or a site path starting with /; leave empty for the default Nomen icon.') }}</div>
      </div>
    </div>

    <details class="more" :open="optionalOpen">
      <summary>{{ t('Optional: footer copyright and contact email') }}</summary>
      <div class="fields">
        <div class="field">
          <label for="site_copyright">{{ t('Footer copyright') }}</label>
          <input id="site_copyright" v-model="form.copyright" class="input" maxlength="200"
            placeholder="© 2026 Example">
          <div class="hint">{{ t('Leave empty to hide the copyright line.') }}</div>
        </div>
        <div class="field">
          <label for="support_email">{{ t('Public support email') }}</label>
          <input id="support_email" v-model="form.support_email" class="input" type="email">
          <div class="hint">{{ t('The contact address shown to visitors on the sign-in and recovery pages. Leave empty to hide it.') }}</div>
        </div>
        <div class="field">
          <label for="admin_notify_email">{{ t('Admin notification email') }}</label>
          <input id="admin_notify_email" v-model="form.admin_notify_email" class="input" type="email">
          <div class="hint">{{ t('Recovery ticket alerts are sent here. Leave empty to disable alerts.') }}</div>
        </div>
      </div>
    </details>

    <div class="actions">
      <button class="btn btn-primary" type="submit" :class="{ 'is-busy': wizard.busy }" :disabled="wizard.busy">
        {{ t('Continue') }}
      </button>
    </div>

    <div class="nav-links">
      <button class="linkish" type="button" @click="go('db')">← {{ t('Back') }}</button>
      <span></span>
    </div>
  </form>
</template>
