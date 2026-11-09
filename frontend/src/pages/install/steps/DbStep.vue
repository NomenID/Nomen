<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
-->

<script setup>
import { reactive } from 'vue'
import StepHead from '../StepHead.vue'
import { t } from '../i18n'
import { go, submit, wizard } from '../wizard'

const saved = wizard.state.db || {}

const form = reactive({
  driver: saved.driver || 'mysql',
  host: saved.host || '127.0.0.1',
  port: saved.port || 3306,
  name: saved.name || 'nomen',
  user: saved.user || 'nomen',
  pass: saved.pass || '',
  path: saved.path || 'database/nomen.sqlite',
})

async function save() {
  await submit('db', { ...form })
}
</script>

<template>
  <StepHead icon="db" :title="t('Connect a database')"
    :lead="t('Accounts, logs and the application all live in this one database. It only needs to be empty — the installer creates the tables.')" />

  <form @submit.prevent="save">
    <div class="seg">
      <label>
        <input v-model="form.driver" type="radio" name="db_driver" value="mysql">
        <span>MySQL / MariaDB</span>
      </label>
      <label>
        <input v-model="form.driver" type="radio" name="db_driver" value="sqlite">
        <span>SQLite</span>
      </label>
    </div>

    <div v-if="form.driver === 'mysql'" class="fields">
      <div class="field-row">
        <div class="field">
          <label for="db_host">{{ t('Host') }}</label>
          <input id="db_host" v-model="form.host" class="input" autocomplete="off">
        </div>
        <div class="field">
          <label for="db_port">{{ t('Port') }}</label>
          <input id="db_port" v-model="form.port" class="input" inputmode="numeric">
        </div>
      </div>
      <div class="field">
        <label for="db_name">{{ t('Database name') }}</label>
        <input id="db_name" v-model="form.name" class="input" autocomplete="off">
      </div>
      <div class="field">
        <label for="db_user">{{ t('Username') }}</label>
        <input id="db_user" v-model="form.user" class="input" autocomplete="off">
      </div>
      <div class="field">
        <label for="db_pass">{{ t('Password') }}</label>
        <input id="db_pass" v-model="form.pass" class="input" type="password" autocomplete="off">
      </div>
    </div>

    <div v-else class="fields">
      <div class="field">
        <label for="db_path">{{ t('Database file') }}</label>
        <input id="db_path" v-model="form.path" class="input">
        <div class="hint">{{ t('Relative paths are resolved from the project root. The file is created automatically; the directory must be writable by PHP.') }}</div>
      </div>
    </div>

    <div class="actions">
      <button class="btn btn-primary" type="submit" :class="{ 'is-busy': wizard.busy }" :disabled="wizard.busy">
        {{ t('Connect and continue') }}
      </button>
    </div>

    <div class="nav-links">
      <button class="linkish" type="button" @click="go('env')">← {{ t('Back') }}</button>
      <span></span>
    </div>
  </form>

  <details class="more">
    <summary>{{ t('Cannot connect?') }}</summary>
    <div class="fields">
      <div class="hint">{{ t('Database accounts created in hosting panels usually allow local connections only — try localhost as the host. Create the database and user in the panel first; if the port is not 3306, change it to the real one.') }}</div>
      <pre class="code">CREATE DATABASE `nomen` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;</pre>
    </div>
  </details>
</template>
