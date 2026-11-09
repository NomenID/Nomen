<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { reactive } from 'vue'
import StepHead from '../StepHead.vue'
import { t } from '../i18n'
import { go, submit, wizard } from '../wizard'

const saved = wizard.state.admin || {}
const hasPassword = saved.has_password === true

const form = reactive({
  username: saved.username || '',
  email: saved.email || '',
  password: '',
  password2: '',
})

async function save() {
  await submit('admin', { ...form })
}
</script>

<template>
  <StepHead icon="admin" :title="t('Create the admin account')"
    :lead="t('You will sign in with this account after installing. You can create more accounts in the admin area later.')" />

  <form @submit.prevent="save">
    <div class="fields">
      <div class="field">
        <label for="admin_username">{{ t('Username') }}</label>
        <input id="admin_username" v-model="form.username" class="input" required minlength="3" maxlength="32"
          pattern="[a-z0-9_]+" autocomplete="username">
        <div class="hint">{{ t('3-32 lowercase letters, digits or underscores.') }}</div>
      </div>
      <div class="field">
        <label for="admin_email">{{ t('Email address') }}</label>
        <input id="admin_email" v-model="form.email" class="input" type="email" required autocomplete="email">
        <div class="hint">{{ t('Used for password recovery and admin notifications; it counts as verified right away.') }}</div>
      </div>
      <div class="field">
        <label for="admin_password">{{ t('Password') }}</label>
        <input id="admin_password" v-model="form.password" class="input" type="password"
          :required="!hasPassword" minlength="8" autocomplete="new-password">
        <div class="hint">{{ hasPassword ? t('Leave empty to keep the password entered earlier.') : t('At least 8 characters, with letters and digits.') }}</div>
      </div>
      <div class="field">
        <label for="admin_password2">{{ t('Repeat the password') }}</label>
        <input id="admin_password2" v-model="form.password2" class="input" type="password"
          :required="!hasPassword" minlength="8" autocomplete="new-password">
      </div>
    </div>

    <div class="actions">
      <button class="btn btn-primary" type="submit" :class="{ 'is-busy': wizard.busy }" :disabled="wizard.busy">
        {{ t('Continue') }}
      </button>
    </div>

    <div class="nav-links">
      <button class="linkish" type="button" @click="go('site')">← {{ t('Back') }}</button>
      <span></span>
    </div>
  </form>
</template>
