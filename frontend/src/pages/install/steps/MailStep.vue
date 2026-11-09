<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { reactive, ref, watch } from 'vue'
import StepHead from '../StepHead.vue'
import { t } from '../i18n'
import { go, submit, wizard } from '../wizard'

const saved = wizard.state.smtp || {}
const mode = ref(saved.host ? 'form' : 'choice')

const form = reactive({
  host: saved.host || '',
  port: saved.port || 587,
  encryption: saved.encryption ?? 'tls',
  username: saved.username || '',
  password: saved.password || '',
  from_email: saved.from_email || '',
})

watch(() => form.encryption, (value) => {
  const port = Number(form.port)
  if (port !== 587 && port !== 465) return
  form.port = value === 'ssl' ? 465 : (value === 'tls' ? 587 : form.port)
})

function skip() {
  submit('mail', { mode: 'skip' })
}

function save() {
  submit('mail', { mode: 'save', ...form })
}

function back() {
  if (mode.value === 'form' && !saved.host) {
    mode.value = 'choice'
    return
  }
  go('plugins')
}
</script>

<template>
  <template v-if="mode === 'choice'">
    <StepHead icon="mail" :title="t('Configure email now?')"
      :lead="t('Sign-up codes, password recovery and notifications all go through SMTP. You can install without it and add it later in config/installed.php.')" />

    <div class="actions">
      <button class="btn btn-primary" type="button" :class="{ 'is-busy': wizard.busy }" :disabled="wizard.busy"
        @click="skip">{{ t('Not now') }}</button>
      <button class="btn btn-ghost" type="button" :disabled="wizard.busy" @click="mode = 'form'">{{ t('Configure now') }}</button>
    </div>

    <div class="nav-links">
      <button class="linkish" type="button" @click="go('plugins')">← {{ t('Back') }}</button>
      <span></span>
    </div>
  </template>

  <template v-else>
    <StepHead icon="mail" :title="t('Set up outgoing email')"
      :lead="t('Sign-up codes, password recovery and notifications all go through SMTP.')" />

    <form @submit.prevent="save">
      <div class="fields">
        <div class="field-row">
          <div class="field">
            <label for="smtp_host">{{ t('SMTP host') }}</label>
            <input id="smtp_host" v-model="form.host" class="input" placeholder="smtp.example.com" autocomplete="off">
          </div>
          <div class="field">
            <label for="smtp_port">{{ t('Port') }}</label>
            <input id="smtp_port" v-model="form.port" class="input" inputmode="numeric">
          </div>
        </div>
        <div class="field">
          <label for="smtp_encryption">{{ t('Encryption') }}</label>
          <select id="smtp_encryption" v-model="form.encryption" class="input">
            <option value="tls">{{ t('STARTTLS (587)') }}</option>
            <option value="ssl">{{ t('SSL (465)') }}</option>
            <option value="">{{ t('No encryption') }}</option>
          </select>
        </div>
        <div class="field">
          <label for="smtp_username">{{ t('Username') }}</label>
          <input id="smtp_username" v-model="form.username" class="input" autocomplete="off">
          <div class="hint">{{ t('Fill this in if the SMTP server requires authentication; leave it empty for a local relay.') }}</div>
        </div>
        <div class="field">
          <label for="smtp_password">{{ t('Password') }}</label>
          <input id="smtp_password" v-model="form.password" class="input" type="password" autocomplete="new-password">
        </div>
        <div class="field">
          <label for="smtp_from">{{ t('From address') }}</label>
          <input id="smtp_from" v-model="form.from_email" class="input" type="email" placeholder="no-reply@example.com">
        </div>
      </div>

      <div class="actions">
        <button class="btn btn-primary" type="submit" :class="{ 'is-busy': wizard.busy }" :disabled="wizard.busy">
          {{ t('Save and continue') }}
        </button>
      </div>

      <div class="nav-links">
        <button class="linkish" type="button" @click="back">← {{ t('Back') }}</button>
        <button class="linkish" type="button" :disabled="wizard.busy" @click="skip">{{ t('Skip for now') }}</button>
      </div>
    </form>
  </template>
</template>
