<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
-->

<script setup>
import StepHead from '../StepHead.vue'
import { t } from '../i18n'
import { go, resetStep, wizard } from '../wizard'

function start() {
  go(wizard.resume || 'env')
}
</script>

<template>
  <StepHead icon="brand" :logo="wizard.defaultLogo" :title="t('Install Nomen')"
    :lead="wizard.started
      ? t('You left off partway through. Each step asks one thing, and you can always go back.')
      : t('Install Nomen on this server. Each step asks one thing, and you can always go back.')" />

  <ul class="prep">
    <li><i>1</i><span><b>{{ t('Connect a database') }}</b><small>{{ t('MySQL, MariaDB or SQLite. Creating the database and user in your hosting panel first is the easiest path.') }}</small></span></li>
    <li><i>2</i><span><b>{{ t('Fill in the site details') }}</b><small>{{ t('Site name, base URL, icon and interface language.') }}</small></span></li>
    <li><i>3</i><span><b>{{ t('Create the admin account') }}</b><small>{{ t('You will sign in with it right after installing — no verification email needed.') }}</small></span></li>
  </ul>

  <div class="actions">
    <button class="btn btn-primary" type="button" @click="start">
      {{ wizard.started ? t('Resume installation') : t('Start installation') }}
    </button>
  </div>

  <div v-if="wizard.started" class="nav-links">
    <span></span>
    <button class="linkish" type="button" :disabled="wizard.busy" @click="resetStep">{{ t('Start over') }}</button>
  </div>
</template>
